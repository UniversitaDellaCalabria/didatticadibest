<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/**
 * Legge una pagina web del sito di Ateneo o del Dipartimento e ne estrae il titolo e il testo, per darlo come fonte al modello linguistico. Solo indirizzi https del
 * dominio unical.it (anche dopo i rimandi), al massimo 2 MB, nessun accesso con credenziali: si leggono solo pagine pubbliche. I file PDF non si leggono.
 */
final class LettorePagine
{
    public const MAX_BYTE = 2 * 1024 * 1024;
    public const MAX_CARATTERI = 30000;

    /**
     * @return array{titolo: string, testo: string}
     * @throws \RuntimeException con il motivo, in italiano, se la pagina non si può leggere
     */
    public function leggi(string $url): array
    {
        if (!PagineRepository::urlAmmesso($url)) {
            throw new \RuntimeException('Indirizzo non ammesso: solo pagine https di unical.it');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'DidatticaDiBEST-Didacticus/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Language: it'],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($r, $tot, $sc): int => $sc > self::MAX_BYTE ? 1 : 0,
        ]);
        $corpo = curl_exec($ch);
        $codice = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finale = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $tipo = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $errore = curl_error($ch);
        curl_close($ch);
        if ($corpo === false) {
            throw new \RuntimeException($errore !== '' ? 'Pagina non raggiungibile: ' . $errore : 'Pagina non raggiungibile');
        }
        if ($codice !== 200) {
            throw new \RuntimeException('La pagina ha risposto ' . $codice);
        }
        if (!PagineRepository::urlAmmesso($finale)) {
            throw new \RuntimeException('La pagina rimanda fuori da unical.it');
        }
        if (stripos($tipo, 'html') === false) {
            throw new \RuntimeException('Non è una pagina web (' . ($tipo !== '' ? $tipo : 'tipo sconosciuto') . '): i PDF non si leggono');
        }

        return self::estrai((string) $corpo);
    }

    /**
     * Il titolo e il testo leggibile di una pagina HTML (senza menu, piè di pagina, script e moduli).
     *
     * @return array{titolo: string, testo: string}
     */
    public static function estrai(string $html): array
    {
        $doc = new \DOMDocument();
        $uso = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($uso);
        $xp = new \DOMXPath($doc);
        $titolo = trim((string) ($xp->query('//title')->item(0)?->textContent ?? ''));
        if ($titolo === '') {
            $titolo = trim((string) ($xp->query('//h1')->item(0)?->textContent ?? ''));
        }
        foreach ($xp->query('//script|//style|//noscript|//nav|//header|//footer|//form|//aside|//iframe|//svg|//select|//button') ?: [] as $n) {
            $n->parentNode?->removeChild($n);
        }
        $radice = $xp->query('//main')->item(0) ?? $xp->query('//article')->item(0) ?? $xp->query('//body')->item(0);
        $testo = '';
        if ($radice !== null) {
            foreach ($xp->query('.//h1|.//h2|.//h3|.//h4|.//p|.//li|.//td|.//th|.//dd|.//dt|.//blockquote', $radice) ?: [] as $n) {
                $riga = trim((string) preg_replace('/\s+/u', ' ', $n->textContent));
                if (mb_strlen($riga) >= 3) {
                    $testo .= $riga . "\n";
                }
            }
        }
        // le righe ripetute (menu laterali, «torna su»…) si tengono una volta sola
        $viste = [];
        $out = [];
        foreach (explode("\n", $testo) as $r) {
            if ($r !== '' && !isset($viste[$r])) {
                $viste[$r] = true;
                $out[] = $r;
            }
        }

        return ['titolo' => mb_substr($titolo, 0, 200), 'testo' => mb_substr(implode("\n", $out), 0, self::MAX_CARATTERI)];
    }
}
