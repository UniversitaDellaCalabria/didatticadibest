<?php
// anagrafe_personale.php - Anagrafi di Ateneo (solo amministratori), dalle API pubbliche del portale Unical:
// elenchi Docenti / Personale tecnico amministrativo / Altro personale, insegnamenti dei corsi del Dipartimento
// (anagrafe_insegnamenti.php apre direttamente quella vista), corsi di studio per il campo dei moduli
// e strutture da sincronizzare (DiBEST di partenza, se ne possono aggiungere altre, es. il dipartimento di un collega).
// anagrafe_docenti.php e anagrafe_pta.php aprono direttamente il loro elenco.
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$viste = GRUPPI_PERSONALE + ['insegnamenti' => 'Insegnamenti', 'corsi' => 'Corsi di studio', 'strutture' => 'Strutture e aggiornamento'];
$vista = (string)($_GET['vista'] ?? ($vista_anagrafe ?? 'docenti'));
if (!isset($viste[$vista])) $vista = 'docenti';
$url_pagina = 'anagrafe_personale.php?p_id=' . (int)$filtro_p;

// Strutture di Ateneo (API structures) per la tendina, conservate una settimana
function strutture_ateneo(): array {
    $f = __DIR__ . '/../cache/strutture_ateneo.json';
    if (is_file($f) && filemtime($f) > time() - 7 * 86400 && ($j = json_decode((string)file_get_contents($f), true))) return $j;
    $el = api_unical_tutte('structures/');
    if ($el === null) return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $out = [];
    foreach ($el as $s) {
        $cod = trim((string)($s['StructureCod'] ?? ''));
        if ($cod === '' || !preg_match('/^[A-Za-z0-9.]{2,20}$/', $cod)) continue;
        $out[] = ['codice' => $cod, 'nome' => trim(preg_replace('/\s+/', ' ', (string)($s['StructureName'] ?? ''))), 'tipo' => trim((string)($s['StructureTypeName'] ?? ''))];
    }
    usort($out, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    @file_put_contents($f, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

$r_strutture = \App\Core\App::get(\App\Anagrafi\StruttureRepository::class);
$r_persone = \App\Core\App::get(\App\Anagrafi\PersonaRepository::class);
$r_corsi = \App\Core\App::get(\App\Anagrafi\CorsoRepository::class);
$r_collegamenti = \App\Core\App::get(\App\Anagrafi\CollegamentiRepository::class);
$r_insegnamenti = \App\Core\App::get(\App\Anagrafi\InsegnamentoRepository::class);

// ==============================================================================
// AZIONI
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['sincronizza'])) {
        $esiti = sincronizza_anagrafe($conn);
        $ok = array_filter($esiti, fn($e) => $e['ok']);
        $testo = implode(' · ', array_map(fn($c, $e) => "$c: {$e['esito']}", array_keys($esiti), $esiti));
        registra_log_audit($conn, "Aggiornamento anagrafe di Ateneo", ["Esito" => $testo]);
        flash_set(($ok ? "Anagrafe aggiornata. " : "Aggiornamento non riuscito. ") . $testo, count($ok) === count($esiti) ? 'success' : 'warning');
    } elseif (isset($_POST['aggiungi_struttura'])) {
        $cod = trim((string)($_POST['codice_manuale'] ?? '')) ?: trim((string)($_POST['codice'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9.]{2,20}$/', $cod)) {
            flash_set("Codice di struttura non valido.", 'danger');
        } else {
            $nome = '';
            foreach (strutture_ateneo() as $s) if ($s['codice'] === $cod) { $nome = $s['nome']; break; }
            $r_strutture->aggiungi($cod, $nome);
            $es = sincronizza_anagrafe($conn, $cod)[$cod] ?? ['ok' => false, 'esito' => 'non eseguito'];
            registra_log_audit($conn, "Struttura aggiunta all'anagrafe", ["Codice" => $cod, "Nome" => $nome, "Esito" => $es['esito']]);
            flash_set("Struttura " . ($nome ?: $cod) . " aggiunta: " . $es['esito'] . ".", $es['ok'] ? 'success' : 'warning');
        }
        admin_redirect("$url_pagina&vista=strutture");
    } elseif (isset($_POST['rimuovi_struttura'])) {
        $cod = (string)$_POST['rimuovi_struttura'];
        $r_strutture->elimina($cod);
        $n_p = $r_persone->eliminaPerOrigine($cod);
        $r_corsi->eliminaDelDipartimento($cod);
        scollega_utenti_senza_persona($conn);
        registra_log_audit($conn, "Struttura tolta dall'anagrafe", ["Codice" => $cod, "Persone tolte" => $n_p]);
        flash_set("Struttura tolta dall'anagrafe ($n_p persone). Le persone già scelte come referenti restano nelle schede, senza pagina nel portale.");
        admin_redirect("$url_pagina&vista=strutture");
    } elseif (isset($_POST['corso_visibile'])) {
        $cc = (string)$_POST['corso_visibile']; $vis = (int)($_POST['visibile'] ?? 0) ? 1 : 0;
        $r_corsi->impostaVisibile($cc, $vis === 1);
        admin_redirect("$url_pagina&vista=corsi&r=" . time() . "#c" . rawurlencode($cc));
    }
    admin_redirect("$url_pagina&vista=$vista");
}

// ==============================================================================
// DATI
// ==============================================================================
$conteggi = array_fill_keys(array_keys(GRUPPI_PERSONALE), 0);
foreach ($r_persone->contaPerGruppo() as $gr => $n_gr) $conteggi[$gr] = $n_gr;
$n_corsi = $r_corsi->contaVisibili();
$strutture = $r_strutture->tutte();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
.ana-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.ana-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:#1e293b; margin-bottom:1rem; }
.ana-tab .nav-link { font-weight:700; font-size:.85rem; color:#334155; background:#f1f5f9; }
.ana-tab .badge { font-size:.68rem; }
.ana-tab .nav-link.active { background:#1e293b; color:#fff; }
</style>

<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-address-book me-2" style="color:#0f766e;" aria-hidden="true"></i>Anagrafi di Ateneo</h4>
    <form method="POST" class="m-0">
        <?php csrf_field(); ?>
        <button type="submit" name="sincronizza" value="1" class="btn btn-sm btn-outline-dark fw-bold" data-attesa="Aggiornamento in corso…"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Aggiorna ora dal portale</button>
    </form>
</div>
<p class="text-secondary small">Docenti, personale e insegnamenti, dal portale dell'Università della Calabria (dati pubblici, aggiornati ogni settimana), comuni a tutto il portale. Gli insegnamenti sono quelli dei corsi del proprio dipartimento (la prima struttura). Servono per scegliere referenti e gestori senza riscrivere nomi ed email, e per riconoscere chi accede: al login con la stessa email la persona entra nel gruppo <strong>Docenti</strong>, <strong>Personale tecnico amministrativo</strong> o <strong>Altro personale di Ateneo</strong>, utilizzabile per riservare gli eventi.</p>

<ul class="nav nav-pills ana-tab gap-1 mb-3 flex-wrap">
    <?php foreach ($viste as $k => $et): ?>
        <li class="nav-item"><a class="nav-link <?php echo $vista === $k ? 'active' : ''; ?>" href="<?php echo $h("$url_pagina&vista=$k"); ?>" <?php echo $vista === $k ? 'aria-current="page"' : ''; ?>>
            <?php echo $h($et); ?>
            <?php if (isset($conteggi[$k])): ?><span class="badge bg-light text-dark ms-1"><?php echo $conteggi[$k]; ?></span><?php elseif ($k === 'corsi'): ?><span class="badge bg-light text-dark ms-1"><?php echo $n_corsi; ?></span><?php elseif ($k === 'insegnamenti'): ?><span class="badge bg-light text-dark ms-1"><?php echo $r_insegnamenti->contaPresenti(anno_accademico_corrente()); ?></span><?php endif; ?>
        </a></li>
    <?php endforeach; ?>
</ul>

<?php if (isset(GRUPPI_PERSONALE[$vista])):
    // ---------------------------------------------------------------- ELENCO PERSONE
    $f_q = trim((string)($_GET['q'] ?? '')); $f_ruolo = (string)($_GET['ruolo'] ?? ''); $f_str = (string)($_GET['struttura'] ?? ''); $f_usciti = !empty($_GET['usciti']);
    $parole_q = array_slice(array_values(array_filter(explode(' ', $f_q), fn($x) => mb_strlen($x) >= 2)), 0, 5);
    $persone = $r_persone->elenco($vista, $f_usciti, $f_ruolo, $f_str, $parole_q);
    if ($persone === null) { $persone = []; echo '<div class="alert alert-danger small">Errore nella lettura dell’anagrafe: ' . htmlspecialchars($r_persone->ultimoErrore()) . '</div>'; }
    // Chi ha già fatto accesso (utenti collegati): letto a parte, senza confrontare le due tabelle in SQL
    $accessi = [];
    foreach ($r_collegamenti->utentiCollegati() as $x) $accessi[$x['persona_id']] = $x;
    foreach ($persone as &$p_acc) { $p_acc['utente_id'] = $accessi[$p_acc['id']]['id'] ?? null; $p_acc['ultimo_accesso'] = $accessi[$p_acc['id']]['ultimo_accesso'] ?? null; }
    unset($p_acc);
    // Filtri: ruoli e strutture presenti nel gruppo
    $opz_ruoli = $r_persone->opzioni('ruolo', $vista);
    $opz_str = $r_persone->opzioni('struttura', $vista);
    $n_usciti = $r_persone->contaUsciti($vista);
    // Attività in cui ciascuno è referente
    $ref_di = [];
    foreach ($r_collegamenti->referentiInCorso() as $x) foreach (json_decode((string)$x['referenti_json'], true) ?: [] as $rf) if (!empty($rf['persona_id'])) $ref_di[$rf['persona_id']][] = $x['titolo'];
?>
<section class="ana-sez">
    <?php if (!$strutture || !array_filter(array_column($strutture, 'ultima_sync'))): ?>
        <div class="alert alert-warning small"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>L'anagrafe non è ancora stata caricata: premi <strong>Aggiorna ora dal portale</strong>.</div>
    <?php endif; ?>
    <form method="GET" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="vista" value="<?php echo $h($vista); ?>">
        <div class="col-md-4"><label class="form-label small fw-bold mb-1" for="fQ">Nome, email o settore</label><input type="search" name="q" id="fQ" class="form-control form-control-sm" value="<?php echo $h($f_q); ?>"></div>
        <div class="col-md-3"><label class="form-label small fw-bold mb-1" for="fR">Ruolo</label>
            <select name="ruolo" id="fR" class="form-select form-select-sm"><option value="">Tutti</option><?php foreach ($opz_ruoli as $o): ?><option value="<?php echo $h($o['ruolo_cod']); ?>" <?php echo $o['ruolo_cod'] === $f_ruolo ? 'selected' : ''; ?>><?php echo $h($o['ruolo'] . ' (' . $o['n'] . ')'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small fw-bold mb-1" for="fS">Struttura</label>
            <select name="struttura" id="fS" class="form-select form-select-sm"><option value="">Tutte</option><?php foreach ($opz_str as $o): ?><option value="<?php echo $h($o['struttura_cod']); ?>" <?php echo $o['struttura_cod'] === $f_str ? 'selected' : ''; ?>><?php echo $h($o['struttura'] . ' (' . $o['n'] . ')'); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2 d-flex gap-1"><button class="btn btn-sm btn-primary fw-bold flex-grow-1"><i class="fa fa-filter me-1" aria-hidden="true"></i>Filtra</button><a class="btn btn-sm btn-outline-secondary" href="<?php echo $h("$url_pagina&vista=$vista"); ?>" title="Togli i filtri" aria-label="Togli i filtri"><i class="fa fa-xmark" aria-hidden="true"></i></a></div>
        <?php if ($n_usciti || $f_usciti): ?>
            <div class="col-12"><div class="form-check form-switch small"><input class="form-check-input" type="checkbox" name="usciti" value="1" id="fU" <?php echo $f_usciti ? 'checked' : ''; ?> onchange="this.form.submit()"><label class="form-check-label" for="fU">Mostra chi non compare più nel portale di Ateneo (<?php echo $n_usciti; ?>): cessati o trasferiti, cancellati dopo 12 mesi</label></div></div>
        <?php endif; ?>
    </form>
    <p class="small text-secondary mb-2"><?php echo count($persone); ?> <?php echo count($persone) === 1 ? 'persona' : 'persone'; ?><?php echo count($persone) === 600 ? ' (prime 600: usa i filtri)' : ''; ?></p>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light small"><tr><th colspan="2">Persona</th><th>Ruolo e struttura</th><th>Contatti</th><th>Nel portale eventi</th></tr></thead>
            <tbody>
            <?php foreach ($persone as $p): $det = json_decode((string)($p['dettaglio_json'] ?? ''), true) ?: []; ?>
                <tr>
                    <td style="width:44px;"><?php echo html_avatar_persona($det['foto'] ?? '', '', 36, '../'); ?></td>
                    <td>
                        <div class="fw-bold"><?php echo $h($p['cognome'] . ' ' . $p['nome']); ?></div>
                        <a class="small" href="<?php echo $h(url_portale_persona($p)); ?>" target="_blank" rel="noopener">Pagina di Ateneo <i class="fa fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="visually-hidden"> (nuova scheda)</span></a>
                    </td>
                    <td class="small">
                        <div><?php echo $h($p['ruolo']); ?></div>
                        <?php if ($p['ssd'] !== ''): ?><div class="text-secondary"><?php echo $h($p['ssd_cod'] . ' – ' . $p['ssd']); ?></div><?php endif; ?>
                        <div class="text-secondary"><?php echo $h($p['struttura']); ?></div>
                    </td>
                    <td class="small">
                        <?php if ($p['email'] !== ''): ?><div><a href="mailto:<?php echo $h($p['email']); ?>"><?php echo $h($p['email']); ?></a></div><?php else: ?><div class="text-muted">email non pubblicata</div><?php endif; ?>
                        <?php if ($p['telefono'] !== ''): ?><div class="text-secondary"><?php echo $h($p['telefono']); ?></div><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($p['utente_id']): ?><div><i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i>Ha fatto accesso<?php echo $p['ultimo_accesso'] ? ' · ' . date('d/m/Y', strtotime($p['ultimo_accesso'])) : ''; ?></div><?php endif; ?>
                        <?php if (!empty($ref_di[$p['id']])): ?>
                            <div title="<?php echo $h(implode(', ', $ref_di[$p['id']])); ?>"><i class="fa fa-address-card me-1" style="color:#0f766e;" aria-hidden="true"></i>Referente in <?php echo count($ref_di[$p['id']]); ?> <?php echo count($ref_di[$p['id']]) === 1 ? 'attività' : 'attività'; ?> · <a href="../persona.php?id=<?php echo $h(rawurlencode($p['id'])); ?>" target="_blank" rel="noopener">pagina pubblica</a></div>
                        <?php endif; ?>
                        <?php if (!$p['attivo']): ?><span class="badge bg-secondary">non più nel portale dal <?php echo $p['uscita_il'] ? date('d/m/Y', strtotime($p['uscita_il'])) : '—'; ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$persone): ?><tr><td colspan="5" class="text-muted small py-3">Nessuna persona con questi filtri.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($vista === 'insegnamenti'):
    // ---------------------------------------------------------------- INSEGNAMENTI
    $aa_disp = $r_insegnamenti->anniPresenti();
    $f_aa = (int)($_GET['aa'] ?? 0); if (!in_array($f_aa, $aa_disp, true)) $f_aa = in_array(anno_accademico_corrente(), $aa_disp, true) ? anno_accademico_corrente() : (int)(end($aa_disp) ?: 0);
    $f_cds = (string)($_GET['cds'] ?? ''); $f_anno = (int)($_GET['anno'] ?? 0); $f_sem = (string)($_GET['sem'] ?? ''); $f_q = trim((string)($_GET['q'] ?? ''));
    $ins = $r_insegnamenti->elenco($f_aa, $f_cds, $f_anno, $f_sem, $f_q);
    $opz_cds = $r_insegnamenti->corsiDellAnno($f_aa);
    $opz_sem = $r_insegnamenti->semestri();
?>
<section class="ana-sez">
    <h2><i class="fa fa-book-open me-1" aria-hidden="true"></i>Insegnamenti <?php echo $f_aa ? $f_aa . '/' . ($f_aa + 1) : ''; ?></h2>
    <p class="small text-secondary">Insegnamenti tenuti nell'anno accademico, per tutti i corsi di studio del Dipartimento, con anno di corso, semestre, settore e docente titolare. Nelle aree <strong>Gruppi degli insegnamenti</strong> un'attività si crea partendo da qui: titolo, corso e docente arrivano da soli.</p>
    <?php if (!$aa_disp): ?>
        <p class="text-muted small mb-0">Nessun insegnamento: premi <strong>Aggiorna ora dal portale</strong>.</p>
    <?php else: ?>
    <form method="GET" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="vista" value="insegnamenti">
        <div class="col-6 col-md-2"><label class="form-label small fw-bold mb-1" for="fiAa">Anno accademico</label>
            <select name="aa" id="fiAa" class="form-select form-select-sm"><?php foreach ($aa_disp as $a): ?><option value="<?php echo $a; ?>" <?php echo $a === $f_aa ? 'selected' : ''; ?>><?php echo $a . '/' . ($a + 1); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small fw-bold mb-1" for="fiCds">Corso di studio</label>
            <select name="cds" id="fiCds" class="form-select form-select-sm"><option value="">Tutti</option><?php foreach ($opz_cds as $o): ?><option value="<?php echo $h($o['cds_cod']); ?>" <?php echo $o['cds_cod'] === $f_cds ? 'selected' : ''; ?>><?php echo $h($o['cds_nome'] . ' (' . $o['n'] . ')'); ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-1"><label class="form-label small fw-bold mb-1" for="fiAnno">Anno</label>
            <select name="anno" id="fiAnno" class="form-select form-select-sm"><option value="0">Tutti</option><?php for ($a = 1; $a <= 6; $a++): ?><option value="<?php echo $a; ?>" <?php echo $a === $f_anno ? 'selected' : ''; ?>><?php echo $a; ?>°</option><?php endfor; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-bold mb-1" for="fiSem">Semestre</label>
            <select name="sem" id="fiSem" class="form-select form-select-sm"><option value="">Tutti</option><?php foreach ($opz_sem as $o): ?><option value="<?php echo $h($o); ?>" <?php echo $o === $f_sem ? 'selected' : ''; ?>><?php echo $h($o); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small fw-bold mb-1" for="fiQ">Cerca</label><input type="search" name="q" id="fiQ" class="form-control form-control-sm" value="<?php echo $h($f_q); ?>" placeholder="Nome, docente, SSD"></div>
        <div class="col-md-1 d-flex gap-1"><button class="btn btn-sm btn-primary fw-bold flex-grow-1" title="Filtra" aria-label="Filtra"><i class="fa fa-filter" aria-hidden="true"></i></button><a class="btn btn-sm btn-outline-secondary" href="<?php echo $h("$url_pagina&vista=insegnamenti"); ?>" title="Togli i filtri" aria-label="Togli i filtri"><i class="fa fa-xmark" aria-hidden="true"></i></a></div>
    </form>
    <div class="d-flex justify-content-between align-items-center mb-2 small">
        <span class="text-secondary"><?php echo count($ins); ?> insegnamenti<?php echo count($ins) >= 600 ? ' (mostrati i primi 600: usa i filtri)' : ''; ?></span>
        <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="csvIns"><i class="fa fa-file-csv me-1" aria-hidden="true"></i>CSV</button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle small" id="tabIns">
            <thead class="table-light"><tr><th>Insegnamento</th><th>Corso di studio</th><th class="text-center">Anno</th><th>Semestre</th><th>SSD</th><th>Docente</th><th class="text-center">Attività</th></tr></thead>
            <tbody>
            <?php foreach ($ins as $i): ?>
                <tr>
                    <td><div class="fw-semibold"><?php echo $h($i['nome']); ?><?php if ($i['partizione'] !== ''): ?> <span class="badge bg-light text-dark border"><?php echo $h($i['partizione']); ?></span><?php endif; ?></div><div class="text-secondary font-monospace"><?php echo $h($i['codice']); ?></div></td>
                    <td><?php echo $h($i['cds_nome']); ?></td>
                    <td class="text-center"><?php echo $i['anno_corso'] ? (int)$i['anno_corso'] . '°' : '—'; ?></td>
                    <td><?php echo $h($i['semestre']); ?></td>
                    <td><?php echo $h($i['ssd_cod']); ?></td>
                    <td><?php echo $h($i['docente'] ?: '—'); ?></td>
                    <td class="text-center"><?php echo (int)$i['n_att'] ?: '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <script>
    document.getElementById('csvIns').addEventListener('click', function () {
        var righe = [];
        document.querySelectorAll('#tabIns tr').forEach(function (tr) { righe.push(Array.prototype.map.call(tr.children, function (c) { return c.textContent.replace(/\s+/g, ' ').trim(); })); });
        var csv = '\ufeff' + righe.map(function (r) { return r.map(function (v) { return '"' + v.replace(/"/g, '""') + '"'; }).join(';'); }).join('\r\n');
        var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        a.download = 'insegnamenti_<?php echo $f_aa; ?>.csv'; document.body.appendChild(a); a.click(); a.remove();
    });
    </script>
    <?php endif; ?>
</section>

<?php elseif ($vista === 'corsi'):
    // ---------------------------------------------------------------- CORSI DI STUDIO
    $corsi = $r_corsi->presentiConStruttura();
?>
<section class="ana-sez">
    <h2><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i>Corsi di studio</h2>
    <p class="small text-secondary">Corsi dei dipartimenti dell'anagrafe. Quelli <strong>proposti</strong> compaiono nel campo <strong>"Corso di studio"</strong> che puoi aggiungere ai moduli dal Form Builder. All'arrivo sono proposti i corsi più recenti del proprio dipartimento (la prima struttura); restano nascosti quelli di anni vecchi, i doppi (stesso nome, regolamento precedente) e i corsi delle strutture aggiunte dopo: puoi cambiare la scelta qui.</p>
    <?php if (!$corsi): ?>
        <p class="text-muted small mb-0">Nessun corso: aggiorna l'anagrafe dal portale.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light small"><tr><th>Corso</th><th>Tipo</th><th class="text-center">Anno</th><th>Codice</th><th class="text-center">Nei moduli</th></tr></thead>
            <tbody>
            <?php foreach ($corsi as $c): ?>
                <tr id="c<?php echo $h($c['codice']); ?>" class="<?php echo $c['visibile'] ? '' : 'text-muted'; ?>">
                    <td><div class="fw-semibold"><?php echo $h($c['nome']); ?></div><?php if ($c['classe'] !== '' && mb_strtolower($c['classe']) !== mb_strtolower($c['nome'])): ?><div class="small text-secondary"><?php echo $h($c['classe']); ?></div><?php endif; ?></td>
                    <td class="small"><?php echo $h($c['tipo_descrizione']); ?></td>
                    <td class="text-center small"><?php echo $c['anno'] ? $h($c['anno'] . '/' . substr((string)($c['anno'] + 1), 2)) : '—'; ?></td>
                    <td class="small font-monospace"><?php echo $h($c['codice']); ?></td>
                    <td class="text-center">
                        <form method="POST" class="m-0">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="corso_visibile" value="<?php echo $h($c['codice']); ?>">
                            <input type="hidden" name="visibile" value="<?php echo $c['visibile'] ? 0 : 1; ?>">
                            <div class="form-check form-switch d-inline-block m-0"><input class="form-check-input" type="checkbox" role="switch" id="cv<?php echo $h($c['codice']); ?>" <?php echo $c['visibile'] ? 'checked' : ''; ?> onchange="this.form.submit()" aria-label="Proponi <?php echo $h($c['nome']); ?> nei moduli"></div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php else:
    // ---------------------------------------------------------------- STRUTTURE
    $elenco_str = strutture_ateneo();
    $gia = array_column($strutture, 'codice');
?>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="ana-sez h-100">
            <h2><i class="fa fa-sitemap me-1" aria-hidden="true"></i>Strutture sincronizzate</h2>
            <p class="small text-secondary">Per ogni struttura arrivano il personale (anche dei suoi uffici e settori), i docenti del dipartimento con il settore disciplinare e i corsi di studio.</p>
            <table class="table table-sm align-middle">
                <thead class="table-light small"><tr><th>Struttura</th><th>Ultimo aggiornamento</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($strutture as $s): ?>
                    <tr>
                        <td><div class="fw-semibold"><?php echo $h($s['nome'] ?: $s['codice']); ?></div><div class="small text-secondary font-monospace"><?php echo $h($s['codice']); ?></div></td>
                        <td class="small"><?php echo $s['ultima_sync'] ? date('d/m/Y H:i', strtotime($s['ultima_sync'])) : 'mai'; ?><?php if ($s['esito'] !== ''): ?><div class="text-secondary"><?php echo $h($s['esito']); ?></div><?php endif; ?></td>
                        <td class="text-end">
                            <form method="POST" class="m-0">
                                <?php csrf_field(); ?>
                                <button type="submit" name="rimuovi_struttura" value="<?php echo $h($s['codice']); ?>" class="btn btn-sm btn-outline-danger" data-confirm="Togliere <?php echo $h($s['nome'] ?: $s['codice']); ?> dall'anagrafe? Le sue persone e i suoi corsi vengono cancellati dal portale eventi (non dal portale di Ateneo)." title="Togli" aria-label="Togli <?php echo $h($s['nome'] ?: $s['codice']); ?>"><i class="fa fa-trash" aria-hidden="true"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$strutture): ?><tr><td colspan="3" class="text-muted small">Nessuna struttura.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <p class="small text-secondary mb-0">Aggiornamento automatico una volta a settimana (cron). Chi non compare più resta segnato come "non più nel portale" e viene cancellato dopo 12 mesi; nella dashboard compare un avviso se era gestore o referente.</p>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="ana-sez h-100">
            <h2><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi una struttura</h2>
            <p class="small text-secondary">Ad esempio il dipartimento di un collega che collabora a un progetto.</p>
            <form method="POST">
                <?php csrf_field(); ?>
                <label for="selStr" class="form-label small fw-bold">Struttura di Ateneo</label>
                <select name="codice" id="selStr" class="form-select form-select-sm mb-2">
                    <option value="">-- Scegli --</option>
                    <?php if (!$elenco_str): ?><option value="" disabled>Elenco non disponibile: usa il codice qui sotto</option><?php endif; ?>
                    <?php foreach ($elenco_str as $s): if (in_array($s['codice'], $gia, true)) continue; ?>
                        <option value="<?php echo $h($s['codice']); ?>"><?php echo $h($s['nome'] . ($s['tipo'] !== '' ? ' · ' . $s['tipo'] : '') . ' (' . $s['codice'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="codStr" class="form-label small fw-bold">oppure codice della struttura</label>
                <input type="text" name="codice_manuale" id="codStr" class="form-control form-control-sm font-monospace mb-1" maxlength="20" pattern="[A-Za-z0-9.]{2,20}" placeholder="es. 002020">
                <div class="form-text mb-2">È il numero che compare nell'indirizzo delle pagine di Ateneo, es. <span class="font-monospace">…/addressbook/?structuretree=<strong>002020</strong></span>.</div>
                <button type="submit" name="aggiungi_struttura" value="1" class="btn btn-sm btn-primary fw-bold" data-attesa="Caricamento in corso…"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi e carica</button>
            </form>
        </section>
    </div>
</div>
<?php endif; ?>

<script>
// Pulsanti che avviano un aggiornamento dal portale: testo di attesa (può richiedere qualche secondo)
document.addEventListener('submit', function (e) {
    var b = e.submitter; if (!b || !b.dataset.attesa) return;
    setTimeout(function () { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + b.dataset.attesa; }, 0);
});
// Select con ricerca per l'elenco delle strutture
window.addEventListener('load', function () { if (window.jQuery && jQuery.fn.select2) jQuery('#selStr').select2({ width: '100%', theme: 'bootstrap-5', placeholder: 'Cerca la struttura…' }); });
</script>

<?php require_once 'admin_footer.php'; ?>
