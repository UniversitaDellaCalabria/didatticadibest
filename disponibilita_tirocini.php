<?php
// Disponibilità dei docenti per i tirocini (pagina pubblica): carte con barra dei posti. Il disegno sta in inc/disponibilita_pubblica.php.
require_once 'config.php';
require_once 'functions.php';

$tipo_disp = 'tirocinio';
require __DIR__ . '/inc/disponibilita_pubblica.php';
