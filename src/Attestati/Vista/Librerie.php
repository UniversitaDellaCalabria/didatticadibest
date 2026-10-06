<?php

declare(strict_types=1);

namespace App\Attestati\Vista;

/** Librerie e caratteri salvati sul server (assets/vendor, assets/js): nessun CDN riceve l'indirizzo dei visitatori. */
final class Librerie
{
    /**
     * Percorso dalla radice del sito di un file in assets/vendor (stessa struttura dei CDN), valido sia dalle pagine
     * pubbliche sia da /admin. $urlBase = indirizzo del portale senza "/" finale.
     */
    public static function urlVendor(string $urlBase, string $percorso): string
    {
        return rtrim((string) parse_url($urlBase, PHP_URL_PATH), '/') . '/assets/vendor/' . ltrim($percorso, '/');
    }

    /**
     * Librerie JavaScript salvate sul server (assets/js): QR e scanner funzionano anche se il CDN non risponde.
     * Se il file locale mancasse (es. non caricato sul server) si ripiega sul CDN, in modo sincrono
     * (document.write subito dopo lo script locale): il codice che segue trova la libreria come prima.
     */
    public static function scriptLibreria(string $urlBase, string $nome): string
    {
        $librerie = [
            'qrcode' => ['qrcode-generator-1.4.4.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js',
                         'sha384-mZT2gIty7ZDdOGkxfP6joZcYdMW1Jvj9dRlfpTmaJAKKXTqzygtB22k7FLe+KZC1', 'typeof qrcode==="function"'],
            'html5-qrcode' => ['html5-qrcode-2.3.8.min.js', 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js',
                         'sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+', 'typeof Html5Qrcode==="function"'],
        ];
        if (!isset($librerie[$nome])) {
            return '';
        }
        [$file, $cdn, $sri, $presente] = $librerie[$nome];
        // Percorso dalla radice del sito (es. /eventi/assets/js/...): vale sia dalle pagine pubbliche sia da /admin
        $locale = rtrim((string) parse_url($urlBase, PHP_URL_PATH), '/') . '/assets/js/' . $file;

        return '<script src="' . htmlspecialchars($locale) . '" integrity="' . $sri . '"></script>'
             . '<script>' . $presente . '||document.write(\'<script src="' . $cdn . '" integrity="' . $sri . '" crossorigin="anonymous"><\/script>\');</script>';
    }
}
