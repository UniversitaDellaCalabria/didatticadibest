<?php

declare(strict_types=1);

/*
 * src/bootstrap.php - Avvio delle classi OOP (namespace App\, cartella src/), incluso da functions.php.
 * Carica l'autoload (quello di Composer se c'è vendor/, altrimenti src/autoload.php) e, se la connessione
 * del portale esiste già, prepara il container con la classe Database costruita sulla stessa connessione.
 * Non cambia nulla del comportamento del sito: le funzioni procedurali restano e, man mano, diventano facciate delle classi.
 */

if (!defined('APP_BOOTSTRAP')) {
    define('APP_BOOTSTRAP', true);
    $vendor = dirname(__DIR__) . '/vendor/autoload.php';
    require is_file($vendor) ? $vendor : __DIR__ . '/autoload.php';
    unset($vendor);
}
