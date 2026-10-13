<?php
// admin/inizio.php - Pagina di ingresso della gestione, organizzata a moduli: prima si sceglie il modulo
// (Eventi e seminari, Formazione Scuola Lavoro, Prenotazioni e risorse, Didattica, Gestione del portale), poi si lavora nella sua pagina:
// card delle aree per i moduli con aree, card delle funzioni per gli altri. Con un solo modulo si entra direttamente.
require_once 'admin_header.php';

// Aree divise per modulo (le aree senza tipo stanno in Eventi e seminari)
$aree_per_modulo = [];
foreach ($pagine_disponibili as $a_m) $aree_per_modulo[modulo_di_area($a_m)][] = $a_m;
$sez_sel = $modulo_corrente !== '' && in_array($modulo_corrente, $moduli_utente, true) ? $modulo_corrente : null;
if ($sez_sel === null && count($moduli_utente) === 1) $sez_sel = $moduli_utente[0];

// Funzioni dei moduli senza aree e del sottomodulo FSL: [titolo, descrizione, icona, pagina]
$funzioni_modulo = [
    'orientamento' => $is_full_admin || ha_modulo($conn, (int)$u_id_curr, 'orientamento') ? [['Report per ambito', 'Terza missione e public engagement: eventi, ore, partecipanti e scuole per ambito e per anno, in Excel', 'fa-chart-column', 'report_ambiti.php']] : [],
    'fsl'          => $puo_fsl_convenzioni ? [[$puo_fsl ? 'Pannello della Formazione Scuola Lavoro' : 'Convenzioni FSL', 'Per tutte le aree FSL: convenzioni con le scuole, verifica delle iscrizioni, riepilogo per anno e valutazioni', 'fa-briefcase', 'fsl.php']] : [],
    'calendari'    => [],
    // Referenti dei consigli: solo le sedute; Tutorato a chi ha il compito «Bandi» (o gestisce tutta la Didattica)
    'didattica'    => $puo_didattica ? array_values(array_filter([
        $puo_didattica_tutto ? ['Pratiche degli studenti', 'Moduli online, pratiche con stato, istruttoria e messaggi', 'fa-inbox', 'didattica.php?tab=pratiche'] : null,
        ['Sedute e verbali', 'Consigli dei corsi di studio, presenze, convalide e piani di studio, verbale in Word ed Excel', 'fa-gavel', 'didattica.php?tab=sedute'],
        $puo_didattica_tutto ? ['Moduli e documenti', 'Modulistica: documenti da scaricare e moduli online guidati', 'fa-file-lines', 'didattica.php?tab=moduli'] : null,
        $puo_didattica_tutto ? ['Ufficio e ricevimento', 'Operatori dell’Ufficio didattico e sportello di ricevimento', 'fa-people-group', 'didattica.php?tab=ufficio'] : null,
        $puo_tutorato ? ['Tutorato · lettere di incarico', 'Bandi e lettere dei vincitori: conferma con SPID/CIE, firme PAdES del docente e del direttore, protocollo', 'fa-user-graduate', 'tutorato.php'] : null,
    ])) : [],
    'portale'      => array_values(array_filter([
        $is_full_admin ? ['Anagrafi', 'Docenti, personale TA, insegnamenti, corsi di studio e strutture dal portale di Ateneo', 'fa-address-book', 'anagrafe_docenti.php'] : null,
        $puo_fsl_scuole ? ['Anagrafe scuole', 'Scuole del Ministero, abbinamento delle scuole scritte a mano, scuole collegate', 'fa-building-columns', 'scuole.php'] : null,
        $is_full_admin ? ['Aree di tutti i moduli', 'Crea, nascondi o elimina le aree e scegli il loro tipo', 'fa-layer-group', 'aree.php'] : null,
        $is_full_admin ? ['Testata e home', 'Logo, testata, carosello e widget della home', 'fa-image', 'testata.php'] : null,
        $is_full_admin ? ['Menu del sito', 'Voci del menu di navigazione del sito pubblico', 'fa-link', 'menu.php'] : null,
        $is_full_admin ? ['Utenti e abilitazioni', 'Amministratori, abilitati per area, per modulo e per la FSL', 'fa-users-cog', 'utenti.php'] : null,
        $is_full_admin ? ['Uffici di Ateneo', 'Uffici che ricevono le pratiche (per esempio i pagamenti del tutorato) e persone dell’anagrafe che ne fanno parte', 'fa-building-columns', 'uffici_ateneo.php'] : null,
        $is_full_admin ? ['Sistema e registri', 'Email, backup, controllo notturno, registro operazioni e accessi', 'fa-gear', 'sistema.php'] : null,
    ])),
];

// Numeri di un'area (solo per chi la gestisce tutta)
$numeri_area = function (array $a) use ($is_full_admin, $u_id_curr): ?array {
    if (!$is_full_admin && !in_array((int)$u_id_curr, ids_gestori_da_campi($a['gestore_utente_id'] ?? 0, $a['gestori_utenti_ids'] ?? '', $a['permessi_gestori_json'] ?? ''), true)) return null;
    $id = (int)$a['id'];
    $x = \App\Core\App::get(\App\Portale\AreeRepository::class)->numeriArea($id, tipo_area($a) === 'calendario');
    if (tipo_area($a) === 'calendario') {
        return $x ? [[(int)$x['risorse'], 'risorse'], [(int)$x['prossime'], 'prenotazioni in arrivo'], [(int)$x['da_approvare'], 'da approvare', true]] : null;
    }
    if (!$x) return null;
    $out = [[(int)$x['eventi'], 'eventi']];
    if ((int)$x['progetti'] > 0) $out[] = [(int)$x['progetti'], 'progetti'];
    $out[] = [(int)$x['iscritti'], 'iscritti'];
    return $out;
};

// ── Cose da fare: ogni riga è [modulo, numero, titolo, dettaglio, link, avviso] e compare solo se l'utente può occuparsene ──
$cose = [];
$col_mod = fn(string $k) => MODULI_PORTALE[$k]['colore'] ?? '#334155';
try {
    $cdf = \App\Core\App::per($conn)->get(\App\Portale\CoseDaFare::class);
    if ($puo_didattica_tutto) {
        $sm = $cdf->praticheDaSmistare();
        if ($sm['n'] > 0) $cose[] = ['didattica', $sm['n'], $sm['n'] === 1 ? 'Pratica da assegnare' : 'Pratiche da assegnare',
            'Didattica · ' . ($sm['giorni'] >= 3 ? 'la più vecchia aspetta da ' . $sm['giorni'] . ' giorni' : 'arrivate da poco'), 'didattica.php?tab=pratiche&carico=smistare', $sm['giorni'] >= 7];
    }
    if ($puo_didattica) {
        $vf = $cdf->verbaliInFirma($puo_didattica_tutto ? null : $consigli_referente);
        if ($vf > 0) $cose[] = ['didattica', $vf, $vf === 1 ? 'Verbale in attesa di firma' : 'Verbali in attesa di firma', 'Didattica · sedute dei consigli', 'didattica.php?tab=sedute', false];
    }
    if ($puo_tutorato) {
        $n_tv = $cdf->tutoratoDaValidare();
        if ($n_tv > 0) $cose[] = ['didattica', $n_tv, $n_tv === 1 ? 'Pratica di tutorato da validare' : 'Pratiche di tutorato da validare', 'Didattica · fine attività firmata dal docente: da passare all\'ufficio pagamenti', 'tutorato.php?vista=validare', false];
    }
    if ($puo_fsl_convenzioni) {
        $n_cf = $cdf->convenzioniDaPrendereInCarico();
        if ($n_cf > 0) $cose[] = ['fsl', $n_cf, $n_cf === 1 ? 'Convenzione della scuola da seguire' : 'Convenzioni delle scuole da seguire', 'Formazione Scuola Lavoro · caricate dalle scuole: da prendere in carico, far firmare al Direttore e protocollare', 'fsl.php?tab=convenzioni', true];
        $n_st = count(array_filter(\App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioniScuole::class)->scuole(), fn($g) => $g['da_stipulare']));
        if ($n_st > 0) $cose[] = ['fsl', $n_st, $n_st === 1 ? 'Convenzione da stipulare' : 'Convenzioni da stipulare', 'Formazione Scuola Lavoro · scuole con prenotazioni ma senza convenzione valida', 'fsl.php?tab=stipulare', true];
    }
    if ($puo_fsl) {
        $pf = \App\Core\App::per($conn)->get(\App\Fsl\ServizioPannelloFsl::class);
        $anno_f = $pf->annoCorrente();
        $n_el = count(\App\Fsl\PercorsoFsl::contatori($pf->riepilogo("$anno_f-09-01", ($anno_f + 1) . '-08-31')['righe'], date('Y-m-d'))['elenchi_mancanti']);
        if ($n_el > 0) $cose[] = ['fsl', $n_el, $n_el === 1 ? 'Elenco studenti mancante' : 'Elenchi studenti mancanti', 'Formazione Scuola Lavoro · attività finite: senza elenco non partono gli attestati', 'fsl.php?tab=riepilogo#elenchi', false];
    }
    // Messaggi e prenotazioni da approvare: nelle aree che l'utente gestisce per intero
    $aree_mie = array_values(array_filter($pagine_disponibili, fn($a) => $is_full_admin || in_array((int)$u_id_curr, ids_gestori_da_campi($a['gestore_utente_id'] ?? 0, $a['gestori_utenti_ids'] ?? '', $a['permessi_gestori_json'] ?? ''), true)));
    $ids_mie = array_map(fn($a) => (int)$a['id'], $aree_mie);
    $ids_cal = array_map(fn($a) => (int)$a['id'], array_filter($aree_mie, fn($a) => tipo_area($a) === 'calendario'));
    $msg_aree = $cdf->messaggiNonLetti(array_values(array_diff($ids_mie, $ids_cal)));
    $app_aree = $cdf->prenotazioniRisorseDaApprovare($ids_cal);
    foreach ($aree_mie as $a) {
        $id_a = (int)$a['id']; $mod_a = modulo_di_area($a);
        if (!empty($msg_aree[$id_a])) $cose[] = [$mod_a, $msg_aree[$id_a], $msg_aree[$id_a] === 1 ? 'Messaggio da leggere' : 'Messaggi da leggere', $a['titolo'] . ' · scritti da chi si è iscritto', 'messaggi.php?p_id=' . $id_a, false];
        if (!empty($app_aree[$id_a])) $cose[] = [$mod_a, $app_aree[$id_a], $app_aree[$id_a] === 1 ? 'Prenotazione da approvare' : 'Prenotazioni da approvare', $a['titolo'] . ' · aule, laboratori e sportelli', 'prenotazioni_risorse.php?p_id=' . $id_a, false];
    }
} catch (\Throwable $e) {
    error_log('Scrivania, cose da fare: ' . $e->getMessage());
}
$cose_per_modulo = [];
foreach ($cose as $c) $cose_per_modulo[$c[0]] = ($cose_per_modulo[$c[0]] ?? 0) + $c[1];
$mesi_it = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
$giorni_it = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
$ora_h = (int)date('G');
$saluto = $ora_h < 13 ? 'Buongiorno' : ($ora_h < 18 ? 'Buon pomeriggio' : 'Buonasera');
$nome_u = trim((string)($utente_admin['nome'] ?? ''));
?>
<style>
.ini-card { border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; transition: transform .12s, box-shadow .12s, border-color .12s; color: inherit; text-decoration: none; display: flex; flex-direction: column; height: 100%; }
.ini-card:hover, .ini-card:focus-visible { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(15,23,42,.10); border-color: #94a3b8; color: inherit; }
.ini-ico { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
.ini-aree { font-size: .8rem; color: #475569; }
.ini-aree span { display: inline-block; background: #f1f5f9; border-radius: 999px; padding: 2px 9px; margin: 2px 4px 2px 0; }
.ini-area-barra { height: 6px; border-radius: 14px 14px 0 0; }
.ini-num { font-size: .8rem; color: #475569; }
.ini-num strong { color: #0f172a; font-size: 1rem; }
.ini-num .ini-avviso strong { color: #b45309; }
[data-bs-theme="dark"] .ini-card { background: #2b2f33; border-color: #3a3f46; }
[data-bs-theme="dark"] .ini-aree span { background: #1e2227; color: #cbd5e1; }
[data-bs-theme="dark"] .ini-num strong { color: #f1f5f9; }
</style>

<?php if (!$moduli_utente): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">Non risulti assegnato a nessuna area o modulo.</div></div>
<?php elseif ($sez_sel === null): ?>
    <?php echo pn_testata($saluto . ($nome_u !== '' ? ', ' . $nome_u : ''), 'fa-house', 'Oggi è ' . $giorni_it[(int)date('w')] . ' ' . date('j') . ' ' . $mesi_it[(int)date('n') - 1] . '. Ecco cosa ti aspetta; sotto trovi i tuoi moduli.'); ?>

    <h2 class="h6 fw-bold text-secondary text-uppercase mb-2" style="letter-spacing:.05em;font-size:.75rem;">Cose da fare</h2>
    <div class="pn-cose mb-4">
        <?php
        // Le righe si compongono prima: se manca un file (caricamento a metà) compare un avviso, non una pagina bianca
        try {
            $html_cose = '';
            foreach ($cose as [$k_c, $n_c, $t_c, $d_c, $u_c, $av_c]) {
                $html_cose .= pn_cosa($n_c, $t_c, $d_c, $u_c . (str_contains($u_c, '?') ? '&' : '?') . (str_contains($u_c, 'p_id=') ? '' : 'p_id=' . (int)$filtro_p), $col_mod($k_c), $av_c);
            }
        } catch (\Throwable $e) {
            error_log('Scrivania, righe: ' . $e->getMessage());
            $html_cose = '<div class="p-3 text-danger"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Non riesco a mostrare le cose da fare: controlla che tutti i file del pannello siano stati caricati (inc/pannello.php e src/Portale/Vista/Pannello.php).</div>';
        }
        echo $html_cose !== '' ? $html_cose : '<div class="pn-tutto-ok"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Tutto in ordine: al momento non c\'è niente che aspetti te.</div>'; ?>
    </div>

    <h2 class="h6 fw-bold text-secondary text-uppercase mb-2" style="letter-spacing:.05em;font-size:.75rem;">I miei moduli</h2>
    <div class="row g-3">
        <?php foreach ($moduli_utente as $k): $s = MODULI_PORTALE[$k]; $col = $s['colore']; $aree_s = $aree_per_modulo[$k] ?? []; ?>
        <div class="col-md-6 col-xl-4">
            <a class="ini-card p-4" href="inizio.php?sezione=<?php echo urlencode($k); ?>&amp;p_id=<?php echo (int)$filtro_p; ?>">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="ini-ico" style="background: <?php echo $col; ?>1a; color: <?php echo $col; ?>;"><i class="fa <?php echo $s['icona']; ?>" aria-hidden="true"></i></span>
                    <div class="flex-grow-1">
                        <h2 class="h5 fw-bold mb-0"><?php echo htmlspecialchars($s['nome']); ?></h2>
                        <?php if ($aree_s): ?><div class="small text-secondary"><?php echo count($aree_s); ?> <?php echo count($aree_s) === 1 ? 'area' : 'aree'; ?></div><?php endif; ?>
                    </div>
                    <?php if (!empty($cose_per_modulo[$k])): ?><span class="pn-stato pn-tono-attesa align-self-start"><i class="fa fa-hourglass-half" aria-hidden="true"></i><?php echo (int)$cose_per_modulo[$k]; ?> da fare</span><?php endif; ?>
                </div>
                <p class="small text-secondary mb-3"><?php echo htmlspecialchars($s['descr']); ?></p>
                <div class="ini-aree mt-auto">
                    <?php foreach ($aree_s as $a): ?><span><?php echo htmlspecialchars($a['titolo']); ?></span><?php endforeach; ?>
                    <?php foreach ($funzioni_modulo[$k] ?? [] as $f): ?><span><i class="fa <?php echo $f[2]; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($f[0]); ?></span><?php endforeach; ?>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: $s = MODULI_PORTALE[$sez_sel]; $aree_mod = $aree_per_modulo[$sez_sel] ?? []; $funz = $funzioni_modulo[$sez_sel] ?? []; ?>
    <?php echo pn_testata($s['nome'], $s['icona'], $s['descr'] . '.' . ($aree_mod ? " Scegli l'area: entri nella sua dashboard." : ''),
        '', count($moduli_utente) > 1 ? '<a href="inizio.php?p_id=' . (int)$filtro_p . '" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>La mia scrivania</a>' : ''); ?>

    <?php if ($aree_mod): ?>
    <?php if ($funz): ?><h2 class="h6 fw-bold text-secondary text-uppercase mb-2" style="letter-spacing:.05em;font-size:.75rem;">Aree</h2><?php endif; ?>
    <div class="row g-3 mb-4">
        <?php foreach ($aree_mod as $a): $col_a = colore_valido($a['colore_primario'] ?? '', '#0056B3'); $num = $numeri_area($a); $t = tipo_area($a); ?>
        <div class="col-md-6 col-xl-4">
            <a class="ini-card" href="<?php echo $t === 'calendario' ? 'prenotazioni_risorse.php' : 'dashboard.php'; ?>?p_id=<?php echo (int)$a['id']; ?>">
                <div class="ini-area-barra" style="background: <?php echo $col_a; ?>;"></div>
                <div class="p-4 d-flex flex-column flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <h2 class="h5 fw-bold mb-0" style="color: <?php echo $col_a; ?>;"><?php echo htmlspecialchars($a['titolo']); ?></h2>
                        <?php if ((int)($a['visibile'] ?? 1) === 0): ?><span class="badge bg-warning text-dark" style="font-size:.65rem;"><i class="fa fa-eye-slash me-1" aria-hidden="true"></i>Nascosta</span><?php endif; ?>
                    </div>
                    <div class="small text-secondary mb-3"><?php echo $t !== '' ? htmlspecialchars(TIPI_AREA[$t]['nome']) : 'Eventi generici (tipo da assegnare)'; ?><?php if ((int)$a['id'] === (int)$filtro_p): ?> · <span class="fw-bold">ultima usata</span><?php endif; ?></div>
                    <?php if ($num): ?>
                        <div class="ini-num d-flex flex-wrap gap-3 mt-auto">
                            <?php foreach ($num as $n): ?><div class="<?php echo !empty($n[2]) && $n[0] > 0 ? 'ini-avviso' : ''; ?>"><strong><?php echo (int)$n[0]; ?></strong> <?php echo htmlspecialchars($n[1]); ?></div><?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="ini-num mt-auto">Le attività che gestisci in quest'area</div>
                    <?php endif; ?>
                    <div class="mt-3 small fw-bold" style="color: <?php echo $col_a; ?>;">Entra <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($funz): ?>
    <?php if ($aree_mod): ?><h2 class="h6 fw-bold text-secondary text-uppercase mb-2" style="letter-spacing:.05em;font-size:.75rem;"><?php echo $sez_sel === 'fsl' ? 'Per tutte le aree FSL' : ($sez_sel === 'orientamento' ? 'Per tutte le aree' : 'Funzioni'); ?></h2><?php endif; ?>
    <div class="row g-3">
        <?php foreach ($funz as $f): ?>
        <div class="col-md-6 col-xl-4">
            <a class="ini-card p-4" href="<?php echo htmlspecialchars($f[3] . (str_contains($f[3], "?") ? "&" : "?")); ?>p_id=<?php echo (int)$filtro_p; ?>">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <span class="ini-ico" style="background: <?php echo $s['colore']; ?>1a; color: <?php echo $s['colore']; ?>;"><i class="fa <?php echo $f[2]; ?>" aria-hidden="true"></i></span>
                    <h2 class="h5 fw-bold mb-0"><?php echo htmlspecialchars($f[0]); ?></h2>
                </div>
                <p class="small text-secondary mb-0"><?php echo htmlspecialchars($f[1]); ?></p>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php elseif (!$aree_mod): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-muted">Nessuna area in questo modulo<?php echo $is_full_admin ? ': creala da <a href="nuova_area.php?p_id=' . (int)$filtro_p . '">Nuova area</a> scegliendo un tipo di questo modulo' : ''; ?>.</div></div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($is_full_admin && $pagine_disponibili && in_array($sez_sel, [null, 'orientamento', 'fsl', 'calendari'], true)): ?>
    <p class="small text-secondary mt-4 mb-0"><i class="fa fa-layer-group me-1" aria-hidden="true"></i>Per creare, nascondere o assegnare il tipo alle aree vai in <a href="aree.php?p_id=<?php echo (int)$filtro_p; ?>">Aree</a>.</p>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
