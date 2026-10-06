<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Anagrafi\ServizioScuole;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Core\Sito;
use App\Eventi\EventoRepository;
use App\Eventi\Turni;
use App\Infrastructure\Audit\AuditLog;
use App\Infrastructure\Mail\Mailer;
use App\Portale\ColoriAree;

/**
 * Azioni del pannello Iscritti sulle prenotazioni: presenza, approvazione, rifiuto, annullamento, eliminazione, azioni di massa,
 * convenzioni, prenotazione manuale e modifica. Le email, i testi e l'ordine delle operazioni sono quelli di admin/iscritti.php.
 */
final class ServizioIscritti
{
    /** Azioni di massa ammesse e come compaiono nel messaggio finale. */
    public const AZIONI_DI_MASSA = [
        'presente' => 'segnate presenti',
        'assente' => 'segnate assenti',
        'approva' => 'approvate',
        'promuovi' => "promosse dalla lista d'attesa",
        'annulla' => 'annullate',
        'chiedi_conv' => 'con la richiesta della convenzione inviata',
        'conv_ricevuta' => 'con la convenzione segnata come ricevuta',
    ];

    private const STATI_ATTIVI = ['confermata', 'in_attesa', 'da_approvare', 'richiesta_conferma'];
    private const BOTTONE_RICEVUTA = "<p style='margin-top:15px;'><a href='%s' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica Ricevuta PDF</a></p>";

    public function __construct(
        private IscrittiRepository $iscritti,
        private Iscrizioni $iscrizioni,
        private Convenzioni $convenzioni,
        private Attestati $attestati,
        private Mailer $mailer,
        private ColoriAree $colori,
        private Sito $sito,
        private ServizioAbilitazioni $abilitazioni,
        private ServizioScuole $scuole,
        private EventoRepository $eventi,
        private AuditLog $audit,
    ) {
    }

    /**
     * I campi del modulo (custom_*) di un POST come nome => valore: gli elenchi diventano testo separato da virgole.
     *
     * @param array<array-key, mixed> $post
     * @return array<string, string>
     */
    public static function campiDaPost(array $post): array
    {
        $dati = [];
        foreach ($post as $k => $v) {
            if (strpos((string) $k, 'custom_') === 0) {
                $dati[substr((string) $k, 7)] = is_array($v) ? implode(', ', $v) : trim((string) $v);
            }
        }

        return $dati;
    }

    // ------------------------------------------------------------------ presenza

    /** Segna o toglie la presenza; se è presente parte l'email con l'attestato (se l'attività è conclusa). */
    public function segnaPresenza(int $prenotazioneId, int $valore, Operatore $op): void
    {
        $this->iscritti->impostaPresenza($prenotazioneId, $valore);
        if ($valore === 1) {
            $this->attestati->inviaSeConcluso($prenotazioneId);
        }
        $this->audit->registra($op->id, 'Modifica Presenza Check-in', ['ID Prenotazione' => $prenotazioneId], $op->ip);
    }

    // ------------------------------------------------------------------ azioni di massa

    /**
     * Applica un'azione alle prenotazioni selezionate (dell'area e visibili al gestore) con stato compatibile.
     *
     * @param list<int> $ids
     */
    public function azioneDiMassa(string $azione, array $ids, int $paginaId, string $rbac, Operatore $op): RisultatoMassivo
    {
        $fatte = 0;
        $saltate = 0;
        $oltreCapienza = 0;
        $turniLiberati = [];
        foreach ($ids as $prId) {
            if (!$this->abilitazioni->prenotazioneAutorizzata($prId, $paginaId, $rbac)) {
                ++$saltate;
                continue;
            }
            $p = $this->iscritti->prenotazioneConTurnoEvento($prId) ?? [];
            $st = (string) ($p['stato'] ?? '');
            $ok = false;
            switch ($azione) {
                case 'presente':
                    if ($st === 'confermata') {
                        if ($this->iscritti->segnaPresente($prId) > 0) {
                            $this->attestati->inviaSeConcluso($prId);
                        }
                        $ok = true;
                    }
                    break;
                case 'assente':
                    $this->iscritti->impostaPresenza($prId, 0);
                    $ok = true;
                    break;
                case 'chiedi_conv':
                    if (in_array($st, self::STATI_ATTIVI, true) && ($p['convenzione'] ?? '') !== 'ricevuta'
                        && $this->convenzioni->richiedi($prId)) {
                        $this->iscritti->convenzioneRichiesta($prId, false);
                        $ok = true;
                    }
                    break;
                case 'conv_ricevuta':
                    if (in_array($st, self::STATI_ATTIVI, true) && ($p['convenzione'] ?? '') === 'no') {
                        $ok = $this->convenzioni->ricevutaDaGestore($prId, $op->email);
                    }
                    break;
                case 'approva':
                    // In attesa della convenzione: approvare = convenzione ricevuta (registrata per la scuola)
                    if ($st === 'da_approvare' && ($p['convenzione'] ?? '') === 'no') {
                        $this->convenzioni->ricevutaDaGestore($prId, $op->email);
                        $st = $this->iscritti->stato($prId);
                        if ($st === 'confermata') {
                            $ok = true;
                            break;
                        }
                    }
                    // no break: se è ancora da approvare si conferma come una promozione
                case 'promuovi':
                    $da = $azione === 'approva' ? 'da_approvare' : 'in_attesa';
                    if ($st === $da && $this->iscritti->confermaDaStato($prId, $da) > 0) {
                        $this->iscrizioni->decadiAtteseVincolate($prId);
                        $this->emailConfermaDaAdmin($p, $azione === 'approva' ? 'approvata' : 'promossa');
                        $turno = $this->iscritti->turnoAdmin((int) ($p['turno_id'] ?? 0));
                        if ($turno && $this->iscrizioni->postiOccupati((int) $p['turno_id']) > (int) $turno['max_posti']) {
                            ++$oltreCapienza;
                        }
                        $ok = true;
                    }
                    break;
                case 'annulla':
                    if (in_array($st, self::STATI_ATTIVI, true)) {
                        $this->iscritti->annulla($prId);
                        if (!empty($p['email'])) {
                            $this->mailer->invia(
                                (string) $p['email'],
                                'Prenotazione Annullata: ' . $p['evento_titolo'],
                                'La tua prenotazione per <strong>' . htmlspecialchars((string) $p['evento_titolo']) . "</strong> è stata annullata dall'amministrazione.",
                                $this->colori->delTurno((int) $p['turno_id'])
                            );
                        }
                        if ($st !== 'in_attesa') {
                            $turniLiberati[(int) $p['turno_id']] = true;
                        }
                        $ok = true;
                    }
                    break;
            }
            $ok ? ++$fatte : ++$saltate;
        }
        // Posti liberati dagli annullamenti: una sola promozione per turno, a fine elaborazione
        foreach (array_keys($turniLiberati) as $turnoId) {
            $this->iscrizioni->promuoviListaAttesa($turnoId);
        }
        $this->audit->registra($op->id, 'Azione di massa iscritti', ['Azione' => $azione, 'Eseguite' => $fatte, 'Saltate' => $saltate], $op->ip);

        return new RisultatoMassivo($azione, $fatte, $saltate, $oltreCapienza);
    }

    /**
     * Email «posto confermato» con la ricevuta, per l'approvazione o la promozione fatta da un amministratore.
     *
     * @param array<string, string|null> $p prenotazione con turno ed evento
     */
    private function emailConfermaDaAdmin(array $p, string $motivo): void
    {
        if (empty($p['email'])) {
            return;
        }
        $link = $this->sito->urlBase() . '/stampa_ricevuta.php?code=' . urlencode((string) $p['codice_prenotazione']);
        $btn = sprintf(self::BOTTONE_RICEVUTA, $link);
        $titolo = htmlspecialchars((string) $p['evento_titolo']);
        $turno = htmlspecialchars(Turni::etichetta($p));
        $testo = $motivo === 'promossa'
            ? "Si è liberato un posto: la tua prenotazione in lista d'attesa per <strong>$titolo</strong> ($turno) è stata <strong>CONFERMATA</strong>."
            : "La tua richiesta per l'evento <strong>$titolo</strong> ($turno) è stata <strong>APPROVATA</strong>!";
        $oggetto = ($motivo === 'promossa' ? 'Posto Confermato: ' : 'Prenotazione Approvata: ') . $p['evento_titolo'];
        $this->mailer->invia((string) $p['email'], $oggetto, "<p>$testo</p>$btn", $this->colori->delTurno((int) $p['turno_id']));
    }

    // ------------------------------------------------------------------ convenzioni

    /** Un gestore segna la convenzione come ricevuta (registrata per la scuola dell'anagrafe). */
    public function convenzioneRicevuta(int $prenotazioneId, Operatore $op): void
    {
        $this->convenzioni->ricevutaDaGestore($prenotazioneId, $op->email);
        $this->audit->registra($op->id, 'Convenzione ricevuta', ['ID Prenotazione' => $prenotazioneId], $op->ip);
    }

    /** Email con la richiesta della convenzione; se parte, la prenotazione risulta «da stipulare». */
    public function richiediConvenzione(int $prenotazioneId): bool
    {
        $ok = $this->convenzioni->richiedi($prenotazioneId);
        if ($ok) {
            $this->iscritti->convenzioneRichiesta($prenotazioneId, true);
        }

        return $ok;
    }

    /**
     * Richiesta della convenzione a tutti gli iscritti dei filtri che non l'hanno: le scuole con una convenzione valida nel
     * registro vengono segnate come ricevute senza email.
     *
     * @return array{inviate: int, gia: int, fallite: int}
     */
    public function richiediConvenzioneATutti(FiltriIscritti $filtri, Operatore $op): array
    {
        $inviate = 0;
        $gia = 0;
        $fallite = 0;
        foreach ($this->iscritti->senzaConvenzione($filtri) as $x) {
            [$dal, $al] = $this->convenzioni->periodoPrenotazione($x);
            if (!empty($x['scuola_codice']) && $this->convenzioni->convenzioneValida($x['scuola_codice'], false, $dal, $al)) {
                $this->convenzioni->segnaRicevuta((int) $x['id'], false);
                ++$gia;
                continue;
            }
            if ($this->convenzioni->richiedi((int) $x['id'])) {
                $this->iscritti->convenzioneRichiesta((int) $x['id'], false);
                ++$inviate;
            } else {
                ++$fallite;
            }
        }
        $this->audit->registra($op->id, 'Richiesta convenzione agli iscritti', ['Inviate' => $inviate, 'Già in registro' => $gia, 'Non inviate' => $fallite], $op->ip);

        return ['inviate' => $inviate, 'gia' => $gia, 'fallite' => $fallite];
    }

    // ------------------------------------------------------------------ approvazione, rifiuto, annullamento, eliminazione

    /**
     * Approva una prenotazione da approvare. Se è in attesa della convenzione la segna ricevuta (e, quando il turno non chiede
     * anche l'approvazione, risulta già confermata): in quel caso ritorna true e non c'è altro da fare.
     *
     * @param string $urlBase indirizzo del portale per il link alla ricevuta (dalla richiesta in corso)
     * @return bool true = confermata dalla sola convenzione ricevuta
     */
    public function approva(int $prenotazioneId, string $urlBase, Operatore $op): bool
    {
        $p = $this->iscritti->prenotazioneConTurnoEvento($prenotazioneId);
        if ($p && ($p['convenzione'] ?? '') === 'no') {
            $this->convenzioni->ricevutaDaGestore($prenotazioneId, $op->email);
            $p = $this->iscritti->prenotazioneConTurnoEvento($prenotazioneId);
            if (($p['stato'] ?? '') !== 'da_approvare') {
                return true;
            }
        }
        if ($p) {
            $this->iscritti->conferma($prenotazioneId);
            $this->iscrizioni->decadiAtteseVincolate($prenotazioneId);
            $link = $urlBase . '/stampa_ricevuta.php?code=' . urlencode((string) $p['codice_prenotazione']);
            $corpo = "<p>La tua richiesta per l'evento <strong>{$p['evento_titolo']}</strong> è stata <strong>APPROVATA</strong>!</p>" . sprintf(self::BOTTONE_RICEVUTA, $link);
            $this->mailer->invia((string) $p['email'], 'Prenotazione Approvata: ' . $p['evento_titolo'], $corpo, $this->colori->delTurno((int) $p['turno_id']));
        }

        return false;
    }

    /** Rifiuta la richiesta; il posto tenuto da una richiesta in approvazione si offre alla lista d'attesa. */
    public function rifiuta(int $prenotazioneId): void
    {
        $p = $this->iscritti->prenotazioneConTurnoEvento($prenotazioneId);
        if (!$p) {
            return;
        }
        $this->iscritti->rifiuta($prenotazioneId);
        $corpo = "<p>Siamo spiacenti di informarti che la tua richiesta per l'evento <strong>{$p['evento_titolo']}</strong> non è stata accolta.</p>";
        $this->mailer->invia((string) $p['email'], 'Aggiornamento Prenotazione: ' . $p['evento_titolo'], $corpo, $this->colori->delTurno((int) $p['turno_id']));
        if (in_array($p['stato'], ['da_approvare', 'confermata', 'richiesta_conferma'], true)) {
            $this->iscrizioni->promuoviListaAttesa((int) $p['turno_id']);
        }
    }

    /**
     * Annulla la prenotazione e avvisa l'iscritto; il posto liberato passa alla lista d'attesa.
     *
     * @return array{esito: string, errno: int, errore: string} esito = 'annullata' | 'errore' | 'non_trovata'
     */
    public function annulla(int $prenotazioneId): array
    {
        $p = $this->iscritti->prenotazioneConTurnoEvento($prenotazioneId);
        if (!$p) {
            return ['esito' => 'non_trovata', 'errno' => 0, 'errore' => ''];
        }
        $r = $this->iscritti->annullaConErrore($prenotazioneId);
        if (!$r['ok']) {
            error_log("[iscritti] UPDATE annulla_pren fallito pr_id=$prenotazioneId errno={$r['errno']} err={$r['errore']}");

            return ['esito' => 'errore', 'errno' => $r['errno'], 'errore' => $r['errore']];
        }
        if (!empty($p['email'])) {
            $this->mailer->invia((string) $p['email'], 'Prenotazione Annullata: ' . $p['evento_titolo'], "La tua prenotazione è stata annullata dall'amministrazione.", $this->colori->delTurno((int) $p['turno_id']));
        }
        if (in_array($p['stato'], ['confermata', 'richiesta_conferma', 'da_approvare'], true)) {
            $this->iscrizioni->promuoviListaAttesa((int) $p['turno_id']);
        }

        return ['esito' => 'annullata', 'errno' => 0, 'errore' => ''];
    }

    /** Elimina definitivamente la prenotazione (con studenti e messaggi) e avvisa l'iscritto. */
    public function elimina(int $prenotazioneId): void
    {
        $p = $this->iscritti->prenotazioneConTurnoEvento($prenotazioneId);
        if (!$p) {
            return;
        }
        $this->iscritti->elimina($prenotazioneId);
        if (!empty($p['email'])) {
            $this->mailer->invia((string) $p['email'], 'Cancellazione Prenotazione', "La tua prenotazione per <strong>{$p['evento_titolo']}</strong> è stata cancellata.", $this->colori->delTurno((int) $p['turno_id']));
        }
        if (in_array($p['stato'], ['confermata', 'richiesta_conferma', 'da_approvare'], true)) {
            $this->iscrizioni->promuoviListaAttesa((int) $p['turno_id']);
        }
    }

    // ------------------------------------------------------------------ prenotazione manuale e modifica

    /**
     * Prenotazione inserita dalla segreteria. Per i progetti valgono le regole del modulo pubblico (numero di partecipanti,
     * una sola edizione, posti e lista d'attesa). Ritorna il messaggio da mostrare (null se il turno non esiste o l'inserimento non riesce).
     *
     * @param array<array-key, mixed> $post campi del form (custom_*)
     */
    public function prenotazioneManuale(int $turnoId, string $nome, string $cognome, string $email, string $matricola, int $numPosti, array $post, mixed $codiciScuola): ?EsitoAzione
    {
        $custom = array_filter(self::campiDaPost($post), static fn ($v) => $v !== '');
        $scuola = $this->scuole->applicaScelta($custom, $codiciScuola);
        $json = $custom ? json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        $json = $json === false ? null : $json;

        $turno = $this->iscritti->turnoAdmin($turnoId);
        if (!$turno) {
            return null;
        }
        $stato = 'confermata';
        // Progetti: stesse regole del modulo pubblico (numero di partecipanti, una sola edizione, posti e lista d'attesa)
        $tp = $this->iscritti->turnoConTipoEvento($turnoId);
        if ($tp && $tp['tipo'] === 'progetto') {
            $eventoId = (int) $tp['evento_id'];
            $numPosti = 1;
            $errore = $this->iscrizioni->validaPartecipantiProgetto($custom, $this->eventi->dettagliProgetti([$eventoId])[$eventoId] ?? null, $tp);
            if ($errore === null && $email !== '' && $this->iscritti->emailGiaIscrittaAEvento($eventoId, $email)) {
                $errore = "questa email è già iscritta (o in lista d'attesa) a un'edizione del progetto";
            }
            if ($errore !== null) {
                return new EsitoAzione("Prenotazione non inserita: $errore", 'danger');
            }
            if ($this->iscrizioni->postiOccupati($turnoId) + $numPosti > (int) $tp['max_posti']) {
                if ((int) $tp['abilita_lista_attesa'] !== 1) {
                    return new EsitoAzione("Prenotazione non inserita: l'edizione è al completo.", 'danger');
                }
                $stato = 'in_attesa';
            }
        }
        $codice = strtoupper(substr((string) ($turno['slug'] ?? '') ?: 'EV', 0, 2)) . '-' . strtoupper(bin2hex(random_bytes(4)));
        $id = $this->iscritti->inserisciManuale($turnoId, $codice, $stato, $numPosti, $nome, $cognome, $email, $matricola, $json);
        if ($id <= 0) {
            return null;
        }
        if ($scuola) {
            $this->iscritti->impostaScuola($id, $scuola);
        }
        if ($stato === 'confermata') {
            $this->iscrizioni->decadiAtteseVincolate($id);
        }
        $esito = $stato === 'confermata'
            ? new EsitoAzione("Prenotazione manuale inserita. Codice: $codice", 'success')
            : new EsitoAzione("Edizione al completo: prenotazione inserita in lista d'attesa. Codice: $codice", 'warning');
        if (!empty($email)) {
            $corpo = '<p>Gentile <strong>' . htmlspecialchars($nome . ' ' . $cognome) . '</strong>,</p>'
                . '<p>La tua ' . ($stato === 'confermata' ? 'prenotazione' : "richiesta, in <strong>lista d'attesa</strong>,") . ' per <strong>' . htmlspecialchars((string) $turno['evento_titolo']) . '</strong> è stata inserita dalla segreteria.</p>'
                . "<p><strong>Codice prenotazione:</strong> <span style='font-family:monospace;font-size:1.2em;color:#B80000;'>$codice</span></p>"
                . '<p>Conserva questo codice: ti servirà per il check-in.</p>'
                . '<p>Cordiali saluti,<br>Segreteria DiBEST</p>';
            $this->mailer->invia($email, ($stato === 'confermata' ? 'Conferma Prenotazione: ' : "Lista d'attesa: ") . $turno['evento_titolo'], $corpo, $this->colori->delTurno($turnoId));
        }

        return $esito;
    }

    /**
     * Modifica dei dati di una prenotazione (anche il turno). Parte dai dati del modulo già salvati: allegati e campi non presenti
     * nel modale restano com'erano. Il codice della scuola cambia solo se è stata scelta una scuola o il testo del campo è stato modificato.
     *
     * @param array<array-key, mixed> $post campi del form (custom_*)
     */
    public function modifica(int $prenotazioneId, int $nuovoTurnoId, string $nome, string $cognome, string $email, string $matricola, array $post, mixed $codiciScuola): void
    {
        $custom = json_decode($this->iscritti->datiCustomJson($prenotazioneId), true) ?: [];
        $prima = $custom;
        foreach (self::campiDaPost($post) as $k => $v) {
            $custom[$k] = $v;
        }
        $scuola = $this->scuole->applicaScelta($custom, $codiciScuola);
        $scuolaToccata = $scuola !== null;
        foreach (array_keys((array) $codiciScuola) as $campo) {
            if (($prima[$campo] ?? '') !== ($custom[$campo] ?? '')) {
                $scuolaToccata = true;
            }
        }
        $json = !empty($custom) ? json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        $this->iscritti->aggiornaDati($prenotazioneId, $nuovoTurnoId, $nome, $cognome, $email, $matricola, $json === false ? null : $json);
        if ($scuolaToccata) {
            $this->iscritti->impostaScuola($prenotazioneId, $scuola);
        }
    }
}
