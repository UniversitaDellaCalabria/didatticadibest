<?php

declare(strict_types=1);

namespace App\Tutorato;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Legge dal Word del decreto di approvazione atti dei bandi di tutorato i vincitori dell'ART. 2: una tabella per ambito disciplinare con le colonne
 * COGNOME, NOME, CORSO DI LAUREA, INSEGNAMENTO, N. ORE, PERIODO e COMPENSO (le graduatorie di merito dell'ART. 1 non servono). Il periodo, scritto
 * una volta sola in una cella unita, vale per tutte le righe della tabella. Dal testo ricava anche gli estremi del decreto del bando e della commissione.
 */
final class ImportGraduatoria
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @return array{righe: list<array<string, mixed>>, decreti: array<string, string>, errore: string|null}
     */
    public function leggi(string $docx): array
    {
        $vuoto = ['righe' => [], 'decreti' => [], 'errore' => null];
        if ($docx === '' || strncmp($docx, 'PK', 2) !== 0) {
            return ['errore' => 'Il file non è un documento Word (.docx): se hai un .doc aprilo in Word e salvalo come .docx.'] + $vuoto;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'grad');
        file_put_contents($tmp, $docx);
        $xml = null;
        $z = new ZipArchive();
        if ($z->open($tmp) === true) {
            $xml = $z->getFromName('word/document.xml');
            $z->close();
        }
        @unlink($tmp);
        if (!is_string($xml) || $xml === '') {
            return ['errore' => 'Non riesco a leggere il documento Word.'] + $vuoto;
        }
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return ['errore' => 'Il documento Word non è leggibile.'] + $vuoto;
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);
        $corpo = $xp->query('/w:document/w:body')->item(0);
        if (!$corpo instanceof DOMElement) {
            return ['errore' => 'Il documento Word è vuoto.'] + $vuoto;
        }

        $righe = [];
        $ambito = '';
        $dopoArt2 = false;
        $decreti = [];
        foreach ($corpo->childNodes as $n) {
            if (!$n instanceof DOMElement) {
                continue;
            }
            if ($n->localName === 'p') {
                $t = $this->testo($n, $xp);
                $this->estraiDecreti($t, $decreti);
                if (preg_match('/^\s*ART\.?\s*2\b/iu', $t)) {
                    $dopoArt2 = true;
                }
                if ($dopoArt2 && preg_match('/AMBITO\s+DISCIPLINARE\s+(.+)$/iu', $t, $m)) {
                    $ambito = $this->pulisci($m[1]);
                }
            } elseif ($n->localName === 'tbl' && $dopoArt2) {
                foreach ($this->tabella($n, $xp, $ambito) as $r) {
                    $righe[] = $r;
                }
            }
        }
        if (!$righe) {
            return ['errore' => 'Non trovo la tabella dei vincitori (ART. 2, colonne cognome, nome, corso di laurea, insegnamento, ore, periodo, compenso).'] + $vuoto;
        }

        return ['righe' => $righe, 'decreti' => $decreti, 'errore' => null];
    }

    /**
     * Le righe di una tabella dei vincitori (vuoto se l'intestazione non è quella attesa).
     *
     * @return list<array<string, mixed>>
     */
    private function tabella(DOMElement $tbl, DOMXPath $xp, string $ambito): array
    {
        $righe = [];
        $col = null;
        $precedente = [];
        foreach ($xp->query('w:tr', $tbl) as $tr) {
            $celle = [];
            foreach ($xp->query('w:tc', $tr) as $tc) {
                $continua = $xp->query('w:tcPr/w:vMerge', $tc)->length > 0 && ($xp->query('w:tcPr/w:vMerge', $tc)->item(0) instanceof DOMElement) && $xp->query('w:tcPr/w:vMerge', $tc)->item(0)->getAttributeNS(self::NS, 'val') !== 'restart';
                $celle[] = ['t' => $this->pulisci($this->testo($tc, $xp)), 'continua' => $continua];
            }
            if ($col === null) {
                $col = $this->colonne(array_column($celle, 't'));
                continue;
            }
            if ($col === []) {
                return [];
            }
            // celle unite verticalmente: il valore è quello della riga sopra
            foreach ($celle as $i => $c) {
                if ($c['continua']) {
                    $celle[$i]['t'] = $precedente[$i] ?? '';
                }
            }
            $precedente = array_column($celle, 't');
            $v = static fn (string $k): string => isset($col[$k], $celle[$col[$k]]) ? (string) $celle[$col[$k]]['t'] : '';
            $ins = $v('insegnamento');
            $ore = DatiLettera::numeroItaliano($v('ore'));
            if ($ins === '' && $ore === null) {
                continue;
            }
            $periodo = $v('periodo');
            $righe[] = ['ambito' => $ambito, 'cognome' => $v('cognome'), 'nome' => $v('nome'), 'corso' => $v('corso'), 'insegnamento' => $ins, 'ore' => $ore,
                'periodo' => $periodo, 'data_fine' => self::dataFine($periodo), 'compenso_decreto' => DatiLettera::numeroItaliano($v('compenso'))];
        }

        return $righe;
    }

    /**
     * Posizione delle colonne dalla riga di intestazione; [] se non è la tabella dei vincitori.
     *
     * @param list<string> $intest
     * @return array<string, int>
     */
    private function colonne(array $intest): array
    {
        $col = [];
        foreach ($intest as $i => $t) {
            $t = mb_strtolower($t);
            foreach (['cognome' => 'cognome', 'nome' => 'nome', 'corso' => 'corso di laurea', 'insegnamento' => 'insegnamento', 'ore' => 'n. ore', 'periodo' => 'periodo', 'compenso' => 'compenso'] as $k => $parola) {
                if (!isset($col[$k]) && ($k === 'nome' ? $t === 'nome' : str_contains($t, $parola))) {
                    $col[$k] = $i;
                }
            }
        }

        return isset($col['cognome'], $col['nome'], $col['insegnamento'], $col['ore']) ? $col : [];
    }

    /** «Dalla data di accettazione dell'incarico fino al 31/07/2026» → 2026-07-31 (null se non c'è una data). */
    public static function dataFine(string $periodo): ?string
    {
        if (preg_match_all('#(\d{1,2})/(\d{1,2})/(\d{4})#', $periodo, $m, PREG_SET_ORDER)) {
            $u = end($m);

            return sprintf('%04d-%02d-%02d', (int) $u[3], (int) $u[2], (int) $u[1]);
        }

        return null;
    }

    /** @param array<string, string> $decreti */
    private function estraiDecreti(string $t, array &$decreti): void
    {
        if (!preg_match_all('#Decreto\s+Direttoriale\s+n\.?\s*(\d+)\s+del\s+(\d{1,2})/(\d{1,2})/(\d{4})#iu', $t, $mm, PREG_SET_ORDER)) {
            return;
        }
        foreach ($mm as $m) {
            // il primo decreto citato è quello del bando; il primo che compare con «commissione» è quello della nomina
            $chiave = !isset($decreti['bando']) ? 'bando' : (!isset($decreti['commissione']) && $m[1] !== explode('/', $decreti['bando'])[0] && preg_match('/commissione/iu', $t) ? 'commissione' : null);
            if ($chiave !== null) {
                $decreti[$chiave] = $m[1] . '/' . $m[4];
                $decreti[$chiave . '_data'] = sprintf('%04d-%02d-%02d', (int) $m[4], (int) $m[3], (int) $m[2]);
            }
        }
    }

    private function testo(DOMElement $n, DOMXPath $xp): string
    {
        $paragrafi = $n->localName === 'p' ? [$n] : iterator_to_array($xp->query('.//w:p', $n));
        $out = [];
        foreach ($paragrafi as $p) {
            $s = '';
            foreach ($xp->query('.//w:t | .//w:tab | .//w:br', $p) as $x) {
                $s .= $x->localName === 't' ? $x->textContent : ' ';
            }
            $out[] = $s;
        }

        return implode(' ', $out);
    }

    private function pulisci(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
