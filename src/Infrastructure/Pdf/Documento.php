<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

/**
 * PDF generati senza librerie esterne (lettere di incarico, estratti, domande, verbali): pagine A4 con logo in testa,
 * paragrafi giustificati con **grassetto**, tabelle, riquadri, spazi per le firme PAdES e numero di pagina.
 * Caratteri standard del PDF (Times, Helvetica) con codifica WinAnsi; con 'pdfa' => true il documento è PDF/A-2b
 * (caratteri Liberation incorporati in sottoinsieme, XMP, profilo sRGB): vedi il costruttore.
 * Spostata da inc/pdf.php (dove resta il vecchio nome PdfSemplice come alias): Step 1 di MIGRATION_PLAN.md.
 */
final class Documento
{
    // Larghezze dei caratteri 32-255 (WinAnsi) in millesimi di em
    public const LARGHEZZE = [
    'Times-Roman' => [250,333,408,500,500,833,778,180,333,333,500,564,250,333,250,278,500,500,500,500,500,500,500,500,500,500,278,278,564,564,564,444,921,722,667,667,722,611,556,722,722,333,389,722,611,889,722,722,556,722,667,556,611,722,722,944,722,722,611,333,278,333,469,500,333,444,500,444,500,444,333,500,500,278,278,500,278,778,500,500,500,500,333,389,278,500,500,722,500,500,444,480,200,480,541,761,500,0,333,500,444,1000,500,500,333,1000,556,333,889,0,611,0,0,333,333,444,444,350,500,1000,333,980,389,333,722,0,444,722,250,333,500,500,500,500,200,500,333,760,276,500,564,333,760,333,400,564,300,300,333,500,453,250,333,300,310,500,750,750,750,444,722,722,722,722,722,722,889,667,611,611,611,611,333,333,333,333,722,722,722,722,722,722,722,564,722,722,722,722,722,722,556,500,444,444,444,444,444,444,667,444,444,444,444,444,278,278,278,278,500,500,500,500,500,500,500,564,500,500,500,500,500,500,500,500],
    'Times-Bold' => [250,333,555,500,500,1000,833,278,333,333,500,570,250,333,250,278,500,500,500,500,500,500,500,500,500,500,333,333,570,570,570,500,930,722,667,722,722,667,611,778,778,389,500,778,667,944,722,778,611,778,722,556,667,722,722,1000,722,722,667,333,278,333,581,500,333,500,556,444,556,444,333,500,556,278,333,556,278,833,556,500,556,556,444,389,333,556,500,722,500,500,444,394,220,394,520,761,500,0,333,500,500,1000,500,500,333,1000,556,333,1000,0,667,0,0,333,333,500,500,350,500,1000,333,1000,389,333,722,0,444,722,250,333,500,500,500,500,220,500,333,747,300,500,570,333,747,333,400,570,300,300,333,556,540,250,333,300,330,500,750,750,750,500,722,722,722,722,722,722,1000,722,667,667,667,667,389,389,389,389,722,722,778,778,778,778,778,570,778,722,722,722,722,722,611,556,500,500,500,500,500,500,722,444,444,444,444,444,278,278,278,278,500,556,500,500,500,500,500,570,500,556,556,556,556,500,556,500],
    'Times-Italic' => [250,333,420,500,500,833,778,214,333,333,500,675,250,333,250,278,500,500,500,500,500,500,500,500,500,500,333,333,675,675,675,500,920,611,611,667,722,611,611,722,722,333,444,667,556,833,667,722,611,722,611,500,556,722,611,833,611,556,556,389,278,389,422,500,333,500,500,444,500,444,278,500,500,278,278,444,278,722,500,500,500,500,389,389,278,500,444,667,444,444,389,400,275,400,541,761,500,0,333,500,556,889,500,500,333,1000,500,333,944,0,556,0,0,333,333,556,556,350,500,889,333,980,389,333,667,0,389,556,250,389,500,500,500,500,275,500,333,760,276,500,675,333,760,333,400,675,300,300,333,500,523,250,333,300,310,500,750,750,750,500,611,611,611,611,611,611,889,667,611,611,611,611,333,333,333,333,722,667,722,722,722,722,722,675,722,722,722,722,722,556,611,500,500,500,500,500,500,500,667,444,444,444,444,444,278,278,278,278,500,500,500,500,500,500,500,675,500,500,500,500,500,444,500,444],
    'Helvetica' => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500],
    'Helvetica-Bold' => [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556],
    ];
    public const FONT = ['R' => 'Times-Roman', 'B' => 'Times-Bold', 'I' => 'Times-Italic', 'H' => 'Helvetica', 'HB' => 'Helvetica-Bold'];
    // PDF/A: gli stessi caratteri in TrueType da incorporare
    public const FONT_TTF = ['R' => 'LiberationSerif-Regular', 'B' => 'LiberationSerif-Bold', 'I' => 'LiberationSerif-Italic', 'H' => 'LiberationSans-Regular', 'HB' => 'LiberationSans-Bold'];
    public float $larg = 595.28;
    public float $alt = 841.89;
    public float $sx = 62;
    public float $dx = 62;
    public float $alto = 60;
    public float $basso = 60;
    public array $pagine = [];        // contenuto di ogni pagina
    public float $y = 0;              // posizione corrente (dall'alto)
    public array $segnaposti = [];    // posizioni con nome (es. riquadri delle firme): [nome => [pagina, x, y, l, a]] in punti PDF
    private ?array $logo = null;      // [dati JPEG, larghezza, altezza, componenti]
    private float $logo_l = 0;
    private float $logo_a = 0;
    private ?array $logo2 = null;     // secondo logo allineato a destra (es. la scuola): [dati JPEG, larghezza, altezza, componenti]
    private float $logo2_l = 0;
    private float $logo2_a = 0;
    private string $logo_pos = 'centro'; // 'centro' o 'sinistra' (allineato al margine del testo)
    private string $piede = '';
    private array $info = [];
    private bool $pdfa = false;
    private array $xmp = [];          // PDF/A: metadati propri [nome => valore] (namespace del portale)
    private string $lingua = 'it-IT';
    private array $ttf = [];          // PDF/A: [chiave font => TrueType]
    private array $usati = [];        // PDF/A: [chiave font => [codice WinAnsi => true]]

    // $o: logo, logo_larghezza, logo_pos ('sinistra'), logo2 (JPEG a destra), logo2_larghezza, piede, info (Title, Author, Subject, Keywords, Creator), pdfa (bool), xmp ([nome => valore]), lingua
    public function __construct(array $o = [])
    {
        if (!empty($o['logo']) && is_file($o['logo']) && ($im = @getimagesize($o['logo'])) && $im[2] === IMAGETYPE_JPEG) {
            $this->logo = [file_get_contents($o['logo']), (int)$im[0], (int)$im[1], (int)($im['channels'] ?? 3)];
            $this->logo_l = (float)($o['logo_larghezza'] ?? 200);
            $this->logo_a = $this->logo_l * $im[1] / max(1, $im[0]);
            $this->logo_pos = ($o['logo_pos'] ?? '') === 'sinistra' ? 'sinistra' : 'centro';
        }
        if (!empty($o['logo2']) && is_file($o['logo2']) && ($im2 = @getimagesize($o['logo2'])) && $im2[2] === IMAGETYPE_JPEG) {
            $this->logo2 = [file_get_contents($o['logo2']), (int)$im2[0], (int)$im2[1], (int)($im2['channels'] ?? 3)];
            $this->logo2_l = (float)($o['logo2_larghezza'] ?? 110);
            $this->logo2_a = $this->logo2_l * $im2[1] / max(1, $im2[0]);
            if ($this->logo2_a > 60) {
                $this->logo2_l *= 60 / $this->logo2_a;
                $this->logo2_a = 60;
            }
        }
        $this->piede = (string)($o['piede'] ?? '');
        $this->info = $o['info'] ?? [];
        $this->lingua = (string)($o['lingua'] ?? 'it-IT');
        if (!empty($o['pdfa'])) {
            foreach (self::FONT_TTF as $k => $f) {
                $file = RADICE_SITO . '/assets/fonts/' . $f . '.ttf';
                if (!is_file($file)) {
                    $this->ttf = [];
                    break;
                }
                $this->ttf[$k] = new TrueType($file);
            }
            $this->pdfa = (bool)$this->ttf && is_file(RADICE_SITO . '/assets/modelli/sRGB.icc');
            if (!$this->pdfa) {
                $this->ttf = [];
            }
            $this->xmp = $o['xmp'] ?? [];
        }
        $this->nuovaPagina();
    }

    // Testo UTF-8 → WinAnsi (i caratteri che non esistono diventano la lettera più vicina)
    public static function ansi(string $s): string
    {
        $s = strtr($s, ["\u{2028}" => ' ', "\u{00A0}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ']);
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $r === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $s) : $r;
    }
    public static function larghezza(string $ansi, string $f, float $sz): float
    {
        $w = 0;
        $t = self::LARGHEZZE[self::FONT[$f]];
        for ($i = 0, $n = strlen($ansi); $i < $n; $i++) {
            $c = ord($ansi[$i]);
            $w += $c >= 32 ? $t[$c - 32] : 0;
        }
        return $w * $sz / 1000;
    }
    // Larghezza con le misure del carattere usato davvero (PDF/A: quelle del TrueType incorporato)
    private function lw(string $ansi, string $f, float $sz): float
    {
        if (!$this->ttf) {
            return self::larghezza($ansi, $f, $sz);
        }
        $w = 0;
        $t = $this->ttf[$f]->larghezzeWinAnsi();
        for ($i = 0, $n = strlen($ansi); $i < $n; $i++) {
            $c = ord($ansi[$i]);
            $w += $c >= 32 ? $t[$c - 32] : 0;
        }
        return $w * $sz / 1000;
    }
    // Testo scritto in un carattere (PDF/A: lettere da tenere nel sottoinsieme incorporato)
    private function tj(string $f, string $ansi): string
    {
        if ($this->ttf) {
            for ($i = 0, $n = strlen($ansi); $i < $n; $i++) {
                $this->usati[$f][ord($ansi[$i])] = true;
            }
        }
        return '(' . self::esc($ansi) . ') Tj';
    }
    private static function esc(string $ansi): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $ansi);
    }
    private function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
    private function out(string $s): void
    {
        $this->pagine[count($this->pagine) - 1] .= $s . "\n";
    }
    public function pagina(): int
    {
        return count($this->pagine);
    }

    public function nuovaPagina(): void
    {
        $this->pagine[] = '';
        $this->y = $this->alto;
        if ($this->logo) {
            $x = $this->logo_pos === 'sinistra' ? $this->sx : ($this->larg - $this->logo_l) / 2;
            $this->out('q ' . $this->n($this->logo_l) . ' 0 0 ' . $this->n($this->logo_a) . ' ' . $this->n($x) . ' ' . $this->n($this->alt - 28 - $this->logo_a) . ' cm /Logo Do Q');
            $this->y = 28 + $this->logo_a + 22;
        }
        if ($this->logo2) {
            $this->out('q ' . $this->n($this->logo2_l) . ' 0 0 ' . $this->n($this->logo2_a) . ' ' . $this->n($this->larg - $this->dx - $this->logo2_l) . ' ' . $this->n($this->alt - 28 - $this->logo2_a) . ' cm /Logo2 Do Q');
            $this->y = max($this->y, 28 + $this->logo2_a + 22);
        }
    }
    public function spazio(float $pt): void
    {
        $this->y += $pt;
    }
    public function serve(float $pt): void
    {
        if ($this->y + $pt > $this->alt - $this->basso) {
            $this->nuovaPagina();
        }
    }

    // Parole di un testo con **grassetto**: [[testo ANSI, font], ...]; "\n" = a capo
    private function parole(string $testo, string $f, string $fb): array
    {
        $out = [];
        $b = false;
        foreach (preg_split('/(\*\*|\n)/', $testo, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $pz) {
            if ($pz === '**') {
                $b = !$b;
                continue;
            }
            if ($pz === "\n") {
                $out[] = ["\n", $f];
                continue;
            }
            foreach (preg_split('/ +/', $pz) ?: [] as $i => $w) {
                if ($i > 0) {
                    $out[] = [' ', $b ? $fb : $f];
                }
                if ($w !== '') {
                    $out[] = [self::ansi($w), $b ? $fb : $f];
                }
            }
        }
        return $out;
    }
    // Righe di un testo in una larghezza: [[[parola, font, x], ...], larghezza, ultima?]
    private function righe(string $testo, float $l, float $sz, string $f, string $fb): array
    {
        $righe = [];
        /** @var list<array{string, string, float|int}> $riga parole della riga in corso: [parola, font, x] (scritta dal closure $chiudi) */
        $riga = [];
        $x = 0;
        $sp = 0;
        $chiudi = function (bool $ultima) use (&$righe, &$riga, &$x) {
            while ($riga && end($riga)[0] === ' ') {
                array_pop($riga);
            } $righe[] = [$riga, $x, $ultima];
            $riga = [];
            $x = 0;
        };
        foreach ($this->parole($testo, $f, $fb) as [$w, $fw]) {
            if ($w === "\n") {
                $chiudi(true);
                continue;
            }
            $lw = $this->lw($w, $fw, $sz);
            if ($w === ' ') {
                if ($riga) {
                    $riga[] = [' ', $fw, $x];
                    $x += $lw;
                } continue;
            }
            if ($x + $lw > $l && $riga) {
                while ($riga && end($riga)[0] === ' ') {
                    $x -= $this->lw(' ', end($riga)[1], $sz);
                    array_pop($riga);
                }
                $chiudi(false);
            }
            $riga[] = [$w, $fw, $x];
            $x += $lw;
        }
        if ($riga || !$righe) {
            $chiudi(true);
        }
        return $righe;
    }
    // Scrive le righe a partire da (x, y dall'alto)
    private function scriviRighe(array $righe, float $x0, float $l, float $sz, string $al, float $interl): void
    {
        foreach ($righe as [$parole, $lr, $ultima]) {
            $this->serve($interl);
            $y = $this->alt - $this->y - $sz * 0.8;
            $spazi = count(array_filter($parole, fn ($p) => $p[0] === ' '));
            $extra = ($al === 'giustificato' && !$ultima && $spazi) ? ($l - $lr) / $spazi : 0;
            $off = $al === 'centro' ? ($l - $lr) / 2 : ($al === 'destra' ? $l - $lr : 0);
            $n_sp = 0;
            foreach ($parole as [$w, $fw, $xw]) {
                if ($w === ' ') {
                    $n_sp++;
                    continue;
                }
                $this->out('BT /' . $fw . ' ' . $this->n($sz) . ' Tf ' . $this->n($x0 + $off + $xw + $extra * $n_sp) . ' ' . $this->n($y) . ' Td ' . $this->tj($fw, $w) . ' ET');
            }
            $this->y += $interl;
        }
    }

    // Paragrafo. $o: sz (11), font R/H, al (sinistra|centro|destra|giustificato), prima, dopo, rientro (pt da sinistra), interlinea
    public function paragrafo(string $testo, array $o = []): void
    {
        $sz = (float)($o['sz'] ?? 11);
        $f = $o['font'] ?? 'R';
        $fb = $f === 'H' ? 'HB' : 'B';
        if (!empty($o['b'])) {
            $f = $fb;
        } if (!empty($o['i'])) {
            $f = 'I';
        }
        $this->y += (float)($o['prima'] ?? 0);
        $x0 = $this->sx + (float)($o['rientro'] ?? 0);
        $l = $this->larg - $this->dx - $x0;
        $this->scriviRighe($this->righe($testo, $l, $sz, $f, $fb), $x0, $l, $sz, $o['al'] ?? 'giustificato', $sz * (float)($o['interlinea'] ?? 1.3));
        $this->y += (float)($o['dopo'] ?? 6);
    }

    // Tabella con bordi; $larghezze in proporzione; intestazione in grassetto su grigio
    public function tabella(array $intest, array $righe, array $o = []): void
    {
        $sz = (float)($o['sz'] ?? 10);
        $pad = 4;
        $l_tot = $this->larg - $this->sx - $this->dx;
        $n = max(count($intest), ...array_map('count', $righe ?: [[]]));
        $pesi = $o['larghezze'] ?? array_fill(0, $n, 1);
        $larg = array_map(fn ($p) => $l_tot * $p / array_sum($pesi), $pesi);
        $tutte = $intest ? array_merge([$intest], $righe) : $righe;
        foreach ($tutte as $i => $riga) {
            $testa = $intest && $i === 0;
            $f = $testa ? 'B' : 'R';
            $celle = [];
            $h = 0;
            for ($c = 0; $c < $n; $c++) {
                $r = $this->righe((string)($riga[$c] ?? ''), $larg[$c] - 2 * $pad, $sz, $f, 'B');
                $celle[] = $r;
                $h = max($h, count($r) * $sz * 1.25 + 2 * $pad);
            }
            $this->serve($h);
            $top = $this->y;
            $x = $this->sx;
            for ($c = 0; $c < $n; $c++) {
                $yb = $this->alt - $top - $h;
                if ($testa) {
                    $this->out('q 0.91 0.91 0.91 rg ' . $this->n($x) . ' ' . $this->n($yb) . ' ' . $this->n($larg[$c]) . ' ' . $this->n($h) . ' re f Q');
                }
                $this->out('q 0.5 G 0.5 w ' . $this->n($x) . ' ' . $this->n($yb) . ' ' . $this->n($larg[$c]) . ' ' . $this->n($h) . ' re S Q');
                $this->y = $top + $pad;
                $this->scriviRighe($celle[$c], $x + $pad, $larg[$c] - 2 * $pad, $sz, $testa ? 'centro' : ($o['al'][$c] ?? 'sinistra'), $sz * 1.25);
                $x += $larg[$c];
            }
            $this->y = $top + $h;
        }
        $this->y += (float)($o['dopo'] ?? 10);
    }

    // Riquadro con bordo e sfondo (es. conferma con SPID/CIE). $o: sz, colore (r g b 0-1), font
    public function riquadro(string $testo, array $o = []): void
    {
        $sz = (float)($o['sz'] ?? 8.5);
        $pad = 6;
        $l = $this->larg - $this->sx - $this->dx;
        $f = $o['font'] ?? 'H';
        $righe = $this->righe($testo, $l - 2 * $pad, $sz, $f, $f === 'H' ? 'HB' : 'B');
        $h = count($righe) * $sz * 1.3 + 2 * $pad;
        $this->serve($h);
        $yb = $this->alt - $this->y - $h;
        $this->out('q ' . ($o['sfondo'] ?? '0.94 0.97 1') . ' rg ' . ($o['bordo'] ?? '0 0.34 0.7') . ' RG 0.8 w ' . $this->n($this->sx) . ' ' . $this->n($yb) . ' ' . $this->n($l) . ' ' . $this->n($h) . ' re B Q');
        $top = $this->y;
        $this->y += $pad;
        $this->scriviRighe($righe, $this->sx + $pad, $l - 2 * $pad, $sz, 'sinistra', $sz * 1.3);
        $this->y = $top + $h + (float)($o['dopo'] ?? 8);
    }

    // Spazio con nome per una firma visibile (Aruba lo usa per l'aspetto della firma PAdES): titolo, riga, nome
    public function spazioFirma(string $nome, string $titolo, string $sotto, float $x, float $l, float $h = 62): void
    {
        $top = $this->y;
        // Titolo in uno spazio fisso di 3 righe: le righe delle firme affiancate restano allineate
        $this->y = $top;
        $this->scriviRighe($this->righe($titolo, $l, 10, 'B', 'B'), $x, $l, 10, 'centro', 13);
        $this->y = $top + 39;
        $this->segnaposti[$nome] = ['pagina' => $this->pagina(), 'x' => $x, 'y' => $this->alt - $this->y - $h, 'l' => $l, 'a' => $h];
        $this->y += $h;
        $this->out('q 0.6 G 0.5 w ' . $this->n($x + 10) . ' ' . $this->n($this->alt - $this->y) . ' m ' . $this->n($x + $l - 10) . ' ' . $this->n($this->alt - $this->y) . ' l S Q');
        $this->y += 3;
        if ($sotto !== '') {
            $this->scriviRighe($this->righe($sotto, $l, 9, 'R', 'B'), $x, $l, 9, 'centro', 11.5);
        }
    }

    // PDF finale (stringa)
    public function pdf(): string
    {
        $obj = []; // numero => contenuto
        $np = count($this->pagine);
        $adesso = time();
        // Piè di pagina centrato, su due righe se è troppo lungo, con il numero di pagina (scritto prima: servono le sue lettere)
        foreach ($this->pagine as $i => &$c) {
            if ($this->piede === '' && $np < 2) {
                continue;
            }
            $t = self::ansi(trim($this->piede . ($np > 1 ? ' – pag. ' . ($i + 1) . ' di ' . $np : '')));
            $max = $this->larg - $this->sx - $this->dx;
            $righe_p = [$t];
            if ($this->lw($t, 'H', 7) > $max && ($k = strrpos(substr($t, 0, (int)(strlen($t) / 2) + 20), self::ansi(' – '))) !== false) {
                $righe_p = [substr($t, 0, $k), substr($t, $k + strlen(self::ansi(' – ')))];
            }
            foreach ($righe_p as $j => $rp) {
                $c .= 'BT /H 7 Tf ' . $this->n(($this->larg - $this->lw($rp, 'H', 7)) / 2) . ' ' . (count($righe_p) > 1 && $j === 0 ? 36 : 27) . ' Td ' . $this->tj('H', $rp) . " ET\n";
            }
        }
        unset($c);
        $id = 3;
        $font_ids = [];
        foreach (self::FONT as $k => $nome) {
            if (!$this->ttf) {
                $font_ids[$k] = $id;
                $obj[$id++] = "<< /Type /Font /Subtype /Type1 /BaseFont /$nome /Encoding /WinAnsiEncoding >>";
                continue;
            }
            if (empty($this->usati[$k])) {
                continue;
            }
            // PDF/A: TrueType incorporato con le sole lettere usate (sottoinsieme con prefisso di 6 lettere)
            $f = $this->ttf[$k];
            $prefisso = '';
            $hh = md5($k . implode(',', array_keys($this->usati[$k])));
            for ($i = 0; $i < 6; $i++) {
                $prefisso .= chr(65 + hexdec($hh[$i]) % 26);
            }
            $nome_ps = $prefisso . '+' . $f->nomePs();
            $prog = $f->sottoinsieme(array_keys($this->usati[$k]));
            $z = gzcompress($prog, 6);
            $font_ids[$k] = $id;
            $obj[$id] = "<< /Type /Font /Subtype /TrueType /BaseFont /$nome_ps /FirstChar 32 /LastChar 255 /Widths [" . implode(' ', $f->larghezzeWinAnsi()) . "] /Encoding /WinAnsiEncoding /FontDescriptor " . ($id + 1) . " 0 R >>";
            $obj[$id + 1] = "<< /Type /FontDescriptor /FontName /$nome_ps " . $f->descrittore() . " /FontFile2 " . ($id + 2) . " 0 R >>";
            $obj[$id + 2] = '<< /Length ' . strlen((string) $z) . ' /Length1 ' . strlen($prog) . " /Filter /FlateDecode >>\nstream\n" . (string) $z . "\nendstream";
            $id += 3;
        }
        $id_logo = null;
        if ($this->logo) {
            $id_logo = $id++;
            $cs = $this->logo[3] === 1 ? '/DeviceGray' : ($this->logo[3] === 4 ? '/DeviceCMYK' : '/DeviceRGB');
            $obj[$id_logo] = "<< /Type /XObject /Subtype /Image /Width {$this->logo[1]} /Height {$this->logo[2]} /ColorSpace $cs /BitsPerComponent 8 /Filter /DCTDecode"
                            . ($this->logo[3] === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : '') . " /Length " . strlen($this->logo[0]) . " >>\nstream\n" . $this->logo[0] . "\nendstream";
        }
        $id_logo2 = null;
        if ($this->logo2) {
            $id_logo2 = $id++;
            $cs2 = $this->logo2[3] === 1 ? '/DeviceGray' : ($this->logo2[3] === 4 ? '/DeviceCMYK' : '/DeviceRGB');
            $obj[$id_logo2] = "<< /Type /XObject /Subtype /Image /Width {$this->logo2[1]} /Height {$this->logo2[2]} /ColorSpace $cs2 /BitsPerComponent 8 /Filter /DCTDecode"
                            . ($this->logo2[3] === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : '') . " /Length " . strlen($this->logo2[0]) . " >>
stream
" . $this->logo2[0] . "
endstream";
        }
        $risorse = '<< /Font << ' . implode(' ', array_map(fn ($k, $i) => "/$k $i 0 R", array_keys($font_ids), $font_ids)) . ' >>' . (($id_logo || $id_logo2) ? ' /XObject << ' . ($id_logo ? "/Logo $id_logo 0 R " : '') . ($id_logo2 ? "/Logo2 $id_logo2 0 R " : '') . '>>' : '') . ' >>';
        $kids = [];
        foreach ($this->pagine as $c) {
            $z = function_exists('gzcompress') ? gzcompress($c, 6) : null;
            $obj[$id] = '<< /Length ' . strlen($z ?? $c) . ($z !== null ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . ($z ?? $c) . "\nendstream";
            $obj[$id + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->n($this->larg) . ' ' . $this->n($this->alt) . "] /Resources $risorse /Contents $id 0 R >>";
            $kids[] = ($id + 1) . ' 0 R';
            $id += 2;
        }
        $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count $np >>";
        // Informazioni del documento: testo in UTF-16 (accenti e simboli senza perdite), date con il fuso orario
        $tz = date('P', $adesso);
        $data_pdf = 'D:' . date('YmdHis', $adesso) . str_replace(':', "'", $tz) . "'";
        $info = array_filter($this->info + ['Creator' => 'Didattica DiBEST'], fn ($v) => trim((string)$v) !== '') + ['Producer' => 'Didattica DiBEST'];
        $inf = '';
        foreach ($info as $k => $v) {
            $inf .= '/' . preg_replace('/[^A-Za-z]/', '', $k) . ' <FEFF' . strtoupper(bin2hex(mb_convert_encoding((string)$v, 'UTF-16BE', 'UTF-8'))) . '> ';
        }
        $inf .= "/CreationDate ($data_pdf) /ModDate ($data_pdf) ";
        $id_info = $id++;
        $obj[$id_info] = "<< $inf>>";
        $catalogo = '/Type /Catalog /Pages 2 0 R';
        if ($this->pdfa) {
            $id_xmp = $id++;
            $id_icc = $id++;
            $xmp = $this->xmpPdfa($info, date('Y-m-d\TH:i:s', $adesso) . $tz);
            $obj[$id_xmp] = '<< /Type /Metadata /Subtype /XML /Length ' . strlen($xmp) . " >>\nstream\n" . $xmp . "\nendstream";
            $icc = (string)file_get_contents(RADICE_SITO . '/assets/modelli/sRGB.icc');
            $obj[$id_icc] = '<< /N 3 /Length ' . strlen($icc) . " >>\nstream\n" . $icc . "\nendstream";
            $catalogo .= " /Metadata $id_xmp 0 R /Lang (" . self::esc($this->lingua) . ") /OutputIntents [<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /Info (sRGB IEC61966-2.1) /RegistryName (http://www.color.org) /DestOutputProfile $id_icc 0 R >>]";
        }
        $obj[1] = "<< $catalogo >>";
        ksort($obj);
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $xref = [];
        foreach ($obj as $k => $v) {
            $xref[$k] = strlen($pdf);
            $pdf .= "$k 0 obj\n$v\nendobj\n";
        }
        $start = strlen($pdf);
        $tot = max(array_keys($obj)) + 1;
        $pdf .= "xref\n0 $tot\n0000000000 65535 f \n";
        for ($k = 1; $k < $tot; $k++) {
            $pdf .= isset($xref[$k]) ? sprintf("%010d 00000 n \n", $xref[$k]) : "0000000000 65535 f \n";
        }
        $idf = md5($pdf);
        $pdf .= "trailer\n<< /Size $tot /Root 1 0 R /Info $id_info 0 R /ID [<$idf> <$idf>] >>\nstartxref\n$start\n%%EOF\n";
        return $pdf;
    }

    // Metadati XMP per PDF/A-2b: gli stessi delle informazioni del documento, più quelli propri del portale
    // (namespace https://didattica.dibest.unical.it/ns/pratica/, dichiarato con lo schema di estensione PDF/A)
    private function xmpPdfa(array $info, string $data): string
    {
        $x = fn ($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $ns = 'https://didattica.dibest.unical.it/ns/pratica/';
        $propri = [];
        foreach ($this->xmp as $k => $v) {
            if (($k = (string) preg_replace('/[^A-Za-z0-9]/', '', (string)$k)) !== '' && preg_match('/^[A-Za-z]/', $k)) {
                $propri[$k] = (string)$v;
            }
        }
        $d = "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
           . "<rdf:Description rdf:about=\"\" xmlns:pdfaid=\"http://www.aiim.org/pdfa/ns/id/\"><pdfaid:part>2</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance></rdf:Description>\n"
           . "<rdf:Description rdf:about=\"\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\"><dc:format>application/pdf</dc:format>"
           . (isset($info['Title']) ? '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $x($info['Title']) . '</rdf:li></rdf:Alt></dc:title>' : '')
           . (isset($info['Author']) ? '<dc:creator><rdf:Seq><rdf:li>' . $x($info['Author']) . '</rdf:li></rdf:Seq></dc:creator>' : '')
           . (isset($info['Subject']) ? '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . $x($info['Subject']) . '</rdf:li></rdf:Alt></dc:description>' : '')
           . '<dc:language><rdf:Bag><rdf:li>' . $x($this->lingua) . "</rdf:li></rdf:Bag></dc:language></rdf:Description>\n"
           . "<rdf:Description rdf:about=\"\" xmlns:pdf=\"http://ns.adobe.com/pdf/1.3/\"><pdf:Producer>" . $x($info['Producer']) . '</pdf:Producer>'
           . (isset($info['Keywords']) ? '<pdf:Keywords>' . $x($info['Keywords']) . '</pdf:Keywords>' : '') . "</rdf:Description>\n"
           . "<rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\"><xmp:CreatorTool>" . $x($info['Creator']) . "</xmp:CreatorTool><xmp:CreateDate>$data</xmp:CreateDate><xmp:ModifyDate>$data</xmp:ModifyDate><xmp:MetadataDate>$data</xmp:MetadataDate></rdf:Description>\n";
        if ($propri) {
            $d .= "<rdf:Description rdf:about=\"\" xmlns:pdfaExtension=\"http://www.aiim.org/pdfa/ns/extension/\" xmlns:pdfaSchema=\"http://www.aiim.org/pdfa/ns/schema#\" xmlns:pdfaProperty=\"http://www.aiim.org/pdfa/ns/property#\">"
                . "<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType=\"Resource\"><pdfaSchema:schema>Pratica del portale Didattica DiBEST</pdfaSchema:schema><pdfaSchema:namespaceURI>$ns</pdfaSchema:namespaceURI><pdfaSchema:prefix>dibest</pdfaSchema:prefix><pdfaSchema:property><rdf:Seq>";
            foreach ($propri as $k => $v) {
                $d .= "<rdf:li rdf:parseType=\"Resource\"><pdfaProperty:name>$k</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>$k</pdfaProperty:description></rdf:li>";
            }
            $d .= "</rdf:Seq></pdfaSchema:property></rdf:li></rdf:Bag></pdfaExtension:schemas></rdf:Description>\n<rdf:Description rdf:about=\"\" xmlns:dibest=\"$ns\">";
            foreach ($propri as $k => $v) {
                $d .= "<dibest:$k>" . $x($v) . "</dibest:$k>";
            }
            $d .= "</rdf:Description>\n";
        }
        return $d . "</rdf:RDF>\n</x:xmpmeta>\n" . str_repeat(str_repeat(' ', 99) . "\n", 10) . '<?xpacket end="w"?>';
    }
}
