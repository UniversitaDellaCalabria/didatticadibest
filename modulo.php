<?php
// modulo.php - Compilazione di un modulo online della didattica (serve l'accesso): apre una pratica.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$m = modulo_didattica($conn, (int)($_GET['id'] ?? 0));
// Anteprima per chi gestisce la Didattica: si vede il modulo anche se non è pubblicato (o non è aperto a chi lo guarda), ma non si invia
$anteprima = (int)($_GET['anteprima'] ?? 0) === 1 && utente_gestisce_didattica($conn, $user_info);
if (!$m || $m['tipo'] !== 'online' || (!(int)$m['attivo'] && !$anteprima)) {
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
if ($anteprima) { $puo = true; $aperto = true; }
$errori = [];
// Bozza da riprendere o pratica già inviata che nessuno ha ancora preso in carico (?pratica=): si compila di nuovo lo stesso modulo
$pr = null;
if ($puo && !$anteprima && (int)($_GET['pratica'] ?? 0) > 0) {
    $pr = pratica($conn, (int)$_GET['pratica']);
    if (!$pr || (int)$pr['utente_id'] !== (int)$user_info['id'] || (int)$pr['modulo_id'] !== (int)$m['id']) { $pr = null; flash_set("Pratica non trovata.", 'danger'); header('Location: pratiche.php'); exit; }
    if (!pratica_modificabile($pr)) { flash_set("La pratica è già stata presa in carico dall'ufficio: non si può più modificare. Puoi scrivere all'ufficio da qui.", 'warning'); header('Location: pratiche.php?id=' . (int)$pr['id']); exit; }
}
$e_bozza = $pr && $pr['stato'] === 'bozza';
$vecchie = $pr ? (json_decode((string)$pr['risposte_json'], true) ?: []) : [];
if ($puo && !$anteprima && $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['invia_modulo']) || isset($_POST['salva_bozza']))) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (!check_rate_limit($conn, 'modulo_didattica', 40, 3600)) $errori[] = "Troppi invii in poco tempo: riprova più tardi.";
    else {
        [$risposte, $errori] = leggi_risposte_modulo($campi, $conn);
        if ($pr) $errori = errori_senza_allegati_presenti($errori, $campi, $vecchie);
        // Attestati: i docenti indicati devono essere nell'anagrafe (a loro arriva la richiesta di conferma); la bozza si salva comunque
        if (!$errori && !isset($_POST['salva_bozza'])) $errori = controlla_docenti_modulo($conn, $m, $risposte);
        if (isset($_POST['salva_bozza']) && !$pr || isset($_POST['salva_bozza']) && $e_bozza) {
            // Bozza: nessun controllo sui campi obbligatori, si riprende e si invia quando si vuole
            $id = salva_bozza_pratica($conn, $m, $user_info, $risposte, $e_bozza ? (int)$pr['id'] : 0);
            if ($id) { flash_set("Bozza salvata: la trovi tra le tue pratiche e puoi riprenderla e inviarla quando vuoi."); header('Location: modulo.php?id=' . (int)$m['id'] . '&pratica=' . $id); exit; }
            $errori[] = "Non è stato possibile salvare la bozza: riprova.";
        } elseif (!$errori) {
            if ($e_bozza) {
                if (invia_bozza_pratica($conn, (int)$pr['id'], $m, $user_info, $risposte)) { flash_set("Richiesta inviata: ti abbiamo mandato una conferma per email. Qui puoi seguirne lo stato."); header('Location: pratiche.php?id=' . (int)$pr['id']); exit; }
                $errori[] = "Non è stato possibile inviare la richiesta: riprova.";
            } elseif ($pr) {
                $err_m = modifica_pratica($conn, (int)$pr['id'], $m, $user_info, $risposte);
                if (!$err_m) { flash_set("Richiesta modificata: l'ufficio vedrà i dati aggiornati."); header('Location: pratiche.php?id=' . (int)$pr['id']); exit; }
                $errori[] = $err_m;
            } else {
                $id = crea_pratica($conn, $m, $user_info, $risposte);
                if ($id) { flash_set("Richiesta inviata: ti abbiamo mandato una conferma per email. Qui puoi seguirne lo stato e, finché l'ufficio non la prende in carico, modificarla."); header('Location: pratiche.php?id=' . $id); exit; }
                $errori[] = "Non è stato possibile salvare la richiesta: riprova.";
            }
        }
        if ($errori) {
            // Allegati già salvati di un invio non andato a buon fine: si eliminano
            foreach ($risposte as $r) if (!empty($r['file']) && is_file(RADICE_SITO . '/' . $r['file']) && !in_array($r['file'], array_column($vecchie, 'file'), true)) @unlink(RADICE_SITO . '/' . $r['file']);
        }
    }
}
$iniziali = $pr ? valori_iniziali_pratica($conn, $campi, $vecchie) : [];

$page_cfg['titolo'] = $m['titolo'];
require_once 'header.php';
?>
<?php $con_tabelle = in_array('tabella', array_column($campi, 'tipo'), true); ?>
<div class="container my-4" style="max-width: <?php echo $con_tabelle ? 1180 : 920; ?>px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <a href="modulistica.php">Modulistica</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary"><?php echo $h($m['titolo']); ?></span></nav>
    <?php if ($anteprima): ?><div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status"><i class="fa fa-eye" aria-hidden="true"></i><span><strong>Anteprima</strong> per chi gestisce la Didattica: il modulo <?php echo (int)$m['attivo'] ? 'è pubblicato' : '<strong>non è ancora pubblicato</strong> (gli studenti non lo vedono)'; ?>. Da qui non si può inviare.</span><a class="btn btn-sm btn-outline-secondary ms-auto" href="admin/didattica.php?tab=moduli"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Torna ai moduli</a></div><?php endif; ?>
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
            <?php echo html_datalist_didattica($conn, $campi); ?>
            <?php // Campi brevi affiancati; con più sezioni (titoli) il modulo va a pagine (assets/js/campi-pratica.js) ?>
            <div class="pagine-modulo"><div class="row gx-4">
            <?php foreach ($campi as $c): echo html_campo_pratica($c, $c['tipo'] === 'file' ? '' : ($_SERVER['REQUEST_METHOD'] === 'POST' ? valori_post_campo($c) : (array_key_exists($c['nome'], $iniziali) ? $iniziali[$c['nome']] : valore_iniziale_campo($c, $user_info))), $conn, true); endforeach; ?>
            </div></div>
            <?php if (!$campi): ?><p class="text-secondary">Il modulo non ha domande: invia la richiesta e l'ufficio ti contatterà.</p><?php endif; ?>
            <?php $allegati_presenti = array_filter($vecchie, fn($r) => !empty($r['file'])); if ($allegati_presenti): ?><div class="small text-secondary mb-2"><i class="fa fa-paperclip me-1" aria-hidden="true"></i>Allegati già caricati: <?php echo $h(implode(', ', array_map(fn($r) => (string)($r['nome_file'] ?? ''), $allegati_presenti))); ?>. Restano se non ne scegli di nuovi.</div><script>document.querySelectorAll('input[type=file][required]').forEach(function (i) { i.required = false; });</script><?php endif; ?>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                <?php if ($pr && !$e_bozza): ?>
                <button type="submit" name="invia_modulo" value="1" class="btn btn-success fw-bold invia-modulo"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva le modifiche</button>
                <a href="pratiche.php?id=<?php echo (int)$pr['id']; ?>" class="btn btn-outline-secondary">Annulla</a>
                <?php else: ?>
                <button type="submit" name="invia_modulo" value="1" class="btn btn-success fw-bold invia-modulo"<?php echo $anteprima ? ' disabled title="Anteprima: non si può inviare"' : ''; ?>><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia la richiesta</button>
                <button type="submit" name="salva_bozza" value="1" class="btn btn-outline-secondary fw-bold salva-bozza" formnovalidate<?php echo $anteprima ? ' disabled title="Anteprima: non si può salvare"' : ''; ?>><i class="fa fa-pen me-1" aria-hidden="true"></i>Salva come bozza</button>
                <?php endif; ?>
            </div>
            <?php if ($pr && !$e_bozza): ?><div class="form-text">Puoi modificare la richiesta finché l'ufficio non la prende in carico.</div>
            <?php elseif ($e_bozza): ?><div class="form-text">Questa è una bozza: non è ancora arrivata all'ufficio. La puoi riprendere dalle tue pratiche.</div>
            <?php else: ?><div class="form-text">Con «Salva come bozza» la richiesta resta con te: la riprendi e la invii quando vuoi. Dopo l'invio puoi ancora modificarla finché l'ufficio non la prende in carico.</div><?php endif; ?>
        </form>
        <?php echo js_tabelle_pratica(); ?>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
