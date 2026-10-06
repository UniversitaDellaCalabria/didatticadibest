<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Sito;
use App\Infrastructure\Pdf\Documento;

/** La domanda dello studente in PDF/A-2b, generata all'invio e pronta da protocollare, con i metadati visibili e invisibili (XMP). */
final class DomandaPdfa
{
    public function __construct(
        private Database $db,
        private ModuloRepository $moduli,
        private PraticaRepository $pratiche,
        private StoricoPratica $storico,
        private AllegatiPratiche $allegati,
        private Sito $sito
    ) {
    }

    public function config(?array $m): array
    {
        $d = json_decode((string)($m['domanda_json'] ?? ''), true) ?: [];
        return ['pdf' => !empty($d['pdf']), 'bollo' => !empty($d['bollo']), 'oggetto' => trim((string)($d['oggetto'] ?? '')) ?: (string)($m['titolo'] ?? ''),
                'destinatario' => trim((string)($d['destinatario'] ?? '')), 'chiede' => trim((string)($d['chiede'] ?? ''))];
    }

    public static function ateneoPrecedente(array $p): string
    {
        $sc = '';
        $scritto = '';
        foreach (json_decode((string)$p['risposte_json'], true) ?: [] as $r) {
            if (!empty($r['nascosto']) || trim((string)($r['valore'] ?? '')) === '') {
                continue;
            }
            $e = mb_strtolower((string)$r['etichetta']);
            if (preg_match('/denominazione dell.ateneo/u', $e)) {
                $scritto = trim((string)$r['valore']);
            } elseif (preg_match('/^ateneo/u', $e)) {
                $sc = trim((string)$r['valore']);
            }
        }
        return $scritto !== '' ? $scritto : $sc;
    }

    public static function frasiPiano(array $righe, string $chi = 'Lo/La studente/ssa chiede'): array
    {
        $out = [];
        foreach ($righe as $r) {
            if (empty($r['piano'])) {
                continue;
            }
            $ins = trim((string)(($r['esito'] ?? '') !== 'no' && trim((string)($r['ins'] ?? '')) !== '' ? $r['ins'] : $r['richiesto']));
            $out[] = $chi . ' che l\'insegnamento «' . $ins . '» sia inserito nel piano di studi come insegnamento a scelta'
                   . (trim((string)($r['elimina'] ?? '')) !== '' ? ', eliminando dal piano l\'insegnamento «' . trim((string)$r['elimina']) . '»' : '') . '.';
        }
        return $out;
    }

    public static function frasiPianoDecisioni(?array $dec): array
    {
        return is_array($dec) && ($dec['tipo'] ?? '') === 'convalide' ? self::frasiPiano($dec['righe'] ?? []) : [];
    }

    public function pdf(array $p, array $m): string
    {
        $cfg = $this->config($m);
        $campi = CampiModulo::da($m['campi_json'] ?? '');
        $aiuto_di = [];
        foreach ($campi as $c) {
            $aiuto_di[mb_strtolower($c['etichetta'])] = $c;
        }
        $risposte = array_values(array_filter(json_decode((string)$p['risposte_json'], true) ?: [], fn ($r) => empty($r['nascosto'])));
        $usate = [];
        // Dato anagrafico: per tipo del campo o per la sua domanda
        $dato = function (?string $tipo, ?string $re) use ($risposte, &$usate): string {
            foreach ($risposte as $i => $r) {
                if (($tipo !== null && ($r['tipo'] ?? '') === $tipo) || ($re !== null && preg_match($re, mb_strtolower((string)$r['etichetta'])))) {
                    if (trim((string)$r['valore']) === '' || ($r['tipo'] ?? '') === 'tabella') {
                        continue;
                    }
                    $usate[$i] = true;
                    $v = trim((string)$r['valore']);
                    return ($r['tipo'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? date('d/m/Y', (int) strtotime($v)) : $v;
                }
            }
            return '';
        };
        $luogo = $dato(null, '/luogo di nascita|^nato/u');
        $nascita = $dato(null, '/data di nascita/u');
        $cf = $dato('codice_fiscale', null);
        $comune = $dato(null, '/comune di residenza|^residente/u');
        $indirizzo = $dato(null, '/indirizzo/u');
        $cell = $dato(null, '/cellulare|telefono/u');
        $aa = $dato('anno_accademico', null);
        $corso = $dato('corso_studio', null);
        $matr = $dato(null, '/^matricola/u') ?: (string)$p['matricola'];
        $nome = mb_strtoupper(trim($p['cognome'] . ' ' . $p['nome']));
        $acc = json_decode((string)($p['accesso_json'] ?? ''), true) ?: ['metodo' => 'ateneo'];
        $metodo = (\App\Tutorato\Costanti::METODI_ACCESSO[$acc['metodo'] ?? 'ateneo'] ?? '') ?: 'credenziali di Ateneo';
        $quando = strtotime($p['creata_il']);
        $impronta = hash('sha256', (string) json_encode(['codice' => $p['codice'], 'modulo' => (int)$p['modulo_id'], 'utente' => (int)$p['utente_id'], 'cf' => $cf,
                                                 'risposte' => json_decode((string)$p['risposte_json'], true), 'inviata' => $p['creata_il']], JSON_UNESCAPED_UNICODE));
        $titolo = $cfg['oggetto'] . ' – ' . trim($p['cognome'] . ' ' . $p['nome']);

        $pdf = new Documento(['logo' => $this->sito->radice() . '/' . Costanti::LOGO_VERBALE, 'logo_larghezza' => 200, 'logo_pos' => 'sinistra', 'pdfa' => true,
            'piede' => 'Domanda ' . $p['codice'] . ' – ' . $cfg['oggetto'],
            'info' => ['Title' => $titolo, 'Author' => trim($p['nome'] . ' ' . $p['cognome']), 'Subject' => $cfg['oggetto'] . ' – pratica ' . $p['codice'],
                       'Keywords' => implode('; ', array_filter([$p['codice'], $cfg['oggetto'], $corso, $matr !== '' ? 'matricola ' . $matr : ''])), 'Creator' => 'Didattica DiBEST – modulistica online'],
            'xmp' => ['codicePratica' => $p['codice'], 'modulo' => $m['titolo'], 'richiedente' => trim($p['cognome'] . ' ' . $p['nome']), 'codiceFiscale' => $cf,
                      'matricola' => $matr, 'email' => $p['email'], 'corsoDiStudio' => $corso, 'annoAccademico' => $aa, 'dataInvio' => date('c', $quando),
                      'accesso' => $metodo . (!empty($acc['livello']) ? ' livello ' . (int)$acc['livello'] : ''), 'indirizzoIP' => (string)($p['ip_invio'] ?? ''),
                      'improntaDatiSHA256' => $impronta, 'destinatario' => str_replace("\n", ' – ', $cfg['destinatario'])]]);
        if ($cfg['destinatario'] !== '') {
            $pdf->paragrafo($cfg['destinatario'], ['al' => 'destra', 'dopo' => 14, 'rientro' => 90]);
        }
        $pdf->paragrafo('**Oggetto:** ' . $cfg['oggetto'] . '.', ['dopo' => 12, 'al' => 'sinistra']);
        $frase = 'Il/La sottoscritto/a **' . $nome . '**'
               . ($luogo !== '' ? ', nato/a a ' . $luogo : '') . ($nascita !== '' ? ' il ' . $nascita : '')
               . ($cf !== '' ? ', codice fiscale ' . $cf : '')
               . ($comune !== '' ? ', residente in ' . $comune . ($indirizzo !== '' ? ', ' . $indirizzo : '') : '')
               . ($cell !== '' ? ', cellulare ' . $cell : '') . ($p['email'] !== '' ? ', email ' . $p['email'] : '')
               . ($aa !== '' || $corso !== '' ? ', iscritto/a' . ($aa !== '' ? ' per l\'a.a. ' . $aa : '') . ($corso !== '' ? (preg_match('/^corso/iu', $corso) ? ' al c' . mb_substr($corso, 1) : ' al Corso di Studio in ' . $corso) : '') : '')
               . ($matr !== '' ? ', matricola n. ' . $matr : '') . ',';
        $pdf->paragrafo($frase, ['dopo' => 8, 'interlinea' => 1.4]);
        $pdf->paragrafo('**CHIEDE**', ['al' => 'centro', 'dopo' => 8, 'sz' => 12]);
        $chiede = $cfg['chiede'] !== '' ? $cfg['chiede'] : 'quanto indicato di seguito:';
        $pdf->paragrafo(TestiPratica::segnaposti($chiede, $p), ['dopo' => 8, 'interlinea' => 1.4]);
        // Tabelle (esami): la colonna del piano diventa «Sì» / «Sì – elimina: …»
        $pesi_tipo = ['codice' => 1.1, 'denominazione' => 3.2, 'insegnamento' => 3.4, 'insegnamento_dip' => 3.2, 'cfu' => 0.6, 'ssd' => 1.2, 'voto' => 0.7, 'data' => 1.2, 'piano' => 1.7];
        $tab_campi = [];
        foreach ($campi as $c) {
            if ($c['tipo'] === 'tabella') {
                $tab_campi[mb_strtolower($c['etichetta'])] = $c['colonne'];
            }
        }
        foreach ($risposte as $i => $r) {
            if (($r['tipo'] ?? '') !== 'tabella' || empty($r['righe'])) {
                continue;
            }
            $usate[$i] = true;
            $cols = $tab_campi[mb_strtolower((string)$r['etichetta'])] ?? array_map(fn ($n) => ['nome' => $n, 'tipo' => CampiModulo::tipoColonnaDaNome($n)], $r['colonne'] ?? []);
            $righe = array_map(function ($riga) use ($cols) {
                foreach ($cols as $j => $col) {
                    if ($col['tipo'] === 'piano') {
                        [$si, $el] = CampiModulo::valorePiano((string)($riga[$j] ?? ''));
                        $riga[$j] = $si ? 'Sì' . ($el !== '' ? ' – elimina: ' . $el : '') : '—';
                    }
                }
                return $riga;
            }, $r['righe']);
            $pdf->paragrafo('**' . $r['etichetta'] . '**', ['sz' => 10, 'dopo' => 4, 'al' => 'sinistra']);
            $pdf->tabella(
                array_map(fn ($c) => $c['tipo'] === 'piano' ? 'A scelta nel piano' : $c['nome'], $cols),
                $righe,
                ['sz' => count($cols) > 5 ? 8 : 9, 'larghezze' => array_map(fn ($c) => $pesi_tipo[$c['tipo']] ?? 1.4, $cols)]
            );
        }
        $frasi = self::frasiPiano(TestiPratica::righeRichieste($p, $campi), 'Chiede inoltre');
        foreach ($frasi as $f) {
            $pdf->paragrafo($f, ['dopo' => 4]);
        }
        if ($frasi) {
            $pdf->spazio(4);
        }
        // Altre risposte (es. come si è conclusa la carriera, note), allegati e dichiarazioni
        $altre = [];
        $allegati = [];
        $dich = [];
        foreach ($risposte as $i => $r) {
            if (isset($usate[$i]) || trim((string)($r['valore'] ?? '')) === '') {
                continue;
            }
            if (!empty($r['file'])) {
                $allegati[] = $r['etichetta'] . ' (' . $r['nome_file'] . ')';
                continue;
            }
            if (($r['tipo'] ?? '') === 'dichiarazione') {
                $dich[] = $r;
                continue;
            }
            if (preg_match('/^ateneo|denominazione dell.ateneo|carriera precedente$/u', mb_strtolower((string)$r['etichetta'])) && str_contains($chiede, '{')) {
                continue;
            }
            $v = ($r['tipo'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r['valore']) ? date('d/m/Y', strtotime($r['valore'])) : (string)$r['valore'];
            $altre[] = $r['etichetta'] . ': ' . $v;
        }
        if ($altre) {
            $pdf->paragrafo('**Ulteriori indicazioni**', ['dopo' => 3, 'al' => 'sinistra']);
            foreach ($altre as $a) {
                $pdf->paragrafo('– ' . $a, ['dopo' => 2, 'rientro' => 10, 'al' => 'sinistra']);
            } $pdf->spazio(6);
        }
        if ($allegati) {
            $pdf->paragrafo('**Allega**', ['dopo' => 3, 'al' => 'sinistra']);
            foreach ($allegati as $a) {
                $pdf->paragrafo('– ' . $a, ['dopo' => 2, 'rientro' => 10, 'al' => 'sinistra']);
            } $pdf->spazio(6);
        }
        foreach ($dich as $r) {
            $c = $aiuto_di[mb_strtolower((string)$r['etichetta'])] ?? null;
            if ($c && $c['aiuto'] !== '') {
                $pdf->paragrafo($c['aiuto'], ['sz' => 9.5, 'dopo' => 3]);
            }
            $pdf->paragrafo('[X] ' . $r['etichetta'], ['sz' => 9.5, 'dopo' => 8, 'b' => true, 'al' => 'sinistra']);
        }
        $pdf->serve(70);
        $pdf->paragrafo('Rende, ' . date('d/m/Y', $quando), ['dopo' => 4, 'al' => 'sinistra', 'prima' => 6]);
        $pdf->paragrafo("Il/La richiedente\n**" . trim($p['nome'] . ' ' . $p['cognome']) . "**\nistanza presentata per via telematica", ['al' => 'destra', 'dopo' => 14]);
        // Metadati visibili: chi, quando, come, da dove, impronta dei dati
        $pdf->riquadro("DATI DEL DOCUMENTO\n"
            . 'Pratica ' . $p['codice'] . ' – ' . $m['titolo'] . "\n"
            . 'Inviata il ' . date('d/m/Y', $quando) . ' alle ore ' . date('H:i:s', $quando) . ' dal portale Didattica DiBEST (' . parse_url($this->sito->urlBase(), PHP_URL_HOST) . ")\n"
            . 'Richiedente: ' . trim($p['cognome'] . ' ' . $p['nome']) . ($cf !== '' ? ' – C.F. ' . $cf : '') . ($matr !== '' ? ' – matricola ' . $matr : '') . ' – ' . $p['email'] . "\n"
            . 'Identificazione: accesso con ' . $metodo . (!empty($acc['livello']) ? ' (livello ' . (int)$acc['livello'] . ')' : '') . (!empty($acc['idp']) ? ' – identity provider ' . $acc['idp'] : '')
            . (!empty($p['ip_invio']) ? ' – indirizzo IP ' . $p['ip_invio'] : '') . "\n"
            . 'Impronta SHA-256 dei dati della domanda: ' . $impronta . "\n"
            . 'Documento in formato PDF/A-2b generato automaticamente; numero e data di protocollo sono attribuiti dall\'Ufficio protocollo.', ['sz' => 7.5]);
        return $pdf->pdf();
    }

    public function genera(int $id, int $uid = 0, string $autore_nome = ''): ?string
    {
        $p = $this->pratiche->perId($id);
        $m = $p ? $this->moduli->perId((int)$p['modulo_id']) : null;
        if (!$p || !$m) {
            return null;
        }
        $dir = $this->sito->radice() . '/' . $this->allegati->cartella();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "# Allegati delle pratiche: si scaricano solo da allegato_pratica.php\nRequire all denied\n");
        }
        $pdf = $this->pdf($p, $m);
        $nome = 'domanda_' . preg_replace('/[^A-Za-z0-9_-]/', '', $p['codice']) . '_' . bin2hex(random_bytes(4)) . '.pdf';
        if (@file_put_contents($dir . $nome, $pdf) === false) {
            return null;
        }
        $sha = hash('sha256', $pdf);
        $this->db->esegui("UPDATE pratiche SET domanda_pdf = ?, domanda_sha = ? WHERE id = ?", [$this->allegati->cartella() . $nome, $sha, $id]);
        $this->storico->evento(
            $id,
            'attivita',
            $uid ? 'ufficio' : 'studente',
            $uid ?: (int)$p['utente_id'],
            null,
            'Domanda in PDF/A pronta per il protocollo (impronta SHA-256 del file: ' . $sha . ').',
            $this->allegati->cartella() . $nome,
            'Domanda_' . $p['codice'] . '.pdf',
            false,
            $autore_nome
        );
        return $this->allegati->cartella() . $nome;
    }

    public function eventoDomanda(array $p): ?int
    {
        if (empty($p['domanda_pdf'])) {
            return null;
        }
        $e = $this->db->valore("SELECT id FROM pratiche_eventi WHERE pratica_id = ? AND allegato = ? ORDER BY id DESC LIMIT 1", [(int)$p['id'], (string)$p['domanda_pdf']]);
        return $e ? (int)$e : null;
    }
}
