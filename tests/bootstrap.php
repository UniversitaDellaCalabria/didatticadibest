<?php

declare(strict_types=1);

// Avvio dei test PHPUnit: autoload di Composer (App\ e Tests\) e costanti del portale usate dalle classi.
require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('RADICE_SITO')) {
    define('RADICE_SITO', dirname(__DIR__));
}

// Costanti del portale usate dalle classi (definite in inc/ per il sito)
if (!defined('AMBITI_EVENTO')) {
    define('AMBITI_EVENTO', [
        'orientamento' => ['nome' => 'Orientamento', 'icona' => 'fa-compass', 'colore' => '#0056B3', 'descr' => 'Per futuri studenti e scuole'],
        'ricerca' => ['nome' => 'Ricerca', 'icona' => 'fa-flask', 'colore' => '#7c3aed', 'descr' => 'Seminari e conferenze scientifiche'],
        'terza_missione' => ['nome' => 'Public engagement', 'icona' => 'fa-people-group', 'colore' => '#b45309', 'descr' => 'Eventi aperti alla cittadinanza, terza missione'],
        'didattica' => ['nome' => 'Didattica', 'icona' => 'fa-graduation-cap', 'colore' => '#047857', 'descr' => 'Per gli studenti iscritti'],
    ]);
}

if (!defined('TIPI_AREA')) {
    define('TIPI_AREA', [
        'fsl' => ['nome' => 'Formazione Scuola Lavoro', 'sezione' => 'fsl', 'disponibile' => true],
        'eventi' => ['nome' => 'Eventi e seminari', 'sezione' => 'orientamento', 'disponibile' => true],
        'gruppi' => ['nome' => 'Gruppi degli insegnamenti', 'sezione' => 'calendari', 'disponibile' => true],
        'calendario' => ['nome' => 'Aule, laboratori e sportelli', 'sezione' => 'calendari', 'disponibile' => true],
    ]);
}
if (!defined('CAMPO_PARTECIPANTI')) {
    define('CAMPO_PARTECIPANTI', 'numero_partecipanti');
}
if (!defined('POSTI_SENZA_LIMITE')) {
    define('POSTI_SENZA_LIMITE', 1000);
}
