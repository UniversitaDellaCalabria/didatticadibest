<?php
// agenda_ics.php - Calendario .ics dell'agenda (stessi filtri di agenda.php: ?ambito=…, ?scuole=1): si aggiunge per indirizzo
// a Google Calendar, Outlook o al telefono e si aggiorna da solo con i nuovi eventi.
require_once 'config.php';
require_once 'functions.php';

$ambito = isset(AMBITI_EVENTO[$_GET['ambito'] ?? '']) ? $_GET['ambito'] : '';
$scuole = !empty($_GET['scuole']);
$nome = 'DiBEST · ' . ($ambito !== '' ? AMBITI_EVENTO[$ambito]['nome'] : ($scuole ? 'Per le scuole' : 'Eventi e seminari'));
$ics = ics_agenda($conn, eventi_agenda($conn, ['ambito' => $ambito, 'scuole' => $scuole]), $nome);

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="agenda_dibest' . ($ambito !== '' ? '_' . $ambito : '') . '.ics"');
header('Cache-Control: public, max-age=1800');
echo $ics;
