<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Infrastructure\Documenti\Xml;

/**
 * Il testo scritto per un punto del verbale: pulizia dell'HTML dell'editor (solo paragrafi, a capo, grassetto, corsivo,
 * sottolineato ed elenchi) e conversione in paragrafi di Word.
 */
final class TestoVerbale
{
    private const AMMESSI = '<p><br><strong><b><em><i><u><ul><ol><li>';
    private const FORMA = ['b' => false, 'i' => false, 'u' => false];

    /** HTML ripulito: nessun attributo, nessun tag fuori dall'elenco, vuoto se non c'è testo. */
    public static function pulisci(string $html): string
    {
        $html = strip_tags($html, self::AMMESSI);
        $html = (string) preg_replace('#<(/?)(p|br|strong|b|em|i|u|ul|ol|li)\b[^>]*>#i', '<$1$2>', $html);
        $html = trim((string) preg_replace('#<p>(?:\s|&nbsp;|<br>)*</p>#i', '', $html));
        $testo = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $testo === '' ? '' : mb_substr($html, 0, 60000);
    }

    /** Testo semplice (senza tag) per le anteprime e le prove. */
    public static function inTesto(string $html): string
    {
        $t = (string) preg_replace('#</(p|li)>|<br>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** XML dei paragrafi di Word per l'HTML (elenchi puntati e numerati con rientro). */
    public static function inWord(string $html): string
    {
        $html = self::pulisci($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument();
        $prima = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($prima);
        $corpo = $doc->getElementsByTagName('body')->item(0);
        $xml = '';
        $libero = '';
        foreach ($corpo ? iterator_to_array($corpo->childNodes) : [] as $n) {
            if ($n instanceof \DOMElement && in_array($n->nodeName, ['ul', 'ol'], true)) {
                $xml .= self::paragrafoLibero($libero);
                $libero = '';
                $i = 0;
                foreach ($n->childNodes as $li) {
                    if ($li instanceof \DOMElement && $li->nodeName === 'li') {
                        $i++;
                        $xml .= self::paragrafo(self::runs($li, self::FORMA, ($n->nodeName === 'ol' ? $i . '.' : '•')), 720, 360, 0);
                    }
                }
            } elseif ($n instanceof \DOMElement && $n->nodeName === 'p') {
                $xml .= self::paragrafoLibero($libero);
                $libero = '';
                $xml .= self::paragrafo(self::runs($n, self::FORMA));
            } else {
                $libero .= self::runs($n, self::FORMA);
            }
        }

        return $xml . self::paragrafoLibero($libero);
    }

    private static function paragrafoLibero(string $runs): string
    {
        return trim(strip_tags($runs)) !== '' ? self::paragrafo($runs) : '';
    }

    /** @param array{b: bool, i: bool, u: bool} $f */
    private static function runs(\DOMNode $nodo, array $f, string $puntino = ''): string
    {
        $out = $puntino !== '' ? '<w:r><w:t xml:space="preserve">' . Xml::testo($puntino) . '</w:t></w:r><w:r><w:tab/></w:r>' : '';
        $nodi = $nodo instanceof \DOMText ? [$nodo] : iterator_to_array($nodo->childNodes);
        foreach ($nodi as $c) {
            if ($c instanceof \DOMText) {
                $t = (string) preg_replace('/\s+/u', ' ', $c->nodeValue ?? '');
                if ($t === '') {
                    continue;
                }
                $rpr = ($f['b'] ? '<w:b/><w:bCs/>' : '') . ($f['i'] ? '<w:i/>' : '') . ($f['u'] ? '<w:u w:val="single"/>' : '');
                $out .= '<w:r>' . ($rpr ? "<w:rPr>$rpr</w:rPr>" : '') . '<w:t xml:space="preserve">' . Xml::testo($t) . '</w:t></w:r>';
            } elseif ($c instanceof \DOMElement) {
                if ($c->nodeName === 'br') {
                    $out .= '<w:r><w:br/></w:r>';
                    continue;
                }
                $g = $f;
                if (in_array($c->nodeName, ['strong', 'b'], true)) {
                    $g['b'] = true;
                } elseif (in_array($c->nodeName, ['em', 'i'], true)) {
                    $g['i'] = true;
                } elseif ($c->nodeName === 'u') {
                    $g['u'] = true;
                }
                $out .= self::runs($c, $g);
            }
        }

        return $out;
    }

    private static function paragrafo(string $runs, int $rientro = 0, int $sporgente = 0, int $dopo = 120): string
    {
        return '<w:p><w:pPr>' . ($rientro ? '<w:tabs><w:tab w:val="left" w:pos="' . $rientro . '"/></w:tabs>' : '') . '<w:spacing w:after="' . $dopo . '" w:line="264" w:lineRule="auto"/>'
            . ($rientro ? '<w:ind w:left="' . $rientro . '" w:hanging="' . $sporgente . '"/>' : '') . '<w:jc w:val="both"/></w:pPr>' . $runs . '</w:p>';
    }
}
