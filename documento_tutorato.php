<?php
// documento_tutorato.php - Scarica un documento del tutorato (serve l'accesso). I file stanno in cartelle bloccate al web.
//   ?i=ID&f=decreto_bando|decreto_commissione|decreto_atti|unilav|lettera|registro|fine|dichiarazione   documento di una pratica
//   ?b=ID&f=decreto_bando|decreto_commissione|decreto_atti                                               decreto di un bando (referente)
// Permessi: referente del tutorato e amministratori (tutto tranne la dichiarazione individuale, che ha l'IBAN), ufficio pagamenti
// (le pratiche che gli sono state passate), tutor (le sue). La dichiarazione si apre solo da tutor e ufficio, e ogni apertura si registra.
require_once __DIR__ . '/middleware.php';

$nega = function (string $msg = 'Documento non disponibile.'): never {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit($msg);
};
$f = preg_replace('/[^a-z_]/', '', (string)($_GET['f'] ?? ''));
$referente = $is_full_admin || ha_modulo($conn, (int)$u_id, 'didattica') || utente_operatore_ufficio($conn, $user_info, 'bandi');
$pag = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioPagamenti::class);

if (isset($_GET['b'])) {
    // Decreto di un bando: solo per chi lavora al tutorato
    if (!$referente || !in_array($f, ['decreto_bando', 'decreto_commissione', 'decreto_atti'], true)) $nega();
    $b = \App\Core\App::per($conn)->get(\App\Tutorato\BandoRepository::class)->perId((int)$_GET['b']);
    $p = $b ? \App\Core\App::per($conn)->get(\App\Tutorato\FileTutorato::class)->percorsoDecreto($b, substr($f, 8)) : null;
    if (!$p) $nega();
    $doc = ['percorso' => $p, 'contenuto' => null, 'nome' => 'Tutorato_' . $f . '_bando' . (int)$b['id'] . '.pdf'];
} else {
    $i = \App\Core\App::per($conn)->get(\App\Tutorato\IncaricoRepository::class)->perId((int)($_GET['i'] ?? 0));
    if (!$i) $nega();
    $ruolo = $pag->ruolo($user_info, $i, $referente);
    if ($ruolo === null) $nega();
    // L'IBAN è solo del tutor e dell'ufficio pagamenti
    if ($f === 'dichiarazione' && !in_array($ruolo, ['tutor', 'ufficio'], true)) $nega();
    // Il modello SCAFIS (iter per Uniticket, ha l'IBAN) lo apre il tutor e chi lavora al tutorato: ogni apertura si registra
    if ($f === 'scafis' && !in_array($ruolo, ['tutor', 'referente'], true)) $nega();
    $doc = $pag->documento($i, $f, $user_info);
    if (!$doc) $nega();
}

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['scarica']) ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^\w.\-]+/', '_', $doc['nome']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if ($doc['percorso'] !== null) {
    header('Content-Length: ' . filesize($doc['percorso']));
    readfile($doc['percorso']);
} else {
    header('Content-Length: ' . strlen((string)$doc['contenuto']));
    echo $doc['contenuto'];
}
exit;
