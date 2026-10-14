<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Auth\Sessione;
use App\Auth\UtenteRepository;
use App\Core\IndirizzoClient;

/** Pratiche degli studenti: invio, storico, cambi di stato, messaggi con l'ufficio e autodichiarazioni. */
final class ServizioPratiche
{
    public function __construct(
        private PraticaRepository $pratiche,
        private ModuloRepository $moduli,
        private LetturaRisposte $lettura,
        private ServizioUffici $ufficio,
        private AllegatiPratiche $allegati,
        private NotifichePratiche $notifiche,
        private StoricoPratica $storico,
        private FlussoPratica $flusso,
        private UtenteRepository $utenti,
        private IndirizzoClient $client,
        private Sessione $sessione
    ) {
    }

    /**
     * Pratica con il titolo del modulo (per id).
     *
     * @return array<string, mixed>|null
     */
    public function pratica(int $id): ?array
    {
        return $this->pratiche->perId($id);
    }

    /**
     * Nuova pratica dal modulo compilato. Ritorna l'id (0 se non riesce).
     *
     * @param array<string, mixed> $m
     * @param array<string, mixed> $u
     * @param list<array<string, mixed>> $risposte
     */
    public function crea(array $m, array $u, array $risposte): int
    {
        $codice = 'PR-' . strtoupper(bin2hex(random_bytes(4)));
        $json = (string) json_encode($risposte, JSON_UNESCAPED_UNICODE);
        $matr = (string) (($u['matricola_studente'] ?? '') ?: ($u['matricola_dipendente'] ?? ''));
        // Da dove e come è entrata la persona (metadati della domanda in PDF/A)
        $ip = mb_substr((string) ($this->client->ip() ?? ''), 0, 45);
        $acc = (string) json_encode(array_intersect_key((array) ($this->sessione->leggi('auth_meta') ?? ['metodo' => 'ateneo']), array_flip(['metodo', 'livello', 'idp', 'spid_code', 'istante'])), JSON_UNESCAPED_UNICODE);
        $uid = (int) $u['id'];
        $id = $this->pratiche->inserisci((int) $m['id'], $uid, $codice, $u['nome'], $u['cognome'], (string) ($u['email'] ?? ''), $matr, $json, $ip, $acc);
        if (!$id) {
            return 0;
        }
        $this->evento($id, 'stato', 'studente', $uid, 'inviata', 'Pratica inviata');
        // Domanda in PDF/A (moduli che la prevedono) e primo ufficio dell'iter se è il protocollo
        $this->flusso->avvia($id, $m, $u);
        if ($p = $this->pratiche->perId($id)) {
            $this->notifiche->email($p, 'nuova');
        }

        return $id;
    }

    /**
     * Da dove e come è entrata la persona (metadati della domanda in PDF/A): [indirizzo IP, accesso in JSON].
     *
     * @return array{0: string, 1: string}
     */
    private function accesso(): array
    {
        $ip = mb_substr((string) ($this->client->ip() ?? ''), 0, 45);
        $acc = (string) json_encode(array_intersect_key((array) ($this->sessione->leggi('auth_meta') ?? ['metodo' => 'ateneo']), array_flip(['metodo', 'livello', 'idp', 'spid_code', 'istante'])), JSON_UNESCAPED_UNICODE);

        return [$ip, $acc];
    }

    /** @param array<string, mixed> $u */
    private static function matricola(array $u): string
    {
        return (string) (($u['matricola_studente'] ?? '') ?: ($u['matricola_dipendente'] ?? ''));
    }

    /**
     * La pratica si può ancora modificare: è una bozza oppure è stata inviata ma nessuno l'ha presa in carico (nessuna persona assegnata,
     * niente protocollo, nessuna firma in corso, non ancora in consiglio).
     *
     * @param array<string, mixed> $p
     */
    public static function modificabile(array $p): bool
    {
        if (($p['stato'] ?? '') === 'bozza') {
            return true;
        }

        return in_array($p['stato'] ?? '', ['inviata', 'in_lavorazione'], true) && empty($p['assegnata_a']) && (string) ($p['protocollo'] ?? '') === ''
            && empty($p['firme_attesa']) && empty($p['seduta_id']) && (int) ($p['passo'] ?? 0) <= 1 && empty($p['bollo_il']);
    }

    /**
     * Gli allegati già caricati restano se lo studente non ne sceglie di nuovi.
     *
     * @param list<array<string, mixed>> $nuove
     * @param list<array<string, mixed>> $vecchie
     * @return list<array<string, mixed>>
     */
    public static function conservaAllegati(array $nuove, array $vecchie): array
    {
        $prima = [];
        foreach ($vecchie as $r) {
            if (!empty($r['file'])) {
                $prima[mb_strtolower((string) $r['etichetta'])] = $r;
            }
        }
        foreach ($nuove as $i => $r) {
            if (($r['tipo'] ?? '') === 'file' && empty($r['file']) && empty($r['nascosto']) && isset($prima[mb_strtolower((string) $r['etichetta'])])) {
                $v = $prima[mb_strtolower((string) $r['etichetta'])];
                $nuove[$i]['file'] = $v['file'];
                $nuove[$i]['nome_file'] = $v['nome_file'] ?? null;
                $nuove[$i]['valore'] = $v['valore'] ?? '';
            }
        }

        return $nuove;
    }

    /**
     * Toglie gli errori «campo obbligatorio» dei campi allegato che hanno già un file (modifica di una pratica o di una bozza).
     *
     * @param list<string> $errori
     * @param list<array<string, mixed>> $campi
     * @param list<array<string, mixed>> $vecchie
     * @return list<string>
     */
    public static function senzaErroriAllegati(array $errori, array $campi, array $vecchie): array
    {
        $con = [];
        foreach ($vecchie as $r) {
            if (!empty($r['file'])) {
                $con[] = (string) $r['etichetta'] . ': campo obbligatorio';
            }
        }

        return array_values(array_filter($errori, static fn (string $e): bool => !in_array($e, $con, true)));
    }

    /**
     * Salva le risposte come bozza (si riprende e si invia più tardi): non ci sono controlli sui campi obbligatori. Con $id si aggiorna una bozza
     * già salvata. Ritorna l'id (0 se non riesce o la bozza non è di $u).
     *
     * @param array<string, mixed> $m
     * @param array<string, mixed> $u
     * @param list<array<string, mixed>> $risposte
     */
    public function salvaBozza(array $m, array $u, array $risposte, int $id = 0): int
    {
        $uid = (int) $u['id'];
        if ($id > 0) {
            $p = $this->pratiche->perId($id);
            if (!$p || $p['stato'] !== 'bozza' || (int) $p['utente_id'] !== $uid || (int) $p['modulo_id'] !== (int) $m['id']) {
                return 0;
            }
            $risposte = self::conservaAllegati($risposte, json_decode((string) $p['risposte_json'], true) ?: []);
            $this->pratiche->aggiornaRisposte($id, (string) json_encode($risposte, JSON_UNESCAPED_UNICODE), self::matricola($u));

            return $id;
        }
        $nuovo = $this->pratiche->inserisciBozza((int) $m['id'], $uid, 'PR-' . strtoupper(bin2hex(random_bytes(4))), $u['nome'], $u['cognome'], (string) ($u['email'] ?? ''), self::matricola($u), (string) json_encode($risposte, JSON_UNESCAPED_UNICODE));
        if ($nuovo) {
            $this->evento($nuovo, 'stato', 'studente', $uid, 'bozza', 'Bozza salvata: si può riprendere e inviare quando vuoi');
        }

        return $nuovo;
    }

    /**
     * Invia una bozza: diventa una pratica «inviata» e parte l'iter (come all'invio diretto). Ritorna se è andata a buon fine.
     *
     * @param array<string, mixed> $m
     * @param array<string, mixed> $u
     * @param list<array<string, mixed>> $risposte
     */
    public function inviaBozza(int $id, array $m, array $u, array $risposte): bool
    {
        $p = $this->pratiche->perId($id);
        if (!$p || $p['stato'] !== 'bozza' || (int) $p['utente_id'] !== (int) $u['id'] || (int) $p['modulo_id'] !== (int) $m['id']) {
            return false;
        }
        $risposte = self::conservaAllegati($risposte, json_decode((string) $p['risposte_json'], true) ?: []);
        $this->pratiche->aggiornaRisposte($id, (string) json_encode($risposte, JSON_UNESCAPED_UNICODE), self::matricola($u));
        [$ip, $acc] = $this->accesso();
        $this->pratiche->inviaBozza($id, $ip, $acc);
        $this->evento($id, 'stato', 'studente', (int) $u['id'], 'inviata', 'Pratica inviata');
        $this->flusso->avvia($id, $m, $u);
        if ($p2 = $this->pratiche->perId($id)) {
            $this->notifiche->email($p2, 'nuova');
        }

        return true;
    }

    /**
     * Lo studente cambia le risposte di una pratica già inviata, finché nessuno l'ha presa in carico: la domanda in PDF/A si rifà.
     * Ritorna un errore o null.
     *
     * @param array<string, mixed> $m
     * @param array<string, mixed> $u
     * @param list<array<string, mixed>> $risposte
     */
    public function modifica(int $id, array $m, array $u, array $risposte): ?string
    {
        $p = $this->pratiche->perId($id);
        if (!$p || (int) $p['utente_id'] !== (int) $u['id']) {
            return 'Pratica non trovata.';
        }
        if ($p['stato'] === 'bozza') {
            return $this->salvaBozza($m, $u, $risposte, $id) ? null : 'Non è stato possibile salvare la bozza.';
        }
        if (!self::modificabile($p)) {
            return "La pratica è già stata presa in carico dall'ufficio: non si può più modificare. Scrivi all'ufficio dalla pagina della pratica.";
        }
        $risposte = self::conservaAllegati($risposte, json_decode((string) $p['risposte_json'], true) ?: []);
        $this->pratiche->aggiornaRisposte($id, (string) json_encode($risposte, JSON_UNESCAPED_UNICODE), self::matricola($u));
        $this->evento($id, 'attivita', 'studente', (int) $u['id'], null, 'Richiesta modificata dallo studente prima della presa in carico.');
        $this->flusso->rigenera($id, $m);

        return null;
    }

    /** La bozza si elimina (solo la propria). */
    public function eliminaBozza(int $id, int $uid): bool
    {
        $p = $this->pratiche->perId($id);

        return $p && $p['stato'] === 'bozza' && (int) $p['utente_id'] === $uid && $this->pratiche->eliminaBozza($id);
    }

    /**
     * Riga dello storico (vedi StoricoPratica::evento).
     */
    public function evento(int $praticaId, string $tipo, string $autore, int $uid, ?string $stato, string $testo, ?string $allegato = null, ?string $nomeAll = null, bool $interno = false, string $autoreNome = ''): void
    {
        $this->storico->evento($praticaId, $tipo, $autore, $uid, $stato, $testo, $allegato, $nomeAll, $interno, $autoreNome);
    }

    /**
     * Cambia lo stato. $richiesta (solo per «integrazione»): ['tipo' => 'documenti'|'autodichiarazione', 'testo' => …] da mostrare allo studente.
     *
     * @param array<string, mixed>|null $richiesta
     */
    public function cambiaStato(int $id, string $stato, string $nota, int $uid, string $autoreNome = '', ?array $richiesta = null): bool
    {
        if (!isset(Costanti::STATI_PRATICA[$stato]) || $stato === 'bozza') {
            return false;
        }
        $p = $this->pratiche->perId($id);
        if (!$p) {
            return false;
        }
        $rj = null;
        if ($stato === 'integrazione') {
            $tipoR = ($richiesta['tipo'] ?? '') === 'autodichiarazione' ? 'autodichiarazione' : 'documenti';
            $rj = (string) json_encode(['tipo' => $tipoR, 'testo' => mb_substr(trim((string) ($richiesta['testo'] ?? '')) ?: $nota, 0, 3000), 'da' => $autoreNome, 'il' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
        }
        $this->pratiche->impostaStato($id, $stato, $rj);
        $testoEv = $nota;
        if ($rj) {
            $r = json_decode($rj, true);
            $testoEv = ($r['tipo'] === 'autodichiarazione' ? "Richiesta un'autodichiarazione: " : 'Richiesti documenti: ') . $r['testo'];
        }
        $this->evento($id, 'stato', 'ufficio', $uid, $stato, $testoEv, null, null, false, $autoreNome);
        $p['stato'] = $stato;
        // Email solo ai passaggi: richiesta di integrazione ed esito (il ritorno «in lavorazione» non è un passaggio)
        if (in_array($stato, ['integrazione', 'accolta', 'respinta', 'chiusa'], true)) {
            $this->notifiche->email($p, 'stato', $testoEv);
        }

        return true;
    }

    /**
     * Messaggio o attività (es. verbale caricato) dello studente o dell'ufficio, con allegato facoltativo ($file = elemento di $_FILES).
     * $interno: nota tra i referenti (lo studente non la vede e non riceve email). Lo studente che risponde a un'integrazione
     * di documenti la chiude e la pratica torna in lavorazione. Ritorna un errore o null.
     *
     * @param array<string, mixed>|null $file
     */
    public function messaggio(int $id, string $autore, int $uid, string $testo, ?array $file = null, bool $interno = false, string $tipo = 'messaggio', string $autoreNome = ''): ?string
    {
        $p = $this->pratiche->perId($id);
        if (!$p) {
            return 'Pratica non trovata.';
        }
        $testo = mb_substr(trim($testo), 0, 5000);
        $all = null;
        $nomeAll = null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $all = $this->allegati->salva($file);
            if (!$all) {
                return 'Allegato non valido (PDF, JPG o PNG fino a 10 MB).';
            }
            $nomeAll = mb_substr(basename((string) $file['name']), 0, 200);
        }
        if ($testo === '' && !$all) {
            return 'Scrivi il messaggio o allega un file.';
        }
        $interno = $interno && $autore === 'ufficio';
        $this->evento($id, $tipo === 'attivita' ? 'attivita' : 'messaggio', $autore, $uid, null, $testo, $all, $nomeAll, $interno, $autoreNome);
        if ($autore === 'studente' && $p['stato'] === 'integrazione') {
            $rq = json_decode((string) ($p['richiesta_json'] ?? ''), true) ?: [];
            if (($rq['tipo'] ?? 'documenti') !== 'autodichiarazione') {
                $this->pratiche->riprendi($id);
                $this->evento($id, 'stato', 'studente', $uid, 'in_lavorazione', 'Integrazione inviata');
            }
        }
        if ($autore === 'ufficio') {
            // Un operatore che aveva la pratica prima (ufficio precedente) la integra: lo sa chi ce l'ha in carico ora
            $uAut = $uid > 0 ? $this->utenti->perId($uid) : null;
            $oAut = $uAut ? $this->ufficio->operatore(0, $uAut) : null;
            $oCar = !empty($p['assegnata_a']) ? $this->ufficio->operatore((int) $p['assegnata_a']) : null;
            if ($oAut && $oCar && (int) $oAut['id'] !== (int) $oCar['id'] && in_array((int) $oAut['id'], $this->pratiche->operatori($id), true)) {
                $this->notifiche->invia(
                    $oCar['email'],
                    'Integrazione sulla pratica ' . $p['codice'] . ' da ' . $oAut['nominativo'],
                    '<p>' . htmlspecialchars($this->ufficio->etichetta($oAut)) . ', che ha avuto in carico la pratica <strong>' . htmlspecialchars($p['codice']) . '</strong> (' . htmlspecialchars($p['modulo_titolo']) . '), ha aggiunto:</p>'
                    . "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br(htmlspecialchars($testo !== '' ? $testo : '(documento allegato)')) . ($nomeAll ? '<br><em>Allegato: ' . htmlspecialchars($nomeAll) . '</em>' : '') . '</p>'
                    . "<p><a href='" . htmlspecialchars($this->notifiche->linkPannello($id)) . "'>Apri la pratica</a></p>"
                );
            }
        }
        // Le note interne non mandano email (le notifiche partono ai passaggi della pratica)
        if ($interno) {
            return null;
        }
        $this->notifiche->email($p, $autore === 'ufficio' ? 'msg_ufficio' : 'msg_studente', $testo . ($nomeAll ? "\n[Allegato: $nomeAll]" : ''));

        return null;
    }

    /**
     * Lo studente rende l'autodichiarazione richiesta (DPR 445/2000): resta nello storico con data e ora, la pratica torna in lavorazione.
     */
    public function autodichiarazione(int $id, int $uid, string $aggiunta, bool $conferma): ?string
    {
        $p = $this->pratiche->perId($id);
        $rq = $p ? (json_decode((string) ($p['richiesta_json'] ?? ''), true) ?: []) : [];
        if (!$p || $p['stato'] !== 'integrazione' || ($rq['tipo'] ?? '') !== 'autodichiarazione') {
            return "Non c'è un'autodichiarazione da rendere.";
        }
        if (!$conferma) {
            return 'Per inviare devi spuntare la dichiarazione.';
        }
        $testo = 'Il/La sottoscritto/a ' . trim($p['nome'] . ' ' . $p['cognome']) . ($p['matricola'] !== '' ? ', matricola ' . $p['matricola'] : '') . ', dichiara: ' . $rq['testo']
               . (trim($aggiunta) !== '' ? "\n" . mb_substr(trim($aggiunta), 0, 3000) : '')
               . "\nDichiarazione resa ai sensi degli artt. 46 e 47 del D.P.R. 445/2000, consapevole delle sanzioni penali previste dall'art. 76 per le dichiarazioni mendaci, il " . date('d/m/Y \a\l\l\e H:i') . '.';
        $this->evento($id, 'autodich', 'studente', $uid, null, $testo);
        $this->pratiche->riprendi($id);
        $this->evento($id, 'stato', 'studente', $uid, 'in_lavorazione', 'Autodichiarazione inviata');
        $this->notifiche->email($p, 'msg_studente', $testo);

        return null;
    }

    /**
     * Istruttoria dell'ufficio: campi dell'ufficio del modulo, seduta, delibera e protocollo. Ritorna il codice della pratica (null se non c'è).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function salvaIstruttoria(int $id, array $post, array $files): ?string
    {
        $p = $this->pratiche->perId($id);
        if (!$p) {
            return null;
        }
        $m = $this->moduli->perId((int) $p['modulo_id']);
        [$risU] = $this->lettura->leggi(CampiModulo::ufficio(CampiModulo::da($m['campi_json'] ?? '')), $post, $files);
        $jsonU = $risU ? (string) json_encode($risU, JSON_UNESCAPED_UNICODE) : null;
        $sed = (int) ($post['seduta_id'] ?? 0) ?: null;
        $del = mb_substr(trim((string) ($post['delibera'] ?? '')), 0, 5000);
        $prot = mb_substr(trim((string) ($post['protocollo'] ?? '')), 0, 100);
        $protD = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($post['protocollo_data'] ?? '')) ? $post['protocollo_data'] : null;
        $this->pratiche->salvaIstruttoria($id, $jsonU, $sed, $del, $prot, $protD);

        return (string) $p['codice'];
    }
}
