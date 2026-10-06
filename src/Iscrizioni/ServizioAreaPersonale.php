<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Anagrafi\ServizioScuole;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Eventi\EventoRepository;
use App\Eventi\Turni;
use App\Infrastructure\Mail\Mailer;
use App\Iscrizioni\Vista\MessaggiAreaPersonale;
use App\Portale\ColoriAree;
use App\Sistema\ImpostazioniSistemaRepository;
use Throwable;

/**
 * Area personale di chi ha prenotato (area_personale.php): elenco delle prenotazioni con i turni alternativi, messaggi alla
 * segreteria, modifica dei dati e cambio turno (con blocco anti-overbooking sul turno di destinazione), annullamento,
 * conferma o rinuncia del posto offerto dalla lista d'attesa. Le azioni ritornano il messaggio (HTML) da mostrare dopo il rimando.
 */
final class ServizioAreaPersonale
{
    public function __construct(
        private Database $db,
        private AreaPersonaleRepository $repo,
        private CampiFormRepository $campi,
        private EventoRepository $eventi,
        private ServizioVincoli $vincoli,
        private ServizioListaAttesa $listaAttesa,
        private NotifichePrenotazione $notifiche,
        private ServizioAbilitazioni $abilitazioni,
        private ImpostazioniSistemaRepository $impostazioni,
        private RegoleFsl $fsl,
        private ServizioScuole $scuole,
        private Mailer $mailer,
        private ColoriAree $colori,
        private Sito $sito,
        private Orologio $orologio,
    ) {
    }

    // ---- elenco ----

    /**
     * Prenotazioni dell'utente divise in attive (turno non concluso, con i turni alternativi a cui passare) e passate.
     * Tre query in tutto, qualunque sia lo storico: prenotazioni, turni futuri degli eventi coinvolti e posti confermati di quei turni.
     *
     * @return array{attive: list<array<string, mixed>>, passate: list<array<string, mixed>>}
     */
    public function prenotazioni(int $utenteId, string $emailSql): array
    {
        $adesso = $this->orologio->adesso()->format('Y-m-d H:i:s');
        $righe = [];
        $eventiIds = [];
        foreach ($this->repo->dellUtente($utenteId, $emailSql) as $row) {
            $row['turni_alternativi'] = [];
            $row['_is_attiva'] = !Turni::concluso($row);
            if ($row['_is_attiva']) {
                $eventiIds[(int) $row['evento_id']] = true;
            }
            $righe[] = $row;
        }

        $turniPerEvento = [];
        $occupatiPerTurno = [];
        if (!empty($eventiIds)) {
            $turniIds = [];
            foreach ($this->repo->turniFuturi(array_keys($eventiIds), $adesso) as $ta) {
                $turniPerEvento[(int) $ta['evento_id']][] = $ta;
                $turniIds[] = (int) $ta['id'];
            }
            if (!empty($turniIds)) {
                $occupatiPerTurno = $this->repo->postiConfermatiPerTurno(array_values(array_unique($turniIds)));
            }
        }

        // Seconda passata: turni alternativi dai dati già in memoria, poi attive/passate
        $attive = [];
        $passate = [];
        foreach ($righe as $row) {
            if ($row['_is_attiva']) {
                foreach (($turniPerEvento[(int) $row['evento_id']] ?? []) as $ta) {
                    $occupati = $occupatiPerTurno[(int) $ta['id']] ?? 0;
                    $ta['posti_liberi'] = (int) $ta['max_posti'] - $occupati;

                    $chiuso = false;
                    if (!empty($ta['data_apertura']) && $this->orologio->adesso()->format('Y-m-d H:i:s') < $ta['data_apertura']) {
                        $chiuso = true;
                    }
                    if (!empty($ta['data_chiusura']) && $this->orologio->adesso()->format('Y-m-d H:i:s') > $ta['data_chiusura']) {
                        $chiuso = true;
                    }
                    $ta['is_closed'] = $chiuso;

                    $row['turni_alternativi'][] = $ta;
                }
                unset($row['_is_attiva']);
                $attive[] = $row;
            } else {
                unset($row['_is_attiva']);
                $passate[] = $row;
            }
        }

        return ['attive' => $attive, 'passate' => $passate];
    }

    /**
     * Campi del modulo di iscrizione dell'evento (quelli dell'area e quelli dell'evento), per la finestra «Modifica prenotazione».
     *
     * @return list<array<string, string|null>>
     */
    public function campiModulo(int $paginaId, int $eventoId): array
    {
        return $this->campi->perModulo($paginaId, $eventoId);
    }

    /**
     * Questionari di gradimento da compilare: eventi conclusi (con presenza o senza registro presenze) di cui la persona
     * non ha ancora risposto; se manca, crea il token del questionario sulla prenotazione.
     *
     * @param list<array<string, mixed>> $passate
     * @return array{disponibili: int, eventi: array<int, array{titolo: mixed, link: string, data: mixed}>}
     */
    public function sondaggiDisponibili(array $passate): array
    {
        $disponibili = 0;
        $eventi = [];
        foreach ($passate as $pr) {
            $eventoId = (int) $pr['evento_id'];
            $prId = (int) $pr['id'];

            $stato = empty($pr['stato']) ? 'confermata' : $pr['stato'];
            $presente = (int) $pr['presente'];
            $completato = (int) ($pr['sondaggio_completato'] ?? 0);

            if ($stato === 'confermata' && $completato === 0 && $this->repo->sondaggioAttivo($eventoId)) {
                $abilitaPresenze = (int) ($pr['abilita_presenze'] ?? 1);

                if ($presente === 1 || $abilitaPresenze === 0) {
                    $token = $pr['token_sondaggio'] ?? '';
                    if (empty($token)) {
                        $token = bin2hex(random_bytes(16));
                        $this->repo->impostaTokenSondaggio($prId, $token);
                    }

                    if (!isset($eventi[$eventoId])) {
                        $eventi[$eventoId] = [
                            'titolo' => $pr['evento_titolo'],
                            'link' => 'sondaggio.php?token=' . $token,
                            'data' => $pr['data_turno'],
                        ];
                        ++$disponibili;
                    }
                }
            }
        }

        return ['disponibili' => $disponibili, 'eventi' => $eventi];
    }

    // ---- messaggi alla segreteria ----

    /**
     * Messaggio dell'utente alla segreteria (chat della prenotazione), con avviso per email ai gestori dell'evento.
     * Ritorna false (e non fa nulla) se la prenotazione non è della persona o il messaggio è vuoto.
     */
    public function inviaMessaggio(int $prenotazioneId, int $utenteId, string $emailSql, string $corpo): bool
    {
        $messaggioHtml = nl2br(htmlspecialchars(trim($corpo)));
        if (!$this->repo->appartieneA($prenotazioneId, $utenteId, $emailSql) || empty($messaggioHtml)) {
            return false;
        }
        // Segna gli eventuali messaggi dell'admin come letti, poi salva il nuovo messaggio
        $this->repo->segnaLettiMessaggiAdmin($prenotazioneId);
        $this->repo->inserisciMessaggioUtente($prenotazioneId, $utenteId, $messaggioHtml);

        // Notifica email ai gestori dell'evento
        $dati = $this->repo->personaEEvento($prenotazioneId);
        if ($dati !== null) {
            $oggetto = 'Nuovo messaggio assistenza – ' . $dati['evento_titolo'];
            $corpoEmail = '<p>Gentile Gestore,</p>'
                    . '<p><strong>' . htmlspecialchars($dati['nome'] . ' ' . $dati['cognome']) . "</strong> ha inviato un messaggio riguardante l'evento <strong>" . htmlspecialchars((string) $dati['evento_titolo']) . '</strong>.</p>'
                    . '<p>Accedi al pannello di amministrazione &gt; Messaggi per rispondere.</p>'
                    . '<p>Cordiali saluti,<br>Sistema Didattica DiBEST</p>';
            // I messaggi di assistenza vanno a tutti i gestori, indipendentemente dall'interruttore notifiche prenotazioni
            foreach ($this->abilitazioni->emailGestoriEvento((int) $dati['evento_id'], false) as $gestore) {
                $this->mailer->invia($gestore, $oggetto, $corpoEmail);
            }
        }

        return true;
    }

    // ---- modifica dei dati e cambio turno ----

    /**
     * Modifica dei dati della prenotazione e, se richiesto, cambio turno: se il nuovo turno ha posto resta confermata, altrimenti
     * (con lista d'attesa) va in coda. Il posto lasciato passa al primo in coda.
     *
     * @param array<string, mixed> $post campi del modulo ($_POST): custom_* e scuola_codice
     * @param string $baseLink indirizzo della cartella del portale (per il link alla ricevuta nelle email)
     * @return string messaggio HTML da mostrare
     */
    public function modifica(int $prenotazioneId, int $utenteId, string $emailSql, int $nuovoTurnoId, string $nome, string $cognome, string $email, string $matricola, array $post, string $baseLink): string
    {
        $vecchia = $this->repo->perModifica($prenotazioneId, $utenteId, $emailSql);
        if ($vecchia === null) {
            return MessaggiAreaPersonale::nonAutorizzata();
        }
        $turnoAttuale = (int) $vecchia['turno_id'];
        $postiRichiesti = (int) $vecchia['num_posti'];

        $turnoDaSalvare = $turnoAttuale;
        $nuovoStato = null;

        // Si parte dai dati già salvati: allegati e campi non mostrati nel modulo di modifica restano com'erano
        $custom = json_decode((string) ($vecchia['dati_custom_json'] ?? ''), true) ?: [];
        $customPrima = $custom;
        foreach ($post as $k => $v) {
            if (strpos((string) $k, 'custom_') === 0) {
                $custom[str_replace('custom_', '', (string) $k)] = is_array($v) ? implode(', ', $v) : trim((string) $v);
            }
        }
        // Campo «Scuola»: nome ufficiale se scelta dall'anagrafe; il codice cambia solo se la scuola è stata toccata
        $scuola = $this->scuole->applicaScelta($custom, $post['scuola_codice'] ?? []);
        $scuolaToccata = $scuola !== null;
        foreach (array_keys((array) ($post['scuola_codice'] ?? [])) as $campo) {
            if (($customPrima[$campo] ?? '') !== ($custom[$campo] ?? '')) {
                $scuolaToccata = true;
            }
        }
        $jsonCustom = !empty($custom) ? (string) json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;

        // Progetti per le scuole: il numero di partecipanti modificato deve restare nei limiti del progetto
        $evp = $this->repo->turnoEdEvento($turnoAttuale);
        $dettagli = $evp ? ($this->eventi->dettagliProgetti([(int) $evp['evento_id']])[(int) $evp['evento_id']] ?? null) : null;
        $cambioTurno = $nuovoTurnoId > 0 && $nuovoTurnoId !== $turnoAttuale;
        // Cambio turno non più consentito dopo la scadenza per annullare del turno attuale
        if ($evp && $cambioTurno && self::annullamentoScaduto($evp)) {
            return MessaggiAreaPersonale::cambioTurnoScaduto((string) $evp['annullabile_fino']);
        }
        if ($evp && $this->fsl->prenotazioneDiClasse($evp['tipo'] === 'progetto', $dettagli)) {
            $errore = LimitiPartecipanti::valida($custom, ($dettagli ?? []) + ['per_scuole' => 1], $evp);
            if ($errore !== null) {
                return MessaggiAreaPersonale::modificaNonSalvata($errore);
            }
        }

        // Sezione critica: transazione + blocco pessimistico anti-overbooking sul turno di destinazione (se si cambia turno).
        try {
            [$turnoDaSalvare, $nuovoStato] = $this->db->transazione(function () use ($cambioTurno, $nuovoTurnoId, $postiRichiesti, $turnoDaSalvare, $prenotazioneId, $nome, $cognome, $email, $matricola, $jsonCustom, $scuolaToccata, $scuola, $utenteId): array {
                $nuovoStato = null;
                if ($cambioTurno) {
                    $info = $this->repo->capienzaTurno($nuovoTurnoId);
                    if ($info !== null) {
                        // FOR UPDATE: blocca le prenotazioni del turno di destinazione finché la transazione non finisce,
                        // per un conteggio affidabile anche in concorrenza.
                        $liberi = $info['max_posti'] - $this->repo->postiConfermatiBloccati($nuovoTurnoId);
                        if ($liberi >= $postiRichiesti) {
                            $turnoDaSalvare = $nuovoTurnoId;
                            $nuovoStato = 'confermata';
                        } elseif ($info['abilita_lista_attesa'] == 1) {
                            $turnoDaSalvare = $nuovoTurnoId;
                            $nuovoStato = 'in_attesa';
                        } else {
                            throw new PostiEsauriti();
                        }
                    }
                }

                if (!$this->repo->aggiorna($prenotazioneId, $nome, $cognome, $email, $matricola, $jsonCustom, $nuovoStato !== null ? $turnoDaSalvare : null, $nuovoStato)) {
                    throw new \RuntimeException($this->repo->messaggioErrore() ?: 'Errore sconosciuto in fase di aggiornamento prenotazione');
                }
                if ($scuolaToccata) {
                    $this->repo->impostaScuolaPrenotazione($prenotazioneId, $scuola);
                    if ($scuola && $utenteId > 0) {
                        $this->repo->impostaScuolaUtente($utenteId, $scuola);
                    }
                }

                return [$turnoDaSalvare, $nuovoStato];
            });
        } catch (PostiEsauriti) {
            return MessaggiAreaPersonale::turnoEsaurito();
        } catch (Throwable $e) {
            error_log("[EditPrenotazione][pr_id=$prenotazioneId] Transazione fallita: " . $e->getMessage());

            return MessaggiAreaPersonale::erroreAggiornamentoRiprova();
        }

        if ($nuovoStato === 'confermata') {
            $this->vincoli->decadiAttese($prenotazioneId);
        }
        if (!$cambioTurno) {
            return MessaggiAreaPersonale::prenotazioneAggiornata();
        }

        // Il posto lasciato passa al primo in coda del turno di partenza
        $promosso = $this->repo->primoInAttesa($turnoAttuale);
        if ($promosso !== null) {
            $promossoId = (int) $promosso['id'];
            $this->repo->promuoviDallaCoda($promossoId);
            $this->vincoli->decadiAttese($promossoId);

            $link = $baseLink . '/stampa_ricevuta.php?code=' . urlencode((string) $promosso['codice_prenotazione']);
            $bottone = "<p style='margin-top:15px;'><a href='$link' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>";

            $oggetto = 'Posto Disponibile! Prenotazione CONFERMATA';
            $corpo = '<p>Ottime notizie <strong>' . htmlspecialchars((string) $promosso['nome']) . "</strong>!</p><p>Si è appena liberato un posto e la tua prenotazione in lista d'attesa è passata a <strong>CONFERMATA UFFICIALMENTE</strong>.</p>$bottone";
            $this->mailer->invia((string) $promosso['email'], $oggetto, $corpo, $this->colori->delTurno($turnoAttuale));
        }

        return MessaggiAreaPersonale::modificaSalvata($nuovoStato === 'in_attesa');
    }

    // ---- annullamento ----

    /**
     * Annullamento della prenotazione da parte della persona: la riga resta nel DB come «annullata», email di conferma alla persona
     * e di disdetta ai gestori, il posto liberato passa al primo in coda. Oltre il termine del turno non si annulla più
     * (chi è solo in lista d'attesa può sempre uscirne).
     */
    public function annulla(int $prenotazioneId, int $utenteId, string $emailSql, string $baseLink): string
    {
        $p = $this->repo->perAnnullamento($prenotazioneId, $utenteId, $emailSql);
        if ($p === null) {
            return MessaggiAreaPersonale::annullamentoNegato();
        }
        if (self::annullamentoScaduto($p) && !in_array($p['stato'], ['in_attesa', 'annullata', 'rifiutata', 'scaduta'], true)) {
            return MessaggiAreaPersonale::annullamentoScaduto((string) $p['annullabile_fino']);
        }
        // Anche un posto offerto e non ancora confermato va ripassato alla coda
        $eraConfermata = in_array($p['stato'], ['confermata', 'richiesta_conferma'], true);
        $turnoId = (int) $p['turno_id'];

        // L'annullamento è un UPDATE (non un DELETE): la riga resta per storico e verifiche e non ricompare nell'elenco attivo
        $this->repo->annulla($prenotazioneId);

        $dataFormattata = implode(' · ', array_filter([$p['nome_turno'] ?? '', !empty($p['data_turno']) ? date('d/m/Y', strtotime($p['data_turno'])) : '']));
        $oraFormattata = Turni::orario($p) ?: 'da definire';
        $cerca = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}', '{LINK_RICEVUTA}'];
        $sostituisci = [$p['nome'], $p['cognome'], $p['matricola'], $p['evento_titolo'], $dataFormattata, $oraFormattata, $p['luogo'], $p['codice_prenotazione'], ''];

        $sistema = $this->impostazioni->riga() ?? [];

        $oggetto = $sistema['email_canc_utente_oggetto'] ?: 'Cancellazione Prenotazione Confermata';
        $corpo = $sistema['email_canc_utente_corpo'] ?: "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>La tua prenotazione per l'evento <strong>{TITOLO_EVENTO}</strong> è stata cancellata con successo.</p>";
        $colore = $this->colori->delTurno((int) $p['turno_id']);
        $this->mailer->invia((string) $p['email'], str_replace($cerca, $sostituisci, (string) $oggetto), str_replace($cerca, $sostituisci, (string) $corpo), $colore);

        // Gestori con notifiche attive + indirizzi aggiuntivi dell'evento, con il riepilogo completo
        $destinatari = $this->notifiche->destinatari((int) $p['evento_id']);
        $riepilogo = $destinatari ? $this->notifiche->riepilogo($prenotazioneId) : null;
        if ($riepilogo) {
            $oggettoGestori = 'Disdetta: ' . $p['evento_titolo'];
            $intro = '<p><strong>' . htmlspecialchars($p['nome'] . ' ' . $p['cognome']) . "</strong> ha appena <strong>annullato</strong> la prenotazione per l'evento <strong>" . htmlspecialchars((string) $p['evento_titolo']) . '</strong>.</p>';
            $gestori = $this->abilitazioni->emailGestoriEvento((int) $p['evento_id']);
            foreach ($destinatari as $emailGestore) {
                $this->mailer->invia($emailGestore, $oggettoGestori, NotifichePrenotazione::corpoPer($emailGestore, $intro, $riepilogo, $gestori), $colore);
            }
        }

        if ($eraConfermata) {
            $promosso = $this->repo->primoInAttesa($turnoId);
            if ($promosso !== null) {
                $promossoId = (int) $promosso['id'];
                $this->repo->promuoviDallaCoda($promossoId);
                $this->vincoli->decadiAttese($promossoId);

                $link = $baseLink . '/stampa_ricevuta.php?code=' . urlencode((string) $promosso['codice_prenotazione']);
                $bottone = "<p style='margin-top:15px;'><a href='$link' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>";

                $oggettoPromosso = 'Posto Disponibile! Prenotazione CONFERMATA: ' . $p['evento_titolo'];
                $corpoPromosso = "<p>Ottime notizie <strong>{NOME} {COGNOME}</strong>!</p><p>Si è appena liberato un posto e la tua prenotazione in lista d'attesa per l'evento <strong>{TITOLO_EVENTO}</strong> è passata a <strong>CONFERMATA UFFICIALMENTE</strong>.</p>{LINK_RICEVUTA}";

                $sostituisciPromosso = array_map('strval', [$promosso['nome'], $promosso['cognome'], $promosso['matricola'], $p['evento_titolo'], $dataFormattata, $oraFormattata, $p['luogo'], $promosso['codice_prenotazione'], $bottone]);
                $this->mailer->invia((string) $promosso['email'], str_replace($cerca, $sostituisciPromosso, $oggettoPromosso), str_replace($cerca, $sostituisciPromosso, $corpoPromosso), $this->colori->delTurno($turnoId));
            }
        }

        return MessaggiAreaPersonale::annullata();
    }

    // ---- posto offerto dalla lista d'attesa ----

    /**
     * Risposta della persona al posto offerto dalla lista d'attesa (link nell'email): conferma o rinuncia. Il controllo dell'offerta
     * (ancora valida, non scaduta) avviene in transazione con la riga bloccata. Una scuola senza convenzione tiene il posto
     * ma la prenotazione resta «da approvare» finché la convenzione non arriva.
     */
    public function rispondiAlPosto(int $prenotazioneId, int $utenteId, string $emailSql, bool $conferma): string
    {
        try {
            $p = $this->db->transazione(function () use ($prenotazioneId, $utenteId, $emailSql, $conferma): array {
                $p = $this->repo->offertaBloccata($prenotazioneId, $utenteId, $emailSql);

                $scaduta = $p && (($p['stato'] === 'scaduta')
                        || ($p['stato'] === 'richiesta_conferma' && !empty($p['scadenza_conferma']) && $p['scadenza_conferma'] < $this->orologio->adesso()->format('Y-m-d H:i:s')));
                if (!$p || $p['stato'] !== 'richiesta_conferma' || $scaduta) {
                    throw new OffertaNonValida((bool) $scaduta);
                }

                // Scuola senza convenzione: il posto resta suo, ma la prenotazione si conferma quando arriva la convenzione
                $senzaConvenzione = ($p['convenzione'] ?? '') === 'no';
                $nuovoStato = $conferma ? ($senzaConvenzione ? 'da_approvare' : 'confermata') : 'annullata';
                if (!$this->repo->impostaStato($prenotazioneId, $nuovoStato)) {
                    throw new \RuntimeException('Errore durante il salvataggio');
                }

                return $p;
            });
        } catch (OffertaNonValida $e) {
            return $e->scaduta ? MessaggiAreaPersonale::offertaScaduta() : MessaggiAreaPersonale::offertaNonValida();
        } catch (Throwable) {
            return MessaggiAreaPersonale::erroreSalvataggio();
        }

        $senzaConvenzione = ($p['convenzione'] ?? '') === 'no';
        $colore = $this->colori->delTurno((int) $p['turno_id']);
        if ($conferma && $senzaConvenzione) {
            $cfg = $this->repo->areaDelTurno((int) $p['turno_id']);
            $corpo = '<p>Gentile <strong>' . htmlspecialchars($p['nome'] . ' ' . $p['cognome']) . '</strong>,</p>'
                   . '<p>hai accettato il posto per <strong>' . htmlspecialchars((string) $p['evento_titolo']) . '</strong> (' . htmlspecialchars(Turni::etichetta($p)) . ').</p>'
                   . $this->fsl->istruzioniConvenzione($cfg, true, (string) $p['codice_prenotazione']);
            $this->mailer->invia((string) $p['email'], 'Posto accettato, in attesa della convenzione: ' . $p['evento_titolo'], $corpo, $colore);

            return MessaggiAreaPersonale::postoAccettatoInAttesaConvenzione($this->fsl->istruzioniConvenzione($cfg, false, (string) $p['codice_prenotazione']));
        }
        if ($conferma) {
            $this->vincoli->decadiAttese($prenotazioneId);
            $link = $this->sito->urlBase() . '/stampa_ricevuta.php?code=' . urlencode((string) $p['codice_prenotazione']);
            $corpo = '<p>Gentile <strong>' . htmlspecialchars($p['nome'] . ' ' . $p['cognome']) . '</strong>,</p>'
                   . '<p>hai confermato il tuo posto per <strong>' . htmlspecialchars((string) $p['evento_titolo']) . '</strong> (' . htmlspecialchars(Turni::etichetta($p)) . '). La prenotazione è <strong>CONFERMATA</strong>.</p>'
                   . "<p style='margin-top:15px;'><a href='$link' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>";
            $this->mailer->invia((string) $p['email'], 'Prenotazione CONFERMATA: ' . $p['evento_titolo'], $corpo, $colore);

            return MessaggiAreaPersonale::postoConfermato();
        }
        // Il posto passa subito al prossimo in lista d'attesa
        $this->listaAttesa->promuovi((int) $p['turno_id']);

        return MessaggiAreaPersonale::rinunciaAlPosto();
    }

    /**
     * Riquadro «Si è liberato un posto per te» (link dell'email, ?conferma_posto=ID): l'offerta da mostrare, oppure il messaggio
     * da tenere in sessione se è già confermata, scaduta o non valida.
     *
     * @return array{riquadro: array<string, mixed>|null, messaggio: string|null}
     */
    public function riquadroOfferta(int $prenotazioneId, int $utenteId, string $emailSql): array
    {
        $riga = $this->repo->offertaPerRiquadro($prenotazioneId, $utenteId, $emailSql);
        if ($riga && $riga['stato'] === 'richiesta_conferma') {
            return ['riquadro' => $riga, 'messaggio' => null];
        }
        if ($riga && $riga['stato'] === 'confermata') {
            return ['riquadro' => null, 'messaggio' => MessaggiAreaPersonale::postoGiaConfermato()];
        }
        if ($riga && $riga['stato'] === 'scaduta') {
            return ['riquadro' => null, 'messaggio' => MessaggiAreaPersonale::offertaScaduta()];
        }

        return ['riquadro' => null, 'messaggio' => MessaggiAreaPersonale::offertaNonDisponibile()];
    }

    /**
     * Il turno ha una scadenza per annullare o cambiare turno ed è passata.
     *
     * @param array<string, mixed>|null $turno
     */
    public static function annullamentoScaduto(?array $turno): bool
    {
        return !empty($turno['annullabile_fino']) && date('Y-m-d H:i:s') > $turno['annullabile_fino'];
    }
}
