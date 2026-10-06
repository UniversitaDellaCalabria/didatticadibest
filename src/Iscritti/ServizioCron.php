<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\SincronizzazioneAnagrafe;
use App\Anagrafi\Testi;
use App\Auth\Abilitazioni\IdsGestori;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Auth\ServizioUtenti;
use App\Core\Orologio;
use App\Core\Sito;
use App\Eventi\Turni;
use App\Infrastructure\Mail\Mailer;
use App\Portale\ColoriAree;
use App\Sistema\ControlloSito;
use App\Sistema\ImpostazioniSistemaRepository;
use App\Sistema\ReportEmailSettimanale;

/**
 * Compiti pianificati di cron_background.php (motore delle automazioni): ogni metodo esegue un compito e ritorna la riga di
 * resoconto da stampare (stesso testo di prima). L'ordine dei compiti lo decide lo script.
 */
final class ServizioCron
{
    public function __construct(
        private CronRepository $cron,
        private ImpostazioniSistemaRepository $impostazioni,
        private Mailer $mailer,
        private ColoriAree $colori,
        private Sito $sito,
        private Orologio $orologio,
        private Convenzioni $convenzioni,
        private Attestati $attestati,
        private Pianificati $pianificati,
        private ServizioAbilitazioni $abilitazioni,
        private ServizioUtenti $utenti,
        private ServizioScuole $scuole,
        private ReportEmailSettimanale $report,
        private ControlloSito $controllo,
        private SincronizzazioneAnagrafe $anagrafe,
    ) {
    }

    // ------------------------------------------------------------------ eventi e dopo l'evento

    public function autoArchiviazione(): string
    {
        return '- Auto-archiviati ' . $this->cron->archiviaScaduti() . " eventi scaduti.\n";
    }

    /**
     * Email dopo l'evento ai presenti: avviso dell'attestato (se previsto) e sondaggio (se configurato).
     *
     * @param string $adesso data e ora corrente (Y-m-d H:i:s)
     * @param string $urlBase indirizzo del portale ricavato dalla richiesta in corso, per il link all'Area Personale
     */
    public function emailPostEvento(string $adesso, string $urlBase): string
    {
        $righe = $this->cron->daEmailPostEvento($adesso);
        $n = 0;
        if ($righe) {
            $sys = $this->impostazioni->riga() ?? [];
            $linkArea = "<a href='$urlBase/area_personale.php' style='color:#B30000; font-weight:bold;'>Area Personale</a>";
            foreach ($righe as $p) {
                $trova = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{LINK_AREA_PERSONALE}'];
                $ora = Turni::orario($p) ?: 'da definire';
                $data = implode(' · ', array_filter([$p['nome_turno'] ?? '', !empty($p['data_turno']) ? date('d/m/Y', (int) strtotime((string) $p['data_turno'])) : '']));
                $sostituisci = array_map('strval', [$p['nome'], $p['cognome'], $p['matricola'], $p['evento_titolo'], $data, $ora, $p['luogo'], $linkArea]);
                $colore = $this->colori->delTurno((int) $p['turno_id']);

                // Avviso dell'attestato (se configurato e se l'attestato personale esiste: non nei progetti senza attestati né in
                // quelli per le scuole, dove li riceve il docente per la classe)
                $regola = $this->attestati->regolaEvento((int) $p['evento_id']);
                if (!empty($sys['email_attestato_corpo']) && in_array($regola, ['evento', 'singolo'], true)) {
                    $this->mailer->invia((string) $p['email'], str_replace($trova, $sostituisci, (string) ($sys['email_attestato_oggetto'] ?? '')), str_replace($trova, $sostituisci, (string) $sys['email_attestato_corpo']), $colore);
                }
                // Sondaggio (se configurato)
                if (!empty($sys['email_sondaggio_corpo'])) {
                    $this->mailer->invia((string) $p['email'], str_replace($trova, $sostituisci, (string) ($sys['email_sondaggio_oggetto'] ?? '')), str_replace($trova, $sostituisci, (string) $sys['email_sondaggio_corpo']), $colore);
                }
                // Segna come inviata per non ripetere l'invio al prossimo giro
                $this->cron->segnaPostEventoInviata((int) $p['id']);
                ++$n;
            }
        }

        return "- Inviate $n email post-evento (Attestati/Sondaggi).\n";
    }

    /** Promemoria al docente di una classe con attestati per inserire l'elenco degli studenti (una sola volta). */
    public function promemoriaElenco(): string
    {
        $n = 0;
        foreach ($this->cron->daPromemoriaElenco() as $pm) {
            if (empty($pm['email'])) {
                continue;
            }
            $link = $this->sito->urlBase() . '/elenco_studenti.php?code=' . urlencode((string) $pm['codice_prenotazione']);
            $corpo = '<p>Gentile <strong>' . htmlspecialchars($pm['nome'] . ' ' . $pm['cognome']) . '</strong>,</p>'
                . ($pm['tipo'] === 'progetto'
                    ? '<p>il progetto <strong>' . htmlspecialchars((string) $pm['titolo']) . '</strong> si conclude il <strong>' . date('d/m/Y', (int) strtotime((string) $pm['data_fine'])) . '</strong>.</p>'
                    : "<p>l'attività <strong>" . htmlspecialchars((string) $pm['titolo']) . '</strong> ' . ($pm['data_turno'] < date('Y-m-d') ? 'si è svolta' : 'si svolge') . ' il <strong>' . date('d/m/Y', (int) strtotime((string) $pm['data_turno'])) . '</strong>.</p>')
                . '<p>Per ricevere gli <strong>attestati di partecipazione</strong> dei tuoi studenti inserisci il loro elenco (cognome e nome): puoi scriverlo, incollarlo da Excel o caricare il modello compilato.</p>'
                . "<p style='text-align:center; margin:28px 0;'><a href='" . htmlspecialchars($link) . "' style='background-color:#198754; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold;'>Inserisci l'elenco degli studenti</a></p>";
            $this->mailer->invia((string) $pm['email'], 'Promemoria: elenco degli studenti per gli attestati - ' . $pm['titolo'], $corpo, $this->colori->delTurno((int) $pm['turno_id']));
            $this->cron->segnaPromemoriaElencoInviato((int) $pm['id']);
            ++$n;
        }

        return "- Inviati $n promemoria per l'elenco degli studenti.\n";
    }

    /** Attestati degli studenti ad attività conclusa (se il cron degli attestati non è pianificato a parte). */
    public function attestatiClassi(): string
    {
        $n = 0;
        foreach ($this->cron->classiDaAttestare() as $id) {
            if ($this->attestati->inviaGruppo($id) === true) {
                ++$n;
            }
        }

        return "- Inviati attestati degli studenti per $n iscrizioni.\n";
    }

    // ------------------------------------------------------------------ convenzioni con le scuole

    /** Verifica delle iscrizioni alle attività FSL (anche confermate) con il registro delle convenzioni. */
    public function convenzioniVerifica(): string
    {
        $v = $this->convenzioni->verificaFsl();

        return "- Convenzioni FSL: {$v['coperte']} iscrizioni coperte, {$v['da_stipulare']} da stipulare ({$v['nuove_da_stipulare']} nuove), {$v['senza_codice']} con la scuola scritta a mano.\n";
    }

    /** Promemoria alla scuola ogni 7 giorni (massimo 3) finché la convenzione non arriva, fino alla fine dell'attività. */
    public function convenzioniPromemoria(): string
    {
        $promemoria = 0;
        $dalRegistro = 0;
        foreach ($this->cron->convenzioniDaSollecitare() as $x) {
            // Nel frattempo registrata nell'anagrafe: niente promemoria, la prenotazione si aggiorna
            [$dal, $al] = $this->convenzioni->periodoPrenotazione($x);
            if (!empty($x['scuola_codice']) && $this->convenzioni->convenzioneValida($x['scuola_codice'], false, $dal, $al)) {
                $this->convenzioni->segnaRicevuta((int) $x['id']);
                ++$dalRegistro;
                continue;
            }
            $this->convenzioni->richiedi((int) $x['id'], 'promemoria');
            $this->cron->contaPromemoriaConvenzione((int) $x['id']);
            ++$promemoria;
        }

        return "- Convenzioni: $promemoria promemoria alle scuole, $dalRegistro prenotazioni aggiornate dal registro.\n";
    }

    /** Avviso ai gestori (una volta) quando l'attività inizia entro 7 giorni e ci sono scuole ancora senza convenzione. */
    public function convenzioniAvvisoGestori(): string
    {
        $perEvento = [];
        foreach ($this->cron->convenzioniMancantiInPartenza() as $x) {
            $perEvento[(int) $x['evento_id']][] = $x;
        }
        foreach ($perEvento as $eventoId => $righe) {
            $destinatari = $this->abilitazioni->emailGestoriEvento($eventoId) ?: $this->utenti->emailAmministratori();
            $tabella = '';
            foreach ($righe as $x) {
                $s = !empty($x['scuola_codice']) ? $this->scuole->perCodice($x['scuola_codice']) : null;
                $scuola = $s ? Testi::etichettaScuola($s) : ($this->nomeScuola($x) ?: '—');
                $tabella .= "<tr><td style='padding:5px 8px;border-bottom:1px solid #e5e7eb;'>" . self::h($scuola) . "</td><td style='padding:5px 8px;border-bottom:1px solid #e5e7eb;'>" . self::h(trim($x['nome'] . ' ' . $x['cognome'])) . "<br><span style='color:#6b7280;'>" . self::h($x['email']) . '</span></td>'
                    . "<td style='padding:5px 8px;border-bottom:1px solid #e5e7eb;'>" . self::h(Turni::etichetta($x)) . "</td><td style='padding:5px 8px;border-bottom:1px solid #e5e7eb;font-family:monospace;'>" . self::h($x['codice_prenotazione']) . '</td></tr>';
            }
            $link = $this->sito->urlBase() . '/admin/iscritti.php?p_id=' . (int) $righe[0]['pagina_id'];
            $corpo = "<p>L'attività <strong>" . self::h($righe[0]['titolo']) . '</strong> inizia il <strong>' . date('d/m/Y', (int) strtotime((string) $righe[0]['inizio'])) . '</strong> e queste scuole non hanno ancora inviato la <strong>convenzione</strong>:</p>'
                . "<table style='border-collapse:collapse;font-size:13px;width:100%;'><tr style='background:#f3f4f6;'><th style='padding:5px 8px;text-align:left;'>Scuola</th><th style='padding:5px 8px;text-align:left;'>Docente</th><th style='padding:5px 8px;text-align:left;'>Turno</th><th style='padding:5px 8px;text-align:left;'>Codice</th></tr>$tabella</table>"
                . '<p>Quando arriva la convenzione segnala come ricevuta da Iscrizioni: la prenotazione si conferma e la scuola riceve l\'email.</p>'
                . "<p><a href='" . self::h($link) . "' style='background:#c2410c;color:#fff;padding:9px 16px;text-decoration:none;border-radius:6px;font-weight:bold;'>Apri Iscrizioni</a></p>";
            foreach ($destinatari as $email) {
                $this->mailer->invia($email, 'Convenzioni mancanti: ' . $righe[0]['titolo'], $corpo);
            }
            $this->cron->segnaAvvisoGestori(array_map(static fn (array $x): int => (int) $x['id'], $righe));
        }

        return '- Convenzioni: avvisati i gestori di ' . count($perEvento) . " attività in partenza con scuole senza convenzione.\n";
    }

    /** Avviso agli amministratori (una volta) per le convenzioni del registro che scadono entro 60 giorni. */
    public function convenzioniInScadenza(): string
    {
        $righe = $this->cron->convenzioniInScadenza();
        if ($righe) {
            $lista = '';
            foreach ($righe as $x) {
                $s = $this->scuole->perCodice($x['scuola_codice']);
                $lista .= '<li><strong>' . self::h($s ? Testi::etichettaScuola($s) : $x['scuola_codice']) . '</strong> (' . self::h($x['scuola_codice']) . '): scade il <strong>' . date('d/m/Y', (int) strtotime((string) $x['scadenza'])) . '</strong>'
                    . ($x['protocollo'] !== '' ? ' · prot. ' . self::h($x['protocollo']) : '') . '</li>';
            }
            $corpo = "<p>Queste convenzioni con le scuole scadono nei prossimi 60 giorni:</p><ul>$lista</ul>"
                . "<p>Dopo la scadenza, alle nuove iscrizioni di queste scuole verrà chiesta di nuovo la convenzione. Il rinnovo si registra in <a href='" . self::h($this->sito->urlBase() . '/admin/fsl.php?tab=convenzioni') . "'>Formazione Scuola Lavoro → Convenzioni</a>.</p>";
            foreach ($this->utenti->emailAmministratori() as $email) {
                $this->mailer->invia($email, 'Convenzioni con le scuole in scadenza (' . count($righe) . ')', $corpo);
            }
            $this->cron->segnaAvvisoScadenza(array_map(static fn (array $x): int => (int) $x['id'], $righe));
        }

        return '- Convenzioni: ' . count($righe) . " in scadenza segnalate agli amministratori.\n";
    }

    /** Scheda di valutazione della struttura ospitante (FSL): invito a attività concluse da non più di 30 giorni e un solo promemoria dopo 7. */
    public function valutazioniFsl(): string
    {
        $inviti = 0;
        foreach ($this->cron->daInvitoValutazione() as $id) {
            if ($this->convenzioni->invitoValutazione($id)) {
                ++$inviti;
            }
        }
        $promemoria = 0;
        foreach ($this->cron->daPromemoriaValutazione() as $id) {
            if ($this->convenzioni->invitoValutazione($id, true)) {
                ++$promemoria;
            }
        }

        return "- Schede di valutazione FSL: $inviti inviti, $promemoria promemoria.\n";
    }

    // ------------------------------------------------------------------ conservazione dei dati (privacy)

    /**
     * Elenchi degli studenti: cancellati quelli delle iscrizioni annullate (senza attestati emessi), ridotti alle iniziali dopo $mesi mesi.
     */
    public function conservazioneStudenti(int $mesi): string
    {
        $cancellati = $this->cron->cancellaElenchiAnnullati();
        $ridotti = $this->cron->anonimizzaStudenti($mesi);

        return "- Elenchi studenti: $cancellati nomi cancellati (iscrizioni annullate), $ridotti ridotti alle iniziali dopo $mesi mesi.\n";
    }

    /** Registri tecnici: accessi ed email oltre $mesiLog mesi, registro delle azioni oltre $mesiAudit mesi (art. 5.1.e GDPR). */
    public function conservazioneRegistri(int $mesiLog, int $mesiAudit): string
    {
        $accessi = $this->cron->eliminaAccessiVecchi($mesiLog);
        $email = $this->cron->eliminaEmailVecchie($mesiLog);
        $azioni = $this->cron->eliminaAzioniVecchie($mesiAudit);

        return "- Conservazione registri: eliminati $accessi accessi e $email email più vecchi di $mesiLog mesi, $azioni azioni più vecchie di $mesiAudit mesi.\n";
    }

    /**
     * Prenotazioni concluse da più di $mesi mesi: nome e cognome alle iniziali, email, matricola e dati del modulo svuotati, messaggi
     * e allegati eliminati. Restano codice, turno, stato e presenza.
     */
    public function conservazionePrenotazioni(int $mesi): string
    {
        $n = 0;
        foreach ($this->cron->daAnonimizzare($mesi) as $p) {
            // Allegati caricati nel modulo (solo file dentro uploads/allegati_prenotazioni)
            foreach ((json_decode((string) $p['dati_custom_json'], true) ?: []) as $val) {
                if (!is_string($val)) {
                    continue;
                }
                foreach (array_map('trim', explode(',', $val)) as $percorso) {
                    if (preg_match('#^uploads/allegati_prenotazioni/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $percorso)) {
                        @unlink($this->sito->radice() . '/' . $percorso);
                    }
                }
            }
            $this->cron->anonimizza((int) $p['id']);
            ++$n;
        }

        return "- Conservazione prenotazioni: $n anonimizzate (attività concluse da più di $mesi mesi).\n";
    }

    /** Tutorato: lettere di incarico protocollate o annullate senza dati personali ('' se il modulo non c'è). */
    public function conservazioneTutorato(int $mesi): string
    {
        $n = $this->pianificati->conservaTutorato($mesi);

        return $n === null ? '' : "- Conservazione tutorato: $n lettere di incarico senza dati personali (più vecchie di $mesi mesi).\n";
    }

    /** Sedute: convocazioni cancellate dopo $mesi mesi ('' se il modulo non c'è). */
    public function conservazioneSedute(int $mesi): string
    {
        $n = $this->pianificati->conservaSedute($mesi);

        return $n === null ? '' : "- Conservazione sedute: $n convocazioni cancellate (sedute di più di $mesi mesi fa).\n";
    }

    /**
     * Account senza accesso da $mesi mesi, esclusi amministratori e gestori (di area, di evento o con un perimetro): le prenotazioni
     * restano, senza il legame con l'account.
     */
    public function conservazioneAccount(int $mesi): string
    {
        $esclusi = [];
        foreach ([...$this->cron->gestoriDelleAree(), ...$this->cron->gestoriDegliEventi()] as $g) {
            foreach (IdsGestori::daCampi($g['singolo'], $g['gestori_utenti_ids'], $g['permessi_gestori_json']) as $id) {
                $esclusi[$id] = true;
            }
        }
        foreach ($this->cron->utentiConPerimetro() as $id) {
            $esclusi[$id] = true;
        }

        return '- Conservazione account: ' . $this->cron->eliminaUtentiInattivi($mesi, array_keys($esclusi)) . " account eliminati (nessun accesso da $mesi mesi).\n";
    }

    // ------------------------------------------------------------------ riepilogo, controllo del portale, anagrafe

    /** Riepilogo settimanale delle email agli amministratori. */
    public function riepilogoSettimanale(): string
    {
        $esito = $this->report->invia();

        return '- Riepilogo settimanale email: ' . ($esito === true ? 'inviato agli amministratori' : $esito) . ".\n";
    }

    /** Controllo automatico del portale, una volta al giorno dalle 3 di notte ('' se non è il momento). */
    public function controlloPortale(): string
    {
        $adesso = $this->orologio->adesso();
        $file = $this->sito->radice() . '/cache/controllo_sito.json';
        if ((int) $adesso->format('G') >= 3 && (!is_file($file) || date('Y-m-d', (int) filemtime($file)) !== $adesso->format('Y-m-d'))) {
            $stato = $this->controllo->esegui();

            return '- Controllo del portale: ' . ($stato['problemi'] ? $stato['problemi'] . ' problemi (amministratori avvisati)' : 'tutto a posto') . ".\n";
        }

        return '';
    }

    /** Anagrafe del personale e corsi di studio dalle API di Ateneo, una volta a settimana ('' se non è il momento). */
    public function anagrafeAteneo(): string
    {
        $file = $this->sito->radice() . '/cache/anagrafe_sync.txt';
        if (!is_file($file) || filemtime($file) < time() - 7 * 86400) {
            $esiti = [];
            foreach ($this->anagrafe->sincronizza() as $codice => $es) {
                $esiti[] = "$codice: " . $es['esito'];
            }

            return '- Anagrafe di Ateneo aggiornata (' . ($esiti ? implode('; ', $esiti) : 'nessuna struttura') . ").\n";
        }

        return '';
    }

    /** @param array<string, string|null> $prenotazione */
    private function nomeScuola(array $prenotazione): string
    {
        return !empty($prenotazione['scuola_codice']) ? $this->scuole->nomeDaPrenotazione($prenotazione) : ServizioScuole::nomeScrittoNelModulo($prenotazione);
    }

    private static function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
