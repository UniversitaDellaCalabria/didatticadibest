<?php
// anagrafe_personale.php - Anagrafe del personale di Ateneo (solo amministratori), dalle API pubbliche del portale Unical:
// elenchi Docenti / Personale tecnico amministrativo / Altro personale, corsi di studio per il campo dei moduli
// e strutture da sincronizzare (DiBEST di partenza, se ne possono aggiungere altre, es. il dipartimento di un collega).
// anagrafe_docenti.php e anagrafe_pta.php aprono direttamente il loro elenco.
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$viste = GRUPPI_PERSONALE + ['corsi' => 'Corsi di studio', 'strutture' => 'Strutture e aggiornamento'];
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
            $st = $conn->prepare("INSERT IGNORE INTO anagrafe_strutture (codice, nome) VALUES (?, ?)");
            $st->bind_param("ss", $cod, $nome); $st->execute();
            $es = sincronizza_anagrafe($conn, $cod)[$cod] ?? ['ok' => false, 'esito' => 'non eseguito'];
            registra_log_audit($conn, "Struttura aggiunta all'anagrafe", ["Codice" => $cod, "Nome" => $nome, "Esito" => $es['esito']]);
            flash_set("Struttura " . ($nome ?: $cod) . " aggiunta: " . $es['esito'] . ".", $es['ok'] ? 'success' : 'warning');
        }
        admin_redirect("$url_pagina&vista=strutture");
    } elseif (isset($_POST['rimuovi_struttura'])) {
        $cod = (string)$_POST['rimuovi_struttura'];
        $st = $conn->prepare("DELETE FROM anagrafe_strutture WHERE codice = ?"); $st->bind_param("s", $cod); $st->execute();
        $st = $conn->prepare("DELETE FROM personale_ateneo WHERE origine = ?"); $st->bind_param("s", $cod); $st->execute();
        $n_p = $st->affected_rows;
        $st = $conn->prepare("DELETE FROM corsi_studio WHERE dipartimento_cod = ?"); $st->bind_param("s", $cod); $st->execute();
        scollega_utenti_senza_persona($conn);
        registra_log_audit($conn, "Struttura tolta dall'anagrafe", ["Codice" => $cod, "Persone tolte" => $n_p]);
        flash_set("Struttura tolta dall'anagrafe ($n_p persone). Le persone già scelte come referenti restano nelle schede, senza pagina nel portale.");
        admin_redirect("$url_pagina&vista=strutture");
    } elseif (isset($_POST['corso_visibile'])) {
        $cc = (string)$_POST['corso_visibile']; $vis = (int)($_POST['visibile'] ?? 0) ? 1 : 0;
        $st = $conn->prepare("UPDATE corsi_studio SET visibile = ? WHERE codice = ?"); $st->bind_param("is", $vis, $cc); $st->execute();
        admin_redirect("$url_pagina&vista=corsi#c" . rawurlencode($cc));
    }
    admin_redirect("$url_pagina&vista=$vista");
}

// ==============================================================================
// DATI
// ==============================================================================
$conteggi = array_fill_keys(array_keys(GRUPPI_PERSONALE), 0);
$r = $conn->query("SELECT gruppo, COUNT(*) n FROM personale_ateneo WHERE attivo = 1 GROUP BY gruppo");
while ($r && $x = $r->fetch_assoc()) $conteggi[$x['gruppo']] = (int)$x['n'];
$n_corsi = (int)($conn->query("SELECT COUNT(*) n FROM corsi_studio WHERE presente = 1 AND visibile = 1")->fetch_assoc()['n'] ?? 0);
$strutture = [];
$r = $conn->query("SELECT * FROM anagrafe_strutture ORDER BY aggiunta_il");
while ($r && $x = $r->fetch_assoc()) $strutture[] = $x;
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
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-address-book me-2" style="color:#0f766e;" aria-hidden="true"></i>Anagrafe personale di Ateneo</h4>
    <form method="POST" class="m-0">
        <?php csrf_field(); ?>
        <button type="submit" name="sincronizza" value="1" class="btn btn-sm btn-outline-dark fw-bold" data-attesa="Aggiornamento in corso…"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Aggiorna ora dal portale</button>
    </form>
</div>
<p class="text-secondary small">Docenti e personale delle strutture scelte, dal portale dell'Università della Calabria (dati pubblici, aggiornati ogni settimana). Servono per scegliere referenti e gestori senza riscrivere nomi ed email, e per riconoscere chi accede: al login con la stessa email la persona entra nel gruppo <strong>Docenti</strong>, <strong>Personale tecnico amministrativo</strong> o <strong>Altro personale di Ateneo</strong>, utilizzabile per riservare gli eventi.</p>

<ul class="nav nav-pills ana-tab gap-1 mb-3 flex-wrap">
    <?php foreach ($viste as $k => $et): ?>
        <li class="nav-item"><a class="nav-link <?php echo $vista === $k ? 'active' : ''; ?>" href="<?php echo $h("$url_pagina&vista=$k"); ?>" <?php echo $vista === $k ? 'aria-current="page"' : ''; ?>>
            <?php echo $h($et); ?>
            <?php if (isset($conteggi[$k])): ?><span class="badge bg-light text-dark ms-1"><?php echo $conteggi[$k]; ?></span><?php elseif ($k === 'corsi'): ?><span class="badge bg-light text-dark ms-1"><?php echo $n_corsi; ?></span><?php endif; ?>
        </a></li>
    <?php endforeach; ?>
</ul>

<?php if (isset(GRUPPI_PERSONALE[$vista])):
    // ---------------------------------------------------------------- ELENCO PERSONE
    $f_q = trim((string)($_GET['q'] ?? '')); $f_ruolo = (string)($_GET['ruolo'] ?? ''); $f_str = (string)($_GET['struttura'] ?? ''); $f_usciti = !empty($_GET['usciti']);
    $where = "gruppo = ? AND attivo = " . ($f_usciti ? 0 : 1); $tipi = 's'; $par = [$vista];
    if ($f_ruolo !== '') { $where .= " AND ruolo_cod = ?"; $tipi .= 's'; $par[] = $f_ruolo; }
    if ($f_str !== '') { $where .= " AND struttura_cod = ?"; $tipi .= 's'; $par[] = $f_str; }
    foreach (array_slice(array_filter(explode(' ', $f_q), fn($x) => mb_strlen($x) >= 2), 0, 5) as $w) {
        $like = '%' . addcslashes($w, '%_\\') . '%';
        $where .= " AND (cognome LIKE ? OR nome LIKE ? OR email LIKE ? OR ssd LIKE ?)"; $tipi .= 'ssss'; array_push($par, $like, $like, $like, $like);
    }
    $persone = [];
    $st = $conn->prepare("SELECT * FROM personale_ateneo WHERE $where ORDER BY cognome, nome LIMIT 600");
    if ($st) { $st->bind_param($tipi, ...$par); $st->execute(); $persone = $st->get_result()->fetch_all(MYSQLI_ASSOC); }
    else echo '<div class="alert alert-danger small">Errore nella lettura dell’anagrafe: ' . htmlspecialchars($conn->error) . '</div>';
    // Chi ha già fatto accesso (utenti collegati): letto a parte, senza confrontare le due tabelle in SQL
    $accessi = [];
    $r = $conn->query("SELECT id, persona_id, ultimo_accesso FROM utenti WHERE persona_id IS NOT NULL");
    while ($r && $x = $r->fetch_assoc()) $accessi[$x['persona_id']] = $x;
    foreach ($persone as &$p_acc) { $p_acc['utente_id'] = $accessi[$p_acc['id']]['id'] ?? null; $p_acc['ultimo_accesso'] = $accessi[$p_acc['id']]['ultimo_accesso'] ?? null; }
    unset($p_acc);
    // Filtri: ruoli e strutture presenti nel gruppo
    $opz_ruoli = $conn->query("SELECT ruolo_cod, ruolo, COUNT(*) n FROM personale_ateneo WHERE gruppo = '" . $conn->real_escape_string($vista) . "' AND attivo = 1 GROUP BY ruolo_cod, ruolo ORDER BY ruolo")->fetch_all(MYSQLI_ASSOC);
    $opz_str = $conn->query("SELECT struttura_cod, struttura, COUNT(*) n FROM personale_ateneo WHERE gruppo = '" . $conn->real_escape_string($vista) . "' AND attivo = 1 GROUP BY struttura_cod, struttura ORDER BY struttura")->fetch_all(MYSQLI_ASSOC);
    $n_usciti = (int)($conn->query("SELECT COUNT(*) n FROM personale_ateneo WHERE gruppo = '" . $conn->real_escape_string($vista) . "' AND attivo = 0")->fetch_assoc()['n'] ?? 0);
    // Attività in cui ciascuno è referente
    $ref_di = [];
    $r = $conn->query("SELECT e.titolo, d.referenti_json FROM progetti_dettagli d JOIN eventi e ON e.id = d.evento_id WHERE e.archiviato = 0 AND d.referenti_json LIKE '%persona_id%'");
    while ($r && $x = $r->fetch_assoc()) foreach (json_decode((string)$x['referenti_json'], true) ?: [] as $rf) if (!empty($rf['persona_id'])) $ref_di[$rf['persona_id']][] = $x['titolo'];
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

<?php elseif ($vista === 'corsi'):
    // ---------------------------------------------------------------- CORSI DI STUDIO
    $corsi = $conn->query("SELECT c.*, s.nome AS dipartimento FROM corsi_studio c LEFT JOIN anagrafe_strutture s ON s.codice = c.dipartimento_cod WHERE c.presente = 1 ORDER BY c.tipo_descrizione, c.nome, c.anno DESC")->fetch_all(MYSQLI_ASSOC);
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
