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
        if (!isset(Costanti::STATI_PRATICA[$stato])) {
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
