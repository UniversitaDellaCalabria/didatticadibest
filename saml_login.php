<?php
// config.php apre la sessione con i cookie params corretti (SameSite, Secure, HttpOnly)
require_once 'config.php';
require_once 'functions.php';

// Rate limiting: max 15 accessi alla pagina di login in 5 minuti per IP
if (!check_rate_limit($conn, 'saml_login', 15, 300)) {
    http_response_code(429);
    die("Troppi tentativi di accesso. Attendi qualche minuto e riprova.");
}


$_saml_env = @parse_ini_string(preg_replace('/^\s*#.*$/m', '', (string)@file_get_contents(defined('FILE_ENV') ? FILE_ENV : __DIR__ . '/.env'))) ?: []; // righe con # ignorate (vedi config.php)
$simplesaml_path = $_saml_env['SIMPLESAML_PATH'] ?? '/opt/simplesamlphp/lib/_autoload.php';
unset($_saml_env);

// Accesso con il SSO di Ateneo (ruoli di base, SimpleSAML, creazione/aggiornamento dell'utente, sessione): App\Auth\Saml\AccessoSso
\App\Core\App::get(\App\Auth\Saml\AccessoSso::class)->accedi(
    (string)$simplesaml_path, (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    function () { imposta_cookie_uscito(false); }
);

// Blocca open redirect: accetta solo percorsi relativi (no schema http://, javascript:, né URL protocol-relative //)
$redirect_raw = $_GET['redirect'] ?? '';
if (
    !empty($redirect_raw) &&
    !preg_match('#^[a-z][a-z0-9+\-.]*:#i', $redirect_raw) &&
    strpos($redirect_raw, '//') !== 0
) {
    $redirect = $redirect_raw;
} else {
    $redirect = 'index.php';
}
$redirect_js = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
// JS redirect bypassa bfcache e cache HTTP: il browser ricarica sempre la pagina dal server
ob_end_clean();
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Accesso completato</title>
<script>window.location.replace('<?php echo $redirect_js; ?>');</script>
</head>
<body>Accesso effettuato. <a href="<?php echo $redirect_js; ?>">Clicca qui se non vieni reindirizzato.</a></body>
</html>
<?php
exit;
