<?php
// menu.php - Gestione Voci di Menu e Navigazione Portale (Supporto 3 Livelli)
require_once 'admin_header.php';

if (!$is_full_admin) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Riservato agli amministratori globali.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) { echo "<script>window.location.replace('$url');</script>"; exit; }

// Tutte le pagine utili del portale, aggiunte al menu NASCOSTE (il sito non cambia): poi si spostano e si mostrano a mano
if (isset($_POST['crea_catalogo_pagine'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    [$aggiunte_cat, $presenti_cat] = \App\Core\App::per($conn)->get(\App\Portale\CatalogoPagineMenu::class)->crea();
    registra_log_audit($conn, "Menu: pagine del portale aggiunte nascoste", ["Aggiunte" => $aggiunte_cat, "Già nel menu" => $presenti_cat]);
    flash_set($aggiunte_cat ? "Aggiunte $aggiunte_cat pagine, nascoste, sotto «" . \App\Portale\CatalogoPagineMenu::RADICE . "» ($presenti_cat erano già nel menu). Il sito non è cambiato: spostale dove vuoi e mostrale." : "Non ci sono pagine nuove da aggiungere: tutte quelle del catalogo sono già nel menu.", $aggiunte_cat ? 'success' : 'info');
    admin_redirect("menu.php?p_id=$filtro_p");
}

// AJAX: salva ordine stesso livello
if (isset($_POST['ajax_menu_ordine'])) {
    header('Content-Type: application/json');
    csrf_verify($_POST['csrf_token'] ?? '');
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    foreach ($ids as $pos => $id) {
        \App\Core\App::get(\App\Portale\MenuRepository::class)->impostaOrdine((int)$id, (int)$pos);
    }
    echo json_encode(['ok' => true]); exit;
}

if (!function_exists('getVisibilitaBadge')) {
    function getVisibilitaBadge($v_id, $ruoli) {
        if ($v_id == \App\Portale\AppartenenzeVisitatore::RESPONSABILI_RISORSE) return '<span class="badge bg-dark shadow-sm">🔒 Responsabili di risorse</span>';
        if (($u_id = \App\Portale\AppartenenzeVisitatore::ufficioDaCodice((int)$v_id)) !== null) return '<span class="badge bg-dark shadow-sm">🔒 Ufficio: ' . htmlspecialchars($GLOBALS['uffici_menu'][$u_id] ?? ('n. ' . $u_id)) . '</span>';
        if ($v_id == 0)  return '<span class="badge bg-success shadow-sm">🌐 Pubblico</span>';
        if ($v_id == -1) return '<span class="badge bg-warning text-dark shadow-sm">🔑 Autenticati SSO</span>';
        foreach ($ruoli as $r) { if ($r['id'] == $v_id) return '<span class="badge bg-dark shadow-sm">🔒 Solo ' . htmlspecialchars($r['nome']) . '</span>'; }
        return '<span class="badge bg-secondary shadow-sm">Tutti</span>';
    }
}

// ==============================================================================
// BACKEND
// ==============================================================================

if (isset($_POST['add_menu_voce'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $label        = $_POST['etichetta'] ?? '';
    $url          = $_POST['url'] ?? '';
    $ord          = (int)($_POST['ordine'] ?? 0);
    $genitore_id  = (int)($_POST['genitore_id'] ?? 0);
    $visibilita_id = (int)($_POST['ruolo_visibilita_id'] ?? 0);
    $scheda       = isset($_POST['apri_nuova_scheda']) ? 1 : 0;
    \App\Core\App::get(\App\Portale\MenuRepository::class)->inserisciDaPannello($genitore_id, (string)$label, (string)$url, $ord, $scheda === 1, $visibilita_id);
    flash_set("Voce di menu aggiunta!");
    admin_redirect("menu.php?p_id=$filtro_p");
}

if (isset($_POST['edit_menu_voce'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id           = (int)$_POST['menu_id'];
    $label        = $_POST['etichetta'] ?? '';
    $url          = $_POST['url'] ?? '';
    $ord          = (int)($_POST['ordine'] ?? 0);
    $genitore_id  = (int)($_POST['genitore_id'] ?? 0);
    $visibilita_id = (int)($_POST['ruolo_visibilita_id'] ?? 0);
    $scheda       = isset($_POST['apri_nuova_scheda']) ? 1 : 0;
    \App\Core\App::get(\App\Portale\MenuRepository::class)->aggiornaDaPannello($id, $genitore_id, (string)$label, (string)$url, $ord, $scheda === 1, $visibilita_id);
    flash_set("Voce aggiornata!");
    admin_redirect("menu.php?p_id=$filtro_p");
}

if (isset($_POST['toggle_vis'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['toggle_vis'];
    \App\Core\App::get(\App\Portale\MenuRepository::class)->alternaVisibilita($id);
    flash_set("Visibilità aggiornata.");
    admin_redirect("menu.php?p_id=$filtro_p");
}

if (isset($_POST['del_menu'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $m_del_id = (int)$_POST['del_menu'];
    \App\Core\App::get(\App\Portale\MenuRepository::class)->eliminaConSottomenu($m_del_id);
    flash_set("Voce e sottomenu eliminati.");
    admin_redirect("menu.php?p_id=$filtro_p");
}

// ==============================================================================
// PREPARAZIONE DATI
// ==============================================================================

$ruoli = get_ruoli($conn);
// Uffici (Gestione del portale → Uffici): una voce può essere riservata alle persone di un ufficio (per esempio la Direzione) o ai responsabili di risorse
$uffici_menu = [];
try { foreach (\App\Core\App::per($conn)->get(\App\Uffici\UfficiRepository::class)->tutti(null, true) as $u_m) $uffici_menu[(int)$u_m['id']] = (string)$u_m['nome']; } catch (\Throwable $e) { error_log('Menu, uffici: ' . $e->getMessage()); }
function opzioniVisibilita($ruoli, $uffici, int $scelta, bool $breve = false): string {
    $o = '<option value="0"' . ($scelta === 0 ? ' selected' : '') . '>🌐 ' . ($breve ? 'Tutti' : 'Tutti (Pubblico)') . '</option><option value="-1"' . ($scelta === -1 ? ' selected' : '') . '>🔑 ' . ($breve ? 'Autenticati SSO' : 'Solo Autenticati SSO') . '</option>';
    foreach ($ruoli as $r) $o .= '<option value="' . (int)$r['id'] . '"' . ($scelta === (int)$r['id'] ? ' selected' : '') . '>🔒 Solo: ' . htmlspecialchars($r['nome']) . '</option>';
    $o .= '<optgroup label="Persone con un compito"><option value="' . \App\Portale\AppartenenzeVisitatore::RESPONSABILI_RISORSE . '"' . ($scelta === \App\Portale\AppartenenzeVisitatore::RESPONSABILI_RISORSE ? ' selected' : '') . '>🔒 Responsabili di risorse (aule, laboratori, mezzi)</option></optgroup>';
    if ($uffici) {
        $o .= '<optgroup label="Persone di un ufficio">';
        foreach ($uffici as $id => $nome) { $cod = \App\Portale\AppartenenzeVisitatore::codiceUfficio((int)$id); $o .= '<option value="' . $cod . '"' . ($scelta === $cod ? ' selected' : '') . '>🔒 Ufficio: ' . htmlspecialchars($nome) . '</option>'; }
        $o .= '</optgroup>';
    }

    return $o;
}

$aree_lavoro = [];
$aree_lavoro = \App\Core\App::get(\App\Portale\AreeRepository::class)->titoliESlug();

function getGenitoriOptions($conn, $selected_id = 0, $exclude_id = 0) {
    $html = '<option value="0">-- 👑 Voce Principale (Livello 1) --</option>';
    $menu_repo = \App\Core\App::per($conn)->get(\App\Portale\MenuRepository::class);
    foreach ($menu_repo->figli(0) as $m0) {
        if ($m0['id'] == $exclude_id) continue;
        $sel0 = ($m0['id'] == $selected_id) ? 'selected' : '';
        $html .= "<option value='{$m0['id']}' $sel0>📂 {$m0['etichetta']} (Livello 1)</option>";
        foreach ($menu_repo->figli((int)$m0['id']) as $m1) {
            if ($m1['id'] == $exclude_id) continue;
            $sel1 = ($m1['id'] == $selected_id) ? 'selected' : '';
            $html .= "<option value='{$m1['id']}' $sel1>&nbsp;&nbsp;&nbsp;↳ Sotto: {$m1['etichetta']}</option>";
        }
    }
    return $html;
}

$tutti_i_menu = [];
$tutti_i_menu = \App\Core\App::get(\App\Portale\MenuRepository::class)->righe();
?>

<style>
.menu-node { border-radius:8px; margin-bottom:6px; }
.menu-node-header { display:flex; align-items:center; gap:8px; padding:10px 12px; border-radius:8px; background:#fff; border:1px solid #dee2e6; transition:box-shadow .15s; }
.menu-node-header:hover { box-shadow:0 2px 8px rgba(0,0,0,.08); }
.menu-node-header.hidden-node { opacity:.55; }
.menu-drag-handle { cursor:grab; color:#adb5bd; font-size:1rem; flex-shrink:0; }
.menu-drag-handle:active { cursor:grabbing; }
.menu-children { margin-top:4px; padding-left:28px; border-left:2px solid #dee2e6; }
.menu-l2-children { margin-top:4px; padding-left:28px; border-left:2px dashed #dee2e6; }
.sortable-ghost { opacity:.35; background:#e9ecef !important; border-radius:8px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="pn-titolo"><i class="fa fa-link me-2" aria-hidden="true"></i>Menu di navigazione</h1><p class="pn-aiuto">Le voci del menu del sito pubblico: l&#39;ordine, i nomi e dove portano.</p></div>
    <span class="badge bg-secondary"><?= count($tutti_i_menu) ?> voci totali</span>
</div>

<!-- TUTTE LE PAGINE DEL PORTALE, NASCOSTE -->
<div class="card shadow-sm border-0 mb-4" style="border-left:4px solid #047857 !important;"><div class="card-body">
    <h2 class="h6 fw-bold mb-1"><i class="fa fa-list-check me-1 text-success" aria-hidden="true"></i>Aggiungi al menu tutte le pagine del portale (nascoste)</h2>
    <p class="small text-secondary mb-2">Crea sotto <strong>«<?php echo htmlspecialchars(\App\Portale\CatalogoPagineMenu::RADICE); ?>»</strong>, raggruppate per argomento, tutte le pagine utili per le persone: agenda e ambiti, aree e archivi, aule e ricevimento, modulistica e pratiche, tesi e aziende convenzionate, area riservata, guide, verifica attestati, privacy. Sono <strong>nascoste</strong>: il sito non cambia. Poi, per ogni voce che ti serve, premi la matita e scegli la <em>Posizione</em> (dove metterla), quindi l'occhio per mostrarla. Le pagine che sono già nel menu non si ripetono: puoi rilanciare quando nascono pagine nuove.</p>
    <form method="POST"><?php csrf_field(); ?><button type="submit" name="crea_catalogo_pagine" value="1" class="btn btn-sm btn-success fw-bold"><i class="fa fa-eye-slash me-1" aria-hidden="true"></i>Aggiungi le pagine nascoste</button></form>
</div></div>

<!-- FORM AGGIUNGI NUOVA VOCE -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white border-bottom fw-bold text-primary py-3">
        <i class="fa fa-plus-circle me-2"></i> Aggiungi Nuova Voce
    </div>
    <div class="card-body p-4">
        <form method="POST" class="row g-3 align-items-end">
            <?php csrf_field(); ?>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">1. Posizione</label>
                <select name="genitore_id" class="form-select border-primary fw-bold text-primary">
                    <?php echo getGenitoriOptions($conn); ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">2. Etichetta visibile</label>
                <input type="text" name="etichetta" class="form-control" required placeholder="Es. Offerta Formativa">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">3. Collega Area Esistente (opzionale)</label>
                <select class="form-select border-success text-success fw-bold" onchange="document.getElementById('urlNuovo').value = this.value;">
                    <option value="">-- Scrivi URL manualmente --</option>
                    <optgroup label="🔗 Aree Correnti">
                        <?php foreach ($aree_lavoro as $al): ?><option value="<?= htmlspecialchars($al['slug']) ?>.php">Area: <?= htmlspecialchars($al['titolo']) ?></option><?php endforeach; ?>
                    </optgroup>
                    <optgroup label="🗄️ Archivi Storici">
                        <?php foreach ($aree_lavoro as $al): ?><option value="<?= htmlspecialchars($al['slug']) ?>_archivio.php">Archivio: <?= htmlspecialchars($al['titolo']) ?></option><?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-bold text-secondary">URL di Destinazione</label>
                <input type="text" id="urlNuovo" name="url" class="form-control" placeholder="Es. index.php o https://..." required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary">Visibilità</label>
                <select name="ruolo_visibilita_id" class="form-select">
                    <?= opzioniVisibilita($ruoli, $uffici_menu, 0) ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary">Ordine</label>
                <input type="number" name="ordine" class="form-control text-center fw-bold" value="0" required>
            </div>
            <div class="col-md-2 d-flex align-items-center">
                <div class="form-check form-switch pt-2">
                    <input class="form-check-input" type="checkbox" name="apri_nuova_scheda" id="chkNew" value="1">
                    <label class="form-check-label small fw-bold" for="chkNew">Nuova scheda</label>
                </div>
            </div>
            <div class="col-12 text-end border-top pt-3">
                <button type="submit" name="add_menu_voce" class="btn btn-primary fw-bold px-5 shadow"><i class="fa fa-plus me-1"></i> Crea Voce Menu</button>
            </div>
        </form>
    </div>
</div>

<!-- ALBERO DI NAVIGAZIONE -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white border-bottom fw-bold py-3 d-flex justify-content-between align-items-center">
        <span><i class="fa fa-sitemap text-primary me-2"></i> Albero di Navigazione</span>
        <span class="small text-muted fw-normal"><i class="fa fa-arrows-up-down me-1"></i> Trascina per riordinare all'interno del livello</span>
    </div>
    <div class="card-body p-4">
        <?php
        $has_menus = false;
        foreach ($tutti_i_menu as $m) { if ($m['genitore_id'] == 0) { $has_menus = true; break; } }
        ?>
        <?php if (!$has_menus): ?>
            <div class="text-center p-5 text-muted">
                <i class="fa fa-sitemap fs-1 mb-3 d-block opacity-50"></i>
                Nessuna voce di menu configurata.
            </div>
        <?php else: ?>
            <!-- Livello 0 — sortable -->
            <div id="menu-sort-l0" class="menu-sort-list">
            <?php foreach ($tutti_i_menu as $m0):
                if ($m0['genitore_id'] != 0) continue;
                $vis0 = (!isset($m0['visibile']) || $m0['visibile'] == 1);
            ?>
                <div class="menu-node" data-menu-id="<?= $m0['id'] ?>">
                    <div class="menu-node-header <?= !$vis0 ? 'hidden-node' : '' ?>">
                        <span class="menu-drag-handle"><i class="fa fa-grip-vertical"></i></span>
                        <span class="badge bg-primary flex-shrink-0">L1</span>
                        <strong class="flex-fill text-dark"><?= htmlspecialchars($m0['etichetta']) ?></strong>
                        <code class="small text-muted d-none d-md-inline"><?= htmlspecialchars($m0['url']) ?></code>
                        <?php echo getVisibilitaBadge((int)($m0['ruolo_visibilita_id'] ?? 0), $ruoli); ?>
                        <?php if ($m0['apri_nuova_scheda']): ?><span class="badge bg-light border text-muted" title="Apre in nuova scheda"><i class="fa fa-external-link-alt"></i></span><?php endif; ?>
                        <?php if (!$vis0): ?><span class="badge bg-danger">Nascosto</span><?php endif; ?>
                        <div class="d-flex gap-1 flex-shrink-0 ms-auto">
                            <form method="POST" class="d-inline m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="toggle_vis" value="<?= $m0['id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $vis0 ? 'btn-outline-warning text-dark' : 'btn-outline-success' ?> py-0 px-2" title="<?= $vis0 ? 'Nascondi' : 'Mostra' ?>"><i class="fa <?= $vis0 ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                            </form>
                            <button class="btn btn-outline-info btn-sm py-0 px-2" data-bs-toggle="modal" data-bs-target="#modEditMenu<?= $m0['id'] ?>"><i class="fa fa-edit"></i></button>
                            <form method="POST" class="d-inline m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="del_menu" value="<?= $m0['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" data-confirm="Eliminando questa voce verranno eliminati anche tutti i suoi sottomenu. Procedere?"><i class="fa fa-trash"></i></button>
                            </form>
                        </div>
                    </div>

                    <?php
                    // Controlla se ha figli
                    $has_children_l1 = false;
                    foreach ($tutti_i_menu as $mc) { if ($mc['genitore_id'] == $m0['id']) { $has_children_l1 = true; break; } }
                    ?>
                    <?php if ($has_children_l1): ?>
                    <div class="menu-children">
                        <div id="menu-sort-l1-<?= $m0['id'] ?>" class="menu-sort-list">
                        <?php foreach ($tutti_i_menu as $m1):
                            if ($m1['genitore_id'] != $m0['id']) continue;
                            $vis1 = (!isset($m1['visibile']) || $m1['visibile'] == 1);
                        ?>
                            <div class="menu-node" data-menu-id="<?= $m1['id'] ?>">
                                <div class="menu-node-header <?= !$vis1 ? 'hidden-node' : '' ?>">
                                    <span class="menu-drag-handle"><i class="fa fa-grip-vertical"></i></span>
                                    <span class="badge bg-info text-dark flex-shrink-0">L2</span>
                                    <span class="flex-fill text-secondary fw-bold">↳ <?= htmlspecialchars($m1['etichetta']) ?></span>
                                    <code class="small text-muted d-none d-md-inline"><?= htmlspecialchars($m1['url']) ?></code>
                                    <?php echo getVisibilitaBadge((int)($m1['ruolo_visibilita_id'] ?? 0), $ruoli); ?>
                                    <?php if (!$vis1): ?><span class="badge bg-danger">Nascosto</span><?php endif; ?>
                                    <div class="d-flex gap-1 flex-shrink-0 ms-auto">
                                        <form method="POST" class="d-inline m-0">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="toggle_vis" value="<?= $m1['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $vis1 ? 'btn-outline-warning text-dark' : 'btn-outline-success' ?> py-0 px-2"><i class="fa <?= $vis1 ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                                        </form>
                                        <button class="btn btn-outline-info btn-sm py-0 px-2" data-bs-toggle="modal" data-bs-target="#modEditMenu<?= $m1['id'] ?>"><i class="fa fa-edit"></i></button>
                                        <form method="POST" class="d-inline m-0">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="del_menu" value="<?= $m1['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" data-confirm="Eliminare questo sottomenu?"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </div>
                                </div>

                                <?php
                                $has_children_l2 = false;
                                foreach ($tutti_i_menu as $mc) { if ($mc['genitore_id'] == $m1['id']) { $has_children_l2 = true; break; } }
                                ?>
                                <?php if ($has_children_l2): ?>
                                <div class="menu-l2-children">
                                    <div id="menu-sort-l2-<?= $m1['id'] ?>" class="menu-sort-list">
                                    <?php foreach ($tutti_i_menu as $m2):
                                        if ($m2['genitore_id'] != $m1['id']) continue;
                                        $vis2 = (!isset($m2['visibile']) || $m2['visibile'] == 1);
                                    ?>
                                        <div class="menu-node" data-menu-id="<?= $m2['id'] ?>">
                                            <div class="menu-node-header bg-light <?= !$vis2 ? 'hidden-node' : '' ?>">
                                                <span class="menu-drag-handle"><i class="fa fa-grip-vertical"></i></span>
                                                <span class="badge bg-light border text-dark flex-shrink-0">L3</span>
                                                <span class="flex-fill text-muted">— <?= htmlspecialchars($m2['etichetta']) ?></span>
                                                <code class="small text-muted d-none d-md-inline"><?= htmlspecialchars($m2['url']) ?></code>
                                                <?php echo getVisibilitaBadge((int)($m2['ruolo_visibilita_id'] ?? 0), $ruoli); ?>
                                                <?php if (!$vis2): ?><span class="badge bg-danger">Nascosto</span><?php endif; ?>
                                                <div class="d-flex gap-1 flex-shrink-0 ms-auto">
                                                    <form method="POST" class="d-inline m-0">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="toggle_vis" value="<?= $m2['id'] ?>">
                                                        <button type="submit" class="btn btn-sm <?= $vis2 ? 'btn-outline-warning text-dark' : 'btn-outline-success' ?> py-0 px-2"><i class="fa <?= $vis2 ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                                                    </form>
                                                    <button class="btn btn-outline-info btn-sm py-0 px-2" data-bs-toggle="modal" data-bs-target="#modEditMenu<?= $m2['id'] ?>"><i class="fa fa-edit"></i></button>
                                                    <form method="POST" class="d-inline m-0">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="del_menu" value="<?= $m2['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" data-confirm="Eliminare questo collegamento?"><i class="fa fa-trash"></i></button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODALI DI MODIFICA -->
<?php foreach ($tutti_i_menu as $em): ?>
<div class="modal fade" id="modEditMenu<?= $em['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-info">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="menu_id" value="<?= $em['id'] ?>">
                <div class="modal-header bg-info text-white py-2">
                    <h6 class="modal-title fw-bold"><i class="fa fa-edit me-1"></i> Modifica: <?= htmlspecialchars($em['etichetta']) ?></h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start bg-light">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Genitore / Posizione</label>
                            <select name="genitore_id" class="form-select border-info">
                                <?php echo getGenitoriOptions($conn, $em['genitore_id'], $em['id']); ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Etichetta visibile</label>
                            <input type="text" name="etichetta" class="form-control" value="<?= htmlspecialchars($em['etichetta']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-success">Aggiorna da Area (opzionale)</label>
                            <select class="form-select border-success text-success" onchange="document.getElementById('editUrl<?= $em['id'] ?>').value = this.value;">
                                <option value="">-- Mantieni URL corrente --</option>
                                <optgroup label="Aree Correnti">
                                    <?php foreach ($aree_lavoro as $al): ?><option value="<?= htmlspecialchars($al['slug']) ?>.php">Area: <?= htmlspecialchars($al['titolo']) ?></option><?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Archivi Storici">
                                    <?php foreach ($aree_lavoro as $al): ?><option value="<?= htmlspecialchars($al['slug']) ?>_archivio.php">Archivio: <?= htmlspecialchars($al['titolo']) ?></option><?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">URL di Destinazione</label>
                            <input type="text" id="editUrl<?= $em['id'] ?>" name="url" class="form-control" value="<?= htmlspecialchars($em['url']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Visibilità</label>
                            <select name="ruolo_visibilita_id" class="form-select">
                                <?= opzioniVisibilita($ruoli, $uffici_menu, (int)$em['ruolo_visibilita_id'], true) ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Ordine</label>
                            <input type="number" name="ordine" class="form-control text-center" value="<?= $em['ordine'] ?>" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-center pt-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="apri_nuova_scheda" id="chkEdit<?= $em['id'] ?>" value="1" <?= ($em['apri_nuova_scheda'] == 1) ? 'checked' : '' ?>>
                                <label class="form-check-label small fw-bold" for="chkEdit<?= $em['id'] ?>">Nuova scheda</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="edit_menu_voce" class="btn btn-info text-white fw-bold shadow-sm px-4"><i class="fa fa-save me-1"></i> Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- SortableJS -->
<script src="<?php echo url_vendor('jsdelivr/npm/sortablejs@1.15.2/Sortable.min.js'); ?>" integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM" crossorigin="anonymous"></script>
<script>
(function() {
    function initSortable(el) {
        if (!el) return;
        Sortable.create(el, {
            handle: '.menu-drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function() {
                var ids = [];
                el.querySelectorAll(':scope > [data-menu-id]').forEach(function(c) { ids.push(c.dataset.menuId); });
                var fd = new FormData();
                fd.append('ajax_menu_ordine', '1');
                fd.append('csrf_token', '<?php echo htmlspecialchars(csrf_token()); ?>');
                ids.forEach(function(id) { fd.append('ids[]', id); });
                fetch('menu.php?p_id=<?= $filtro_p ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .catch(function() {});
            }
        });
    }

    initSortable(document.getElementById('menu-sort-l0'));
    document.querySelectorAll('[id^="menu-sort-l1-"], [id^="menu-sort-l2-"]').forEach(function(el) {
        initSortable(el);
    });
})();
</script>

<?php require_once 'admin_footer.php'; ?>
