<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Sito;
use App\Infrastructure\Pdf\TestoPdf;

/**
 * Legge da sola la cartella dei verbali (uploads/verbali/archivio/, sul NAS): i PDF messi lì dentro, in cartelle per corso, organo e anno, entrano
 * nell'archivio senza essere copiati; il portale li legge dov'è. Ogni cartella appartiene a un organo (un consiglio o un altro organo: vale la cartella
 * più specifica). Cartelle e file sparsi senza organo non si importano: compaiono nell'elenco «da collegare». I file nuovi si trovano, quelli
 * cambiati si rileggono, quelli spostati tengono i dati già corretti, quelli tolti spariscono dall'archivio (solo se la cartella è raggiungibile).
 */
final class ScansioneArchivio
{
    /** Quanti PDF si leggono (testo compreso) a ogni scansione: il resto alla prossima (di notte, o con «Scansiona ora»). */
    public const MAX_PER_VOLTA = 40;

    public function __construct(
        private SedutaRepository $sedute,
        private ConsiglioRepository $consigli,
        private OrganiArchivio $organi,
        private TestoPdf $testo,
        private Sito $sito,
        private string $cartella = Costanti::DIR_VERBALI
    ) {
    }

    /** La cartella da leggere, con la barra finale. */
    public function radice(): string
    {
        return $this->sito->radice() . '/' . $this->cartella . 'archivio/';
    }

    /** Crea la cartella (e la protezione del web nella cartella dei verbali) se manca. */
    public function prepara(): bool
    {
        $dir = $this->sito->radice() . '/' . $this->cartella;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && !is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "# Verbali: si scaricano solo dal pannello o con il link personale\nRequire all denied\n");
        }
        if (!is_dir($this->radice())) {
            @mkdir($this->radice(), 0755, true);
        }

        return is_dir($this->radice());
    }

    /**
     * Le cartelle collegate a un organo.
     *
     * @return list<array{cartella: string, chiave: string, nome: string, consiglio_id: ?int, organo_id: ?int}>
     */
    public function mappe(): array
    {
        $m = [];
        foreach ($this->consigli->tutti() as $c) {
            $cart = OrganiArchivio::cartella((string) ($c['cartella_archivio'] ?? ''));
            if ($cart !== '') {
                $m[] = ['cartella' => $cart, 'chiave' => mb_strtolower($cart), 'nome' => (string) $c['nome'], 'consiglio_id' => (int) $c['id'], 'organo_id' => null];
            }
        }
        foreach ($this->organi->tutti() as $o) {
            $cart = OrganiArchivio::cartella((string) $o['cartella']);
            if ($cart !== '' && (int) $o['attivo'] === 1) {
                $m[] = ['cartella' => $cart, 'chiave' => mb_strtolower($cart), 'nome' => (string) $o['nome'], 'consiglio_id' => null, 'organo_id' => (int) $o['id']];
            }
        }

        return $m;
    }

    /**
     * L'organo di una cartella: quello con la cartella più specifica che la contiene.
     *
     * @param list<array<string, mixed>> $mappe
     * @return array<string, mixed>|null
     */
    public static function trova(array $mappe, string $dir): ?array
    {
        $d = mb_strtolower($dir);
        $best = null;
        foreach ($mappe as $m) {
            if ($d === $m['chiave'] || str_starts_with($d, $m['chiave'] . '/')) {
                if ($best === null || strlen((string) $m['chiave']) > strlen((string) $best['chiave'])) {
                    $best = $m;
                }
            }
        }

        return $best;
    }

    /** Un anno o un anno accademico scritto come nome di cartella («2019», «2019-2020», «A.A. 2019/20»). */
    public static function eAnno(string $segmento): bool
    {
        return (bool) preg_match('#^(?:a\.?\s?a\.?\s*)?(?:19|20)\d{2}(?:\s*[-_/]\s*(?:19|20)?\d{2})?$#iu', $segmento);
    }

    /**
     * La cartella di un verbale con i suoi allegati («Organo/2025/Verb. CCL_02-10-2025»): la prima sottocartella che non è un anno sotto la cartella dell'organo.
     * Null se il PDF sta direttamente nella cartella dell'organo o in quella di un anno.
     */
    public static function cartellaVerbale(string $rel, string $cartellaOrgano): ?string
    {
        $dir = trim(dirname($rel) === '.' ? '' : dirname($rel), '/');
        $sotto = mb_strlen($dir) > mb_strlen($cartellaOrgano) ? trim(mb_substr($dir, mb_strlen($cartellaOrgano)), '/') : '';
        $percorso = [];
        foreach ($sotto === '' ? [] : explode('/', $sotto) as $seg) {
            $percorso[] = $seg;
            if (!self::eAnno($seg)) {
                return trim($cartellaOrgano . '/' . implode('/', $percorso), '/');
            }
        }

        return null;
    }

    /**
     * Il PDF del verbale tra i file di una cartella di verbale: il nome uguale a quello della cartella, poi uno che comincia per «verb» o contiene «verbale»,
     * altrimenti l'unico (o il primo per nome) che non comincia per «allegato». Null se ci sono solo allegati.
     *
     * @param list<string> $nomi i PDF direttamente nella cartella
     */
    public static function scegliVerbale(array $nomi, string $nomeCartella): ?string
    {
        $cand = array_values(array_filter($nomi, static fn (string $n): bool => !str_starts_with(mb_strtolower($n), 'allegat')));
        if (!$cand) {
            return null;
        }
        usort($cand, 'strnatcasecmp');
        $base = mb_strtolower($nomeCartella);
        foreach ($cand as $n) {
            if (mb_strtolower((string) pathinfo($n, PATHINFO_FILENAME)) === $base) {
                return $n;
            }
        }
        foreach ($cand as $n) {
            $l = mb_strtolower($n);
            if (str_starts_with($l, 'verb') || str_contains($l, 'verbale')) {
                return $n;
            }
        }

        return $cand[0];
    }

    /** La cartella senza le sottocartelle dell'anno («Biologia/Commissione didattica/2019» → «Biologia/Commissione didattica»). */
    public static function senzaAnni(string $dir): string
    {
        $p = $dir === '' ? [] : explode('/', $dir);
        while ($p && preg_match('#^(?:a\.?\s?a\.?\s*)?(?:19|20)\d{2}(?:\s*[-_/]\s*(?:19|20)?\d{2})?$#iu', (string) end($p))) {
            array_pop($p);
        }

        return implode('/', $p);
    }

    /** @return list<array{rel: string, abs: string, dim: int, mtime: int}> i PDF della cartella, in ordine di percorso */
    public function elenca(): array
    {
        $radice = $this->radice();
        if (!is_dir($radice)) {
            return [];
        }
        $out = [];
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($radice, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !preg_match('/^[.@#]|^~\$/u', $f->getFilename()) && !$f->isLink()
            ));
            foreach ($it as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'pdf') {
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($radice)));
                    $out[] = ['rel' => $rel, 'abs' => $f->getPathname(), 'dim' => (int) $f->getSize(), 'mtime' => (int) $f->getMTime()];
                }
            }
        } catch (\Throwable) {
            return [];
        }
        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['rel'], $b['rel']));

        return $out;
    }

    /**
     * Le cartelle con PDF che non appartengono a nessun organo, con quanti PDF contengono (senza leggere i file).
     *
     * @return array<string, int> cartella (senza anni) => numero di PDF; '' = file sparsi nella cartella principale
     */
    public function nonCollegate(): array
    {
        $mappe = $this->mappe();
        $out = [];
        foreach ($this->elenca() as $f) {
            $dir = ltrim(dirname($f['rel']), '.');
            $dir = $dir === '/' ? '' : trim($dir, '/');
            if (self::trova($mappe, $dir) === null) {
                $c = self::senzaAnni($dir);
                $out[$c] = ($out[$c] ?? 0) + 1;
            }
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * Una scansione: nuovi PDF, PDF cambiati o spostati, PDF spariti.
     *
     * @return array{trovati: int, nuovi: int, aggiornati: int, spostati: int, doppi: int, rimossi: int, restanti: int, non_collegati: array<string, int>, allegati: array<string, int>, allegati_aggiornati: int, senza_verbale: list<string>, errori: list<string>, avvisi: list<string>}
     */
    public function esegui(int $max = self::MAX_PER_VOLTA): array
    {
        $r = ['trovati' => 0, 'nuovi' => 0, 'aggiornati' => 0, 'spostati' => 0, 'doppi' => 0, 'rimossi' => 0, 'restanti' => 0, 'non_collegati' => [], 'allegati' => [], 'allegati_aggiornati' => 0, 'senza_verbale' => [], 'errori' => [], 'avvisi' => []];
        if (!$this->prepara()) {
            $r['errori'][] = 'La cartella ' . $this->cartella . 'archivio/ non esiste e non riesco a crearla (permessi? NAS non montato?).';

            return $r;
        }
        $mappe = $this->mappe();
        $file = $this->elenca();
        $r['trovati'] = count($file);
        $esistenti = $this->sedute->daCartella();
        $chiave = fn (string $rel): string => $this->cartella . 'archivio/' . $rel;
        $perCartella = [];
        foreach ($file as $f) {
            $d = dirname($f['rel']);
            $perCartella[$d === '.' ? '' : $d][] = basename($f['rel']);
        }
        $principali = [];
        $nonPiuVerbali = [];
        $presenti = [];
        foreach ($file as $f) {
            $presenti[$chiave($f['rel'])] = true;
        }
        $budget = $max;

        foreach ($file as $f) {
            $k = $chiave($f['rel']);
            if (strlen($k) > 255) {
                $r['errori'][] = 'Percorso troppo lungo (accorcia i nomi delle cartelle): ' . $f['rel'];
                continue;
            }
            $dir = ltrim(dirname($f['rel']), '.');
            $dir = $dir === '/' ? '' : trim($dir, '/');
            $m = self::trova($mappe, $dir);
            $firma = $f['dim'] . '-' . $f['mtime'];
            $riga = $esistenti[$k] ?? null;
            // cartella di un verbale con gli allegati: il PDF del verbale è uno solo, il resto (anche Excel e Word) sono i suoi allegati
            $cv = $m ? self::cartellaVerbale($f['rel'], (string) $m['cartella']) : null;
            $allegatiJson = null;
            if ($cv !== null) {
                $principali[$cv] ??= self::scegliVerbale($perCartella[$cv] ?? [], basename($cv));
                if ($principali[$cv] === null) {
                    $r['senza_verbale'][$cv] = $cv;
                } elseif (basename($f['rel']) === $principali[$cv] && dirname($f['rel']) === $cv) {
                    $allegatiJson = $this->allegatiDi($cv, $f['rel']);
                }
                if ($allegatiJson === null) {
                    if ($riga) {
                        $nonPiuVerbali[] = (int) $riga['id'];
                    }
                    continue;
                }
            } elseif ($m && str_starts_with(mb_strtolower(basename($f['rel'])), 'allegat')) {
                $c = self::senzaAnni($dir);
                $r['allegati'][$c] = ($r['allegati'][$c] ?? 0) + 1;
                if ($riga) {
                    $nonPiuVerbali[] = (int) $riga['id'];
                }
                continue;
            }
            if ($riga && $cv !== null && (string) $riga['allegati_json'] !== (string) $allegatiJson) {
                $this->sedute->aggiornaAllegati((int) $riga['id'], $allegatiJson);
                $r['allegati_aggiornati']++;
                $riga['allegati_json'] = $allegatiJson;
            }
            if ($riga && (string) $riga['cartella_firma'] === $firma) {
                continue;
            }
            if (!$riga && !$m) {
                $c = self::senzaAnni($dir);
                $r['non_collegati'][$c] = ($r['non_collegati'][$c] ?? 0) + 1;
                continue;
            }
            if ($f['dim'] <= 0) {
                $r['errori'][] = 'File vuoto: ' . $f['rel'];
                continue;
            }
            if ($f['dim'] > ArchivioVerbali::MAX_BYTE) {
                $r['errori'][] = 'Più grande di 30 MB (non importato): ' . $f['rel'];
                continue;
            }
            $pdf = @file_get_contents($f['abs']);
            if ($pdf === false || !str_starts_with(ltrim(substr($pdf, 0, 16)), '%PDF')) {
                $r['errori'][] = 'Non è un PDF leggibile: ' . $f['rel'];
                continue;
            }
            $hash = sha1($pdf);

            if ($riga) {
                // lo stesso file con data o dimensione diversa: se il contenuto è identico basta ricordare la nuova firma
                if ((string) $riga['file_hash'] === $hash) {
                    $this->sedute->aggiornaDaCartella((int) $riga['id'], $this->campi($riga, $k, $f, $hash, null, $firma, null));
                    continue;
                }
                if ($budget <= 0) {
                    $r['restanti']++;
                    continue;
                }
                $budget--;
                $this->sedute->aggiornaDaCartella((int) $riga['id'], $this->campi($riga, $k, $f, $hash, $this->leggi($pdf), $firma, null));
                $r['aggiornati']++;
                continue;
            }

            $gia = $this->sedute->perImpronta($hash);
            if ($gia) {
                $vecchio = null;
                foreach ($esistenti as $kk => $x) {
                    if ((int) $x['id'] === (int) $gia['id']) {
                        $vecchio = [$kk, $x];
                    }
                }
                if ($vecchio && !isset($presenti[$vecchio[0]])) {
                    // il file è stato spostato: il verbale resta lo stesso (con i dati già corretti), cambia il percorso e l'organo della nuova cartella
                    if ($budget <= 0) {
                        $r['restanti']++;
                        continue;
                    }
                    $budget--;
                    $this->sedute->aggiornaDaCartella((int) $gia['id'], $this->campi($vecchio[1], $k, $f, $hash, $this->leggi($pdf), $firma, $m));
                    unset($esistenti[$vecchio[0]]);
                    $esistenti[$k] = $vecchio[1];
                    $r['spostati']++;
                    continue;
                }
                $r['doppi']++;
                $r['avvisi'][] = 'Stesso contenuto già in archivio, non importato: ' . $f['rel'];
                continue;
            }
            if ($budget <= 0) {
                $r['restanti']++;
                continue;
            }
            $budget--;
            $testo = $this->leggi($pdf);
            $g = ArchivioVerbali::indovina($f['rel']);
            $data = $g['data'] ?? ArchivioVerbali::dataDa(mb_substr($testo, 0, 6000));
            $numero = $g['numero'] ?? ArchivioVerbali::numeroDa(mb_substr($testo, 0, 3000));
            $this->sedute->inserisciImportato([
                'organo' => $m['nome'], 'anno_accademico' => $g['anno_accademico'] ?? ($data ? ArchivioVerbali::annoAccademico($data) : ''), 'data' => $data,
                'consiglio_id' => $m['consiglio_id'], 'organo_id' => $m['organo_id'], 'allegati_json' => $allegatiJson, 'numero_verbale' => (string) $numero, 'verbale_pdf' => $k,
                'nome_originale' => mb_substr(basename($f['rel']), 0, 255), 'file_hash' => $hash, 'testo_ricerca' => $testo, 'da_cartella' => 1, 'cartella_firma' => $firma,
            ]);
            $r['nuovi']++;
        }

        // PDF che prima erano verbali e adesso risultano allegati (cartella del verbale con i suoi allegati): non sono più verbali
        $r['rimossi'] += $this->sedute->eliminaDaCartella($nonPiuVerbali);

        // verbali i cui file non ci sono più: si tolgono solo se la cartella è davvero raggiungibile (un NAS non montato sembra una cartella vuota)
        $spariti = [];
        foreach ($esistenti as $k => $x) {
            if (!isset($presenti[$k])) {
                $spariti[] = (int) $x['id'];
            }
        }
        if ($spariti) {
            if (!$file) {
                $r['avvisi'][] = 'La cartella risulta vuota: se è sul NAS controlla che sia montato. Non tolgo nulla dall\'archivio.';
            } elseif (count($spariti) > max(5, (int) floor(count($esistenti) / 2))) {
                $r['errori'][] = 'Sono spariti ' . count($spariti) . ' file su ' . count($esistenti) . ': non tolgo nulla (cartella spostata o NAS non montato?). Controlla e rilancia.';
            } else {
                $r['rimossi'] = $this->sedute->eliminaDaCartella($spariti);
            }
        }
        $r['non_collegati'] = $this->riordina($r['non_collegati']);
        $r['allegati'] = $this->riordina($r['allegati']);
        $r['senza_verbale'] = array_values($r['senza_verbale']);

        return $r;
    }

    private function fileEsito(): string
    {
        return $this->sito->radice() . '/cache/archivio_scansione.json';
    }

    private function filePausa(): string
    {
        return $this->sito->radice() . '/cache/archivio_scansione_pausa';
    }

    /** La scansione automatica (ogni ora) è sospesa? «Scansiona ora» funziona comunque. */
    public function inPausa(): bool
    {
        return is_file($this->filePausa());
    }

    public function pausa(bool $si): void
    {
        $dir = dirname($this->filePausa());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if ($si) {
            @file_put_contents($this->filePausa(), date('Y-m-d H:i:s'));
        } else {
            @unlink($this->filePausa());
        }
    }

    /** L'esito dell'ultima scansione (automatica o a mano): ['quando' => data e ora, 'esito' => risultato di esegui()], null se non è mai stata fatta. @return array<string, mixed>|null */
    public function ultimoEsito(): ?array
    {
        $j = is_file($this->fileEsito()) ? json_decode((string) @file_get_contents($this->fileEsito()), true) : null;

        return is_array($j) ? $j : null;
    }

    /** Una scansione che ricorda il suo esito (per la pagina dell'archivio). @return array<string, mixed> */
    public function eseguiERicorda(int $max = self::MAX_PER_VOLTA): array
    {
        $r = $this->esegui($max);
        $dir = dirname($this->fileEsito());
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            @file_put_contents($this->fileEsito(), json_encode(['quando' => date('Y-m-d H:i:s'), 'esito' => $r], JSON_UNESCAPED_UNICODE));
        }

        return $r;
    }

    /** Per il cron (ogni 15 minuti): scansiona al massimo una volta all'ora, o subito di nuovo se la volta prima non ha finito. Ritorna una riga di resoconto ('' se non c'è nulla da dire). */
    public function cron(): string
    {
        if ($this->inPausa()) {
            return '';
        }
        $u = $this->ultimoEsito();
        if ($u && strtotime((string) ($u['quando'] ?? '')) > time() - 3600 && (int) ($u['esito']['restanti'] ?? 0) === 0) {
            return '';
        }
        $r = $this->eseguiERicorda(60);
        $fatto = $r['nuovi'] + $r['aggiornati'] + $r['spostati'] + $r['rimossi'];
        if (!$fatto && !$r['errori']) {
            return '';
        }

        return '- Archivio verbali dalla cartella: ' . $r['nuovi'] . ' nuovi, ' . $r['aggiornati'] . ' aggiornati, ' . $r['spostati'] . ' spostati, ' . $r['rimossi'] . ' tolti'
            . ($r['restanti'] ? ', ' . $r['restanti'] . ' da leggere alla prossima volta' : '') . ($r['errori'] ? ' · ' . count($r['errori']) . ' errori (vedi la pagina dell\'archivio)' : '') . ".
";
    }

    /** @param array<string, int> $a @return array<string, int> */
    private function riordina(array $a): array
    {
        ksort($a, SORT_NATURAL | SORT_FLAG_CASE);

        return $a;
    }

    /**
     * Gli allegati di un verbale: tutti i file della sua cartella (anche nelle sottocartelle) tranne il PDF del verbale.
     *
     * @return string|null JSON [{p: percorso, n: nome, d: dimensione}], null se non ce ne sono
     */
    private function allegatiDi(string $cartellaVerbale, string $principaleRel): string
    {
        $base = $this->radice() . $cartellaVerbale;
        $out = [];
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !preg_match('/^[.@#]|^~\\$/u', $f->getFilename()) && !$f->isLink()
            ));
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($this->radice())));
                if ($rel === $principaleRel) {
                    continue;
                }
                $out[] = ['p' => $this->cartella . 'archivio/' . $rel, 'n' => substr($rel, strlen($cartellaVerbale) + 1), 'd' => (int) $f->getSize()];
            }
        } catch (\Throwable) {
            return '';
        }
        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['n'], $b['n']));

        return $out ? (string) json_encode(array_slice($out, 0, 100), JSON_UNESCAPED_UNICODE) : '';
    }

    private function leggi(string $pdf): string
    {
        return strlen($pdf) <= ArchivioVerbali::MAX_BYTE_TESTO ? $this->testo->estrai($pdf) : '';
    }

    /**
     * I campi da scrivere per un verbale già in archivio la cui file è cambiato o spostato.
     *
     * @param array<string, mixed> $riga
     * @param array{rel: string} $f
     * @param array<string, mixed>|null $nuovoOrgano
     * @return array<string, mixed>
     */
    private function campi(array $riga, string $percorso, array $f, string $hash, ?string $testo, string $firma, ?array $nuovoOrgano): array
    {
        return [
            'verbale_pdf' => $percorso, 'nome_originale' => mb_substr(basename($f['rel']), 0, 255), 'file_hash' => $hash,
            'testo_ricerca' => $testo ?? (string) ($this->sedute->testoRicerca((int) $riga['id']) ?? ''), 'cartella_firma' => $firma,
            'organo' => $nuovoOrgano['nome'] ?? (string) $riga['organo'], 'consiglio_id' => $nuovoOrgano ? $nuovoOrgano['consiglio_id'] : $riga['consiglio_id'],
            'organo_id' => $nuovoOrgano ? $nuovoOrgano['organo_id'] : $riga['organo_id'],
        ];
    }
}
