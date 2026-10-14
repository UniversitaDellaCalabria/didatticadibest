<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

/**
 * Testo di un PDF «digitale» (non scansionato), per la ricerca nell'archivio dei verbali. Senza librerie esterne: legge gli oggetti del file
 * (anche quelli compressi negli object stream), i font con la loro tabella ToUnicode e i comandi di testo delle pagine. Quando non ci riesce
 * (PDF scansionato, cifrato o con filtri insoliti) restituisce una stringa vuota: l'archivio funziona lo stesso, senza ricerca nel testo.
 */
final class TestoPdf
{
    /** @var array<int, array{d: string, s: ?string}> numero oggetto => dizionario e flusso (già decompresso) */
    private array $oggetti = [];
    /** @var array<int, ?array{w: int, m: array<string, string>}> cache dei font per numero oggetto */
    private array $font = [];

    public function estrai(string $pdf, int $max = 400000): string
    {
        $this->oggetti = [];
        $this->font = [];
        if (!str_starts_with(ltrim(substr($pdf, 0, 16)), '%PDF') || str_contains($pdf, '/Encrypt')) {
            return '';
        }
        try {
            $this->leggiOggetti($pdf);
            $testo = '';
            foreach ($this->pagine() as $pagina) {
                $testo .= $this->testoPagina($pagina) . "\n";
                if (strlen($testo) > $max) {
                    break;
                }
            }
        } catch (\Throwable) {
            return '';
        }
        $testo = (string) preg_replace('/[^\P{C}\n]+/u', ' ', $testo);
        $testo = (string) preg_replace('/[ \t]+/u', ' ', $testo);
        $testo = (string) preg_replace('/\s*\n\s*/u', "\n", $testo);

        return trim(mb_substr($testo, 0, $max));
    }

    private function leggiOggetti(string $pdf): void
    {
        preg_match_all('/(?<![0-9])(\d{1,7})\s+\d{1,5}\s+obj\b(.*?)endobj/s', $pdf, $m, PREG_SET_ORDER);
        foreach ($m as $o) {
            $corpo = $o[2];
            $dict = $corpo;
            $flusso = null;
            if (($p = strpos($corpo, 'stream')) !== false) {
                $dict = substr($corpo, 0, $p);
                $dati = substr($corpo, $p + 6);
                $dati = preg_replace('/^\r?\n/', '', $dati) ?? '';
                $fine = strrpos($dati, 'endstream');
                $dati = $fine === false ? $dati : rtrim(substr($dati, 0, $fine), "\r\n");
                $flusso = $this->decodifica($dict, $dati);
            }
            $num = (int) $o[1];
            $this->oggetti[$num] = ['d' => $dict, 's' => $flusso];
            if ($flusso !== null && preg_match('#/Type\s*/ObjStm#', $dict)) {
                $this->oggettiCompressi($dict, $flusso);
            }
        }
    }

    private function oggettiCompressi(string $dict, string $flusso): void
    {
        if (!preg_match('#/N\s+(\d+)#', $dict, $n) || !preg_match('#/First\s+(\d+)#', $dict, $f)) {
            return;
        }
        $nums = preg_split('/\s+/', trim(substr($flusso, 0, (int) $f[1]))) ?: [];
        $primo = (int) $f[1];
        $conta = (int) $n[1];
        for ($i = 0; $i < $conta; $i++) {
            $num = (int) ($nums[$i * 2] ?? 0);
            $da = $primo + (int) ($nums[$i * 2 + 1] ?? 0);
            $a = $i + 1 < $conta ? $primo + (int) ($nums[($i + 1) * 2 + 1] ?? 0) : strlen($flusso);
            if ($num > 0 && $a >= $da) {
                $this->oggetti[$num] ??= ['d' => substr($flusso, $da, $a - $da), 's' => null];
            }
        }
    }

    private function decodifica(string $dict, string $dati): ?string
    {
        if (!preg_match('#/Filter\s*(\[[^\]]*\]|/\w+)#', $dict, $f)) {
            return $dati;
        }
        $filtri = $f[1];
        if (preg_match_all('#/(\w+)#', $filtri, $mm) && array_diff($mm[1], ['FlateDecode', 'Fl'])) {
            return null;
        }
        $out = @gzuncompress($dati);
        if ($out === false) {
            $out = @gzinflate(substr($dati, 2));
        }
        if ($out === false) {
            $out = @gzinflate($dati);
        }

        return $out === false ? null : $out;
    }

    /**
     * Le pagine (oggetti /Type /Page) con i flussi di contenuto e i font dichiarati nelle risorse.
     *
     * @return list<array{c: list<string>, f: array<string, int>}>
     */
    private function pagine(): array
    {
        $out = [];
        $nums = array_keys($this->oggetti);
        sort($nums);
        foreach ($nums as $num) {
            $d = $this->oggetti[$num]['d'];
            if (!preg_match('#/Type\s*/Page(?![a-z])#', $d)) {
                continue;
            }
            $contenuti = [];
            if (preg_match('#/Contents\s*(\[[^\]]*\]|\d+\s+\d+\s+R)#', $d, $c)) {
                preg_match_all('#(\d+)\s+\d+\s+R#', $c[1], $refs);
                foreach ($refs[1] as $r) {
                    if (($s = $this->oggetti[(int) $r]['s'] ?? null) !== null) {
                        $contenuti[] = $s;
                    }
                }
            }
            $out[] = ['c' => $contenuti, 'f' => $this->fontDellaPagina($num)];
        }

        return $out;
    }

    /** @return array<string, int> nome risorsa => numero oggetto del font (risalendo ai /Parent se la pagina non ha risorse proprie) */
    private function fontDellaPagina(int $num): array
    {
        $cur = $num;
        for ($i = 0; $i < 8 && isset($this->oggetti[$cur]); $i++) {
            $d = $this->oggetti[$cur]['d'];
            if (preg_match('#/Resources\s*(<<|\d+\s+\d+\s+R)#', $d, $r, PREG_OFFSET_CAPTURE)) {
                $ris = $r[1][0] === '<<' ? $this->dizionario($d, (int) $r[1][1]) : ($this->oggetti[(int) $r[1][0]]['d'] ?? '');
                if (preg_match('#/Font\s*(<<|\d+\s+\d+\s+R)#', $ris, $f, PREG_OFFSET_CAPTURE)) {
                    $fd = $f[1][0] === '<<' ? $this->dizionario($ris, (int) $f[1][1]) : ($this->oggetti[(int) $f[1][0]]['d'] ?? '');
                    preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', $fd, $m, PREG_SET_ORDER);
                    $out = [];
                    foreach ($m as $x) {
                        $out[$x[1]] = (int) $x[2];
                    }

                    return $out;
                }
            }
            if (!preg_match('#/Parent\s+(\d+)\s+\d+\s+R#', $d, $p)) {
                break;
            }
            $cur = (int) $p[1];
        }

        return [];
    }

    /** Il dizionario «<< … >>» che inizia a $da (con i dizionari annidati). */
    private function dizionario(string $s, int $da): string
    {
        $liv = 0;
        $n = strlen($s);
        for ($i = $da; $i < $n - 1; $i++) {
            if ($s[$i] === '<' && $s[$i + 1] === '<') {
                $liv++;
                $i++;
            } elseif ($s[$i] === '>' && $s[$i + 1] === '>') {
                $liv--;
                $i++;
                if ($liv === 0) {
                    return substr($s, $da, $i - $da + 1);
                }
            }
        }

        return substr($s, $da);
    }

    /** @return array{w: int, m: array<string, string>}|null larghezza in byte dei codici e tabella codice => testo (vuota = codifica semplice) */
    private function fontPerNumero(int $num): ?array
    {
        if (array_key_exists($num, $this->font)) {
            return $this->font[$num];
        }
        $this->font[$num] = null;
        $d = $this->oggetti[$num]['d'] ?? '';
        if (preg_match('#/ToUnicode\s+(\d+)\s+\d+\s+R#', $d, $t) && ($cm = $this->oggetti[(int) $t[1]]['s'] ?? null) !== null) {
            $this->font[$num] = $this->leggiCMap($cm);
        } else {
            $this->font[$num] = ['w' => 1, 'm' => []];
        }

        return $this->font[$num];
    }

    /** @return array{w: int, m: array<string, string>} */
    private function leggiCMap(string $cm): array
    {
        $w = 1;
        if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cm, $c)) {
            $w = max(1, intdiv(strlen($c[1]), 2));
        }
        $u = fn (string $hex) => (string) mb_convert_encoding((string) hex2bin(strlen($hex) % 2 ? '0' . $hex : $hex), 'UTF-8', 'UTF-16BE');
        $m = [];
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cm, $blocchi)) {
            foreach ($blocchi[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $b, $p, PREG_SET_ORDER);
                foreach ($p as $x) {
                    $m[(string) hexdec($x[1])] = $u($x[2]);
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cm, $blocchi)) {
            foreach ($blocchi[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $b, $p, PREG_SET_ORDER);
                foreach ($p as $x) {
                    $lo = (int) hexdec($x[1]);
                    $hi = min((int) hexdec($x[2]), $lo + 65535);
                    if ($x[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $x[3], $lista);
                        foreach ($lista[1] as $i => $h) {
                            $m[(string) ($lo + $i)] = $u($h);
                        }
                    } else {
                        $base = substr($x[3], 1, -1);
                        $testo = $u($base);
                        $ultimo = $testo === '' ? 0 : mb_ord(mb_substr($testo, -1));
                        $testa = $testo === '' ? '' : mb_substr($testo, 0, -1);
                        for ($c = $lo; $c <= $hi; $c++) {
                            $m[(string) $c] = $testa . mb_chr($ultimo + ($c - $lo));
                        }
                    }
                }
            }
        }

        return ['w' => $w, 'm' => $m];
    }

    /** @param array{c: list<string>, f: array<string, int>} $pagina */
    private function testoPagina(array $pagina): string
    {
        $out = '';
        foreach ($pagina['c'] as $c) {
            $out .= $this->leggiContenuto($c, $pagina['f']) . "\n";
        }

        return $out;
    }

    /** @param array<string, int> $fontPagina */
    private function leggiContenuto(string $c, array $fontPagina): string
    {
        $out = '';
        $font = null;
        $pila = [];
        $n = strlen($c);
        $i = 0;
        // Posizione verticale del testo: se cambia riga si va a capo, altrimenti (parole scritte in blocchi BT separati) si mette uno spazio
        $ty = 0.0;
        $ultimaY = null;
        $bt = 0;
        $ultimoBt = -1;
        $capo = false;
        $scrivi = function (string $t) use (&$out, &$ty, &$ultimaY, &$bt, &$ultimoBt, &$capo): void {
            if ($t === '') {
                return;
            }
            if ($out !== '' && ($capo || ($ultimaY !== null && abs($ty - $ultimaY) > 1.0))) {
                $out .= "\n";
            } elseif ($out !== '' && $bt !== $ultimoBt && !preg_match('/\s$/', $out)) {
                $out .= ' ';
            }
            $out .= $t;
            $ultimaY = $ty;
            $ultimoBt = $bt;
            $capo = false;
        };
        while ($i < $n) {
            $ch = $c[$i];
            if ($ch === ' ' || $ch === "\n" || $ch === "\r" || $ch === "\t" || $ch === "\f" || $ch === "\0") {
                $i++;
            } elseif ($ch === '(') {
                [$s, $i] = $this->stringaLiterale($c, $i);
                $pila[] = ['s', $s];
            } elseif ($ch === '<' && ($c[$i + 1] ?? '') === '<') {
                $i += 2;
            } elseif ($ch === '>' && ($c[$i + 1] ?? '') === '>') {
                $i += 2;
            } elseif ($ch === '<') {
                $f = strpos($c, '>', $i);
                $f = $f === false ? $n : $f;
                $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($c, $i + 1, $f - $i - 1));
                $pila[] = ['s', (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex)];
                $i = $f + 1;
            } elseif ($ch === '[' || $ch === ']') {
                $pila[] = [$ch, ''];
                $i++;
            } elseif ($ch === '%') {
                $f = strpos($c, "\n", $i);
                $i = $f === false ? $n : $f + 1;
            } elseif ($ch === '/') {
                $f = $i + 1;
                while ($f < $n && !str_contains(" \n\r\t\f\0/[]()<>", $c[$f])) {
                    $f++;
                }
                $pila[] = ['n', substr($c, $i + 1, $f - $i - 1)];
                $i = $f;
            } else {
                $f = $i;
                while ($f < $n && !str_contains(" \n\r\t\f\0/[]()<>%", $c[$f])) {
                    $f++;
                }
                $tok = substr($c, $i, max(1, $f - $i));
                $i = $f > $i ? $f : $i + 1;
                if (is_numeric($tok)) {
                    $pila[] = ['d', $tok];
                    continue;
                }
                switch ($tok) {
                    case 'Tf':
                        $nome = null;
                        foreach ($pila as $x) {
                            if ($x[0] === 'n') {
                                $nome = $x[1];
                            }
                        }
                        $font = $nome !== null && isset($fontPagina[$nome]) ? $this->fontPerNumero($fontPagina[$nome]) : ['w' => 1, 'm' => []];
                        break;
                    case 'Tj':
                    case "'":
                    case '"':
                        if ($tok !== 'Tj') {
                            $capo = true;
                        }
                        $ultimo = end($pila);
                        if ($ultimo && $ultimo[0] === 's') {
                            $scrivi($this->decodificaTesto($ultimo[1], $font));
                        }
                        break;
                    case 'TJ':
                        $parti = '';
                        foreach ($pila as $x) {
                            if ($x[0] === 's') {
                                $parti .= $this->decodificaTesto($x[1], $font);
                            } elseif ($x[0] === 'd' && (float) $x[1] < -600) {
                                $parti .= ' ';
                            }
                        }
                        $scrivi($parti);
                        break;
                    case 'BT':
                        $bt++;
                        $ty = 0.0;
                        break;
                    case 'Td':
                    case 'TD':
                        $ty += (float) ($pila ? $pila[count($pila) - 1][1] : 0);
                        break;
                    case 'Tm':
                        $ty = (float) ($pila ? $pila[count($pila) - 1][1] : 0);
                        break;
                    case 'T*':
                        $capo = true;
                        break;
                    default:
                        break;
                }
                $pila = [];
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: int} testo della stringa «( … )» e posizione dopo la parentesi di chiusura */
    private function stringaLiterale(string $c, int $i): array
    {
        $n = strlen($c);
        $liv = 0;
        $s = '';
        for (; $i < $n; $i++) {
            $ch = $c[$i];
            if ($ch === '\\') {
                $i++;
                $e = $c[$i] ?? '';
                if ($e >= '0' && $e <= '7') {
                    $oct = $e;
                    while (strlen($oct) < 3 && ($c[$i + 1] ?? '') >= '0' && ($c[$i + 1] ?? '') <= '7') {
                        $oct .= $c[++$i];
                    }
                    $s .= chr(octdec($oct) & 255);
                } else {
                    $s .= match ($e) {
                        'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", "\r", "\n" => '', default => $e,
                    };
                }
            } elseif ($ch === '(') {
                if ($liv++ > 0) {
                    $s .= $ch;
                }
            } elseif ($ch === ')') {
                if (--$liv === 0) {
                    return [$s, $i + 1];
                }
                $s .= $ch;
            } else {
                $s .= $ch;
            }
        }

        return [$s, $n];
    }

    /** @param array{w: int, m: array<string, string>}|null $font */
    private function decodificaTesto(string $s, ?array $font): string
    {
        if ($font === null || !$font['m']) {
            if ($font !== null && $font['w'] === 2 && !$font['m']) {
                return '';
            }

            return (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        $out = '';
        $w = $font['w'];
        for ($k = 0; $k + $w <= strlen($s); $k += $w) {
            $codice = $w === 1 ? ord($s[$k]) : (ord($s[$k]) << 8) + ord($s[$k + 1]);
            $out .= $font['m'][(string) $codice] ?? '';
        }

        return $out;
    }
}
