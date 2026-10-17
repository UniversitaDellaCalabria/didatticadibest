<?php
// convenzioni_tesi.php - Modulo Didattica › Convenzioni di tesi: le convenzioni con enti e aziende per lo svolgimento della tesi.
//   Da seguire   iter guidato: modifiche agli articoli da valutare → protocollo in entrata (il Direttore riceve il link) → firma del Direttore → protocollo in uscita → stipulata
//   Archivio     tutte le convenzioni stipulate (anche quelle già in essere inserite a mano), ricerca, rinnovo, elenco pubblico delle aziende
// Logica in src/ConvenzioniTesi/; pagine dell'azienda: convenzione_tesi.php (link personale), richiedi_convenzione_tesi.php (pubblica), aziende_convenzionate_tesi.php (elenco).
require_once 'admin_header.php';

if (!$puo_conv_tesi) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$svc = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
$notif = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\NotificheConvenzioniTesi::class);
$S = \App\ConvenzioniTesi\Costanti::class;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$d = fn($x) => $x ? date('d/m/Y', strtotime((string)$x)) : '—';
$autore = (string)($_SESSION['utente_nome'] ?? $_SESSION['utente_email'] ?? '');
$tab = ($_GET['tab'] ?? '') === 'archivio' ? 'archivio' : 'iter';
$url = fn(string $t = 'iter', string $extra = '') => 'convenzioni_tesi.php?p_id=' . (int)$filtro_p . '&tab=' . $t . $extra;

// ── Download dei file (solo da qui: le cartelle sono bloccate al web) ──
if (isset($_GET['file'])) {
    $ff = $svc->fileDaScaricare((int)$_GET['file'], (string)($_GET['f'] ?? 'finale'));
    while (ob_get_level() > 0) ob_end_clean();
    if (!$ff) { http_response_code(404); exit('File non trovato.'); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $ff['nome'] . '"'); header('Content-Length: ' . filesize($ff['percorso'])); header('X-Content-Type-Options: nosniff');
    readfile($ff['percorso']); exit;
}

// ── Azioni ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $ok = fn(string $m, string $tipo = 'success') => flash_set($m, $tipo);
    $torna = $url('iter') . '&r=' . time();
    if (isset($_POST['invita'])) {
        [$err] = $svc->avvia((string)($_POST['email'] ?? ''), (string)($_POST['azienda'] ?? ''), $S::ORIGINE_INVITO, $autore);
        if (!$err) registra_log_audit($conn, "Convenzione di tesi: azienda invitata", ["Email" => (string)($_POST['email'] ?? ''), "Azienda" => (string)($_POST['azienda'] ?? '')]);
        $ok($err ?? "Invito mandato: l'azienda riceve il link per compilare la convenzione.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['salva_direttore_testo'])) {
        $errori_d = $svc->salvaDirettoreTesto((string)($_POST['titolo'] ?? ''), (string)($_POST['nome'] ?? ''), (string)($_POST['nato_a'] ?? ''), (string)($_POST['nato_il'] ?? ''));
        if (!$errori_d) registra_log_audit($conn, "Convenzione di tesi: dati del Direttore nel testo", ["Nome" => (string)($_POST['nome'] ?? '')]);
        $ok($errori_d ? implode(' ', $errori_d) : "Dati del Direttore salvati: da ora compaiono nel Word e nel PDF delle convenzioni.", $errori_d ? 'danger' : 'success');
    } elseif (isset($_POST['rimanda'])) {
        $err = $svc->rimandaLink($id);
        $ok($err ?? "Link rimandato all'azienda.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['rispondi_modifiche'])) {
        $c = $svc->perId($id);
        $err = $c ? $svc->rispondiModifiche($c, (array)($_POST['m'] ?? [])) : 'Convenzione non trovata.';
        if (!$err) registra_log_audit($conn, "Convenzione di tesi: risposta alle modifiche agli articoli", ["Convenzione" => $id]);
        $ok($err ?? "Risposta mandata: l'azienda la vede e riceve l'email.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['prendi'])) {
        $es = $svc->protocolloIngresso($id, (string)($_POST['prot_ingresso'] ?? ''), (string)($_POST['prot_ingresso_data'] ?? ''), $autore);
        if (!$es['errore']) registra_log_audit($conn, "Convenzione di tesi protocollata in entrata", ["Convenzione" => $id, "Protocollo in entrata" => (string)($_POST['prot_ingresso'] ?? '')]);
        $ok($es['errore'] ?? ("Protocollo in entrata registrato: l'azienda è stata avvisata. " . ($es['direttore'] === null ? "Il Direttore ha ricevuto l'email con il link per firmare." : $es['direttore'])), $es['errore'] ? 'danger' : ($es['direttore'] === null ? 'success' : 'warning'));
    } elseif (isset($_POST['rifiuta'])) {
        $err = $svc->rifiuta($id, (string)($_POST['motivo'] ?? ''));
        if (!$err) registra_log_audit($conn, "Convenzione di tesi rifiutata", ["Convenzione" => $id]);
        $ok($err ?? "Rifiutata: l'azienda ha ricevuto il motivo per email e può caricare un altro file.", $err ? 'danger' : 'warning');
    } elseif (isset($_POST['invia_direttore'])) {
        $err = $svc->inviaDirettore($id);
        if (!$err) registra_log_audit($conn, "Convenzione di tesi mandata al Direttore per la firma", ["Convenzione" => $id]);
        $ok($err ?? "Mandata al Direttore: ha ricevuto l'email con il link per firmare.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['completa'])) {
        $pdf = '';
        if (($_FILES['file_protocollato']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$_FILES['file_protocollato']['tmp_name'])) $pdf = (string)file_get_contents($_FILES['file_protocollato']['tmp_name']);
        $err = $svc->completa($id, $_POST, $pdf);
        if (!$err) registra_log_audit($conn, "Convenzione di tesi stipulata", ["Convenzione" => $id, "Protocollo" => (string)($_POST['protocollo'] ?? '')]);
        $ok($err ?? "Convenzione stipulata: è in archivio" . "; l'azienda ha ricevuto l'avviso e, se ha dato il consenso, compare nell'elenco pubblico.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['archivia'])) {
        $pdf = '';
        if (($_FILES['file_convenzione']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$_FILES['file_convenzione']['tmp_name'])) $pdf = (string)file_get_contents($_FILES['file_convenzione']['tmp_name']);
        [$errori, $nuovo] = $svc->archivia($_POST, $pdf, $autore, $id);
        if (!$errori) registra_log_audit($conn, $id ? "Convenzione di tesi dell'archivio modificata" : "Convenzione di tesi già in essere inserita in archivio", ["Convenzione" => $nuovo, "Azienda" => (string)($_POST['ragione_sociale'] ?? '')]);
        $ok($errori ? implode(' ', $errori) : ($id ? "Convenzione aggiornata." : "Convenzione inserita in archivio."), $errori ? 'danger' : 'success');
        $torna = $url('archivio') . ($errori && $id ? '&mod=' . $id : '') . '&r=' . time();
    } elseif (isset($_POST['elimina'])) {
        $svc->elimina($id);
        registra_log_audit($conn, "Convenzione di tesi eliminata", ["Convenzione" => $id]);
        $ok("Convenzione eliminata con i suoi file.", 'warning');
        $torna = $url('archivio') . '&r=' . time();
    } elseif (isset($_POST['rinnova'])) {
        [$err, $tk] = $svc->rinnova($id);
        $ok($err ?? "Rinnovo avviato: l'azienda ha ricevuto il link con i dati già inseriti.", $err ? 'danger' : 'success');
        $torna = $url('archivio') . '&r=' . time();
    }
    admin_redirect($torna);
}

$conteggi = $svc->conteggi();
$gruppi = [
    'modifiche' => $svc->inStati([$S::MODIFICHE]), 'caricata' => $svc->inStati([$S::CARICATA]), 'dirfirma' => $svc->inStati([$S::IN_CARICO, $S::DA_FIRMARE]), 'firmata' => $svc->inStati([$S::FIRMATA]),
    'attesa' => $svc->inStati([$S::INVITATA, $S::COMPILATA, $S::RISPOSTA, $S::RIFIUTATA]),
];
$n_da_fare = $svc->daFare();
$dir_firma = $svc->chiFirma();
$dir_testo = $svc->datiDirettoreTesto();
$standard = $svc->articoliStandard();
$oggi = date('Y-m-d');
$val_dal = $oggi; $val_al = date('Y-m-d', strtotime('+' . $S::DURATA_ANNI . ' years -1 day'));
$nome_az = fn(array $c) => (string)($c['azienda'] ?: $c['email']);
?>
<style>
.cvt-passi { display:grid; grid-template-columns:repeat(6, minmax(0,1fr)); gap:8px; margin:0 0 1.25rem; padding:0; list-style:none; }
.cvt-passi li { background:#fff; border:1px solid #e2e8f0; border-top:5px solid var(--c); border-radius:12px; padding:10px 10px 8px; position:relative; }
.cvt-passi .n { display:inline-grid; place-items:center; width:24px; height:24px; border-radius:50%; background:var(--c); color:#fff; font-weight:800; font-size:.8rem; margin-right:4px; }
.cvt-passi .chi { font-size:.66rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--c); }
.cvt-passi h3 { font-size:.85rem; font-weight:700; margin:5px 0 3px; }
.cvt-passi p { font-size:.76rem; color:#475569; margin:0; }
.cvt-passi a.conta { display:inline-block; margin-top:5px; font-weight:800; font-size:.78rem; text-decoration:none; padding:1px 9px; border-radius:99px; background:var(--c); color:#fff; }
.cvt-passi a.conta.zero { background:#e2e8f0; color:#64748b; }
.cvt-tuo { --c:#b30000; } .cvt-altri { --c:#64748b; } .cvt-dir { --c:#7c3aed; } .cvt-ok { --c:#15803d; }
.cvt-fase { margin:1.5rem 0 .5rem; display:flex; align-items:center; gap:10px; flex-wrap:wrap; scroll-margin-top:80px; }
.cvt-fase h2 { margin:0 !important; font-size:1.05rem !important; text-transform:none !important; letter-spacing:0 !important; }
.cvt-fase .chi { font-size:.72rem; font-weight:800; padding:3px 10px; border-radius:99px; color:#fff; background:var(--c); }
.cvt-card { background:#fff; border:1px solid #e2e8f0; border-left:6px solid var(--c); border-radius:12px; padding:14px 16px; margin-bottom:12px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.cvt-az { display:flex; flex-direction:column; gap:1px; margin-bottom:10px; } .cvt-az strong { font-size:1.02rem; }
.cvt-az { display:flex; flex-direction:column; gap:1px; margin-bottom:10px; }
.cvt-azioni { list-style:none; padding:0; margin:0; counter-reset:a; }
.cvt-azioni > li { counter-increment:a; display:grid; grid-template-columns:30px 1fr; gap:8px; padding:8px 0; border-top:1px dashed #e2e8f0; align-items:start; }
.cvt-azioni > li::before { content:counter(a); width:26px; height:26px; border-radius:50%; background:var(--c); color:#fff; display:grid; place-items:center; font-weight:800; font-size:.8rem; margin-top:2px; }
.cvt-esito { background:#f1f5f9; border-radius:8px; padding:7px 10px; font-size:.84rem; color:#334155; margin-top:8px; }
.cvt-vuoto { color:#64748b; font-size:.9rem; padding:8px 0; }
.cvt-art { border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; margin-bottom:10px; background:#fffbeb; }
.cvt-art pre { white-space:pre-wrap; font:inherit; font-size:.83rem; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:8px; margin:0; max-height:220px; overflow:auto; }
@media (max-width: 1199px) { .cvt-passi { grid-template-columns:repeat(3, minmax(0,1fr)); } }
@media (max-width: 767px) { .cvt-passi { grid-template-columns:1fr; } }
</style>
<?php
echo pn_testata('Convenzioni di tesi', 'fa-handshake', "Le convenzioni con enti e aziende che accolgono i nostri studenti per la tesi: l'azienda compila online (senza registrarsi), tu protocolli, il Direttore firma, poi va in archivio e nell'elenco pubblico.",
    '<a class="btn btn-outline-secondary btn-sm" href="../aziende_convenzionate_tesi.php" target="_blank" rel="noopener"><i class="fa fa-list me-1" aria-hidden="true"></i>Elenco pubblico</a>');
echo pn_schede([
    'iter' => ['href' => $url('iter'), 'testo' => 'Da seguire', 'icona' => 'fa-route', 'numero' => $n_da_fare, 'avviso' => true, 'titolo' => 'Convenzioni che aspettano te'],
    'archivio' => ['href' => $url('archivio'), 'testo' => 'Archivio e aziende convenzionate', 'icona' => 'fa-box-archive'],
], $tab, 'Sezioni delle convenzioni di tesi');
?>

<?php if ($tab === 'iter'): ?>
<section class="scu-sez" id="iter">
    <ol class="cvt-passi">
        <li class="cvt-altri"><span class="n">1</span><span class="chi">L'azienda</span><h3>Compila online</h3><p>Dati e, se vuole, modifiche agli articoli. Senza registrarsi.</p><a class="conta<?php echo $gruppi['attesa'] ? '' : ' zero'; ?>" href="#fase-attesa"><?php echo count($gruppi['attesa']); ?> in corso</a></li>
        <li class="cvt-tuo"><span class="n">2</span><span class="chi">Tu</span><h3>Valuti le modifiche</h3><p>Solo se l'azienda le chiede: accetti, rifiuti o rispondi con un testo.</p><a class="conta<?php echo $gruppi['modifiche'] ? '' : ' zero'; ?>" href="#fase-modifiche"><?php echo count($gruppi['modifiche']); ?> da valutare</a></li>
        <li class="cvt-altri"><span class="n">3</span><span class="chi">L'azienda</span><h3>Scarica, firma e ricarica</h3><p>Il PDF compilato, firmato in PAdES dal legale rappresentante.</p></li>
        <li class="cvt-tuo"><span class="n">4</span><span class="chi">Tu · Protocollo</span><h3>Protocollo in entrata</h3><p>Scarichi, protocolli, scrivi il numero: il Direttore riceve da solo l'email.</p><a class="conta<?php echo $gruppi['caricata'] ? '' : ' zero'; ?>" href="#fase-caricata"><?php echo count($gruppi['caricata']); ?> da fare</a></li>
        <li class="cvt-dir"><span class="n">5</span><span class="chi">Il Direttore</span><h3>Firma dal link</h3><p>Il PDF firmato torna qui da solo.</p><a class="conta<?php echo $gruppi['dirfirma'] ? '' : ' zero'; ?>" href="#fase-dirfirma"><?php echo count($gruppi['dirfirma']); ?> dal Direttore</a></li>
        <li class="cvt-tuo"><span class="n">6</span><span class="chi">Tu · Protocollo</span><h3>Protocollo in uscita</h3><p>Numero e validità: stipulata, in archivio e nell'elenco pubblico.</p><a class="conta<?php echo $gruppi['firmata'] ? '' : ' zero'; ?>" href="#fase-firmata"><?php echo count($gruppi['firmata']); ?> da fare</a></li>
    </ol>

    <div class="row g-3 mb-2">
        <div class="col-lg-7"><form method="POST" class="border rounded p-3 bg-white h-100"><?php csrf_field(); ?>
            <h3 class="h6 fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invita un'azienda</h3>
            <div class="row g-2"><div class="col-sm-5"><label class="form-label small mb-0" for="invAz">Ente o azienda</label><input class="form-control form-control-sm" id="invAz" name="azienda" maxlength="200" placeholder="facoltativo"></div>
                <div class="col-sm-5"><label class="form-label small mb-0" for="invEm">Email *</label><input type="email" class="form-control form-control-sm" id="invEm" name="email" maxlength="255" required></div>
                <div class="col-sm-2 d-flex align-items-end"><button class="btn btn-danger btn-sm fw-bold w-100" type="submit" name="invita" value="1">Invia il link</button></div></div>
            <div class="form-text">L'azienda riceve un link personale per compilare la convenzione. Se ne ha già una aperta riceve di nuovo il link di quella.</div></form></div>
        <div class="col-lg-5"><div class="border rounded p-3 bg-white h-100">
            <h3 class="h6 fw-bold"><i class="fa fa-link me-1" aria-hidden="true"></i>Pagina per le aziende</h3>
            <p class="small mb-1">Da dare a chi chiede la convenzione (si richiede da solo, con l'email):</p>
            <input class="form-control form-control-sm font-monospace" readonly value="<?php echo $h(\App\Core\App::get(\App\Core\Sito::class)->urlBase() . '/richiedi_convenzione_tesi.php'); ?>" onclick="this.select()" aria-label="Indirizzo della pagina pubblica">
            <div class="small mt-2"><i class="fa fa-user-tie me-1" aria-hidden="true"></i>Firma: <strong><?php echo $h($dir_firma['nome'] ?: 'Direttore non indicato'); ?></strong>
                <?php if ($dir_firma['email'] === ''): ?> <a href="uffici.php?p_id=<?php echo (int)$filtro_p; ?>" class="fw-bold">Indicalo in Uffici → Direzione</a><?php endif; ?></div>
        </div></div>
    </div>

    <details class="border rounded p-2 mb-3 bg-white"<?php echo $dir_testo['stato'] !== 'a_posto' ? ' open' : ''; ?> id="direttoreTesto">
        <summary class="fw-bold small"><i class="fa fa-user-tie me-1" aria-hidden="true"></i>Il Direttore nel testo della convenzione
            <?php echo ['mai_indicato' => ' · <span class="badge bg-warning text-dark">il testo usa ancora il modello com\'è</span>', 'a_posto' => ' · <span class="badge bg-success">a posto</span>', 'da_aggiornare' => ' · <span class="badge bg-danger">da aggiornare: il Direttore è cambiato</span>'][$dir_testo['stato']]; ?></summary>
        <form method="POST" class="row g-2 mt-1"><?php csrf_field(); ?>
            <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="dtTit">Titolo</label><select class="form-select form-select-sm" id="dtTit" name="titolo"><?php foreach ($S::TITOLI_DIRETTORE as $t_d): ?><option<?php echo $dir_testo['titolo'] === $t_d ? ' selected' : ''; ?>><?php echo $h($t_d); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="dtNome">Nome e cognome come nel testo</label><input class="form-control form-control-sm" id="dtNome" name="nome" required maxlength="150" value="<?php echo $h($dir_testo['stato'] === 'da_aggiornare' ? $dir_testo['in_carica'] : ($dir_testo['nome'] ?: $dir_testo['in_carica'])); ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="dtLuogo">Nato a</label><input class="form-control form-control-sm" id="dtLuogo" name="nato_a" required maxlength="100" placeholder="es. Lachen (CH)" value="<?php echo $h($dir_testo['stato'] === 'da_aggiornare' ? '' : $dir_testo['nato_a']); ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="dtData">Il</label><input type="date" class="form-control form-control-sm" id="dtData" name="nato_il" required value="<?php echo $h($dir_testo['stato'] === 'da_aggiornare' ? '' : $dir_testo['nato_il']); ?>"></div>
            <div class="col-md-1 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100" type="submit" name="salva_direttore_testo" value="1">Salva</button></div>
            <div class="form-text">Nel modello il Direttore è «rappresentato dal Direttore, <em>titolo nome</em>, nato a <em>luogo</em> il <em>data</em>, domiciliato per la carica…». Il Direttore in carica (Gestione del portale → Uffici → Direzione) è <strong><?php echo $h($dir_testo['in_carica'] ?: 'non indicato'); ?></strong>. Finché non salvi questi dati il Word e il PDF usano il testo del modello com'è; se il Direttore cambia, il nuovo nome compare da solo e luogo e data restano puntini finché non li aggiorni qui.</div>
        </form>
    </details>

    <?php if (!$svc->modelloDisponibile()): ?><div class="alert alert-danger small">Manca il modello Word (<code><?php echo $h($S::MODELLO); ?></code>): le aziende non possono scaricare la convenzione.</div><?php endif; ?>

    <!-- 2 · Modifiche da valutare -->
    <div class="cvt-fase cvt-tuo" id="fase-modifiche"><span class="chi">Tocca a te</span><h2>② Modifiche agli articoli da valutare (<?php echo count($gruppi['modifiche']); ?>)</h2></div>
    <?php if (!$gruppi['modifiche']): ?><p class="cvt-vuoto">Nessuna modifica da valutare.</p><?php endif; ?>
    <?php foreach ($gruppi['modifiche'] as $c): $idc = (int)$c['id']; ?>
    <div class="cvt-card cvt-tuo">
        <div class="cvt-az"><strong><?php echo $h($nome_az($c)); ?></strong><span class="text-secondary small"><?php echo $h($c['email']); ?> · aggiornata il <?php echo $d($c['aggiornata_il']); ?></span></div>
        <?php if (!empty($c['nota_azienda'])): ?><p class="small"><strong>Nota dell'azienda:</strong> <?php echo nl2br($h($c['nota_azienda'])); ?></p><?php endif; ?>
        <form method="POST"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>">
        <?php foreach ($svc->modifiche($c) as $n => $m): if ($m['stato'] !== 'proposta') continue; ?>
            <div class="cvt-art">
                <div class="fw-bold mb-2">Art. <?php echo (int)$n; ?> – <?php echo $h($standard[$n]['titolo'] ?? ''); ?></div>
                <div class="row g-2">
                    <div class="col-md-6"><div class="small text-secondary mb-1">Testo standard</div><pre><?php echo $h($standard[$n]['testo'] ?? ''); ?></pre></div>
                    <div class="col-md-6"><div class="small text-secondary mb-1">Proposta dell'azienda</div><pre><?php echo $h($m['testo']); ?></pre></div>
                </div>
                <div class="mt-2 d-flex flex-wrap gap-3 small">
                    <?php foreach (['accetta' => 'Accetto', 'rifiuta' => 'Rifiuto', 'controproposta' => 'Rispondo con un altro testo'] as $az => $et): ?>
                        <div class="form-check"><input class="form-check-input" type="radio" name="m[<?php echo (int)$n; ?>][azione]" id="m<?php echo $idc . '_' . (int)$n . $az; ?>" value="<?php echo $az; ?>" required><label class="form-check-label fw-bold" for="m<?php echo $idc . '_' . (int)$n . $az; ?>"><?php echo $h($et); ?></label></div>
                    <?php endforeach; ?>
                </div>
                <label class="form-label small mb-0 mt-2" for="mt<?php echo $idc . '_' . (int)$n; ?>">Testo da proporre (solo se rispondi con un altro testo)</label>
                <textarea class="form-control form-control-sm" id="mt<?php echo $idc . '_' . (int)$n; ?>" name="m[<?php echo (int)$n; ?>][testo]" rows="3"><?php echo $h($m['testo']); ?></textarea>
                <label class="form-label small mb-0 mt-2" for="mr<?php echo $idc . '_' . (int)$n; ?>">Nota per l'azienda (obbligatoria se rifiuti)</label>
                <input class="form-control form-control-sm" id="mr<?php echo $idc . '_' . (int)$n; ?>" name="m[<?php echo (int)$n; ?>][risposta]" maxlength="500" placeholder="es. clausola standard di Ateneo, non modificabile">
            </div>
        <?php endforeach; ?>
        <button type="submit" name="rispondi_modifiche" value="1" class="btn btn-danger fw-bold"><i class="fa fa-reply me-1" aria-hidden="true"></i>Manda la risposta all'azienda</button>
        <div class="cvt-esito mt-2"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Se accetti tutto l'azienda può subito scaricare e firmare; altrimenti rivede il testo e torna da te.</div>
        </form>
    </div>
    <?php endforeach; ?>

    <!-- 4 · Da prendere in carico -->
    <div class="cvt-fase cvt-tuo" id="fase-caricata"><span class="chi">Tocca a te</span><h2>④ Firmate dalle aziende: da prendere in carico (<?php echo count($gruppi['caricata']); ?>)</h2></div>
    <?php if (!$gruppi['caricata']): ?><p class="cvt-vuoto">Nessuna convenzione da prendere in carico.</p><?php endif; ?>
    <?php foreach ($gruppi['caricata'] as $c): $idc = (int)$c['id']; $dc = $svc->dati($c); ?>
    <div class="cvt-card cvt-tuo">
        <div class="cvt-az"><strong><?php echo $h($nome_az($c)); ?></strong><span class="text-secondary small"><?php echo $h(($dc['comune'] ?? '') . (!empty($dc['piva']) ? ' · P. IVA ' . $dc['piva'] : '') . (!empty($dc['pec']) ? ' · PEC ' . $dc['pec'] : '')); ?><?php echo $svc->modifiche($c) ? ' · con articoli modificati' : ''; ?></span></div>
        <ol class="cvt-azioni">
            <li><div><strong>Scarica il PDF firmato dall'azienda</strong><div class="mt-1"><a class="btn btn-sm btn-outline-danger" href="<?php echo $h($url('iter', '&file=' . $idc . '&f=azienda')); ?>" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>PDF della convenzione firmata</a></div>
                <div class="text-secondary small mt-1">Firmato da <?php echo $h($c['firmatario'] ?: 'il legale rappresentante'); ?> il <?php echo $d($c['firmata_il']); ?>.</div></div></li>
            <li><div><strong>Protocollalo</strong> nel sistema di protocollo di Ateneo, come fai di solito.</div></li>
            <li><div><strong>Scrivi qui numero e data del protocollo in entrata</strong>
                <form method="POST" class="row g-2 mt-1 align-items-end"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>">
                    <div class="col-sm-4"><label class="form-label small mb-0">N. protocollo in entrata</label><input name="prot_ingresso" class="form-control form-control-sm" maxlength="80" required placeholder="es. 1234"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Data</label><input type="date" name="prot_ingresso_data" class="form-control form-control-sm" value="<?php echo $oggi; ?>"></div>
                    <div class="col-sm-5"><button type="submit" name="prendi" value="1" class="btn btn-danger fw-bold w-100"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Registra e manda al Direttore</button></div></form></div></li>
        </ol>
        <div class="cvt-esito"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Appena registri: l'azienda riceve l'avviso e <strong><?php echo $h($dir_firma['nome'] ?: 'il Direttore'); ?></strong> riceve l'email con il link per firmare. Non devi ricaricare il file.</div>
        <details class="mt-2"><summary class="small fw-bold text-danger" style="cursor:pointer;">Qualcosa non va? Rifiuta con un motivo</summary>
            <form method="POST" class="mt-1" style="max-width:420px;"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>">
                <textarea name="motivo" class="form-control form-control-sm mb-1" rows="2" maxlength="500" required placeholder="Es. firma mancante, dati del rappresentante errati…" aria-label="Motivo del rifiuto"></textarea>
                <button type="submit" name="rifiuta" value="1" class="btn btn-sm btn-outline-danger">Rifiuta e avvisa l'azienda</button></form></details>
    </div>
    <?php endforeach; ?>

    <!-- 6 · Firmate dal Direttore -->
    <div class="cvt-fase cvt-tuo" id="fase-firmata"><span class="chi">Tocca a te</span><h2>⑥ Firmate dal Direttore: protocollo in uscita (<?php echo count($gruppi['firmata']); ?>)</h2></div>
    <?php if (!$gruppi['firmata']): ?><p class="cvt-vuoto">Nessuna convenzione in attesa del protocollo definitivo.</p><?php endif; ?>
    <?php foreach ($gruppi['firmata'] as $c): $idc = (int)$c['id']; ?>
    <div class="cvt-card cvt-tuo">
        <div class="cvt-az"><strong><?php echo $h($nome_az($c)); ?></strong><span class="text-secondary small">firmata dal Direttore il <?php echo $d($c['firmata_direttore_il']); ?> · protocollo in entrata <?php echo $h($c['protocollo_ingresso'] ?: '—'); ?></span></div>
        <ol class="cvt-azioni">
            <li><div><strong>Scarica il PDF firmato dal Direttore</strong><div class="mt-1"><a class="btn btn-sm btn-outline-success" href="<?php echo $h($url('iter', '&file=' . $idc . '&f=direttore')); ?>" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>PDF firmato dal Direttore</a></div></div></li>
            <li><div><strong>Protocollalo</strong> in uscita nel sistema di Ateneo.</div></li>
            <li><div><strong>Scrivi qui il protocollo e per quanto vale la convenzione</strong> (l'art. 10 del modello dice 5 anni)
                <form method="POST" enctype="multipart/form-data" class="row g-2 mt-1"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>">
                    <div class="col-sm-3"><label class="form-label small mb-0">N. protocollo</label><input name="protocollo" class="form-control form-control-sm" maxlength="100" required placeholder="es. 2201"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Data protocollo</label><input type="date" name="protocollo_data" class="form-control form-control-sm" value="<?php echo $oggi; ?>"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Valida dal</label><input type="date" name="valida_dal" class="form-control form-control-sm" required value="<?php echo $val_dal; ?>"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Valida fino al</label><input type="date" name="valida_al" class="form-control form-control-sm" required value="<?php echo $val_al; ?>"></div>
                    <div class="col-12"><label class="form-label small mb-0" for="fp<?php echo $idc; ?>">PDF con la segnatura di protocollo (facoltativo)</label><input type="file" name="file_protocollato" id="fp<?php echo $idc; ?>" class="form-control form-control-sm" accept=".pdf,application/pdf">
                        <div class="form-text">Se lo carichi, per l'azienda e nell'archivio c'è questo file; quello firmato dal Direttore resta scaricabile qui.</div></div>
                    <div class="col-12"><button type="submit" name="completa" value="1" class="btn btn-success fw-bold" data-confirm="Registrare la convenzione come stipulata e avvisare l'azienda?"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Registra: convenzione stipulata</button></div></form></div></li>
        </ol>
        <div class="cvt-esito"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Alla registrazione la convenzione va in archivio, l'azienda riceve l'avviso e, se ha dato il consenso, compare nell'<a href="../aziende_convenzionate_tesi.php" target="_blank" rel="noopener">elenco pubblico</a>.</div>
    </div>
    <?php endforeach; ?>

    <!-- 5 · Dal Direttore -->
    <div class="cvt-fase cvt-dir" id="fase-dirfirma"><span class="chi">Attendi</span><h2>⑤ Dal Direttore per la firma (<?php echo count($gruppi['dirfirma']); ?>)</h2></div>
    <?php if (!$gruppi['dirfirma']): ?><p class="cvt-vuoto">Nessuna convenzione dal Direttore.</p><?php endif; ?>
    <?php foreach ($gruppi['dirfirma'] as $c): $idc = (int)$c['id']; $inviata = $c['stato'] === $S::DA_FIRMARE; ?>
    <div class="cvt-card cvt-dir">
        <div class="cvt-az"><strong><?php echo $h($nome_az($c)); ?></strong><span class="text-secondary small">protocollo in entrata <?php echo $h($c['protocollo_ingresso']); ?></span></div>
        <p class="mb-2 small"><?php if ($inviata): ?><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Il link per firmare è stato mandato a <strong><?php echo $h($dir_firma['nome']); ?></strong> il <?php echo date('d/m/Y H:i', strtotime((string)$c['da_firmare_il'])); ?>. Quando firma, il PDF torna qui e passa al punto ⑥.
            <?php else: ?><i class="fa fa-triangle-exclamation me-1 text-danger" aria-hidden="true"></i><strong>L'email al Direttore non è partita</strong>: controlla che sia indicato in Uffici → Direzione e premi il pulsante.<?php endif; ?></p>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>"><button type="submit" name="invia_direttore" value="1" class="btn btn-sm <?php echo $inviata ? 'btn-outline-primary' : 'btn-primary fw-bold'; ?>"><i class="fa fa-file-signature me-1" aria-hidden="true"></i><?php echo $inviata ? 'Rimanda il link' : 'Invia al Direttore per la firma'; ?></button></form>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $h($url('iter', '&file=' . $idc . '&f=azienda')); ?>" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>PDF dell'azienda</a></div>
    </div>
    <?php endforeach; ?>

    <!-- 1 / 3 · In attesa dell'azienda -->
    <div class="cvt-fase cvt-altri" id="fase-attesa"><span class="chi">Attendi</span><h2>① ③ In corso con l'azienda (<?php echo count($gruppi['attesa']); ?>)</h2></div>
    <?php if (!$gruppi['attesa']): ?><p class="cvt-vuoto">Nessuna convenzione in corso con le aziende.</p>
    <?php else: ?><div class="table-responsive"><table class="table table-sm small align-middle bg-white border">
        <thead><tr><th>Ente o azienda</th><th>Email</th><th>Stato</th><th>Ultima novità</th><th></th></tr></thead><tbody>
        <?php foreach ($gruppi['attesa'] as $c): $et = $S::ETICHETTE[$c['stato']] ?? [$c['stato'], 'secondary']; ?>
            <tr><td><strong><?php echo $h($nome_az($c)); ?></strong><div class="text-secondary"><?php echo $c['origine'] === 'pubblica' ? 'ha richiesto dalla pagina pubblica' : 'invitata da ' . $h($c['invitata_da'] ?: 'ufficio'); ?></div></td>
                <td><?php echo $h($c['email']); ?></td><td><span class="badge bg-<?php echo $et[1]; ?>"><?php echo $h($et[0]); ?></span><?php echo $c['stato'] === $S::RIFIUTATA ? '<div class="text-secondary" style="max-width:240px;white-space:normal;">' . $h($c['nota_ufficio']) . '</div>' : ''; ?></td>
                <td class="text-nowrap"><?php echo $d($c['aggiornata_il']); ?></td>
                <td class="text-nowrap"><form method="POST" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>"><button type="submit" name="rimanda" value="1" class="btn btn-sm btn-outline-primary py-0" title="Rimanda all'azienda il link personale">Rimanda il link</button>
                    <button type="submit" name="elimina" value="1" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare questa convenzione in corso?">Elimina</button></form></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <p class="text-secondary small mt-3 mb-0">Le convenzioni stipulate (anche quelle già in essere) sono in <a href="<?php echo $h($url('archivio')); ?>">Archivio e aziende convenzionate</a>.</p>
</section>

<?php else:
    $vista = in_array($_GET['vista'] ?? '', ['valide', 'scadute'], true) ? $_GET['vista'] : 'tutte';
    $cerca = trim((string)($_GET['q'] ?? ''));
    $archivio = $svc->archivio($cerca, $vista);
    $mod = !empty($_GET['mod']) ? $svc->perId((int)$_GET['mod']) : null;
    if ($mod && $mod['stato'] !== $S::PROTOCOLLATA) $mod = null;
    $dm = $mod ? $svc->dati($mod) : [];
    $tra = date('Y-m-d', strtotime('+60 days'));
?>
<section class="scu-sez" id="archivio">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <form method="GET" class="d-flex gap-2 m-0 flex-grow-1" role="search"><input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="archivio">
            <input class="form-control form-control-sm" name="q" value="<?php echo $h($cerca); ?>" placeholder="Cerca per azienda, protocollo, comune…" aria-label="Cerca">
            <select class="form-select form-select-sm" name="vista" style="width:auto;" aria-label="Quali convenzioni"><?php foreach (['tutte' => 'Tutte', 'valide' => 'Valide', 'scadute' => 'Scadute'] as $k => $t): ?><option value="<?php echo $k; ?>"<?php echo $vista === $k ? ' selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm btn-outline-secondary" type="submit">Filtra</button></form>
    </div>
    <details class="mb-3 border rounded p-3 bg-white"<?php echo $mod ? ' open' : ''; ?> id="formArchivio"><summary class="fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i><?php echo $mod ? 'Modifica la convenzione' : 'Inserisci una convenzione già in essere'; ?></summary>
        <p class="small text-secondary mt-2">Per le convenzioni stipulate prima del portale: senza passare dall'iter. Indica almeno ragione sociale, protocollo e validità; il PDF è facoltativo ma utile.</p>
        <form method="POST" enctype="multipart/form-data" class="row g-2"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)($mod['id'] ?? 0); ?>">
            <?php $f = fn(string $k) => $h($dm[$k] ?? ''); ?>
            <div class="col-md-6"><label class="form-label small fw-bold mb-0">Ragione sociale *</label><input class="form-control form-control-sm" name="ragione_sociale" required maxlength="200" value="<?php echo $mod ? $h($mod['azienda']) : ''; ?>"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Partita IVA</label><input class="form-control form-control-sm" name="piva" maxlength="20" value="<?php echo $f('piva'); ?>"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Codice fiscale</label><input class="form-control form-control-sm" name="cf" maxlength="20" value="<?php echo $f('cf'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Comune</label><input class="form-control form-control-sm" name="comune" maxlength="100" value="<?php echo $f('comune'); ?>"></div>
            <div class="col-md-2"><label class="form-label small mb-0">Prov.</label><input class="form-control form-control-sm" name="provincia" maxlength="2" value="<?php echo $f('provincia'); ?>"></div>
            <div class="col-md-6"><label class="form-label small mb-0">Indirizzo</label><input class="form-control form-control-sm" name="indirizzo" maxlength="200" value="<?php echo $f('indirizzo'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">PEC</label><input class="form-control form-control-sm" name="pec" maxlength="150" value="<?php echo $f('pec'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Email di contatto (riceve gli avvisi di scadenza)</label><input type="email" class="form-control form-control-sm" name="email" maxlength="255" value="<?php echo $mod ? $h($mod['email']) : ''; ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Settore</label><input class="form-control form-control-sm" name="settore" maxlength="150" value="<?php echo $f('settore'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Sito web</label><input class="form-control form-control-sm" name="sito" maxlength="200" value="<?php echo $f('sito'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Rappresentante legale</label><input class="form-control form-control-sm" name="rappr_nome" maxlength="150" value="<?php echo $f('rappr_nome'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Referente</label><input class="form-control form-control-sm" name="referente_nome" maxlength="150" value="<?php echo $f('referente_nome'); ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0">N. protocollo *</label><input class="form-control form-control-sm" name="protocollo" required maxlength="100" value="<?php echo $mod ? $h($mod['protocollo']) : ''; ?>"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Data protocollo</label><input type="date" class="form-control form-control-sm" name="protocollo_data" value="<?php echo $mod ? $h($mod['protocollo_data']) : ''; ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0">Valida dal *</label><input type="date" class="form-control form-control-sm" name="valida_dal" required value="<?php echo $mod ? $h($mod['valida_dal']) : $val_dal; ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0">Valida fino al *</label><input type="date" class="form-control form-control-sm" name="valida_al" required value="<?php echo $mod ? $h($mod['valida_al']) : $val_al; ?>"></div>
            <div class="col-md-8"><label class="form-label small mb-0">PDF della convenzione<?php echo $mod && $mod['file_protocollato'] ? ' (carica solo per sostituirlo)' : ''; ?></label><input type="file" class="form-control form-control-sm" name="file_convenzione" accept=".pdf,application/pdf"></div>
            <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="consenso_elenco" id="cArc" value="1"<?php echo ($mod ? (int)$mod['consenso_elenco'] === 1 : true) ? ' checked' : ''; ?>><label class="form-check-label small" for="cArc">Compare nell'elenco pubblico (consenso dell'azienda)</label></div></div>
            <div class="col-12"><button type="submit" name="archivia" value="1" class="btn btn-danger fw-bold"><?php echo $mod ? 'Salva le modifiche' : 'Inserisci in archivio'; ?></button><?php if ($mod): ?> <a class="btn btn-outline-secondary" href="<?php echo $h($url('archivio')); ?>">Annulla</a><?php endif; ?></div>
        </form>
    </details>
    <?php if (!$archivio): ?><p class="text-muted">Nessuna convenzione stipulata<?php echo $cerca !== '' || $vista !== 'tutte' ? ' con questi filtri' : ' per ora'; ?>.</p>
    <?php else: ?><div class="table-responsive"><table class="table table-sm small align-middle bg-white border">
        <thead class="table-light"><tr><th>Ente o azienda</th><th>Comune</th><th>Protocollo</th><th>Validità</th><th>Elenco pubblico</th><th>File</th><th></th></tr></thead><tbody>
        <?php foreach ($archivio as $c): $dc = $svc->dati($c); $scaduta = $c['valida_al'] && $c['valida_al'] < $oggi; $presto = !$scaduta && $c['valida_al'] && $c['valida_al'] <= $tra; ?>
            <tr><td><strong><?php echo $h($c['azienda']); ?></strong><div class="text-secondary"><?php echo $h(($dc['piva'] ?? '') ? 'P. IVA ' . $dc['piva'] : ($dc['cf'] ?? '')); ?><?php echo $c['origine'] === 'archivio' ? ' · inserita a mano' : ''; ?></div></td>
                <td><?php echo $h(($dc['comune'] ?? '') . (!empty($dc['provincia']) ? ' (' . $dc['provincia'] . ')' : '')); ?></td>
                <td><?php echo $h($c['protocollo']); ?><div class="text-secondary"><?php echo $d($c['protocollo_data']); ?></div></td>
                <td class="text-nowrap"><?php echo $d($c['valida_dal']); ?> – <?php echo $d($c['valida_al']); ?> <?php echo $scaduta ? '<span class="badge bg-secondary">scaduta</span>' : ($presto ? '<span class="badge bg-warning text-dark">in scadenza</span>' : '<span class="badge bg-success">valida</span>'); ?></td>
                <td><?php echo (int)$c['consenso_elenco'] === 1 ? ($scaduta ? 'sì (non più valida)' : 'sì') : 'no'; ?></td>
                <td class="text-nowrap"><?php if ($svc->fileDaScaricare((int)$c['id'], 'finale')): ?><a class="btn btn-sm btn-outline-success py-0" href="<?php echo $h($url('archivio', '&file=' . (int)$c['id'] . '&f=finale')); ?>" target="_blank" rel="noopener">PDF</a><?php else: ?>—<?php endif; ?></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary py-0" href="<?php echo $h($url('archivio', '&mod=' . (int)$c['id'])); ?>#formArchivio">Modifica</a>
                    <form method="POST" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                        <?php if (($scaduta || $presto) && $c['email']): ?><button type="submit" name="rinnova" value="1" class="btn btn-sm btn-outline-primary py-0" data-confirm="Mandare all'azienda il link per rinnovare la convenzione?">Rinnova</button><?php endif; ?>
                        <button type="submit" name="elimina" value="1" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare questa convenzione dall'archivio con i suoi file?">Elimina</button></form></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
<?php require_once 'admin_footer.php'; ?>
