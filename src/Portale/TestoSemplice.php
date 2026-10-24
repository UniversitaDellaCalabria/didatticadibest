<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * Testo scritto «a mano» dal pannello (comunicazioni) reso in HTML sicuro: riga vuota = nuovo paragrafo, righe che iniziano con «- » = elenco puntato,
 * **testo** = grassetto, gli indirizzi web diventano link. Tutto il resto è mostrato com'è (niente HTML).
 */
final class TestoSemplice
{
    public static function html(string $testo): string
    {
        if (self::eHtml($testo)) {
            return self::pulisci($testo);
        }
        $out = '';
        foreach (preg_split('/\n{2,}/', trim(str_replace("\r\n", "\n", $testo))) ?: [] as $blocco) {
            $righe = array_values(array_filter(array_map('trim', explode("\n", $blocco)), static fn (string $r): bool => $r !== ''));
            if (!$righe) {
                continue;
            }
            if (count(array_filter($righe, static fn (string $r): bool => str_starts_with($r, '- '))) === count($righe)) {
                $out .= '<ul>' . implode('', array_map(static fn (string $r): string => '<li>' . self::riga(substr($r, 2)) . '</li>', $righe)) . '</ul>';
            } else {
                $out .= '<p>' . implode('<br>', array_map([self::class, 'riga'], $righe)) . '</p>';
            }
        }

        return $out;
    }

    /** Un'estratto in testo semplice (senza formattazione) per elenchi e feed. */
    public static function estratto(string $testo, int $max = 220): string
    {
        if (self::eHtml($testo)) {
            $testo = html_entity_decode(strip_tags((string) preg_replace('#</(p|li|h[1-6]|div|tr)>|<br\s*/?>#i', ' ', $testo)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', str_replace(['**', "\n- "], ['', ' '], $testo)));

        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
    }

    private const TAG = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'h2', 'h3', 'h4', 'h5', 'ul', 'ol', 'li', 'a', 'img', 'figure', 'figcaption', 'blockquote', 'pre', 'code', 'hr', 'span', 'div',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col', 'iframe', 'video', 'source', 'mark', 'small', 'details', 'summary', 'dl', 'dt', 'dd'];
    private const ATTR = ['href', 'src', 'alt', 'title', 'width', 'height', 'colspan', 'rowspan', 'target', 'rel', 'style', 'class', 'id', 'controls', 'frameborder', 'allowfullscreen', 'allow', 'loading', 'start', 'type', 'scope', 'border', 'cellpadding', 'cellspacing', 'name', 'lang', 'dir', 'poster'];
    /** Incorporamenti video ammessi (solo questi siti). */
    private const IFRAME = '#^https://(www\.youtube(-nocookie)?\.com/embed/|player\.vimeo\.com/video/)#i';

    private static function eHtml(string $t): bool
    {
        return (bool) preg_match('#<(p|h[1-6]|ul|ol|div|table|figure|blockquote|br|img|a)\b#i', $t);
    }

    /** Il testo dell'editor, ripulito: solo tag e attributi della lista, indirizzi sicuri, stili ridotti ai colori e all'allineamento. */
    private static function pulisci(string $html): string
    {
        $doc = new \DOMDocument();
        $prima = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="radice-testo">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prima);
        $radice = $doc->getElementById('radice-testo');
        if (!$radice) {
            return '';
        }
        self::ripulisciNodo($radice);
        $out = '';
        foreach (iterator_to_array($radice->childNodes) as $n) {
            $out .= $doc->saveHTML($n);
        }

        return $out;
    }

    private static function ripulisciNodo(\DOMNode $nodo): void
    {
        foreach (iterator_to_array($nodo->childNodes) as $f) {
            if ($f instanceof \DOMComment) {
                $nodo->removeChild($f);
                continue;
            }
            if (!$f instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($f->tagName);
            if (!in_array($tag, self::TAG, true)) {
                if (in_array($tag, ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'noscript', 'svg', 'math'], true)) {
                    $nodo->removeChild($f);
                } else {
                    self::ripulisciNodo($f);
                    while ($f->firstChild) {
                        $nodo->insertBefore($f->firstChild, $f);
                    }
                    $nodo->removeChild($f);
                }
                continue;
            }
            if ($tag === 'iframe' && !preg_match(self::IFRAME, (string) $f->getAttribute('src'))) {
                $nodo->removeChild($f);
                continue;
            }
            foreach (iterator_to_array($f->attributes) as $a) {
                $nome = strtolower($a->name);
                $v = trim($a->value);
                $ok = in_array($nome, self::ATTR, true);
                if ($ok && in_array($nome, ['href', 'src', 'poster'], true)) {
                    $ok = (bool) preg_match('#^(https?://|mailto:|tel:|/|\#|\.\./|[A-Za-z0-9_\-][^:]*$)#i', $v) && !preg_match('#^\s*(javascript|data|vbscript):#i', $v);
                }
                if ($ok && $nome === 'style') {
                    $v = self::stile($v);
                    $ok = $v !== '';
                    $a->value = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
                }
                if (!$ok) {
                    $f->removeAttribute($a->name);
                }
            }
            if ($tag === 'a' && $f->getAttribute('target') === '_blank') {
                $f->setAttribute('rel', 'noopener noreferrer');
            }
            if ($tag === 'img' && !$f->hasAttribute('loading')) {
                $f->setAttribute('loading', 'lazy');
            }
            self::ripulisciNodo($f);
        }
    }

    private static function stile(string $s): string
    {
        $ok = [];
        foreach (explode(';', $s) as $d) {
            [$p, $v] = array_pad(array_map('trim', explode(':', $d, 2)), 2, '');
            $p = strtolower($p);
            if (in_array($p, ['text-align', 'color', 'background-color', 'width', 'height', 'float', 'margin-left', 'margin-right', 'padding-left', 'text-decoration', 'font-weight', 'font-style', 'border', 'border-collapse', 'vertical-align'], true)
                && $v !== '' && !preg_match('#url\(|expression|javascript|[<>{}]#i', $v)) {
                $ok[] = $p . ': ' . $v;
            }
        }

        return implode('; ', $ok);
    }

    private static function riga(string $r): string
    {
        $s = htmlspecialchars($r, ENT_QUOTES, 'UTF-8');
        $s = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);

        return (string) preg_replace_callback('#https?://[^\s<]+#i', static function (array $m): string {
            $u = rtrim($m[0], '.,;:)');
            $resto = substr($m[0], strlen($u));

            return '<a href="' . $u . '" target="_blank" rel="noopener noreferrer">' . $u . '</a>' . $resto;
        }, $s);
    }
}
