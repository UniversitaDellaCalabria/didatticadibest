<?php
// autorizzazione_scuola.php - Scarica l'autorizzazione della scuola (PDF) caricata per una prenotazione FSL (?code=).
// Solo chi ha prenotato, i gestori e gli amministratori; il file sta in uploads/autorizzazioni/ (cartella bloccata al web).
require_once 'config.php';
require_once 'functions.php';
sync_sso_user($conn);

$nega = function (int $codice, string $testo) { while (ob_get_level() > 0) ob_end_clean(); http_response_code($codice); exit($testo); };
$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{4,50}$/', $code)) $nega(400, 'Codice non valido.');
if (empty($_SESSION['utente_id'])) { header('Location: saml_login.php?redirect=' . urlencode('autorizzazione_scuola.php?code=' . $code)); exit; }

$pr_id = \App\Core\App::per($conn)->get(\App\Attestati\ServizioAttestati::class)->idPerCodice($code);
$p = $pr_id ? prenotazione_per_attestati($conn, $pr_id) : null;
if (!$p || !puo_vedere_prenotazione($p)) $nega(403, 'Accesso negato.');

$f = \App\Core\App::per($conn)->get(\App\Fsl\ServizioDocumentiClasse::class)->file($pr_id);
if ($f === null) $nega(404, 'Nessuna autorizzazione caricata.');

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]+/u', '_', $f['nome']) . '"');
header('Content-Length: ' . filesize($f['percorso']));
header('X-Content-Type-Options: nosniff');
readfile($f['percorso']);
exit;
