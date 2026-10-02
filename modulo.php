<?php
// modulo.php - Compilazione di un modulo online della didattica (serve l'accesso): apre una pratica.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$m = modulo_didattica($conn, (int)($_GET['id'] ?? 0));
if (!$m || $m['tipo'] !== 'online' || !(int)$m['attivo']) {
    http_response_code(404);
    $page_cfg['titolo'] = 'Modulo non trovato';
    require_once 'header.php';
    echo '<div class="container my-5"><h1 class="fw-bold">Modulo non disponibile</h1><p><a href="modulistica.php">Torna alla modulistica</a></p></div>';
    require_once 'footer.php'; exit;
}
$campi = campi_studente(campi_modulo($m['campi_json'])); // i campi dell'ufficio non li vede lo studente
$puo = utente_destinatario_modulo($conn, $m, $user_info);
[$aperto, $periodo] = periodo_modulo($m);
if (!$aperto) $puo = false;
$errori = [];
if ($puo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invia_modulo'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (!check_rate_limit($conn, 'modulo_didattica', 20, 3600)) $errori[] = "Troppi invii in poco tempo: riprova più tardi.";
    else {
        [$risposte, $errori] = leggi_risposte_modulo($campi, $conn);
        if (!$errori) {
            $id = crea_pratica($conn, $m, $user_info, $risposte);
            if ($id) { flash_set("Richiesta inviata: ti abbiamo mandato una conferma per email. Qui puoi seguirne lo stato."); header('Location: pratiche.php?id=' . $id); exit; }
            $errori[] = "Non è stato possibile salvare la richiesta: riprova.";
        } else {
            // Allegati già salvati di un invio non andato a buon fine: si eliminano
            foreach ($risposte as $r) if (!empty($r['file']) && is_file(RADICE_SITO . '/' . $r['file'])) @unlink(RADICE_SITO . '/' . $r['file']);
        }
    }
}

$page_cfg['titolo'] = $m['titolo'];
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 820px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <a href="modulistica.php">Modulistica</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary"><?php echo $h($m['titolo']); ?></span></nav>
    <h1 class="fw-bold h2 mb-2"><?php echo $h($m['titolo']); ?></h1>
    <?php if (trim(strip_tags((string)$m['descrizione'])) !== ''): ?><div class="mb-3"><?php echo strip_tags((string)$m['descrizione'], '<p><br><strong><em><ul><ol><li><a>'); ?></div><?php endif; ?>
    <?php if ($m['file_path']): ?><p><a href="<?php echo $h($m['file_path']); ?>" download><i class="fa fa-download me-1" aria-hidden="true"></i>Scarica il documento</a></p><?php endif; ?>

    <?php if (!$puo): ?>
        <?php if (!$aperto): ?><div class="alert alert-secondary"><i class="fa fa-calendar-xmark me-1" aria-hidden="true"></i>Il modulo non è compilabile ora: <?php echo $h($periodo); ?>.</div>
        <?php else: ?><div class="alert alert-warning">Questo modulo è riservato a: <strong><?php echo $h(DESTINATARI_MODULO[$m['destinatari']] ?? ''); ?></strong>.</div>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($errori): ?><div class="alert alert-danger" role="alert"><strong>Controlla i dati:</strong><ul class="mb-0"><?php foreach ($errori as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm p-3 p-md-4" style="border-radius:12px;">
            <?php csrf_field(); ?>
            <div class="bg-light rounded p-2 small mb-3"><i class="fa fa-user me-1" aria-hidden="true"></i>Richiesta di <strong><?php echo $h(trim($user_info['nome'] . ' ' . $user_info['cognome'])); ?></strong> · <?php echo $h($user_info['email']); ?><?php $matr = ($user_info['matricola_studente'] ?? '') ?: ($user_info['matricola_dipendente'] ?? ''); echo $matr ? ' · matricola ' . $h($matr) : ''; ?>
                <div class="text-secondary">Le comunicazioni arrivano a questa email; puoi cambiarla dal tuo Profilo.</div></div>
            <?php echo html_datalist_didattica($conn, $campi); foreach ($campi as $c): echo html_campo_pratica($c, $c['tipo'] === 'file' ? '' : valori_post_campo($c), $conn); endforeach; ?>
            <?php if (!$campi): ?><p class="text-secondary">Il modulo non ha domande: invia la richiesta e l'ufficio ti contatterà.</p><?php endif; ?>
            <button type="submit" name="invia_modulo" value="1" class="btn btn-success fw-bold mt-2"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia la richiesta</button>
        </form>
        <?php echo js_tabelle_pratica(); ?>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
