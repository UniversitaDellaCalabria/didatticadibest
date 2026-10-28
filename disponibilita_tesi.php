<?php
// Disponibilità dei docenti per la tesi (pagina pubblica): elenco con semafori. Il disegno sta in inc/disponibilita_pubblica.php.
require_once 'config.php';
require_once 'functions.php';

$tipo_disp = 'tesi';
require __DIR__ . '/inc/disponibilita_pubblica.php';
