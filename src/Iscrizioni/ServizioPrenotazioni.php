<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Anagrafi\ServizioScuole;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Auth\LimiteRichieste;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Eventi\EventoRepository;
use App\Eventi\Turni;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Storage\Upload;
use App\Portale\ColoriAree;
use App\Sistema\ImpostazioniSistemaRepository;
use Throwable;

/**
 * Prenotazione dal modulo pubblico di un'area (master_template.php): controlli, anti-robot, blocco per persona e area,
 * transazione con conteggio dei posti bloccato (anti-overbooking), lista d'attesa, email a chi prenota e ai gestori.
 *
 * L'ordine delle operazioni è quello della pagina originale: ogni uscita anticipata diventa un EsitoPrenotazione con il rimando
 * che la pagina eseguiva (header Location). I blocchi per persona e area (GET_LOCK) restano presi fino alla fine della richiesta,
 * come prima, tranne dove la pagina li rilasciava esplicitamente.
 */
final class ServizioPrenotazioni
{
    private const MIME_ALLEGATI = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    private const ESTENSIONI_ALLEGATI = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx'];

    public function __construct(
        private Database $db,
        private PrenotazioneRepository $prenotazioni,
        private EventoRepository $eventi,
        private ServizioVincoli $vincoli,
        private CaptchaPrenotazione $captcha,
        private LimiteRichieste $limite,
        private RegoleFsl $fsl,
        private ServizioScuole $scuole,
        private NotifichePrenotazione $notifiche,
        private ServizioAbilitazioni $abilitazioni,
        private ImpostazioniSistemaRepository $impostazioni,
        private Mailer $mailer,
        private ColoriAree $colori,
        private Upload $upload,
        private Sito $sito,
        private Orologio $orologio,
    ) {
    }

    public function prenota(RichiestaPrenotazione $r): EsitoPrenotazione
    {
        $slug = $r->slug;
        $turnoId = $r->turnoId;
        $email = $r->email;
        $post = $r->post;

        // Il turno deve appartenere a quest'area. Dopo l'invio si torna alla scheda del progetto
        // se la prenotazione riguarda un progetto, altrimenti alla pagina dell'area.
        $evRit = $this->prenotazioni->eventoDelTurnoNellArea($turnoId, $r->paginaId());
        if ($evRit === null) {
            return new EsitoPrenotazione("{$slug}.php?status=error");
        }
        // Ritorno: scheda del progetto, scheda dell'evento (se la prenotazione parte da lì) oppure pagina dell'area
        if (($evRit['tipo'] ?? '') === 'progetto') {
            $ritorno = "{$slug}.php?progetto=" . (int) $evRit['id'] . '&';
        } elseif ((int) ($post['da_scheda'] ?? 0) === (int) $evRit['id']) {
            $ritorno = "{$slug}.php?evento=" . (int) $evRit['id'] . '&';
        } else {
            $ritorno = "{$slug}.php?";
        }

        // Prenotazione pubblica (senza accesso): trappola per i robot, domanda di controllo e limite per indirizzo IP
        if (!$r->connesso() && !$r->controlliPubbliciGiaFatti) {
            $errCaptcha = null;
            if (trim((string) ($post['sito_web'] ?? '')) !== '') {
                $errCaptcha = 'Prenotazione non registrata: riprova.';
            } elseif (!$this->limite->consenti($r->ip, 'prenotazione_pubblica', 20, 3600)) {
                $errCaptcha = "Troppe prenotazioni da questa connessione: riprova tra un'ora o accedi con SPID/CIE.";
            } else {
                $errCaptcha = $this->captcha->verifica((string) ($post['captcha_id'] ?? ''), (string) ($post['captcha_risposta'] ?? ''));
            }
            if ($errCaptcha !== null) {
                return new EsitoPrenotazione("{$ritorno}status=captcha", $errCaptcha);
            }
        }

        if ($r->nome === '' || $r->cognome === '' || $email === '' || $r->nome === '0' || $r->cognome === '0' || $email === '0') {
            return new EsitoPrenotazione("{$ritorno}status=error");
        }

        // L'email si scrive a mano (non viene dall'accesso) e si ripete: le ricevute e gli attestati arrivano lì
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new EsitoPrenotazione("{$ritorno}status=email", 'Indirizzo email non valido.');
        }
        if (isset($post['email_conferma']) && strtolower(trim((string) $post['email_conferma'])) !== $email) {
            return new EsitoPrenotazione("{$ritorno}status=email", 'Le due email non coincidono: riscrivile con attenzione.');
        }

        // Controllo duplicati (email o matricola)
        if ($this->prenotazioni->esisteDuplicata($turnoId, $email, $r->matricola)) {
            return new EsitoPrenotazione("{$ritorno}status=dup");
        }

        $t = $this->prenotazioni->turnoPerPrenotare($turnoId);
        if ($t === null) {
            return new EsitoPrenotazione("{$slug}.php");
        }

        // Chi può prenotare: controllato anche qui, non solo nascondendo il pulsante
        if ((int) ($t['richiede_prenotazione'] ?? 1) === 0) {
            return new EsitoPrenotazione("{$ritorno}status=error");
        }
        $ruoloEv = (int) ($t['ruolo_accesso_id'] ?? 0);
        if ($ruoloEv !== 0) {
            $ruoloOk = $r->connesso() && ($ruoloEv === -1 || $r->ruoloUtente === $ruoloEv || in_array((string) $ruoloEv, $r->ruoliSecondari, true)
                                         || $r->ruoloUtente === 1 || in_array('1', $r->ruoliSecondari, true));
            if (!$ruoloOk) {
                return new EsitoPrenotazione("{$ritorno}status=riservato");
            }
        }
        // Progetti: si partecipa a una sola edizione dello stesso progetto (anche la lista d'attesa conta), salvo che il progetto consenta più edizioni
        if (($t['evento_tipo'] ?? '') === 'progetto'
            && !$this->prenotazioni->piuEdizioniConsentite((int) $t['evento_id'])
            && $this->prenotazioni->partecipaAdAltraEdizione((int) $t['evento_id'], $turnoId, (int) ($r->utenteId ?? 0), $email)) {
            return new EsitoPrenotazione("{$ritorno}status=altra_edizione");
        }

        $adesso = $this->orologio->adesso()->format('Y-m-d H:i:s');
        $aperturaOk = empty($t['data_apertura']) || ($adesso >= $t['data_apertura']);
        $chiusuraOk = empty($t['data_chiusura']) || ($adesso <= $t['data_chiusura']);
        if (!$aperturaOk) {
            return new EsitoPrenotazione("{$ritorno}status=notopened");
        }
        if (!$chiusuraOk) {
            return new EsitoPrenotazione("{$ritorno}status=closed");
        }

        $limiteIsc = (string) ($r->pagina['limite_iscrizioni'] ?? 'nessuno');
        $blocco = null;
        if ($limiteIsc !== 'nessuno') {
            // Blocco per persona e area fino a fine inserimento: due invii simultanei (doppio clic, due schede, turni diversi)
            // non possono superare entrambi il controllo. Quello FOR UPDATE sotto copre solo il singolo turno.
            $blocco = 'dibest_iscr_' . (int) $t['pagina_id'] . '_' . md5($email);
            if (!$this->prenotazioni->ottieniBlocco($blocco)) {
                return new EsitoPrenotazione("{$ritorno}status=error");
            }
            $bloccante = $this->vincoli->iscrizioneVincolata($limiteIsc, (int) $t['pagina_id'], (int) $t['evento_id'], (int) ($r->utenteId ?? 0), $email, $r->matricola);
            if ($bloccante) {
                $this->rilascia($blocco);

                return new EsitoPrenotazione("{$ritorno}status=limite&ev=" . urlencode((string) $bloccante['evento_titolo']));
            }
        }

        $codice = strtoupper(substr($slug, 0, 2)) . '-' . strtoupper(bin2hex(random_bytes(4)));

        $custom = [];
        foreach ($post as $k => $v) {
            if (strpos((string) $k, 'custom_') === 0) {
                $custom[str_replace('custom_', '', (string) $k)] = is_array($v) ? implode(', ', $v) : trim((string) $v);
            }
        }
        // Campo «Scuola»: nome ufficiale e codice meccanografico se scelta dall'anagrafe
        $scuolaCodice = $this->scuole->applicaScelta($custom, $post['scuola_codice'] ?? []);

        // Prenotazioni di classe (progetti per le scuole, eventi con attestati per gli studenti):
        // numero di studenti obbligatorio e dentro i limiti del progetto o del turno
        $eventoId = (int) $t['evento_id'];
        $dett = $this->eventi->dettagliProgetti([$eventoId])[$eventoId] ?? null;
        $eProgetto = ($t['evento_tipo'] ?? '') === 'progetto';
        if ($this->fsl->prenotazioneDiClasse($eProgetto, $dett)) {
            $errStudenti = LimitiPartecipanti::valida($custom, ($dett ?? []) + ['per_scuole' => 1], $t);
            if ($errStudenti !== null) {
                $this->rilascia($blocco);

                return new EsitoPrenotazione("{$ritorno}status=studenti", $errStudenti);
            }
        }

        // Convenzione con la scuola: risposta obbligatoria se il progetto/evento la chiede.
        // «No» → la prenotazione resta da approvare finché la convenzione non arriva.
        $conv = null;
        $convRinnovo = false;
        if ((int) ($dett['convenzione'] ?? 0) === 1) {
            $conv = in_array($post['convenzione'] ?? '', ['si', 'no'], true) ? (string) $post['convenzione'] : null;
            if ($conv === null) {
                $this->rilascia($blocco);

                return new EsitoPrenotazione("{$ritorno}status=studenti", 'Indica se la scuola ha già stipulato la convenzione con il Dipartimento.');
            }
            // Scuola scelta dall'anagrafe: conta il registro delle convenzioni, che deve coprire tutto il periodo dell'attività.
            // Coperto → non serve attendere. Registrata ma non copre il periodo → ne va stipulata una nuova, anche se ha risposto «Sì».
            if ($scuolaCodice) {
                [$dal, $al] = $this->fsl->periodoAttivita($dett['data_inizio'] ?? null, $dett['data_fine'] ?? null, $t['data_turno'] ?? null);
                if ($this->fsl->convenzioneValida($scuolaCodice, $dal, $al)) {
                    $conv = 'ricevuta';
                } elseif ($conv === 'si' && $this->fsl->convenzioniDellaScuola($scuolaCodice)) {
                    $conv = 'no';
                    $convRinnovo = true;
                }
            }
        }

        $this->salvaAllegati($r->files, $custom);

        $jsonCustom = !empty($custom) ? (string) json_encode($custom, JSON_UNESCAPED_UNICODE) : null;

        // Sezione critica: transazione + blocco pessimistico anti-overbooking. Il conteggio FOR UPDATE tiene in coda
        // le richieste concorrenti sullo stesso turno finché questa transazione non fa commit o rollback,
        // così il conteggio posti letto qui dentro è sempre quello reale, mai «vecchio».
        try {
            [$nuovoId, $stato] = $this->db->transazione(function () use ($turnoId, $t, $r, $conv, $codice, $jsonCustom): array {
                $occupati = $this->prenotazioni->postiOccupati($turnoId, true);

                // Anche «da_approvare» occupa un posto: prima si verifica la capienza
                $stato = ((isset($t['richiede_approvazione']) && $t['richiede_approvazione'] == 1) || $conv === 'no') ? 'da_approvare' : 'confermata';
                if (($occupati + $r->numPosti) > $t['max_posti']) {
                    if (isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa'] == 1) {
                        $stato = 'in_attesa';
                    } else {
                        throw new PostiEsauriti();
                    }
                }

                $id = $this->prenotazioni->inserisci($turnoId, $r->utenteId, $codice, $stato, $r->numPosti, $r->nome, $r->cognome, $r->email, $r->matricola, $jsonCustom);
                if ($id === 0) {
                    throw new \RuntimeException($this->db->ultimoErrore() ?: 'Errore sconosciuto in fase di inserimento prenotazione');
                }

                return [$id, $stato];
            });
        } catch (PostiEsauriti) {
            $this->rilascia($blocco);

            return new EsitoPrenotazione("{$ritorno}status=full");
        } catch (Throwable $e) {
            error_log("[Prenotazione][turno_id=$turnoId] Transazione fallita: " . $e->getMessage());
            $this->rilascia($blocco);

            return new EsitoPrenotazione("{$ritorno}status=error");
        }

        // Posto confermato: le altre liste d'attesa della persona nell'ambito del limite decadono
        if ($stato === 'confermata') {
            $this->vincoli->decadiAttese($nuovoId);
        }
        $this->rilascia($blocco);
        if ($conv !== null) {
            $this->prenotazioni->impostaConvenzione($nuovoId, $conv);
        }
        // Scuola dall'anagrafe: codice sulla prenotazione (report) e sul profilo del docente (proposta la prossima volta)
        if ($scuolaCodice) {
            $this->prenotazioni->impostaScuolaPrenotazione($nuovoId, $scuolaCodice);
            if ($r->utenteId) {
                $this->prenotazioni->impostaScuolaUtente($r->utenteId, $scuolaCodice);
            }
        }

        // Da qui in poi: email e notifiche, FUORI dalla transazione (non tengono bloccata la riga).
        $this->inviaEmail($r, $t, $dett, $codice, $nuovoId, $stato, $conv, $convRinnovo);

        $paramStato = ($stato === 'in_attesa') ? '&st_tipo=attesa' : (($stato === 'da_approvare') ? ($conv === 'no' ? '&st_tipo=convenzione' : '&st_tipo=approvare') : '');
        if ($conv === 'no' && $stato === 'in_attesa') {
            $paramStato .= '&conv=no';
        }
        if ($convRinnovo) {
            $paramStato .= '&conv_rinnovo=1';
        }

        return new EsitoPrenotazione("{$ritorno}status=success&code=" . urlencode($codice) . $paramStato);
    }

    private function rilascia(?string $blocco): void
    {
        if ($blocco !== null) {
            $this->prenotazioni->rilasciaBlocco($blocco);
        }
    }

    /**
     * Salva gli allegati dei campi «file» del modulo (PDF, immagini, Word) e li aggiunge ai dati del modulo come elenco di percorsi.
     *
     * @param array<string, mixed> $files
     * @param array<string, mixed> $custom
     */
    private function salvaAllegati(array $files, array &$custom): void
    {
        $cartella = $this->sito->radice() . '/uploads/allegati_prenotazioni/';
        foreach ($files as $k => $f) {
            if (strpos((string) $k, 'custom_') !== 0) {
                continue;
            }
            $campo = str_replace('custom_', '', (string) $k);
            $percorsi = [];
            $n = is_array($f['name']) ? count($f['name']) : 1;
            for ($i = 0; $i < $n; $i++) {
                $file = [
                    'tmp_name' => is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'],
                    'name' => is_array($f['name']) ? $f['name'][$i] : $f['name'],
                    'error' => is_array($f['error']) ? $f['error'][$i] : $f['error'],
                ];
                $nome = $this->upload->salva($file, $cartella, self::ESTENSIONI_ALLEGATI, self::MIME_ALLEGATI);
                if ($nome !== null) {
                    $percorsi[] = 'uploads/allegati_prenotazioni/' . $nome;
                }
            }
            if (!empty($percorsi)) {
                $custom[$campo] = implode(', ', $percorsi);
            }
        }
    }

    /**
     * Email a chi ha prenotato (conferma, richiesta in valutazione, lista d'attesa o convenzione da stipulare) e riepilogo ai gestori.
     *
     * @param array<string, mixed> $t
     * @param array<string, mixed>|null $dett
     */
    private function inviaEmail(RichiestaPrenotazione $r, array $t, ?array $dett, string $codice, int $nuovoId, string $stato, ?string $conv, bool $convRinnovo): void
    {
        $turnoId = $r->turnoId;
        $numPosti = $r->numPosti;
        $dataFormattata = implode(' · ', array_filter([$t['nome_turno'] ?? '', !empty($t['data_turno']) ? date('d/m/Y', strtotime($t['data_turno'])) : '']));
        $oraFormattata = Turni::orario($t) ?: 'da definire';
        $linkRicevuta = $r->baseLink . '/stampa_ricevuta.php?code=' . urlencode($codice);
        $btnRicevuta = "<p style='margin-top:15px;'><a href='$linkRicevuta' target='_blank' style='background:#B30000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>";
        $gcal = Turni::urlGoogleCalendar($t['evento_titolo'], $t['data_turno'], $t['orario_inizio'], $t['orario_fine'], $t['luogo'], "Prenotazione $codice ($numPosti posti)");
        $icsLink = $r->baseLink . '/genera_ics.php?t_id=' . $t['id'];
        $bottoniCalendario = empty($t['data_turno']) ? '' : "<p style='margin-top:15px;'><a href='$gcal' target='_blank' style='background:#4285F4; color:#fff; padding:8px 14px; text-decoration:none; border-radius:4px; font-weight:bold;'>📅 Aggiungi a Google Calendar</a> <a href='$icsLink' style='background:#334155; color:#fff; padding:8px 14px; text-decoration:none; border-radius:4px; font-weight:bold;'>📥 Scarica File .ics</a></p>";
        $cerca = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}', '{LINK_RICEVUTA}'];
        $sostituisci = [$r->nome, $r->cognome, $r->matricola, $t['evento_titolo'], $dataFormattata, $oraFormattata, $t['luogo'], $codice, $btnRicevuta];
        $sistema = $this->impostazioni->riga() ?? [];

        if ($stato === 'da_approvare' && $conv === 'no') {
            $oggetto = 'Prenotazione in attesa della convenzione: ' . $t['evento_titolo'];
            $corpo = '<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>abbiamo ricevuto la prenotazione per <strong>{TITOLO_EVENTO}</strong>.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>'
                . ($convRinnovo ? "<p style='margin:0 0 8px;'><strong>La convenzione della scuola registrata al Dipartimento non copre tutto il periodo dell'attività: va stipulata una nuova convenzione.</strong></p>" : '') . $this->fsl->istruzioniConvenzione($r->pagina, true, $codice) . '{LINK_RICEVUTA}';
        } elseif ($stato === 'da_approvare') {
            $oggetto = 'Richiesta Ricevuta (In valutazione): ' . $t['evento_titolo'];
            $corpo = "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>La tua richiesta per <strong>$numPosti posti</strong> all'evento <strong>{TITOLO_EVENTO}</strong> è in fase di valutazione.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>{LINK_RICEVUTA}";
        } elseif ($stato === 'in_attesa') {
            $oggetto = "Lista d'Attesa: " . $t['evento_titolo'];
            $corpo = "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Sei stato inserito in <strong>lista d'attesa</strong> per l'evento <strong>{TITOLO_EVENTO}</strong>.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>{LINK_RICEVUTA}" . $bottoniCalendario;
            // Senza convenzione: meglio avviarla subito, così se il posto si libera la prenotazione è confermabile
            if ($conv === 'no') {
                $corpo .= "<p style='margin-top:16px;'><strong>Convenzione:</strong> la scuola non l'ha ancora stipulata. Ti consigliamo di avviarla già ora.</p>" . $this->fsl->istruzioniConvenzione($r->pagina, true, $codice);
            }
        } else {
            $oggetto = $sistema['email_conferma_oggetto'] ?: 'Conferma Prenotazione Eventi';
            $corpo = ($sistema['email_conferma_corpo'] ?: "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Prenotazione confermata per <strong>{TITOLO_EVENTO}</strong>.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>{LINK_RICEVUTA}") . $bottoniCalendario;
        }

        // Attività FSL: oltre alla convenzione, l'elenco degli studenti e l'autorizzazione della scuola vanno caricati prima dell'inizio
        $corpo .= $this->fsl->avvisoDocumentiClasse($dett, ($t['evento_tipo'] ?? '') === 'progetto', $codice, $t['data_turno'] ?? null);

        // Attestati per la classe: chi ha prenotato inserisce l'elenco degli studenti dall'Area personale
        if ($stato === 'confermata' && $this->fsl->attestatiDiClasse(['evento_tipo' => $t['evento_tipo'] ?? '', 'attestati' => $dett['attestati'] ?? 0, 'per_scuole' => $dett['per_scuole'] ?? 1])) {
            $linkElenco = $this->sito->urlBase() . '/elenco_studenti.php?code=' . urlencode($codice);
            $corpo .= "<p style='margin-top:18px;'>Per gli <strong>attestati di partecipazione degli studenti</strong> inserisci il loro elenco (cognome e nome) dalla tua Area personale, accedendo con SPID, CIE o credenziali Unical con questo stesso indirizzo email.</p>"
                    . "<p><a href='" . htmlspecialchars($linkElenco) . "' style='background:#198754; color:#fff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>Inserisci l'elenco degli studenti</a></p>";
        }
        $colore = $this->colori->delTurno($turnoId);
        // Programma FSL: una sola email riepilogativa per tutte le attività, mandata da chi le ha raccolte
        if (!$r->senzaEmailPersona) {
            $this->mailer->invia($r->email, str_replace($cerca, $sostituisci, (string) $oggetto), str_replace($cerca, $sostituisci, $corpo), $colore);
        }

        // Gestori dell'area/evento con notifiche attive + indirizzi aggiuntivi dell'evento:
        // ognuno riceve la propria email con il riepilogo completo (campi aggiuntivi compresi)
        $destinatari = $this->notifiche->destinatari((int) $t['evento_id']);
        $riepilogo = $destinatari ? $this->notifiche->riepilogo($nuovoId) : null;
        if ($riepilogo) {
            $oggettoGestori = "Nuova prenotazione ($numPosti " . ($numPosti === 1 ? 'posto' : 'posti') . '): ' . $t['evento_titolo'];
            $intro = "<p>È stata registrata una nuova prenotazione per l'evento <strong>" . htmlspecialchars((string) $t['evento_titolo']) . '</strong>.</p>';
            $gestori = $this->abilitazioni->emailGestoriEvento((int) $t['evento_id']);
            foreach ($destinatari as $emailGestore) {
                $this->mailer->invia($emailGestore, $oggettoGestori, NotifichePrenotazione::corpoPer($emailGestore, $intro, $riepilogo, $gestori), $colore);
            }
        }
    }
}
