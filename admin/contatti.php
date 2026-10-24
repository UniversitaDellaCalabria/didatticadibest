<?php
// contatti.php - Sito pubblico › Contatti: «Chi contattare per cosa», i recapiti istituzionali mostrati agli studenti nella pagina pubblica contatti.php.
// Si scrivono a mano (nessun dato personale preso dagli uffici o dall'anagrafe). Logica in src/Portale/ContattiRepository.php.
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$rc = \App\Core\App::per($conn)->get(\App\Portale\ContattiRepository::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$url = 'contatti.php?p_id=' . (int)$filtro_p;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['esempi'])) {
        $n = $rc->inserisciEsempi();
        flash_set($n ? "Ho aggiunto $n contatti di esempio, ancora nascosti: completali e poi mostrali sul sito." : 'Ci sono già dei contatti.', $n ? 'success' : 'warning');
        registra_log_audit($conn, 'Contatti: esempi aggiunti', ['Contatti' => $n]);
    } elseif (isset($_POST['salva'])) {
        $id = (int)$_POST['salva'];
        $err = $rc->salva($id, $_POST);
        if ($err !== '') { flash_set($err, 'danger'); } else { flash_set($id ? 'Contatto salvato.' : 'Contatto aggiunto.'); registra_log_audit($conn, $id ? 'Contatto modificato' : 'Contatto aggiunto', ['Contatto' => (string)($_POST['titolo'] ?? '')]); }
    } elseif (isset($_POST['mostra'])) {
        $rc->mostra((int)$_POST['mostra'], ($_POST['valore'] ?? '') === '1');
        flash_set(($_POST['valore'] ?? '') === '1' ? 'Ora il contatto si vede sul sito.' : 'Ora il contatto è nascosto.');
    } elseif (isset($_POST['elimina'])) {
        $rc->elimina((int)$_POST['elimina']);
        registra_log_audit($conn, 'Contatto eliminato', ['Contatto' => (int)$_POST['elimina']]);
        flash_set('Contatto eliminato.');
    }
    admin_redirect($url);
}

$elenco = $rc->tutti();
$modifica = isset($_GET['modifica']) ? (int)$_GET['modifica'] : -1; // -1 nessuno, 0 nuovo, >0 quello scelto

// Il modulo di un contatto (nuovo o esistente): parole semplici, un campo per cosa
$modulo = function (array $c) use ($h): string {
    $id = (int)($c['id'] ?? 0);
    $f = fn(string $k, string $et, string $aiuto = '', string $tipo = 'text', string $col = 'col-md-6') => '<div class="' . $col . '"><label class="form-label fw-bold" for="ct' . $id . $k . '">' . $et . '</label><input class="form-control" type="' . $tipo . '" id="ct' . $id . $k . '" name="' . $k . '" value="' . $h($c[$k] ?? '') . '">' . ($aiuto !== '' ? '<div class="form-text">' . $aiuto . '</div>' : '') . '</div>';
    return '<form method="POST" class="row g-3">' . (new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->campo()
        . $f('titolo', 'A chi ci si rivolge *', 'Per esempio: Segreteria didattica', 'text', 'col-12')
        . $f('per_cosa', 'Per quali richieste', 'Una frase: certificati, piano di studi, convalide…', 'text', 'col-12')
        . $f('luogo', 'Dove si trova', 'Edificio, piano, stanza')
        . $f('orari', 'Quando è aperto', 'Per esempio: lunedì e mercoledì 10-12')
        . $f('email', 'Email', 'Un indirizzo dell\'ufficio, non di una persona')
        . $f('telefono', 'Telefono')
        . $f('link_etichetta', 'Testo del collegamento', 'Per esempio: Prenota un appuntamento')
        . $f('link_url', 'Indirizzo del collegamento', 'Inizia con https:// oppure una pagina del portale')
        . $f('ordine', 'Posizione nell\'elenco', 'Numero piccolo = in alto', 'number', 'col-md-3')
        . '<div class="col-md-9 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="visibile" value="1"' . (!empty($c['visibile']) ? ' checked' : '') . '> <span class="fw-bold">Mostra sul sito</span></label></div>'
        . '<div class="col-12 d-flex gap-2"><button type="submit" name="salva" value="' . $id . '" class="btn btn-azione fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>'
        . '<a class="btn btn-outline-secondary" href="contatti.php">Annulla</a></div></form>';
};
?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div><h1 class="pn-titolo"><i class="fa fa-address-card me-2" aria-hidden="true"></i>Contatti per gli studenti</h1>
        <p class="pn-aiuto">«Chi contattare per cosa»: la pagina pubblica che dice agli studenti a quale ufficio rivolgersi. Qui scegli tu cosa mostrare: niente viene preso dagli uffici o dalle persone.</p></div>
    <div class="d-flex gap-2"><a href="../contatti.php" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="fa fa-external-link-alt me-1" aria-hidden="true"></i>Guarda la pagina</a>
        <a href="contatti.php?modifica=0&amp;p_id=<?php echo (int)$filtro_p; ?>" class="btn btn-azione fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi un contatto</a></div>
</div>

<?php if ($modifica === 0): ?>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 fw-bold mb-3">Nuovo contatto</h2><?php echo $modulo(['ordine' => count($elenco) + 1]); ?></div></div>
<?php endif; ?>

<?php if (!$elenco && $modifica !== 0): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
        <i class="fa fa-address-card fs-1 text-secondary mb-3 d-block" aria-hidden="true"></i>
        <h2 class="h5 fw-bold">Non c'è ancora nessun contatto</h2>
        <p class="text-secondary mb-3">Puoi partire da cinque voci di esempio (segreteria, tirocini, tesi, orientamento, direzione): restano nascoste finché non le completi.</p>
        <form method="POST" class="d-inline"><?php csrf_field(); ?><button type="submit" name="esempi" value="1" class="btn btn-azione fw-bold"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Parti dagli esempi</button></form>
    </div></div>
<?php endif; ?>

<div class="d-flex flex-column gap-3">
<?php foreach ($elenco as $c): $id = (int)$c['id']; $vis = (int)$c['visibile'] === 1; $manca = $c['email'] === '' && $c['telefono'] === '' && $c['link_url'] === ''; ?>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <?php if ($modifica === $id): ?>
            <h2 class="h5 fw-bold mb-3">Modifica «<?php echo $h($c['titolo']); ?>»</h2><?php echo $modulo($c); ?>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-start gap-3">
                <div class="flex-grow-1" style="min-width:240px;">
                    <h2 class="h5 fw-bold mb-1"><?php echo $h($c['titolo']); ?> <?php echo $vis ? pn_stato('Si vede sul sito', 'ok', 'fa-eye') : pn_stato('Nascosto', 'spento', 'fa-eye-slash'); ?></h2>
                    <div class="text-secondary"><?php echo $h($c['per_cosa']); ?></div>
                    <div class="small mt-2"><?php echo implode(' · ', array_filter([$c['luogo'], $c['orari'], $c['telefono'], $c['email']], fn($x) => $x !== '')) ?: '<span class="text-secondary">Nessun recapito ancora: aggiungi almeno un\'email o un telefono.</span>'; ?></div>
                    <?php if ($manca && $vis): ?><div class="small text-danger mt-1"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Si vede sul sito ma senza recapiti.</div><?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="valore" value="<?php echo $vis ? 0 : 1; ?>"><button type="submit" name="mostra" value="<?php echo $id; ?>" class="btn btn-outline-secondary"><?php echo $vis ? 'Nascondi' : 'Mostra sul sito'; ?></button></form>
                    <a href="contatti.php?modifica=<?php echo $id; ?>&amp;p_id=<?php echo (int)$filtro_p; ?>" class="btn btn-azione fw-bold">Modifica</a>
                    <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="elimina" value="<?php echo $id; ?>" class="btn btn-outline-danger" data-confirm="Eliminare il contatto «<?php echo $h($c['titolo']); ?>»?" aria-label="Elimina «<?php echo $h($c['titolo']); ?>»"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
                </div>
            </div>
        <?php endif; ?>
    </div></div>
<?php endforeach; ?>
</div>
<?php require_once 'admin_footer.php'; ?>
