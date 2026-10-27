<?php
// area_personale.php - Sito pubblico › Area personale: i riquadri che ogni profilo (studente, docente, Direttore, personale, gestore, amministratore, esterno) trova
// nella sua Area personale. Si accendono e si spengono, si riordinano, si assegnano ai profili; si possono scrivere riquadri nuovi (testo e fino a due pulsanti).
// I riquadri di sistema (pratiche, assistenza, tutorato…) si disegnano da soli con i dati della persona e non si eliminano: si spengono. Logica in src/Portale/Area.
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

use App\Portale\Area\CatalogoRiquadri;
use App\Portale\Area\Profili;
use App\Portale\Area\RiquadriRepository;

$rr = \App\Core\App::per($conn)->get(RiquadriRepository::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$profilo = isset(Profili::NOMI[$_GET['profilo'] ?? '']) ? (string)$_GET['profilo'] : Profili::STUDENTE;
$url = fn(array $extra = []) => 'area_personale.php?' . http_build_query(['p_id' => (int)$filtro_p, 'profilo' => $profilo] + $extra);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $adesso = date('Y-m-d H:i:s');
    $profilo = isset(Profili::NOMI[$_POST['profilo'] ?? '']) ? (string)$_POST['profilo'] : $profilo;
    if (isset($_POST['acceso'])) {
        $rr->imposta((int)$_POST['acceso'], !empty($_POST['valore']), $adesso);
        flash_set(!empty($_POST['valore']) ? 'Riquadro acceso: da ora lo vedono i profili scelti.' : 'Riquadro spento: nessuno lo vede più (si può riaccendere quando vuoi).', 'success');
    } elseif (isset($_POST['sposta'])) {
        $rr->sposta((int)$_POST['sposta'], ($_POST['verso'] ?? '') === 'su' ? -1 : 1, $profilo, $adesso);
    } elseif (isset($_POST['elimina'])) {
        flash_set($rr->elimina((int)$_POST['elimina']) ? 'Riquadro eliminato.' : 'I riquadri di sistema non si eliminano: spegnili.', 'success');
    } elseif (isset($_POST['salva'])) {
        $id = (int)$_POST['salva'];
        [$nuovo, $err] = $rr->salva($id, [
            'titolo' => $_POST['titolo'] ?? '', 'testo' => $_POST['testo'] ?? '', 'icona' => $_POST['icona'] ?? '', 'colore' => $_POST['colore'] ?? '',
            'link' => $_POST['link'] ?? '', 'etichetta_link' => $_POST['etichetta_link'] ?? '', 'link2' => $_POST['link2'] ?? '', 'etichetta_link2' => $_POST['etichetta_link2'] ?? '',
            'profili' => (array)($_POST['profili'] ?? []), 'ordine' => $_POST['ordine'] ?? '', 'attivo' => !empty($_POST['attivo']),
        ], $adesso);
        if ($err !== null) {
            flash_set($err, 'danger');
            admin_redirect($url(['modifica' => $id]));
        }
        registra_log_audit($conn, $id ? 'Area personale: riquadro modificato' : 'Area personale: riquadro creato', ['Riquadro' => $nuovo]);
        flash_set('Riquadro salvato.', 'success');
    }
    admin_redirect($url());
}

$modifica = isset($_GET['modifica']) ? (int)$_GET['modifica'] : -1;
$mod = $modifica > 0 ? $rr->perId($modifica) : null;
$elenco = $rr->perProfilo($profilo);
$quanti = [];
foreach ($rr->tutti() as $r) foreach ($r['profili'] as $p) $quanti[$p] = ($quanti[$p] ?? 0) + ($r['attivo'] ? 1 : 0);
$icone = ['fa-circle-info', 'fa-bullhorn', 'fa-briefcase', 'fa-graduation-cap', 'fa-file-lines', 'fa-calendar-check', 'fa-bell', 'fa-envelope', 'fa-link', 'fa-star', 'fa-book-open', 'fa-handshake', 'fa-clipboard-list', 'fa-circle-question', 'fa-people-group', 'fa-flask'];

$modulo = function (array $r) use ($h, $profilo, $icone): string {
    $id = (int)($r['id'] ?? 0);
    $speciale = ($r['tipo'] ?? '') === CatalogoRiquadri::SPECIALE;
    $sel = $r['profili'] ?? ($id ? [] : [$profilo]);
    $o = '<form method="POST" class="row g-3"><input type="hidden" name="csrf_token" value="' . $h(csrf_token()) . '"><input type="hidden" name="profilo" value="' . $h($profilo) . '">';
    $o .= '<div class="col-12"><label class="form-label fw-bold" for="ap-titolo">Titolo</label><input class="form-control form-control-lg" id="ap-titolo" name="titolo" maxlength="' . RiquadriRepository::MAX_TITOLO . '" value="' . $h($r['titolo'] ?? '') . '"' . ($speciale ? '' : ' required') . '>'
        . ($speciale ? '<div class="form-text">Lascia così per il titolo di serie. Il contenuto di questo riquadro lo disegna il portale con i dati di chi entra: ' . $h($r['descrizione'] ?? '') . '.</div>' : '') . '</div>';
    if (!$speciale) {
        $o .= '<div class="col-12"><label class="form-label fw-bold" for="ap-testo">Testo</label><textarea class="form-control" id="ap-testo" name="testo" rows="4" maxlength="' . RiquadriRepository::MAX_TESTO . '">' . $h($r['testo'] ?? '') . '</textarea><div class="form-text">Poche righe, in testo semplice.</div></div>';
        $o .= '<div class="col-md-6"><label class="form-label fw-bold" for="ap-icona">Icona</label><input class="form-control" id="ap-icona" name="icona" list="ap-icone" maxlength="40" value="' . $h($r['icona'] ?? 'fa-circle-info') . '"><datalist id="ap-icone">';
        foreach ($icone as $i) $o .= '<option value="' . $h($i) . '">';
        $o .= '</datalist><div class="form-text">Nome di un\'icona di Font Awesome (fa-…): scegli dall\'elenco o scrivine un\'altra.</div></div>'
            . '<div class="col-md-6"><label class="form-label fw-bold" for="ap-colore">Colore</label><input class="form-control form-control-color" type="color" id="ap-colore" name="colore" value="' . $h($r['colore'] ?? '#0056B3') . '"></div>';
        foreach ([['link', 'etichetta_link', 'Primo pulsante'], ['link2', 'etichetta_link2', 'Secondo pulsante']] as [$u, $e, $tit]) {
            $o .= '<div class="col-12"><div class="fw-bold mb-1">' . $tit . ' <span class="fw-normal text-secondary">(facoltativo)</span></div><div class="row g-2">'
                . '<div class="col-md-4"><input class="form-control" name="' . $e . '" maxlength="80" placeholder="Testo del pulsante" aria-label="Testo del ' . strtolower($tit) . '" value="' . $h($r[$e] ?? '') . '"></div>'
                . '<div class="col-md-8"><input class="form-control" name="' . $u . '" maxlength="500" placeholder="https://… oppure una pagina del portale, es. pratiche.php" aria-label="Indirizzo del ' . strtolower($tit) . '" value="' . $h($r[$u] ?? '') . '"></div></div></div>';
        }
    }
    $o .= '<div class="col-12"><fieldset><legend class="form-label fw-bold fs-6">Chi lo vede</legend><div class="d-flex flex-wrap gap-3">';
    foreach (Profili::NOMI as $k => $n) $o .= '<label class="form-check m-0"><input class="form-check-input" type="checkbox" name="profili[]" value="' . $k . '"' . (in_array($k, $sel, true) ? ' checked' : '') . '> <span>' . $h($n) . '</span></label>';
    $o .= '</div></fieldset></div>'
        . '<div class="col-md-3"><label class="form-label fw-bold" for="ap-ord">Posizione</label><input class="form-control" type="number" id="ap-ord" name="ordine" value="' . ($id ? (int)$r['ordine'] : '') . '" placeholder="in fondo"><div class="form-text">Numero più basso = più in alto. Di solito basta «Su» e «Giù» nell\'elenco.</div></div>'
        . '<div class="col-12 d-flex flex-wrap align-items-center gap-3"><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="attivo" value="1"' . (!$id || !empty($r['attivo']) ? ' checked' : '') . '> <span class="fw-bold">Acceso (lo vedono i profili scelti)</span></label>'
        . '<button type="submit" name="salva" value="' . $id . '" class="btn btn-azione btn-lg fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva</button>'
        . '<a class="btn btn-outline-secondary btn-lg" href="area_personale.php?profilo=' . $h($profilo) . '">Annulla</a></div></form>';

    return $o;
};
?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div><h1 class="pn-titolo"><i class="fa fa-table-cells-large me-2" aria-hidden="true"></i>Area personale</h1>
        <p class="pn-aiuto">Scegli cosa trova ogni profilo nella sua Area personale. Un riquadro si può spegnere, spostare e dare a più profili; chi ha più profili (un docente che è anche tutor) vede i riquadri di tutti, senza doppioni.</p></div>
    <?php if ($modifica < 0): ?><a href="<?php echo $h($url(['modifica' => 0])); ?>" class="btn btn-azione fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuovo riquadro</a><?php endif; ?>
</div>

<ul class="nav nav-pills gap-1 mb-3" aria-label="Profili">
<?php foreach (Profili::NOMI as $k => $n): ?>
    <li class="nav-item"><a class="nav-link <?php echo $k === $profilo ? 'active' : ''; ?>" href="area_personale.php?profilo=<?php echo $k; ?>&amp;p_id=<?php echo (int)$filtro_p; ?>"<?php echo $k === $profilo ? ' aria-current="page"' : ''; ?>>
        <i class="fa <?php echo Profili::ICONE[$k]; ?> me-1" aria-hidden="true"></i><?php echo $h($n); ?> <span class="badge text-bg-light border"><?php echo (int)($quanti[$k] ?? 0); ?></span></a></li>
<?php endforeach; ?>
</ul>

<?php if ($modifica === 0 || $mod): ?>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 fw-bold mb-3"><?php echo $mod ? 'Modifica «' . $h($mod['titolo']) . '»' : 'Nuovo riquadro'; ?></h2><?php echo $modulo($mod ?: []); ?></div></div>
<?php endif; ?>

<h2 class="h5 fw-bold mb-2"><?php echo $h(Profili::NOMI[$profilo]); ?>: i riquadri, dall'alto in basso</h2>
<?php if (!$elenco): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-4"><p class="text-secondary mb-0">A questo profilo non è assegnato nessun riquadro. Aprine uno da un altro profilo e spunta «<?php echo $h(Profili::NOMI[$profilo]); ?>», oppure scrivi un riquadro nuovo.</p></div></div>
<?php endif; ?>
<div class="d-flex flex-column gap-2">
<?php foreach ($elenco as $i => $r): $id = $r['id']; ?>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex flex-wrap align-items-center gap-3">
        <span class="d-inline-flex align-items-center justify-content-center rounded-3 text-white flex-shrink-0" style="width:42px;height:42px;background:<?php echo $h($r['colore']); ?>;"><i class="fa <?php echo $h($r['icona']); ?>" aria-hidden="true"></i></span>
        <div class="flex-grow-1" style="min-width:240px;">
            <div class="fw-bold fs-6"><?php echo $h($r['titolo']); ?>
                <?php echo $r['attivo'] ? pn_stato('Si vede', 'ok', 'fa-eye') : pn_stato('Spento', 'spento', 'fa-eye-slash'); ?>
                <span class="badge text-bg-light border fw-normal"><?php echo $r['sistema'] ? ($r['tipo'] === CatalogoRiquadri::SCHEDA ? 'Scheda di sistema' : 'Di sistema') : 'Scritto da te'; ?></span></div>
            <div class="small text-secondary"><?php echo $h($r['sistema'] ? $r['descrizione'] : mb_strimwidth((string)preg_replace('/\s+/', ' ', $r['testo']), 0, 140, '…')); ?></div>
            <div class="small text-secondary mt-1">Profili: <?php echo $h(implode(', ', array_map(fn($p) => Profili::NOMI[$p], $r['profili'])) ?: 'nessuno'); ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="profilo" value="<?php echo $h($profilo); ?>"><input type="hidden" name="sposta" value="<?php echo $id; ?>">
                <button class="btn btn-outline-secondary" type="submit" name="verso" value="su"<?php echo $i === 0 ? ' disabled' : ''; ?> aria-label="Sposta «<?php echo $h($r['titolo']); ?>» più in alto"><i class="fa fa-arrow-up" aria-hidden="true"></i></button>
                <button class="btn btn-outline-secondary" type="submit" name="verso" value="giu"<?php echo $i === count($elenco) - 1 ? ' disabled' : ''; ?> aria-label="Sposta «<?php echo $h($r['titolo']); ?>» più in basso"><i class="fa fa-arrow-down" aria-hidden="true"></i></button></form>
            <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="profilo" value="<?php echo $h($profilo); ?>"><input type="hidden" name="valore" value="<?php echo $r['attivo'] ? 0 : 1; ?>">
                <button type="submit" name="acceso" value="<?php echo $id; ?>" class="btn btn-outline-secondary"><?php echo $r['attivo'] ? 'Spegni' : 'Accendi'; ?></button></form>
            <a class="btn btn-azione fw-bold" href="<?php echo $h($url(['modifica' => $id])); ?>">Modifica</a>
            <?php if (!$r['sistema']): ?><form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="profilo" value="<?php echo $h($profilo); ?>">
                <button type="submit" name="elimina" value="<?php echo $id; ?>" class="btn btn-outline-danger" data-confirm="Eliminare «<?php echo $h($r['titolo']); ?>»?" aria-label="Elimina «<?php echo $h($r['titolo']); ?>»"><i class="fa fa-trash" aria-hidden="true"></i></button></form><?php endif; ?>
        </div></div></div></div>
<?php endforeach; ?>
</div>
<p class="small text-secondary mt-3">Un riquadro di sistema compare solo a chi ha qualcosa da vedere: la scheda «Le mie pratiche», per esempio, solo a chi ha presentato una pratica. Spegnerlo qui lo toglie a tutti.</p>
<?php require_once 'admin_footer.php'; ?>
