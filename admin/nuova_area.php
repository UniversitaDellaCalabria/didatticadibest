<?php
// nuova_area.php - Creazione di una nuova area di lavoro (solo amministratori).
// L'area nasce NASCOSTA al pubblico e l'eventuale voce di menu nasce NASCOSTA:
// si pubblicano quando sono pronte (pagina Aree e Menu Navigazione).
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$layout_opzioni = [
    'grid'          => 'Griglia a colonne (per categoria)',
    'list'          => 'Lista cronologica per date',
    'advanced_list' => 'Elenco avanzato con ricerca laterale',
    'calendar'      => 'Calendario interattivo mensile',
    'timeline'      => 'Timeline (cronologia verticale)',
    'agenda'        => 'Agenda a schede per giorno',
    'gruppi'        => 'Gruppi / corsi (con posti e iscrizione)',
    'progetti'      => 'Progetti (elenco con scheda e iscrizione della scuola)',
];
$limiti_opzioni = ['nessuno' => 'Nessun limite', 'un_evento' => "Un solo evento/gruppo in tutta l'area", 'un_turno' => 'Un solo turno per ciascun evento'];

// Voci principali del menu, per scegliere dove mettere la nuova voce
$voci_menu = [];
$r_vm = $conn->query("SELECT id, etichetta FROM menu_voci WHERE genitore_id = 0 OR genitore_id IS NULL ORDER BY ordine ASC, id ASC");
while ($r_vm && $vm = $r_vm->fetch_assoc()) $voci_menu[(int)$vm['id']] = $vm['etichetta'];

$v = [
    'titolo' => '', 'slug' => '', 'sottotitolo' => '', 'colore_primario' => '#0056b3', 'colore_secondario' => '#0056b3',
    'layout_template' => 'grid', 'num_colonne' => 2, 'larghezza_contenitore' => '85%', 'spazio_card' => 30, 'limite_iscrizioni' => 'nessuno', 'chiedi_matricola' => 1,
    'mostra_in_home' => 1, 'hero_descrizione' => '', 'crea_menu' => 1, 'etichetta_menu' => '', 'genitore_menu' => 0, 'tipo_area' => '',
];
$errori = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crea_area'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $v = [
        'titolo'            => mb_substr(trim((string)($_POST['titolo'] ?? '')), 0, 150),
        'slug'              => strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', trim((string)($_POST['slug'] ?? ''))))),
        'sottotitolo'       => mb_substr(trim((string)($_POST['sottotitolo'] ?? '')), 0, 255),
        'colore_primario'   => colore_valido($_POST['colore_primario'] ?? '', '#0056B3'),
        'colore_secondario' => colore_valido($_POST['colore_secondario'] ?? '', '#0056B3'),
        'layout_template'   => array_key_exists($_POST['layout_template'] ?? '', $layout_opzioni) ? $_POST['layout_template'] : 'grid',
        'num_colonne'       => in_array((int)($_POST['num_colonne'] ?? 2), [1, 2, 3], true) ? (int)$_POST['num_colonne'] : 2,
        // Finisce nello stile della pagina: solo numero + unità (85%, 1200px, 90vw, 70rem)
        'larghezza_contenitore' => preg_match('/^\d{1,4}(\.\d+)?(%|px|vw|rem|em)$/', str_replace(' ', '', (string)($_POST['larghezza_contenitore'] ?? ''))) ? str_replace(' ', '', $_POST['larghezza_contenitore']) : '85%',
        'spazio_card'       => max(0, min(100, (int)($_POST['spazio_card'] ?? 30))),
        'limite_iscrizioni' => array_key_exists($_POST['limite_iscrizioni'] ?? '', $limiti_opzioni) ? $_POST['limite_iscrizioni'] : 'nessuno',
        'chiedi_matricola'  => isset($_POST['chiedi_matricola']) ? 1 : 0,
        'mostra_in_home'    => isset($_POST['mostra_in_home']) ? 1 : 0,
        'hero_descrizione'  => trim((string)($_POST['hero_descrizione'] ?? '')),
        'crea_menu'         => isset($_POST['crea_menu']) ? 1 : 0,
        'etichetta_menu'    => mb_substr(trim((string)($_POST['etichetta_menu'] ?? '')), 0, 100),
        'genitore_menu'     => array_key_exists((int)($_POST['genitore_menu'] ?? 0), $voci_menu) ? (int)$_POST['genitore_menu'] : 0,
        'tipo_area'         => isset(TIPI_AREA[$_POST['tipo_area'] ?? '']) && TIPI_AREA[$_POST['tipo_area']]['disponibile'] ? $_POST['tipo_area'] : '',
    ];

    if ($v['titolo'] === '') $errori[] = "Indica il nome dell'area.";
    if ($v['slug'] === '') $errori[] = "Indica l'indirizzo (slug) dell'area: solo lettere, numeri e trattino basso.";
    // Le aree non sono file: .htaccess manda <slug>.php e <slug>_archivio.php ad area.php.
    // Uno slug uguale a un file o a una cartella del sito renderebbe l'area irraggiungibile.
    $radice_sito = dirname(__DIR__);
    if ($v['slug'] !== '' && (str_ends_with($v['slug'], '_archivio') || file_exists("$radice_sito/{$v['slug']}.php")
        || file_exists("$radice_sito/{$v['slug']}_archivio.php") || is_dir("$radice_sito/{$v['slug']}"))) {
        $errori[] = "L'indirizzo \"{$v['slug']}\" non è utilizzabile: coincide con un file o una cartella del sito.";
    }
    if ($v['slug'] !== '') {
        $st = $conn->prepare("SELECT 1 FROM pagine_eventi WHERE slug = ? LIMIT 1");
        $st->bind_param("s", $v['slug']); $st->execute();
        if ($st->get_result()->num_rows > 0) $errori[] = "Esiste già un'area con l'indirizzo \"{$v['slug']}\".";
    }

    if (!$errori) {
        // Area e voce di menu insieme: se una delle due non riesce non resta nulla a metà
        $conn->begin_transaction();
        try {
        $hero = $v['hero_descrizione'] !== '' ? $v['hero_descrizione']
              : '<strong style="color: ' . $v['colore_primario'] . ';">Benvenuto/a a ' . htmlspecialchars($v['titolo']) . ':</strong> ' . ($v['tipo_area'] === 'calendario' ? 'scegli la risorsa e prenota uno slot libero.' : 'scopri il programma e iscriviti.');
        $stmt = $conn->prepare("INSERT INTO pagine_eventi (titolo, slug, sottotitolo, colore_primario, colore_secondario, larghezza_contenitore, layout_template, num_colonne,
                                    spazio_card, mostra_sidebar, chiedi_matricola, visibile, mostra_in_home, limite_iscrizioni, sidebar_titolo, hero_descrizione)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssiiiisss", $v['titolo'], $v['slug'], $v['sottotitolo'], $v['colore_primario'], $v['colore_secondario'], $v['larghezza_contenitore'],
                          $v['layout_template'], $v['num_colonne'], $v['spazio_card'], $v['chiedi_matricola'], $v['mostra_in_home'], $v['limite_iscrizioni'], $v['titolo'], $hero);
        if (!$stmt->execute()) throw new RuntimeException($conn->error);
        $new_id = (int)$conn->insert_id;
        // Tipo dell'area (macroarea)
        $st_ta = $conn->prepare("UPDATE pagine_eventi SET tipo_area = ? WHERE id = ?");
        $st_ta->bind_param("si", $v['tipo_area'], $new_id); $st_ta->execute();

        $msg_menu = '';
        if ($v['crea_menu']) {
            $etichetta = $v['etichetta_menu'] !== '' ? $v['etichetta_menu'] : $v['titolo'];
            $url_menu = $v['slug'] . '.php';
            $r_o = $conn->query("SELECT COALESCE(MAX(ordine), 0) + 1 AS o FROM menu_voci WHERE genitore_id = " . (int)$v['genitore_menu']);
            $ord = $r_o ? (int)$r_o->fetch_assoc()['o'] : 10;
            $st_m = $conn->prepare("INSERT INTO menu_voci (genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id, visibile) VALUES (?, ?, ?, ?, 0, 0, 0)");
            $st_m->bind_param("issi", $v['genitore_menu'], $etichetta, $url_menu, $ord);
            if (!$st_m->execute()) throw new RuntimeException($conn->error);
            $msg_menu = " La voce di menu \"" . $etichetta . "\" è stata creata nascosta: la attivi da Menu Navigazione.";
        }
        $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[nuova_area] ' . $e->getMessage());
            $errori[] = "Errore del database durante la creazione: nessuna modifica salvata. Riprova o controlla il registro degli errori.";
        }
    }
    if (!$errori && isset($new_id)) {
        registra_log_audit($conn, "Creazione Area", ["Area" => $v['titolo'], "Slug" => $v['slug'], "Voce di menu" => $v['crea_menu'] ? 'sì (nascosta)' : 'no']);
        flash_set("Area \"" . $v['titolo'] . "\" creata e NASCOSTA al pubblico: la rendi visibile dalla pagina Aree quando è pronta." . $msg_menu, 'success');
        // Calendari e risorse: si parte creando la prima risorsa con i suoi orari
        admin_redirect($v['tipo_area'] === 'calendario' ? "risorse.php?p_id=$new_id&nuova=1" : "impostazioni_area.php?p_id=$new_id");
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
.na-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem 1.25rem .75rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.na-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:#1e293b; margin-bottom:1rem; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-plus-circle me-2 text-primary" aria-hidden="true"></i>Nuova area</h4>
    <a href="aree.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Torna alle aree</a>
</div>

<?php if ($errori): ?>
    <div class="alert alert-danger"><strong>L'area non è stata creata:</strong><ul class="mb-0 mt-1"><?php foreach ($errori as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-info small"><i class="fa fa-eye-slash me-1" aria-hidden="true"></i>La nuova area nasce <strong>nascosta al pubblico</strong>: puoi prepararla con calma (eventi, form, impostazioni) e renderla visibile dalla pagina <strong>Aree</strong> quando è pronta. Anche la voce di menu, se la crei, nasce nascosta.</div>

<form method="POST" onsubmit="if (window.tinymce) tinymce.triggerSave();">
    <?php csrf_field(); ?>
    <div class="row g-3">
        <div class="col-xl-8">
            <section class="na-sez">
                <h2><i class="fa fa-id-card me-1" aria-hidden="true"></i>Identità</h2>
                <div class="row g-3">
                    <div class="col-md-7">
                        <label for="naTitolo" class="form-label small fw-bold">Nome dell'area <span class="text-danger">*</span></label>
                        <input type="text" name="titolo" id="naTitolo" class="form-control" value="<?php echo $h($v['titolo']); ?>" maxlength="150" required placeholder="es. OpenLab">
                    </div>
                    <div class="col-md-5">
                        <label for="naSlug" class="form-label small fw-bold">Indirizzo della pagina <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="slug" id="naSlug" class="form-control font-monospace" value="<?php echo $h($v['slug']); ?>" maxlength="60" required pattern="[a-zA-Z0-9_\- ]+" placeholder="openlab">
                            <span class="input-group-text">.php</span>
                        </div>
                        <div class="form-text">Solo lettere, numeri e _. Si compila da solo dal nome.</div>
                    </div>
                    <div class="col-12">
                        <label for="naTipo" class="form-label small fw-bold">Tipo di area (macroarea)</label>
                        <?php echo html_scelta_tipo_area('tipo_area', $v['tipo_area'], 'id="naTipo"', 'form-select'); ?>
                        <div class="form-text" id="naTipoDescr">Colloca l'area nella home (Orientamento, Didattica, Calendari e risorse) e propone le impostazioni adatte ai nuovi eventi e progetti. Si cambia quando vuoi dalla pagina Aree.</div>
                        <script>
                        (function () {
                            var descr = <?php echo json_encode(array_map(fn($t) => $t['descr'], TIPI_AREA), JSON_UNESCAPED_UNICODE); ?>, sel = document.getElementById('naTipo');
                            sel.addEventListener('change', function () {
                                if (descr[sel.value]) document.getElementById('naTipoDescr').textContent = descr[sel.value];
                                // Gruppi degli insegnamenti: layout a gruppi e un solo turno (gruppo) per evento
                                if (sel.value === 'gruppi') {
                                    var l = document.getElementById('naLayout'), m = document.getElementById('naLimite');
                                    if (l && l.querySelector('option[value="gruppi"]')) l.value = 'gruppi';
                                    if (m && m.querySelector('option[value="un_turno"]')) m.value = 'un_turno';
                                }
                            });
                        })();
                        </script>
                    </div>
                    <div class="col-12">
                        <label for="naSott" class="form-label small fw-bold">Sottotitolo</label>
                        <input type="text" name="sottotitolo" id="naSott" class="form-control" value="<?php echo $h($v['sottotitolo']); ?>" maxlength="255" placeholder="es. Laboratori aperti per le scuole">
                    </div>
                    <div class="col-12">
                        <label for="naHero" class="form-label small fw-bold">Testo di presentazione</label>
                        <textarea name="hero_descrizione" id="naHero" class="form-control editor-html" rows="5"><?php echo $h($v['hero_descrizione']); ?></textarea>
                        <div class="form-text">Compare in alto nella pagina dell'area. Se lo lasci vuoto viene messo un benvenuto standard.</div>
                    </div>
                </div>
            </section>

            <section class="na-sez">
                <h2><i class="fa fa-table-columns me-1" aria-hidden="true"></i>Colori, template e dimensioni</h2>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label for="naLayout" class="form-label small fw-bold">Layout della pagina</label>
                        <select name="layout_template" id="naLayout" class="form-select">
                            <?php foreach ($layout_opzioni as $k => $lbl): ?><option value="<?php echo $k; ?>" <?php echo $v['layout_template'] === $k ? 'selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="naCol" class="form-label small fw-bold">Colonne</label>
                        <select name="num_colonne" id="naCol" class="form-select">
                            <?php foreach ([1, 2, 3] as $c): ?><option value="<?php echo $c; ?>" <?php echo (int)$v['num_colonne'] === $c ? 'selected' : ''; ?>><?php echo $c; ?> colonn<?php echo $c === 1 ? 'a' : 'e'; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="naCol1" class="form-label small fw-bold">Colore principale</label>
                        <input type="color" name="colore_primario" id="naCol1" class="form-control form-control-color w-100" value="<?php echo $h($v['colore_primario']); ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="naCol2" class="form-label small fw-bold">Colore secondario</label>
                        <input type="color" name="colore_secondario" id="naCol2" class="form-control form-control-color w-100" value="<?php echo $h($v['colore_secondario']); ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="naLarg" class="form-label small fw-bold">Larghezza pagina</label>
                        <input type="text" name="larghezza_contenitore" id="naLarg" class="form-control" value="<?php echo $h($v['larghezza_contenitore']); ?>" maxlength="10" pattern="\s*\d{1,4}(\.\d+)?\s*(%|px|vw|rem|em)\s*" title="Un numero con unità: 85%, 1200px, 90vw…">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="naSpazio" class="form-label small fw-bold">Spazio tra card (px)</label>
                        <input type="number" name="spazio_card" id="naSpazio" class="form-control" value="<?php echo (int)$v['spazio_card']; ?>" min="0" max="100">
                    </div>
                    <div class="col-12 small text-secondary">Logo, copertina, sidebar, allegati e firma degli attestati si impostano subito dopo, in <strong>Impostazioni area</strong>.</div>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            <section class="na-sez">
                <h2><i class="fa fa-user-check me-1" aria-hidden="true"></i>Regole di iscrizione</h2>
                <label for="naLimite" class="form-label small fw-bold">Limite di iscrizioni per persona</label>
                <select name="limite_iscrizioni" id="naLimite" class="form-select mb-3">
                    <?php foreach ($limiti_opzioni as $k => $lbl): ?><option value="<?php echo $k; ?>" <?php echo $v['limite_iscrizioni'] === $k ? 'selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?>
                </select>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="chiedi_matricola" id="naMatr" value="1" <?php echo $v['chiedi_matricola'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="naMatr">Chiedi la matricola nel modulo</label>
                </div>
            </section>

            <section class="na-sez">
                <h2><i class="fa fa-bullhorn me-1" aria-hidden="true"></i>Pubblicazione</h2>
                <p class="small mb-2"><span class="badge bg-warning text-dark"><i class="fa fa-eye-slash me-1" aria-hidden="true"></i>Nascosta al pubblico</span> alla creazione.</p>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="mostra_in_home" id="naHome" value="1" <?php echo $v['mostra_in_home'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="naHome">Mostra in home page</label>
                </div>
                <p class="form-text mt-0 mb-3">Vale quando l'area diventa visibile: card dell'area e prossimi appuntamenti in home.</p>

                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="crea_menu" id="naMenu" value="1" <?php echo $v['crea_menu'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="naMenu">Crea la voce nel menu del sito</label>
                </div>
                <div id="naMenuCampi" class="ps-1">
                    <p class="form-text mt-0 mb-2">La voce nasce <strong>nascosta</strong>: la attivi da <em>Menu Navigazione</em> al momento giusto.</p>
                    <label for="naMenuEt" class="form-label small fw-bold">Testo della voce</label>
                    <input type="text" name="etichetta_menu" id="naMenuEt" class="form-control form-control-sm mb-2" value="<?php echo $h($v['etichetta_menu']); ?>" maxlength="100" placeholder="uguale al nome dell'area">
                    <label for="naMenuPadre" class="form-label small fw-bold">Posizione</label>
                    <select name="genitore_menu" id="naMenuPadre" class="form-select form-select-sm">
                        <option value="0">Voce principale del menu</option>
                        <?php foreach ($voci_menu as $id_vm => $et_vm): ?><option value="<?php echo $id_vm; ?>" <?php echo (int)$v['genitore_menu'] === $id_vm ? 'selected' : ''; ?>>Dentro "<?php echo $h($et_vm); ?>"</option><?php endforeach; ?>
                    </select>
                </div>
            </section>

            <div class="d-grid gap-2 mb-4">
                <button type="submit" name="crea_area" value="1" class="btn btn-primary fw-bold py-2"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Crea l'area</button>
                <a href="aree.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary">Annulla</a>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    // Indirizzo compilato dal nome finché non lo si modifica a mano
    var tit = document.getElementById('naTitolo'), slug = document.getElementById('naSlug'), aMano = slug.value !== '';
    var pulisci = function (s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/['’]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, ''); };
    tit.addEventListener('input', function () { if (!aMano) slug.value = pulisci(tit.value); });
    slug.addEventListener('input', function () { aMano = slug.value !== ''; });
    var menu = document.getElementById('naMenu'), campi = document.getElementById('naMenuCampi');
    var agg = function () { campi.style.display = menu.checked ? '' : 'none'; };
    menu.addEventListener('change', agg); agg();
})();
</script>

<?php require_once 'admin_footer.php'; ?>
