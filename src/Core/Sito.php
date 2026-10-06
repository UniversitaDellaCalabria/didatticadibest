<?php

declare(strict_types=1);

namespace App\Core;

/** Indirizzo pubblico e cartella del portale (per i link nelle email, nei PDF e nei calendari). */
final class Sito
{
    public function __construct(private string $radice, private ?string $urlSito = null)
    {
    }

    public function radice(): string
    {
        return $this->radice;
    }

    /**
     * Indirizzo del portale senza "/" finale, es. https://dibest2.unical.it/didattica.
     * Da riga di comando (cron) non ci sono HTTPS, host né document root: si usa URL_SITO del .env
     * (altrimenti https://dibest2.unical.it/eventi). Spostato da url_base_sito() (inc/base.php), che resta come facciata.
     */
    public function urlBase(): string
    {
        $cli = PHP_SAPI === 'cli';
        if ($cli && preg_match('#^https?://[^/]+#i', (string) $this->urlSito)) {
            return rtrim((string) $this->urlSito, '/');
        }
        $proto = ($cli || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'dibest2.unical.it';
        $docRoot = (!$cli && !empty($_SERVER['DOCUMENT_ROOT'])) ? (string) realpath($_SERVER['DOCUMENT_ROOT']) : '';
        $radice = (string) realpath($this->radice);
        $rel = ($docRoot !== '' && strpos($radice, $docRoot) === 0) ? str_replace('\\', '/', substr($radice, strlen($docRoot))) : '/eventi';

        return $proto . $host . rtrim($rel, '/');
    }
}
