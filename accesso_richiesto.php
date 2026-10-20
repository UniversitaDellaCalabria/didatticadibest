<?php
// accesso_richiesto.php - Per le pagine che si vedono solo dopo l'accesso (credenziali di Ateneo, SPID o CIE) ma non richiedono un ruolo:
// chi non è entrato trova una pagina che lo dice all'inizio e il pulsante per accedere, poi torna alla pagina che voleva.
// Uso, dopo config.php e functions.php:  require_once 'accesso_richiesto.php';  richiedi_accesso($conn, 'Titolo della pagina', 'cosa vedrai');
if (!function_exists('richiedi_accesso')) {
    function richiedi_accesso($conn, string $titolo, string $cosa): void {
        sync_sso_user($conn);
        if (!empty($_SESSION['utente_id'])) return;
        global $page_cfg;
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $dopo = basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)) . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
        $page_cfg['titolo'] = $titolo;
        http_response_code(401);
        require_once __DIR__ . '/header.php';
        ?>
<div class="container my-5" style="max-width: 720px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <?php echo $h($titolo); ?></nav>
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
        <h1 class="h3 fw-bold mb-3"><i class="fa fa-lock me-2 text-danger" aria-hidden="true"></i><?php echo $h($titolo); ?></h1>
        <p class="fs-5 mb-2"><strong>Per visualizzare questa pagina devi prima fare l'accesso.</strong></p>
        <p class="text-secondary"><?php echo $h($cosa); ?> Accedi con le credenziali di Ateneo, con SPID o con CIE: dopo l'accesso torni direttamente qui.</p>
        <a class="btn btn-danger btn-lg" href="saml_login.php?redirect=<?php echo urlencode($dopo); ?>"><i class="fa fa-sign-in-alt me-2" aria-hidden="true"></i>Accedi</a>
    </div></div>
</div>
<?php
        require_once __DIR__ . '/footer.php';
        exit;
    }
}
