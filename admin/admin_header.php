<?php
// admin_header.php - Guscio Superiore, Sicurezza, RBAC e Menu Laterale
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once '../config.php'; // apre la sessione con i parametri sicuri del cookie
require_once '../functions.php';

// 1. SINCRONIZZAZIONE SSO E CONTROLLO ACCESSO
sync_sso_user($conn);
$utente_admin = null;
$u_id_curr = $_SESSION['utente_id'] ?? null;

if ($u_id_curr) {
    $utente_admin = \App\Core\App::get(\App\Auth\UtenteRepository::class)->perId((int)$u_id_curr);
}

if (!$utente_admin && !isset($_GET['sso_tentato'])) {
    // Accesso diretto: login SSO e ritorno a questa stessa pagina (sso_tentato evita un ciclo se il login non riesce)
    $qs = $_GET; $qs['sso_tentato'] = 1;
    $ritorno = 'admin/' . basename($_SERVER['PHP_SELF']) . '?' . http_build_query($qs);
    while (ob_get_level() > 0) ob_end_clean();
    header('Location: ../saml_login.php?redirect=' . urlencode($ritorno));
    exit;
}
if (!$utente_admin) {
    ?>
    <!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Accesso Riservato</title><link href="<?php echo url_vendor('jsdelivr/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'); ?>" rel="stylesheet"><link rel="stylesheet" href="<?php echo url_vendor('cdnjs/ajax/libs/font-awesome/6.4.0/css/all.min.css'); ?>"></head>
    <body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
        <div class="card shadow-sm p-4 text-center" style="max-width: 500px; border-top: 4px solid #990000;">
            <h4 class="fw-bold mb-3 text-danger"><i class="fa fa-lock"></i> Autenticazione Richiesta</h4>
            <p class="text-secondary small mb-0">L'accesso non è andato a buon fine. Riprova; se il problema continua, chiudi il browser e riaprilo.</p>
            <div class="mt-3"><a href="../saml_login.php?redirect=<?php echo urlencode('admin/index.php'); ?>" class="btn btn-danger px-4 fw-bold" style="background-color: #990000;">Accedi con SSO Unical</a></div>
        </div>
    </body></html>
    <?php exit;
}

$u_ruolo_curr = (int)$utente_admin['ruolo_id'];
$u_sec_roles = array_filter(explode(',', $utente_admin['ruoli_secondari'] ?? ''));
$is_full_admin = ($u_ruolo_curr === 1) || in_array('1', $u_sec_roles);

// ==============================================================================
// GESTIONE AZIONI GLOBALI AREA DI LAVORO
// ==============================================================================
if (isset($_POST['toggle_visibilita_pagina']) && $is_full_admin) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $st_vis = (int)$_POST['stato_visibile'] === 1 ? 1 : 0;
    $p_id_toggle = (int)$_POST['pagina_id'];
    \App\Core\App::get(\App\Portale\AreeRepository::class)->impostaVisibile($p_id_toggle, $st_vis === 1);
    flash_set($st_vis ? "Area visibile al pubblico." : "Area nascosta al pubblico: la vedono solo i gestori.");
    echo "<script>window.location.replace('aree.php?p_id=$p_id_toggle');</script>";
    exit;
}

if (isset($_POST['del_pagina_completa']) && $is_full_admin) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $p_id_del = (int)$_POST['pagina_id_del'];
    $aree_repo = \App\Core\App::get(\App\Portale\AreeRepository::class);
    if ($p_info_del = $aree_repo->slugETitolo($p_id_del)) {
        // Conferma obbligatoria: il nome dell'area scritto a mano (senza distinguere maiuscole e spazi in più)
        $norm_nome = fn($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s)));
        if ($norm_nome($_POST['conferma_nome'] ?? '') !== $norm_nome($p_info_del['titolo'])) {
            flash_set("Area non eliminata: per confermare scrivi esattamente il nome dell'area.", 'danger');
            echo "<script>window.location.replace('aree.php');</script>";
            exit;
        }
        $slug_del = $p_info_del['slug'];
        // Ogni evento con turni, prenotazioni, messaggi, campi form e sondaggi (niente dati orfani)
        foreach ($aree_repo->idAttivita($p_id_del) as $ev_id_del) {
            elimina_evento($conn, $ev_id_del);
        }
        $slug_del_safe = preg_replace('/[^a-z0-9_]/', '', $slug_del);
        $menu_url_del = $slug_del_safe . '.php';
        $aree_repo->eliminaDatiDellArea($p_id_del, $menu_url_del);
        // Vecchi file segnaposto (creati dalle versioni precedenti): si eliminano SOLO se sono esattamente
        // lo stub generato per quest'area, mai un altro file del sito con lo stesso nome.
        foreach (["$slug_del_safe.php" => 'master_template.php', "{$slug_del_safe}_archivio.php" => 'master_archivio.php'] as $file_stub => $master) {
            $percorso = dirname(__DIR__) . '/' . $file_stub;
            if ($slug_del_safe === '' || !is_file($percorso) || filesize($percorso) > 200) continue;
            $contenuto = preg_replace('/\s+/', '', (string)file_get_contents($percorso));
            if ($contenuto === "<?php\$page_slug='$slug_del_safe';require_once'$master';?>"
                || $contenuto === "<?php\$page_slug='$slug_del_safe';require_once'$master';") {
                @unlink($percorso);
            }
        }
        
        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Area", ["Area" => $p_info_del['titolo'], "Slug" => $slug_del]);
        flash_set("Area \"" . $p_info_del['titolo'] . "\" eliminata definitivamente.", 'warning');
        echo "<script>window.location.replace('aree.php');</script>";
        exit;
    }
}

// ==============================================================================
// 2. AREE DI LAVORO DELL'UTENTE
// Un'area compare se l'utente la gestisce tutta, se gestisce almeno un'attività oppure se ha un perimetro
// che la riguarda (tutti i progetti / tutti gli eventi dell'area, attività di Formazione Scuola Lavoro).
// ==============================================================================
$aree_ambito = $is_full_admin ? [] : aree_da_ambiti($conn, (int)$u_id_curr);
$pagine_disponibili = [];
$abil_repo_h = \App\Core\App::get(\App\Auth\Abilitazioni\AbilitazioniRepository::class);

foreach (\App\Core\App::get(\App\Portale\AreeRepository::class)->tutte() as $p_row) {
    if ($is_full_admin) {
        $pagine_disponibili[] = $p_row;
        continue;
    }
    $p_id = (int)$p_row['id'];
    $has_access = in_array((int)$u_id_curr, ids_gestori_da_campi($p_row['gestore_utente_id'], $p_row['gestori_utenti_ids'], $p_row['permessi_gestori_json']), true)
               || in_array($p_id, $aree_ambito, true);
    if (!$has_access) {
        foreach ($abil_repo_h->gestoriAttivitaArea($p_id) as $e_row) {
            if (in_array((int)$u_id_curr, ids_gestori_da_campi(0, $e_row['gestori_utenti_ids'], $e_row['permessi_gestori_json']), true)) { $has_access = true; break; }
        }
    }
    if ($has_access) $pagine_disponibili[] = $p_row;
}

$ids_aree_consentite = array_map('intval', array_column($pagine_disponibili, 'id'));
$filtro_p = isset($_GET['p_id']) ? (int)$_GET['p_id'] : 0;
if (!in_array($filtro_p, $ids_aree_consentite, true)) $filtro_p = $ids_aree_consentite[0] ?? 0;
$page_cfg = null;
if ($filtro_p > 0) {
    $page_cfg = \App\Core\App::get(\App\Portale\AreeRepository::class)->perId($filtro_p);
}

// ==============================================================================
// 3. PERMESSI NELL'AREA CORRENTE
// Dentro il proprio perimetro si gestisce tutto: attività, iscritti, sondaggi, moduli, attestati, statistiche.
// - tutta l'area: come un amministratore, ma solo per quell'area (anche Impostazioni area);
// - attività scelte, tutti i progetti, tutti gli eventi, attività FSL: solo quelle ($sql_filtro_eventi_rbac).
// Le vecchie abilitazioni con solo alcune sezioni (eventi, iscritti…) valgono ora per tutto il perimetro.
// ==============================================================================
$uid_rbac = (int)$u_id_curr;
$puo_fsl             = $is_full_admin || ha_modulo($conn, $uid_rbac, 'fsl'); // modulo FSL, perimetro «fsl» o modulo Orientamento (come prima)
$puo_fsl_convenzioni = $puo_fsl || ha_ambito($conn, $uid_rbac, 'fsl_convenzioni');
$puo_fsl_scuole      = $puo_fsl || ha_ambito($conn, $uid_rbac, 'fsl_scuole');
$puo_didattica_tutto = $is_full_admin || ha_modulo($conn, $uid_rbac, 'didattica') || utente_operatore_ufficio($conn, $utente_admin);
// Referenti dei consigli dei corsi di studio: solo le sedute dei loro consigli
$consigli_referente  = $puo_didattica_tutto ? [] : consigli_referente($conn, $utente_admin);
$puo_didattica       = $puo_didattica_tutto || (bool)$consigli_referente;
$puo_tutorato        = $is_full_admin || ha_modulo($conn, $uid_rbac, 'didattica') || utente_operatore_ufficio($conn, $utente_admin, 'bandi');

$can_manage_eventi = $can_manage_iscritti = $can_manage_sondaggi = $can_manage_form = $can_manage_settings = $is_full_admin;
$is_area_manager = $is_full_admin;
$puo_creare_eventi = $puo_creare_progetti = $is_full_admin;
$allowed_events_ids = [];

if (!$is_full_admin && $page_cfg) {
    // Gestore di tutta l'area, oppure abilitato all'intero modulo a cui l'area appartiene
    if (in_array($uid_rbac, ids_gestori_da_campi($page_cfg['gestore_utente_id'], $page_cfg['gestori_utenti_ids'], $page_cfg['permessi_gestori_json']), true)
        || area_nel_modulo_utente($conn, $uid_rbac, $page_cfg)) {
        $is_area_manager = true;
        $can_manage_eventi = $can_manage_iscritti = $can_manage_sondaggi = $can_manage_form = $can_manage_settings = true;
        $puo_creare_eventi = $puo_creare_progetti = true;
    } else {
        // Attività assegnate una per una
        foreach (\App\Core\App::get(\App\Auth\Abilitazioni\AbilitazioniRepository::class)->gestoriAttivitaAreaConTitolo($filtro_p) as $ev_row) {
            if (in_array($uid_rbac, ids_gestori_da_campi(0, $ev_row['gestori_utenti_ids'], $ev_row['permessi_gestori_json']), true)) $allowed_events_ids[] = (int)$ev_row['id'];
        }
        // Perimetri: tutti i progetti / tutti gli eventi dell'area (anche quelli che verranno creati), attività FSL
        $allowed_events_ids = array_values(array_unique(array_merge($allowed_events_ids, attivita_da_ambiti($conn, $uid_rbac, $filtro_p))));
        $puo_creare_progetti = ha_ambito($conn, $uid_rbac, 'progetti', $filtro_p);
        $puo_creare_eventi   = ha_ambito($conn, $uid_rbac, 'eventi', $filtro_p);
        if ($allowed_events_ids || $puo_creare_progetti || $puo_creare_eventi) {
            $can_manage_eventi = $can_manage_iscritti = $can_manage_sondaggi = $can_manage_form = true;
        }
    }
}

// Abilitati senza aree (solo una parte della FSL o il modulo Didattica): la loro pagina di partenza è quella
if (!$is_full_admin && !$pagine_disponibili && in_array(basename($_SERVER['PHP_SELF']), ['index.php', 'inizio.php', 'dashboard.php', 'aree.php'], true)
    && ($puo_fsl_convenzioni || $puo_fsl_scuole || $puo_didattica)) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Location: ' . ($puo_fsl_convenzioni ? 'fsl.php' : ($puo_fsl_scuole ? 'scuole.php' : 'didattica.php')));
    exit;
}

// ==============================================================================
// 4. MODULI: quelli a cui l'utente ha accesso e quello su cui sta lavorando
// Il modulo corrente decide il menu: le pagine di un modulo senza aree (PAGINE_MODULO) lo indicano da sole,
// la pagina iniziale lo riceve con ?sezione=…, tutte le altre seguono l'area corrente.
// ==============================================================================
$moduli_utente = [];
foreach ($pagine_disponibili as $p_m) $moduli_utente[modulo_di_area($p_m)] = true;
if ($puo_fsl_convenzioni) $moduli_utente['fsl'] = true;
if ($puo_didattica) $moduli_utente['didattica'] = true;
if ($is_full_admin || $puo_fsl_scuole) $moduli_utente['portale'] = true;
$moduli_utente = array_values(array_filter(array_keys(MODULI_PORTALE), fn($k) => isset($moduli_utente[$k])));
$pagina_corrente = basename($_SERVER['PHP_SELF']);
if (isset(PAGINE_MODULO[$pagina_corrente])) $modulo_corrente = PAGINE_MODULO[$pagina_corrente];
elseif ($pagina_corrente === 'inizio.php') $modulo_corrente = isset(MODULI_PORTALE[$_GET['sezione'] ?? '']) ? $_GET['sezione'] : '';
elseif (in_array($pagina_corrente, ['aree.php', 'index.php'], true)) $modulo_corrente = '';
else $modulo_corrente = $page_cfg ? modulo_di_area($page_cfg) : '';

if (!function_exists('schede_sistema')) {
    // Schede comuni di "Sistema e registri" (una sola voce di menu per tre pagine)
    function schede_sistema(string $attiva): void {
        global $filtro_p;
        $schede = ['sistema.php' => ['fa-envelope', 'Email e controlli'], 'audit_log.php' => ['fa-user-secret', 'Registro operazioni'], 'log_accessi.php' => ['fa-right-to-bracket', 'Accessi SSO']];
        echo '<ul class="nav nav-pills gap-1 mb-3 flex-wrap" style="--bs-nav-pills-link-active-bg:#1e293b;">';
        foreach ($schede as $file => [$ico, $nome]) {
            $on = $file === $attiva;
            echo '<li class="nav-item"><a class="nav-link fw-bold' . ($on ? ' active' : ' bg-light text-dark') . '" style="font-size:.85rem;" href="' . $file . '?p_id=' . (int)$filtro_p . '"' . ($on ? ' aria-current="page"' : '') . '><i class="fa ' . $ico . ' me-1" aria-hidden="true"></i>' . $nome . '</a></li>';
        }
        echo '</ul>';
    }
}

// Filtro sulle attività per chi non gestisce tutta l'area
$sql_filtro_eventi_rbac = "";
if (!$is_full_admin && !$is_area_manager) {
    $sql_filtro_eventi_rbac = $allowed_events_ids ? " AND e.id IN (" . implode(',', $allowed_events_ids) . ") " : " AND e.id = -1 ";
}

$sys = \App\Core\App::get(\App\Sistema\ImpostazioniSistemaRepository::class)->riga();
// Fase 3: lettura da cache locale (stessa di header.php/footer.php). Sempre coerente con
// il DB perché testata.php invalida la cache subito dopo ogni salvataggio delle impostazioni.
$cfg_p = get_configurazione_portale($conn);
$current_page = basename($_SERVER['PHP_SELF']);

$unread_count = \App\Core\App::get(\App\Portale\AreeRepository::class)->messaggiNonLetti((int)$filtro_p, $sql_filtro_eventi_rbac);
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione - Didattica DiBEST</title>
    <link rel="icon" type="image/x-icon" href="../<?php echo !empty($cfg_p['favicon_path']) ? $cfg_p['favicon_path'] : 'favicon.ico'; ?>">
    <link href="<?php echo url_vendor('jsdelivr/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo url_vendor('datatables/1.13.6/css/dataTables.bootstrap5.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo url_vendor('jsdelivr/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'); ?>" rel="stylesheet" />
    <link rel="stylesheet" href="<?php echo url_vendor('cdnjs/ajax/libs/font-awesome/6.4.0/css/all.min.css'); ?>">
    <script src="<?php echo url_vendor('jquery/jquery-3.7.1.min.js'); ?>" integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs" crossorigin="anonymous"></script>
    <script>
      // Applica il tema prima del render per evitare il flash
      (function() {
        var t = localStorage.getItem('adminTheme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', t);
      })();
    </script>
    <style>
        body { background-color: #f8f9fa; overflow-x: hidden; }
        [data-bs-theme="dark"] body { background-color: #1a1d21 !important; }
        [data-bs-theme="dark"] #sidebar { background: #101418 !important; }
        [data-bs-theme="dark"] .bg-white { background-color: #2b2f33 !important; }
        [data-bs-theme="dark"] .navbar { background-color: #1e2227 !important; border-color: #3a3f46 !important; }
        [data-bs-theme="dark"] .card { background-color: #2b2f33 !important; border-color: #3a3f46 !important; }
        [data-bs-theme="dark"] .form-select, [data-bs-theme="dark"] .form-control { background-color: #1e2227; color: #e0e6f0; border-color: #3a3f46; }
        [data-bs-theme="dark"] .text-muted { color: #8a95a3 !important; }
        #wrapper { display: flex; width: 100%; min-height: 100vh; }
        #sidebar { width: 260px; min-height: 100vh; transition: all 0.3s ease; z-index: 1000; background: #1e293b; }
        #page-content-wrapper { flex-grow: 1; width: 100%; transition: all 0.3s ease; }
        .nav-pills .nav-link { color: #cbd5e1; border-radius: 8px; margin-bottom: 5px; text-align: left; font-weight: 600; padding: 10px 15px; }
        .nav-pills .nav-link.active { background-color: #990000; color: #fff; }
        .nav-pills .nav-link:hover:not(.active) { background-color: rgba(255,255,255,0.1); color: #fff; }
        #sidebar .nav > li { width: 100%; min-width: 0; }
        /* Gruppi richiudibili del menu */
        .side-grp > summary { list-style: none; cursor: pointer; display: flex; align-items: center; }
        .side-grp > summary::-webkit-details-marker { display: none; }
        .side-grp-freccia { margin-left: auto; font-size: .7rem; opacity: .7; transition: transform .15s; }
        .side-grp[open] > summary .side-grp-freccia { transform: rotate(90deg); }
        .side-grp-voci { padding-left: 14px; border-left: 1px solid rgba(255,255,255,.12); margin-left: 24px; }
        .side-grp-voci .nav-link { padding: 7px 12px; font-weight: 500; font-size: .9rem; margin-bottom: 2px; }
        .side-grp > summary:focus-visible { outline: 2px solid #93c5fd; outline-offset: 1px; }
        .area-top { border: 2px solid; border-radius: 999px; padding: 4px 12px; background: #fff; max-width: 42vw; }
        .area-top:hover { background: #f8fafc; }
        .area-top-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .area-top-nome { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        [data-bs-theme="dark"] .area-top { background: #2b2f33; }
        @media (max-width: 575.98px) {
            /* Telefono: Menu + area sulla prima riga, pulsanti sulla seconda */
            #page-content-wrapper > .navbar { flex-wrap: wrap !important; }
            #page-content-wrapper > .navbar .area-top { flex: 1 1 0; max-width: none; justify-content: center; }
            .navbar-azioni { width: 100%; flex-wrap: wrap; justify-content: flex-end; }
        }
        .side-area { background: rgba(255,255,255,0.06); overflow: hidden; }
        .side-modulo { background: rgba(255,255,255,0.08); color: #fff; }
        .side-modulo:hover, .side-modulo.attivo { background: rgba(255,255,255,0.16); }
        .side-altro-modulo { font-weight: 500 !important; font-size: .9rem; padding: 7px 15px !important; }
        @media (max-width: 991.98px) {
            #sidebar { margin-left: -260px; position: fixed; height: 100%; overflow-y: auto; }
            #sidebar.active { margin-left: 0; box-shadow: 5px 0 15px rgba(0,0,0,0.5); }
            #sidebarOverlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; }
            #sidebarOverlay.active { display: block; }
        }
    </style>
</head>
<body>

<div id="wrapper" class="no-print">

    <!-- SIDEBAR DINAMICA (RBAC) -->
    <div id="sidebar" class="text-white shadow">
        <div class="p-4 border-bottom border-secondary d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-danger m-0" style="color:#ff4d4d !important;">Didattica DiBEST<br><small class="text-white fs-6">Gestione</small></h5>
            <button class="btn btn-sm btn-outline-light d-lg-none" id="closeSidebar"><i class="fa fa-times"></i></button>
        </div>
        
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <?php
                // Voce del menu; $attive = pagine che la accendono (la prima è il link)
                $voce_menu = function (array $attive, string $href, string $icona, string $testo, bool $accesa = false) use ($current_page, $filtro_p) {
                    $on = $accesa || in_array($current_page, $attive, true);
                    echo '<li class="nav-item"><a class="nav-link w-100' . ($on ? ' active' : '') . '" href="' . htmlspecialchars($href) . (str_contains($href, '?') ? '&amp;' : '?') . 'p_id=' . (int)$filtro_p . '"' . ($on ? ' aria-current="page"' : '') . '>'
                       . '<i class="fa ' . $icona . ' me-2 text-center" style="width:20px;" aria-hidden="true"></i> ' . htmlspecialchars($testo) . '</a></li>';
                };
                // Gruppo richiudibile: aperto se contiene la pagina corrente, altrimenti come l'ha lasciato l'utente (localStorage)
                $apri_gruppo = function (string $id, string $icona, string $titolo, array $pagine) use ($current_page) {
                    $qui = in_array($current_page, $pagine, true);
                    echo '<li class="nav-item side-grp-li"><details class="side-grp" data-grp="' . $id . '"' . ($qui ? ' open data-qui="1"' : '') . '>'
                       . '<summary class="nav-link w-100"><i class="fa ' . $icona . ' me-2 text-center" style="width:20px;" aria-hidden="true"></i> ' . htmlspecialchars($titolo)
                       . '<i class="fa fa-chevron-right side-grp-freccia" aria-hidden="true"></i></summary><ul class="nav nav-pills flex-column side-grp-voci">';
                };
                $chiudi_gruppo = function () { echo '</ul></details></li>'; };
                ?>
                <?php if (count($moduli_utente) > 1 || count($pagine_disponibili) > 1) $voce_menu([], 'inizio.php', 'fa-table-cells-large', 'Tutti i moduli', $current_page === 'inizio.php' && $modulo_corrente === ''); ?>

                <?php if ($modulo_corrente !== ''): $mod_c = MODULI_PORTALE[$modulo_corrente]; ?>
                <!-- ── Modulo corrente: il menu mostra solo ciò che gli appartiene ── -->
                <li class="nav-item mt-3 mb-1">
                    <a href="inizio.php?sezione=<?php echo urlencode($modulo_corrente); ?>&amp;p_id=<?php echo $filtro_p; ?>" class="side-modulo d-flex align-items-center gap-2 px-3 py-2 rounded text-decoration-none<?php echo $current_page === 'inizio.php' ? ' attivo' : ''; ?>" style="border-left:4px solid <?php echo $mod_c['colore']; ?>;" <?php echo $current_page === 'inizio.php' ? 'aria-current="page"' : ''; ?>>
                        <i class="fa <?php echo $mod_c['icona']; ?>" style="width:20px;" aria-hidden="true"></i>
                        <span style="min-width:0;"><span class="d-block text-secondary fw-bold text-uppercase" style="font-size:.62rem;letter-spacing:.06em;">Modulo</span><span class="fw-bold text-white"><?php echo htmlspecialchars($mod_c['nome']); ?></span></span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (in_array($modulo_corrente, ['orientamento', 'fsl', 'calendari', ''], true) && ($is_full_admin || count($pagine_disponibili) > 1)): // un gestore con una sola area non ha nulla da scegliere ?>
                <li class="nav-item mb-1">
                    <a class="nav-link w-100 <?php echo in_array($current_page, ['aree.php'], true) ? 'active' : ''; ?>" href="aree.php?p_id=<?php echo $filtro_p; ?>">
                        <i class="fa fa-layer-group me-2 text-center" style="width:20px;" aria-hidden="true"></i> Aree
                        <span class="badge rounded-pill bg-secondary ms-1" style="font-size:.65rem;"><?php echo count($pagine_disponibili); ?></span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($filtro_p > 0 && $page_cfg && in_array($modulo_corrente, ['orientamento', 'fsl', 'calendari'], true) && modulo_di_area($page_cfg) === $modulo_corrente): $col_side = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3'); ?>
                <!-- ── Area corrente: tutto ciò che riguarda l'area ── -->
                <li class="nav-item mt-2 mb-2">
                    <div class="side-area px-3 py-2 rounded" style="border-left:4px solid <?php echo $col_side; ?>;">
                        <div class="text-secondary fw-bold text-uppercase" style="font-size:.66rem;letter-spacing:.06em;">Area</div>
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <span class="fw-bold text-white text-truncate" style="min-width:0;" title="<?php echo htmlspecialchars($page_cfg['titolo']); ?>"><?php echo htmlspecialchars($page_cfg['titolo']); ?></span>
                            <?php if (count($pagine_disponibili) > 1): ?><a href="inizio.php?sezione=<?php echo urlencode($modulo_corrente); ?>&amp;p_id=<?php echo $filtro_p; ?>" class="small text-info text-decoration-none text-nowrap">Cambia</a><?php endif; ?>
                        </div>
                        <?php if ((int)($page_cfg['visibile'] ?? 1) === 0): ?><span class="badge bg-warning text-dark mt-1" style="font-size:.65rem;"><i class="fa fa-eye-slash me-1" aria-hidden="true"></i>Nascosta al pubblico</span><?php endif; ?>
                    </div>
                </li>
                <?php
                // Quali voci servono: progetti ed eventi solo se ce ne sono (o se si possono creare) nel perimetro dell'utente
                $tipi_vis = \App\Core\App::get(\App\Portale\AreeRepository::class)->tipiAttivita((int)$filtro_p, $sql_filtro_eventi_rbac);
                $vede_eventi = $can_manage_eventi && ($is_area_manager || $puo_creare_eventi || $tipi_vis['evento']);
                $vede_progetti = $can_manage_eventi && (($page_cfg['layout_template'] ?? '') === 'progetti' || $tipi_vis['progetto'] || $puo_creare_progetti && !$is_area_manager);
                // Studenti e attestati: progetti per le scuole o eventi per le classi
                $mostra_classi = false;
                if ($can_manage_iscritti) {
                    $mostra_classi = \App\Core\App::get(\App\Portale\AreeRepository::class)->haAttivitaConClassi((int)$filtro_p, $sql_filtro_eventi_rbac);
                }
                ?>
                <?php if (tipo_area($page_cfg) === 'calendario'): // Calendari e risorse: prenotazioni a slot invece di eventi e turni
                    $n_appr = 0;
                    if ($is_area_manager) {
                        $n_appr = \App\Core\App::get(\App\Portale\AreeRepository::class)->prenotazioniDaApprovare((int)$filtro_p);
                        $voce_menu(['prenotazioni_risorse.php'], 'prenotazioni_risorse.php', 'fa-calendar-check', 'Prenotazioni' . ($n_appr ? " ($n_appr da approvare)" : ''));
                        $voce_menu(['risorse.php'], 'risorse.php', 'fa-door-open', 'Risorse, orari e chiusure');
                    }
                ?>
                <?php else: ?>
                <?php $voce_menu(['dashboard.php', 'index.php'], 'dashboard.php', 'fa-gauge-high', 'Dashboard'); ?>
                <?php if ($can_manage_eventi || $can_manage_form): $apri_gruppo('attivita', 'fa-calendar-alt', 'Attività', ['eventi.php', 'progetti.php', 'form_builder.php', 'archivio.php']); ?>
                    <?php if ($vede_eventi) $voce_menu(['eventi.php'], 'eventi.php', 'fa-calendar-day', 'Eventi e turni'); ?>
                    <?php if ($vede_progetti) $voce_menu(['progetti.php'], 'progetti.php', 'fa-diagram-project', 'Progetti'); ?>
                    <?php if ($can_manage_form) $voce_menu(['form_builder.php'], 'form_builder.php', 'fa-list-check', 'Moduli di iscrizione'); ?>
                    <?php if ($can_manage_eventi) $voce_menu(['archivio.php'], 'archivio.php', 'fa-box-archive', 'Archivio'); ?>
                <?php $chiudi_gruppo(); endif; ?>

                <?php if ($can_manage_iscritti || $can_manage_sondaggi): $apri_gruppo('partecipanti', 'fa-users', 'Partecipanti', ['iscritti.php', 'scanner.php', 'stampa_badge.php', 'stampa_lista_iscritti.php', 'partecipanti.php', 'sondaggi.php']); ?>
                    <?php if ($can_manage_iscritti) $voce_menu(['iscritti.php', 'scanner.php', 'stampa_badge.php', 'stampa_lista_iscritti.php'], 'iscritti.php', 'fa-user-check', 'Iscritti e check-in'); ?>
                    <?php if ($mostra_classi) $voce_menu(['partecipanti.php'], 'partecipanti.php', 'fa-graduation-cap', 'Studenti e attestati'); ?>
                    <?php if ($can_manage_sondaggi) $voce_menu(['sondaggi.php'], 'sondaggi.php', 'fa-star', 'Sondaggi'); ?>
                <?php $chiudi_gruppo(); endif; ?>

                <?php if ($can_manage_iscritti) $voce_menu(['statistiche.php'], 'statistiche.php', 'fa-chart-pie', 'Statistiche'); ?>
                <?php endif; // fine voci per tipo di area ?>
                <?php if ($can_manage_settings) $voce_menu(['impostazioni_area.php'], 'impostazioni_area.php', 'fa-paint-brush', 'Impostazioni area'); ?>
                <?php endif; // fine area corrente ?>

                <?php if ($modulo_corrente === 'orientamento' && ($is_full_admin || ha_modulo($conn, $uid_rbac, 'orientamento'))): ?>
                    <!-- ── Modulo Eventi e seminari: report per ambito di tutte le aree ── -->
                    <li class="nav-item mt-3 mb-1"><div class="text-secondary small fw-bold px-3 text-uppercase" style="font-size:.66rem;letter-spacing:.06em;">Tutte le aree</div></li>
                    <?php $voce_menu(['report_ambiti.php'], 'report_ambiti.php', 'fa-chart-column', 'Report per ambito'); ?>
                <?php endif; ?>
                <?php if ($modulo_corrente === 'fsl' && $puo_fsl_convenzioni): ?>
                    <!-- ── Modulo Formazione Scuola Lavoro: pannello comune a tutte le aree FSL ── -->
                    <li class="nav-item mt-3 mb-1"><div class="text-secondary small fw-bold px-3 text-uppercase" style="font-size:.66rem;letter-spacing:.06em;">Tutte le aree FSL</div></li>
                    <?php $voce_menu(['fsl.php', 'convenzione_file.php'], 'fsl.php', 'fa-briefcase', $puo_fsl ? 'Formazione Scuola Lavoro' : 'Convenzioni FSL'); ?>
                <?php endif; ?>

                <?php if ($modulo_corrente === 'didattica' && $puo_didattica): ?>
                    <?php $tab_did = $current_page === 'didattica.php' ? (in_array($_GET['tab'] ?? '', ['sedute', 'moduli', 'ufficio', 'statistiche'], true) ? $_GET['tab'] : 'pratiche') : '';
                    if (!$puo_didattica_tutto) $tab_did = $current_page === 'didattica.php' ? 'sedute' : '';
                    if ($puo_didattica_tutto) $voce_menu([], 'didattica.php?tab=pratiche', 'fa-inbox', 'Pratiche degli studenti', $tab_did === 'pratiche');
                    $voce_menu([], 'didattica.php?tab=sedute', 'fa-gavel', $puo_didattica_tutto ? 'Sedute e verbali' : 'Sedute del consiglio', $tab_did === 'sedute');
                    if ($puo_didattica_tutto) {
                        $voce_menu([], 'didattica.php?tab=moduli', 'fa-file-lines', 'Moduli e documenti', $tab_did === 'moduli');
                        $voce_menu([], 'didattica.php?tab=ufficio', 'fa-people-group', 'Ufficio e ricevimento', $tab_did === 'ufficio');
                        $voce_menu([], 'didattica.php?tab=statistiche', 'fa-chart-column', 'Statistiche', $tab_did === 'statistiche');
                    }
                    if ($puo_tutorato) $voce_menu(['tutorato.php'], 'tutorato.php', 'fa-user-graduate', 'Tutorato · lettere di incarico'); ?>
                <?php endif; ?>

                <?php if ($modulo_corrente === 'portale'): ?>
                    <?php
                    // Anagrafi comuni a tutto il portale: personale e insegnamenti dalle API di Ateneo, scuole dal Ministero
                    $vista_ana = $current_page === 'anagrafe_personale.php' ? (string)($_GET['vista'] ?? 'docenti') : '';
                    $apri_gruppo('anagrafi', 'fa-address-book', 'Anagrafi', ['anagrafe_personale.php', 'anagrafe_docenti.php', 'anagrafe_pta.php', 'anagrafe_insegnamenti.php', 'scuole.php']); ?>
                        <?php if ($is_full_admin): ?>
                            <?php $voce_menu(['anagrafe_docenti.php'], 'anagrafe_docenti.php', 'fa-chalkboard-user', 'Docenti', $vista_ana === 'docenti'); ?>
                            <?php $voce_menu(['anagrafe_pta.php'], 'anagrafe_pta.php', 'fa-user-tie', 'Personale TA', $vista_ana === 'pta'); ?>
                            <?php $voce_menu(['anagrafe_insegnamenti.php'], 'anagrafe_insegnamenti.php', 'fa-book-open', 'Insegnamenti', $vista_ana === 'insegnamenti'); ?>
                            <?php $voce_menu([], 'anagrafe_personale.php?vista=corsi', 'fa-graduation-cap', 'Corsi di studio', $vista_ana === 'corsi'); ?>
                        <?php endif; ?>
                        <?php if ($puo_fsl_scuole) $voce_menu(['scuole.php'], 'scuole.php', 'fa-building-columns', 'Scuole'); ?>
                        <?php if ($is_full_admin) $voce_menu([], 'anagrafe_personale.php?vista=strutture', 'fa-sitemap', 'Strutture e aggiornamento', in_array($vista_ana, ['strutture', 'altro'], true)); ?>
                    <?php $chiudi_gruppo(); ?>
                    <?php if ($is_full_admin): ?>
                        <?php $voce_menu(['aree.php', 'nuova_area.php'], 'aree.php', 'fa-layer-group', 'Aree di tutti i moduli'); ?>
                        <?php $apri_gruppo('sito', 'fa-globe', 'Sito pubblico', ['testata.php', 'menu.php']); ?>
                            <?php $voce_menu(['testata.php'], 'testata.php', 'fa-image', 'Testata e home'); ?>
                            <?php $voce_menu(['menu.php'], 'menu.php', 'fa-link', 'Menu'); ?>
                        <?php $chiudi_gruppo(); ?>
                        <?php $voce_menu(['utenti.php'], 'utenti.php', 'fa-users-cog', 'Utenti e abilitazioni'); ?>
                        <?php $voce_menu(['sistema.php', 'audit_log.php', 'log_accessi.php'], 'sistema.php', 'fa-gear', 'Sistema e registri'); ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (count($moduli_utente) > 1): ?>
                    <!-- ── Altri moduli: si passa da uno all'altro senza tornare all'inizio ── -->
                    <li class="nav-item mt-4 mb-1"><div class="text-secondary small fw-bold px-3 text-uppercase" style="font-size:.66rem;letter-spacing:.06em;">Altri moduli</div></li>
                    <?php foreach ($moduli_utente as $k_m): if ($k_m === $modulo_corrente) continue; $m_m = MODULI_PORTALE[$k_m]; ?>
                        <li class="nav-item"><a class="nav-link w-100 side-altro-modulo" href="inizio.php?sezione=<?php echo urlencode($k_m); ?>&amp;p_id=<?php echo $filtro_p; ?>"><i class="fa <?php echo $m_m['icona']; ?> me-2 text-center" style="width:20px;color:<?php echo $m_m['colore']; ?>;filter:brightness(1.6);" aria-hidden="true"></i> <?php echo htmlspecialchars($m_m['nome']); ?></a></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <script>
            // Gruppi del menu: si ricorda quali l'utente ha aperto o chiuso (quello della pagina corrente resta aperto)
            (function () {
                var chiave = 'adminMenuGruppi', stato = {};
                try { stato = JSON.parse(localStorage.getItem(chiave) || '{}') || {}; } catch (e) {}
                document.querySelectorAll('#sidebar .side-grp').forEach(function (d) {
                    var g = d.dataset.grp;
                    if (!d.dataset.qui && stato[g] === 1) d.open = true;
                    d.addEventListener('toggle', function () {
                        stato[g] = d.open ? 1 : 0;
                        try { localStorage.setItem(chiave, JSON.stringify(stato)); } catch (e) {}
                    });
                });
            })();
            </script>
        </div>
    </div>
    
    <div id="sidebarOverlay"></div>

    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-white border-bottom shadow-sm px-3 py-2 d-flex justify-content-between flex-nowrap gap-2 sticky-top" style="z-index: 998;">
            <button class="btn btn-dark d-lg-none flex-shrink-0" id="sidebarToggle"><i class="fa fa-bars"></i> Menu</button>
            <?php if ($modulo_corrente !== '' && !in_array($modulo_corrente, ['orientamento', 'fsl', 'calendari'], true) || ($page_cfg && $modulo_corrente !== '' && modulo_di_area($page_cfg) !== $modulo_corrente)): $m_top = MODULI_PORTALE[$modulo_corrente]; ?>
                <a href="inizio.php?sezione=<?php echo urlencode($modulo_corrente); ?>&amp;p_id=<?php echo $filtro_p; ?>" class="area-top text-decoration-none d-flex align-items-center gap-2" title="Modulo su cui stai lavorando" style="border-color: <?php echo $m_top['colore']; ?>;">
                    <i class="fa <?php echo $m_top['icona']; ?>" style="color: <?php echo $m_top['colore']; ?>;" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline text-secondary" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Modulo</span>
                    <span class="area-top-nome fw-bold" style="color: <?php echo $m_top['colore']; ?>;"><?php echo htmlspecialchars($m_top['nome']); ?></span>
                </a>
            <?php elseif ($filtro_p > 0 && $page_cfg): $col_top = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3'); $link_top = count($pagine_disponibili) > 1 || $is_full_admin; ?>
                <<?php echo $link_top ? 'a href="inizio.php?sezione=' . urlencode(modulo_di_area($page_cfg)) . '&amp;p_id=' . $filtro_p . '"' : 'span'; ?> class="area-top text-decoration-none d-flex align-items-center gap-2" title="Area su cui stai lavorando<?php echo $link_top ? ' — clicca per cambiarla' : ''; ?>" style="border-color: <?php echo $col_top; ?>;">
                    <span class="area-top-dot" style="background: <?php echo $col_top; ?>;" aria-hidden="true"></span>
                    <span class="d-none d-sm-inline text-secondary" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Area</span>
                    <span class="area-top-nome fw-bold" style="color: <?php echo $col_top; ?>;"><?php echo htmlspecialchars($page_cfg['titolo']); ?></span>
                    <?php if ((int)($page_cfg['visibile'] ?? 1) === 0): ?><span class="badge bg-warning text-dark" style="font-size:.62rem;" title="Nascosta al pubblico"><i class="fa fa-eye-slash"></i></span><?php endif; ?>
                    <?php if ($link_top): ?><i class="fa fa-right-left text-secondary" style="font-size:.7rem;" aria-hidden="true"></i><?php endif; ?>
                </<?php echo $link_top ? 'a' : 'span'; ?>>
            <?php endif; ?>
            <div class="ms-auto d-flex align-items-center gap-2 navbar-azioni">
                <span class="text-muted small d-none d-md-inline-block"><i class="fa fa-user-shield text-danger me-1"></i> <strong><?php echo htmlspecialchars($utente_admin['nome'] ?? ''); ?></strong></span>
                
                <a href="messaggi.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-dark btn-sm fw-bold me-2 shadow-sm" style="background-color: #ffffff;">
                    <i class="fa fa-envelope me-1"></i> Messaggi
                    <span id="badgeUnreadWrap" <?php echo (isset($unread_count) && $unread_count > 0) ? '' : 'style="display:none;"'; ?>>
                        <span id="badgeUnread" class="badge bg-danger ms-1 text-white shadow-sm" style="background-color: #B80000 !important;"><?php echo 'Nuovi (' . ($unread_count ?? 0) . ')'; ?></span>
                    </span>
                </a>

                <button id="themeToggle" class="btn btn-outline-secondary btn-sm" title="Cambia tema" onclick="toggleTheme()">
                    <i class="fa fa-moon" id="themeIcon"></i>
                </button>
                <a href="scanner.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-warning btn-sm fw-bold text-dark shadow-sm" title="Apri Scanner Check-in"><i class="fa fa-qrcode"></i> <span class="d-none d-sm-inline">Scanner</span></a>
                <a href="../index.php" target="_blank" class="btn btn-outline-secondary btn-sm" title="Vai al sito"><i class="fa fa-external-link-alt"></i> <span class="d-none d-sm-inline">Visita Sito</span></a>
                <a href="../esci.php" class="btn btn-danger btn-sm" title="Esci"><i class="fa fa-sign-out-alt"></i></a>
            </div>
        </nav>

        <div class="container-fluid p-4" style="max-width: 1400px;">
            <?php echo flash_html(); ?>


<!-- Modal avviso scadenza sessione -->
<div class="modal fade" id="modSessionTimeout" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark py-2">
                <h6 class="modal-title fw-bold"><i class="fa fa-clock me-1"></i> Sessione in scadenza</h6>
            </div>
            <div class="modal-body text-center">
                <p class="mb-1">La tua sessione scadrà tra</p>
                <p class="fw-bold fs-4 text-danger mb-1" id="sessionCountdown">2:00</p>
                <p class="text-muted small">Vuoi restare connesso?</p>
            </div>
            <div class="modal-footer py-2 justify-content-center gap-2">
                <button class="btn btn-success btn-sm fw-bold px-4" onclick="renewSession()"><i class="fa fa-rotate-right me-1"></i> Sì, rinnova</button>
                <a href="../esci.php" class="btn btn-danger btn-sm fw-bold"><i class="fa fa-sign-out-alt me-1"></i> Esci</a>
            </div>
        </div>
    </div>
</div>

<script>
// ── Dark / Light mode toggle ──────────────────────────────────────────────────
function toggleTheme() {
    var html = document.documentElement;
    var current = html.getAttribute('data-bs-theme') || 'light';
    var next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-bs-theme', next);
    localStorage.setItem('adminTheme', next);
    updateThemeIcon(next);
}
function updateThemeIcon(theme) {
    var icon = document.getElementById('themeIcon');
    if (!icon) return;
    icon.className = theme === 'dark' ? 'fa fa-sun' : 'fa fa-moon';
}
// Sincronizza icona al caricamento
document.addEventListener('DOMContentLoaded', function() {
    updateThemeIcon(localStorage.getItem('adminTheme') || 'light');
});

// ── Session timeout (avviso 2 min prima dei 30 min di inattività) ─────────────
(function() {
    var SESSION_MINUTES = 30;
    var WARN_BEFORE_SEC = 120;
    var inactivityTimer, countdownTimer;
    var modal = null;
    var secondsLeft = WARN_BEFORE_SEC;

    function getModal() {
        if (!modal && typeof bootstrap !== 'undefined') {
            modal = new bootstrap.Modal(document.getElementById('modSessionTimeout'));
        }
        return modal;
    }

    function startCountdown() {
        secondsLeft = WARN_BEFORE_SEC;
        var el = document.getElementById('sessionCountdown');
        clearInterval(countdownTimer);
        countdownTimer = setInterval(function() {
            secondsLeft--;
            if (el) {
                var m = Math.floor(secondsLeft / 60);
                var s = secondsLeft % 60;
                el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            }
            if (secondsLeft <= 0) {
                clearInterval(countdownTimer);
                window.location.href = '../esci.php';
            }
        }, 1000);
        var m = getModal();
        if (m) m.show();
    }

    function resetTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(startCountdown, (SESSION_MINUTES * 60 - WARN_BEFORE_SEC) * 1000);
    }

    window.renewSession = function() {
        clearInterval(countdownTimer);
        var m = getModal();
        if (m) m.hide();
        fetch(window.location.href, { method: 'HEAD', credentials: 'same-origin' });
        resetTimer();
    };

    ['click', 'keydown', 'mousemove', 'touchstart'].forEach(function(e) {
        document.addEventListener(e, resetTimer, { passive: true });
    });
    resetTimer();
})();

// ── Badge messaggi in tempo reale (polling ogni 30s) ─────────────────────────
(function() {
    var pId = <?php echo (int)$filtro_p; ?>;
    if (!pId) return;

    function aggiornaBadge(n) {
        var el = document.getElementById('badgeUnread');
        var wrap = document.getElementById('badgeUnreadWrap');
        if (!el || !wrap) return;
        if (n > 0) {
            el.textContent = 'Nuovi (' + n + ')';
            wrap.style.display = '';
        } else {
            wrap.style.display = 'none';
        }
    }

    function fetchUnread() {
        fetch('api_unread.php?p_id=' + pId, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.auth !== false) aggiornaBadge(d.unread); })
            .catch(function() {});
    }

    setInterval(fetchUnread, 30000);
})();

</script>
