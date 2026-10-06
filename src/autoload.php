<?php

declare(strict_types=1);

/*
 * src/autoload.php - Autoload PSR-4 delle classi App\ senza Composer.
 * Sul server non si esegue Composer: questo file basta per caricare le classi di src/ (namespace App\ → cartella src/).
 * In sviluppo, se esiste vendor/autoload.php (composer install), src/bootstrap.php usa quello (con PHPUnit e gli strumenti).
 */

spl_autoload_register(static function (string $classe): void {
    $prefisso = 'App\\';
    if (strncmp($classe, $prefisso, strlen($prefisso)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($classe, strlen($prefisso))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
