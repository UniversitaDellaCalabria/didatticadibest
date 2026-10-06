<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

/**
 * Carattere TrueType per il PDF/A: misure per la codifica WinAnsi, descrittore e sottoinsieme con le sole lettere usate.
 * Spostata da inc/pdf.php (dove resta il vecchio nome TtfSemplice come alias): Step 1 di MIGRATION_PLAN.md.
 */
// Carattere TrueType per il PDF/A: misure (per la codifica WinAnsi), descrittore e sottoinsieme con le sole lettere usate
// (le altre restano nel file ma vuote: stesso numero di glifi, nessun cambio di indici)
final class TrueType
{
    private string $d;
    private array $tab = [];      // nome => [offset, lunghezza]
    private int $upm = 1000;
    private int $n_glifi = 0;
    private int $formato_loca = 0;
    private array $cmap = [];     // codice Unicode => glifo
    private array $adv = [];      // glifo => larghezza (unità del carattere)
    private ?array $winansi = null;

    public function __construct(string $file)
    {
        $this->d = (string)file_get_contents($file);
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + 16 * $i;
            $this->tab[substr($this->d, $o, 4)] = [$this->u32($o + 8), $this->u32($o + 12)];
        }
        $h = $this->tab['head'][0];
        $this->upm = $this->u16($h + 18);
        $this->formato_loca = $this->s16($h + 50);
        $this->n_glifi = $this->u16($this->tab['maxp'][0] + 4);
        $n_hm = $this->u16($this->tab['hhea'][0] + 34);
        $hm = $this->tab['hmtx'][0];
        for ($g = 0; $g < $this->n_glifi; $g++) {
            $this->adv[$g] = $this->u16($hm + 4 * min($g, $n_hm - 1));
        }
        $this->leggiCmap();
    }
    private function u16(int $o): int
    {
        return (unpack('n', $this->d, $o) ?: [1 => 0])[1];
    }
    private function s16(int $o): int
    {
        $v = $this->u16($o);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }
    private function u32(int $o): int
    {
        return (unpack('N', $this->d, $o) ?: [1 => 0])[1];
    }
    private function leggiCmap(): void
    {
        $c = $this->tab['cmap'][0];
        for ($i = 0, $n = $this->u16($c + 2); $i < $n; $i++) {
            $r = $c + 4 + 8 * $i;
            if ($this->u16($r) !== 3 || $this->u16($r + 2) !== 1) {
                continue;
            }
            $t = $c + $this->u32($r + 4);
            if ($this->u16($t) !== 4) {
                continue;
            }
            $seg = $this->u16($t + 6) / 2;
            $fine = $t + 14;
            $ini = $fine + 2 * $seg + 2;
            $delta = $ini + 2 * $seg;
            $rng = $delta + 2 * $seg;
            for ($s = 0; $s < $seg; $s++) {
                $a = $this->u16($ini + 2 * $s);
                $b = $this->u16($fine + 2 * $s);
                $dl = $this->u16($delta + 2 * $s);
                $ro = $this->u16($rng + 2 * $s);
                for ($u = $a; $u <= $b && $u !== 0xFFFF; $u++) {
                    if ($ro === 0) {
                        $g = ($u + $dl) & 0xFFFF;
                    } else {
                        $g = $this->u16($rng + 2 * $s + $ro + 2 * ($u - $a));
                        if ($g) {
                            $g = ($g + $dl) & 0xFFFF;
                        }
                    }
                    if ($g) {
                        $this->cmap[$u] = $g;
                    }
                }
            }
            return;
        }
    }
    // Glifo di un codice WinAnsi (32-255)
    private function glifoWinAnsi(int $c): int
    {
        $u = mb_convert_encoding(chr($c), 'UTF-8', 'Windows-1252');
        $cp = $u !== '' && $u !== '?' || $c === 63 ? mb_ord($u, 'UTF-8') : 0;
        return $cp ? ($this->cmap[$cp] ?? 0) : 0;
    }
    // Larghezze dei codici 32-255 in millesimi di em (/Widths del PDF)
    public function larghezzeWinAnsi(): array
    {
        if ($this->winansi !== null) {
            return $this->winansi;
        }
        $out = [];
        for ($c = 32; $c <= 255; $c++) {
            $g = $this->glifoWinAnsi($c);
            $out[] = $g || $c === 32 ? (int)round($this->adv[$g] * 1000 / $this->upm) : 0;
        }
        return $this->winansi = $out;
    }
    public function nomePs(): string
    {
        // Nome PostScript dalla tabella 'name' (id 6), altrimenti dal nome del file
        [$o] = $this->tab['name'];
        $n = $this->u16($o + 2);
        $st = $o + $this->u16($o + 4);
        for ($i = 0; $i < $n; $i++) {
            $r = $o + 6 + 12 * $i;
            if ($this->u16($r + 6) !== 6) {
                continue;
            }
            $s = substr($this->d, $st + $this->u16($r + 10), $this->u16($r + 8));
            if ($this->u16($r) === 3) {
                $s = mb_convert_encoding($s, 'UTF-8', 'UTF-16BE');
            }
            if (($s = (string) preg_replace('/[^A-Za-z0-9\-]/', '', $s)) !== '') {
                return $s;
            }
        }
        return 'Carattere';
    }
    public function descrittore(): string
    {
        $h = $this->tab['head'][0];
        $k = 1000 / $this->upm;
        $r = fn ($v) => (int)round($v * $k);
        $bbox = [$r($this->s16($h + 36)), $r($this->s16($h + 38)), $r($this->s16($h + 40)), $r($this->s16($h + 42))];
        $hh = $this->tab['hhea'][0];
        $os = $this->tab['OS/2'][0] ?? null;
        $ital = $this->s16($this->tab['post'][0] + 4);
        $cap = $os !== null && $this->u16($os) >= 2 ? $this->s16($os + 88) : $this->s16($hh + 4);
        $peso = $os !== null ? $this->u16($os + 4) : 400;
        $flags = 32 + ($ital ? 64 : 0) + (str_contains($this->nomePs(), 'Serif') ? 2 : 0);
        return "/Flags $flags /FontBBox [" . implode(' ', $bbox) . "] /ItalicAngle $ital /Ascent " . $r($this->s16($hh + 4)) . ' /Descent ' . $r($this->s16($hh + 6))
             . ' /CapHeight ' . $r($cap) . ' /StemV ' . ($peso >= 600 ? 120 : 80);
    }
    private function posGlifo(int $g): array
    {
        $l = $this->tab['loca'][0];
        return $this->formato_loca ? [$this->u32($l + 4 * $g), $this->u32($l + 4 * $g + 4)] : [2 * $this->u16($l + 2 * $g), 2 * $this->u16($l + 2 * $g + 2)];
    }
    // File TrueType con i soli glifi dei codici WinAnsi usati (più .notdef e i pezzi dei glifi composti)
    public function sottoinsieme(array $codici): string
    {
        $tieni = [0 => true];
        foreach ($codici as $c) {
            if ($g = $this->glifoWinAnsi((int)$c)) {
                $tieni[$g] = true;
            }
        }
        $gl = $this->tab['glyf'][0];
        $da_vedere = array_keys($tieni);
        while ($da_vedere) {
            $g = array_pop($da_vedere);
            [$a, $b] = $this->posGlifo($g);
            if ($b <= $a || $this->s16($gl + $a) >= 0) {
                continue;
            }
            $o = $gl + $a + 10;
            do {
                $fl = $this->u16($o);
                $comp = $this->u16($o + 2);
                if (!isset($tieni[$comp])) {
                    $tieni[$comp] = true;
                    $da_vedere[] = $comp;
                }
                $o += 4 + (($fl & 1) ? 4 : 2) + (($fl & 8) ? 2 : (($fl & 0x40) ? 4 : (($fl & 0x80) ? 8 : 0)));
            } while ($fl & 0x20);
        }
        $glyf = '';
        $loca = '';
        for ($g = 0; $g < $this->n_glifi; $g++) {
            $loca .= pack('N', strlen($glyf));
            if (!isset($tieni[$g])) {
                continue;
            }
            [$a, $b] = $this->posGlifo($g);
            $glyf .= substr($this->d, $gl + $a, $b - $a);
            if (strlen($glyf) % 4) {
                $glyf .= str_repeat("\0", 4 - strlen($glyf) % 4);
            }
        }
        $loca .= pack('N', strlen($glyf));
        $tab = [];
        foreach (['cmap', 'cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'OS/2', 'post', 'prep'] as $t) {
            if (!isset($this->tab[$t])) {
                continue;
            }
            [$o, $l] = $this->tab[$t];
            $tab[$t] = match ($t) {
                'glyf' => $glyf, 'loca' => $loca,
                'head' => substr_replace(substr_replace(substr($this->d, $o, $l), "\0\0\0\0", 8, 4), pack('n', 1), 50, 2), // loca lunga, checksum da ricalcolare
                'post' => pack('N', 0x00030000) . substr($this->d, $o + 4, 28),                                              // senza i nomi dei glifi
                default => substr($this->d, $o, $l),
            };
        }
        $nt = count($tab);
        $es = (int)floor(log($nt, 2));
        $sr = 16 * (2 ** $es);
        $testa = pack('Nnnnn', 0x00010000, $nt, $sr, $es, 16 * $nt - $sr);
        $dir = '';
        $corpo = '';
        $off = 12 + 16 * $nt;
        $somma = function (string $s): int {
            $s .= str_repeat("\0", (4 - strlen($s) % 4) % 4);
            $t = 0;
            foreach (unpack('N*', $s) ?: [] as $v) {
                $t = ($t + $v) & 0xFFFFFFFF;
            } return $t;
        };
        foreach ($tab as $t => $s) {
            $dir .= $t . pack('NNN', $somma($s), $off + strlen($corpo), strlen($s));
            $corpo .= $s . str_repeat("\0", (4 - strlen($s) % 4) % 4);
        }
        $file = $testa . $dir . $corpo;
        $adj = (0xB1B0AFBA - $somma($file)) & 0xFFFFFFFF;
        $p_head = strpos($dir, 'head');
        $o_head = (unpack('N', $dir, (int) $p_head + 8) ?: [1 => 0])[1];
        return substr_replace($file, pack('N', $adj), $o_head + 8, 4);
    }
}
