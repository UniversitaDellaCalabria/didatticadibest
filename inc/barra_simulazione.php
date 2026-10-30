<?php
// inc/barra_simulazione.php - La barra fissa in fondo alla pagina mentre l'amministratore vede il portale come un'altra persona («Entra come…»).
// Si include dai piè di pagina (footer.php, admin/admin_footer.php); non scrive niente se la simulazione non c'è. La testata del sito non si tocca.
if (!empty($_SESSION['simula']) && is_array($_SESSION['simula']) && (int)($_SESSION['simula']['fino'] ?? 0) >= time()) {
    $__s = $_SESSION['simula'];
    $__prefisso = basename(dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))) === 'admin' ? '../' : '';
    $__min = max(1, (int)ceil(((int)$__s['fino'] - time()) / 60));
    ?>
<div role="region" aria-label="Simulazione di accesso" style="position:fixed;left:0;right:0;bottom:0;z-index:2000;background:#1e293b;color:#fff;padding:.6rem 1rem;display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;justify-content:center;box-shadow:0 -2px 10px rgba(0,0,0,.3);font-size:.9rem;">
    <span><i class="fa fa-user-secret me-1" aria-hidden="true"></i>Stai vedendo il portale come <strong><?php echo htmlspecialchars((string)($__s['nome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong><?php echo !empty($__s['profili']) ? ' (' . htmlspecialchars((string)$__s['profili'], ENT_QUOTES, 'UTF-8') . ')' : ''; ?>
        · solo visione, non si modifica niente · ancora circa <?php echo $__min; ?> min</span>
    <form method="post" action="<?php echo $__prefisso; ?>simula_esci.php" class="m-0">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" style="border:0;border-radius:6px;background:#B80000;color:#fff;font-weight:700;padding:.35rem .9rem;cursor:pointer;">Torna a te</button>
    </form>
</div>
<div aria-hidden="true" style="height:3.5rem;"></div>
<?php
    unset($__s, $__prefisso, $__min);
}
