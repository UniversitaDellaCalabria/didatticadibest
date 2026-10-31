<?php
// =========================================================================
// Invita l'azienda: lo studente, dalla sua pratica di tesi, invita l'azienda non ancora convenzionata a compilare la convenzione (nome e email del referente).
// Logica in App\ConvenzioniTesi\InvitoAziendaDaPratica. Si arriva dal riquadro «Azienda non ancora convenzionata» di pratiche.php; torna lì.
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

$pratica_ia = (int) ($_POST['pratica'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $pratica_ia > 0) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $errore_ia = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\InvitoAziendaDaPratica::class)->invita($user_info, $pratica_ia, (string) ($_POST['nome'] ?? ''), (string) ($_POST['email'] ?? ''));
    flash_set($errore_ia ?? 'Invito inviato: l\'azienda riceve il link per compilare la convenzione. Qui sotto ne vedi l\'avanzamento.', $errore_ia ? 'warning' : 'success');
    header('Location: pratiche.php?id=' . $pratica_ia); exit;
}
header('Location: pratiche.php'); exit;
