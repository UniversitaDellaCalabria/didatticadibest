<?php
// tirocini.php - Didattica › Tirocini: elenco pubblico dei posti dove si svolgono i tirocini curriculari.
//   Aziende esterne   report dell'Albo aziende del TSP (Cineca): resta chi ha la «x» in «Esiste convenzione attiva». Nel report non c'è l'indirizzo: si scrive qui
//                     (riga per riga, oppure scaricando l'elenco da completare in Excel e ricaricandolo). Si pubblica solo con la convenzione e il comune.
//   Laboratori interni dalle API di Ateneo, per i dipartimenti scelti in Anagrafi › Strutture e aggiornamento; si può nascondere un laboratorio.
// Logica in src/Tirocini/; pagina pubblica: tirocini.php.
require_once 'admin_header.php';

if (!($is_full_admin || $puo_didattica_tutto)) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$app_t = \App\Core\App::per($conn);
$az = $app_t->get(\App\Tirocini\ServizioAziende::class);
$lab = $app_t->get(\App\Tirocini\ServizioLaboratori::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tab = ($_GET['tab'] ?? '') === 'interni' ? 'interni' : 'esterni';
$filtro = in_array($_GET['f'] ?? '', ['senza_indirizzo', 'da_confermare', 'non_pubbliche', 'pubblicate'], true) ? $_GET['f'] : 'tutte';
$cerca = trim((string)($_GET['q'] ?? ''));
$url = fn(string $t = 'esterni', string $extra = '') => 'tirocini.php?tab=' . $t . $extra;

// ── Elenco da completare in Excel ──
if (isset($_GET['da_completare'])) {
    $file = \App\Infrastructure\Documenti\Excel::crea(['Da completare' => [
        'intestazioni' => ['Codice azienda', 'Descrizione', 'Partita IVA / Codice fiscale', 'Settore', 'Indirizzo', 'CAP', 'Comune', 'Provincia', 'Sito web'],
        'righe' => $az->righeDaCompletare(), 'larghezze' => [16, 50, 22, 40, 36, 8, 24, 10, 36],
    ]]);
    if (!$file) { flash_set('Su questo server non si possono creare file Excel.', 'danger'); admin_redirect($url()); }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="aziende_tirocini_da_completare.xlsx"');
    header('Content-Length: ' . filesize($file));
    readfile($file); @unlink($file); exit;
}

// ── Azioni ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['importa'])) {
        $righe = \App\Tirocini\LettoreFoglio::righe($_FILES['file'] ?? []);
        if (is_string($righe)) {
            flash_set($righe, 'danger');
        } else {
            $r = $az->importa($righe);
            registra_log_audit($conn, "Tirocini: caricato il file delle aziende", $r);
            flash_set("File letto: {$r['nuove']} aziende nuove, {$r['aggiornate']} aggiornate" . ($r['senza_convenzione'] ? ", {$r['senza_convenzione']} senza più la convenzione" : '') . ($r['indirizzi'] ? ", {$r['indirizzi']} indirizzi inseriti" : '') . ($r['siti'] ? ", {$r['siti']} siti web" : '') . ($r['scartate'] ? ", {$r['scartate']} righe scartate perché senza codice azienda" : '') . '.', $r['scartate'] ? 'warning' : 'success');
        }
        admin_redirect($url() . '&r=' . time());
    } elseif (isset($_POST['nuova_azienda'])) {
        $es = $az->creaManuale((string)($_POST['nome'] ?? ''), (string)($_POST['partita_iva'] ?? ''), (string)($_POST['codice_fiscale'] ?? ''), (string)($_POST['settore'] ?? ''), (string)($_POST['indirizzo'] ?? ''),
            (string)($_POST['cap'] ?? ''), (string)($_POST['comune'] ?? ''), (string)($_POST['provincia'] ?? ''), (string)($_POST['sito'] ?? ''), !empty($_POST['pubblica']));
        if ($es->riuscito) {
            registra_log_audit($conn, "Tirocini: azienda inserita a mano", ["Azienda" => trim((string)($_POST['nome'] ?? '')), "Id" => $es->messaggio]);
            $nuova = $az->perId((int)$es->messaggio);
            flash_set('Azienda aggiunta' . ($nuova && $nuova['comune'] === '' ? ': senza comune e provincia non si pubblica finché non li scrivi.' : '.'));
            admin_redirect($url('esterni', '&q=' . urlencode(trim((string)($_POST['nome'] ?? '')))) . '&r=' . time() . '#a' . (int)$es->messaggio);
        }
        flash_set($es->messaggio, 'danger');
        $_SESSION['tirocini_bozza'] = array_intersect_key($_POST, array_flip(['nome', 'partita_iva', 'codice_fiscale', 'settore', 'indirizzo', 'cap', 'comune', 'provincia', 'sito']));
        admin_redirect($url('esterni', '&nuova=1') . '&r=' . time());
    } elseif (isset($_POST['elimina_azienda'])) {
        $err = $az->eliminaManuale($id);
        if (!$err) registra_log_audit($conn, "Tirocini: azienda inserita a mano eliminata", ["Azienda" => $id]);
        flash_set($err ?? 'Azienda tolta dall\'elenco.', $err ? 'danger' : 'warning');
        admin_redirect($url('esterni', '&f=' . $filtro) . '&r=' . time());
    } elseif (isset($_POST['salva_azienda'])) {
        $errNome = isset($_POST['nome']) ? $az->salvaAnagrafica($id, (string)$_POST['nome'], (string)($_POST['partita_iva'] ?? ''), (string)($_POST['codice_fiscale'] ?? '')) : null;
        $err = $errNome ?? $az->salvaScheda($id, (string)($_POST['indirizzo'] ?? ''), (string)($_POST['cap'] ?? ''), (string)($_POST['comune'] ?? ''), (string)($_POST['provincia'] ?? ''), (string)($_POST['sito'] ?? ''), !empty($_POST['pubblica']), (string)($_POST['settore'] ?? ''));
        if (!$err) registra_log_audit($conn, "Tirocini: azienda modificata", ["Azienda" => $id]);
        flash_set($err ?? 'Azienda salvata.', $err ? 'danger' : 'success');
        admin_redirect($url('esterni', '&f=' . $filtro . '&q=' . urlencode($cerca)) . '&r=' . time() . '#a' . $id);
    } elseif (isset($_POST['conferma'])) {
        $n = $az->confermaTrovati($id ?: null);
        registra_log_audit($conn, "Tirocini: indirizzi trovati sul web confermati", ["Quanti" => $n]);
        flash_set($n === 1 ? 'Indirizzo confermato: ora si pubblica.' : "$n indirizzi confermati: ora si pubblicano.");
        admin_redirect($url('esterni', '&f=' . $filtro) . '&r=' . time());
    } elseif (isset($_POST['scarta'])) {
        $az->scartaIndirizzo($id);
        flash_set('Indirizzo tolto.', 'warning');
        admin_redirect($url('esterni', '&f=' . $filtro) . '&r=' . time());
    } elseif (isset($_POST['aggiorna_lab'])) {
        $esiti = $lab->sincronizza();
        $testo = implode(' · ', array_map(fn($c, $e) => "$c: {$e['esito']}", array_keys($esiti), $esiti));
        registra_log_audit($conn, "Tirocini: laboratori aggiornati dalle API di Ateneo", ["Esito" => $testo]);
        flash_set($esiti ? "Laboratori aggiornati. $testo" : "Nessun dipartimento scelto: aggiungilo in Anagrafi › Strutture e aggiornamento.", $esiti ? 'success' : 'warning');
        admin_redirect($url('interni') . '&r=' . time());
    } elseif (isset($_POST['pubblica_lab'])) {
        $lab->impostaPubblica((int)$_POST['pubblica_lab'], ($_POST['valore'] ?? '') === '1');
        admin_redirect($url('interni') . '&r=' . time() . '#l' . (int)$_POST['pubblica_lab']);
    }
}

$cont = $az->conteggi();
$elenco = $tab === 'esterni' ? $az->elenco($filtro, $cerca) : [];
$labs = $tab === 'interni' ? $lab->tutti() : [];
$province = \App\Tirocini\Province::tutte();
$bozza = $_SESSION['tirocini_bozza'] ?? []; unset($_SESSION['tirocini_bozza']);
$apri_nuova = isset($_GET['nuova']);
?>
<style>
.tir-kpi { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin:12px 0; }
.tir-kpi a { display:block; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px 14px; text-decoration:none; color:inherit; }
.tir-kpi a.on { border-color:#8e0f1f; box-shadow:0 0 0 2px rgba(142,15,31,.15); }
.tir-kpi b { display:block; font-size:1.5rem; color:#8e0f1f; line-height:1.2; }
.tir-kpi span { font-size:.82rem; color:#64748b; }
.tir-riga { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; margin-bottom:10px; }
.tir-riga.off { background:#f8fafc; }
.tir-riga h3 { font-size:1rem; margin:0; }
.tir-riga .sub { font-size:.82rem; color:#64748b; }
</style>
<?php
echo pn_testata('Tirocini', 'fa-briefcase', "I posti dove si svolgono i tirocini curriculari: le aziende esterne con convenzione attiva (dal report del TSP di Cineca) e i laboratori interni dell'Ateneo. Si pubblica solo ciò che è completo.",
    '<a class="btn btn-outline-secondary btn-sm" href="../tirocini.php" target="_blank" rel="noopener"><i class="fa fa-list me-1" aria-hidden="true"></i>Pagina pubblica</a>');
echo pn_schede([
    'esterni' => ['href' => $url('esterni'), 'testo' => 'Aziende esterne', 'icona' => 'fa-building', 'numero' => $cont['senza_indirizzo'] + $cont['da_confermare'], 'avviso' => true, 'titolo' => 'Aziende da completare o confermare'],
    'interni' => ['href' => $url('interni'), 'testo' => 'Laboratori interni', 'icona' => 'fa-flask'],
], $tab, 'Sezioni dei tirocini');
?>

<?php if ($tab === 'esterni'): ?>
<section class="scu-sez">
    <div class="tir-kpi">
        <?php foreach ([['tutte', $cont['totale'], 'con convenzione attiva'], ['pubblicate', $cont['pubblicate'], 'pubblicate'], ['senza_indirizzo', $cont['senza_indirizzo'], 'senza indirizzo'],
                        ['da_confermare', $cont['da_confermare'], 'indirizzi da confermare'], ['non_pubbliche', $cont['non_pubbliche'], 'non pubbliche']] as [$k, $n, $et]): ?>
        <a href="<?php echo $h($url('esterni', '&f=' . $k)); ?>" class="<?php echo $filtro === $k ? 'on' : ''; ?>"><b><?php echo (int)$n; ?></b><span><?php echo $h($et); ?></span></a>
        <?php endforeach; ?>
    </div>

    <div class="mb-3">
        <button class="btn btn-danger btn-sm fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#nuovaAzienda" aria-expanded="<?php echo $apri_nuova ? 'true' : 'false'; ?>" aria-controls="nuovaAzienda"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi un'azienda a mano</button>
        <div class="collapse<?php echo $apri_nuova ? ' show' : ''; ?> mt-3" id="nuovaAzienda">
            <form method="POST" class="border rounded p-3 bg-white"><?php csrf_field(); ?>
                <h2 class="h6 fw-bold"><i class="fa fa-building-circle-check me-1 text-success" aria-hidden="true"></i>Nuova azienda con convenzione attiva</h2>
                <p class="small text-secondary mb-2">Per le aziende che non sono nel report del TSP. Servono il nome e, per pubblicarla, comune e provincia: il resto si può aggiungere dopo. Non si accettano doppioni (stessa partita IVA, codice fiscale o nome).</p>
                <div class="row g-2">
                    <div class="col-lg-6"><label class="form-label small mb-0" for="na-nome">Nome dell'azienda <span class="text-danger">*</span></label><input class="form-control form-control-sm" id="na-nome" name="nome" value="<?php echo $h($bozza['nome'] ?? ''); ?>" maxlength="255" required></div>
                    <div class="col-6 col-lg-3"><label class="form-label small mb-0" for="na-piva">Partita IVA</label><input class="form-control form-control-sm" id="na-piva" name="partita_iva" value="<?php echo $h($bozza['partita_iva'] ?? ''); ?>" maxlength="20"></div>
                    <div class="col-6 col-lg-3"><label class="form-label small mb-0" for="na-cf">Codice fiscale</label><input class="form-control form-control-sm" id="na-cf" name="codice_fiscale" value="<?php echo $h($bozza['codice_fiscale'] ?? ''); ?>" maxlength="20"></div>
                    <div class="col-lg-4"><label class="form-label small mb-0" for="na-ind">Indirizzo</label><input class="form-control form-control-sm" id="na-ind" name="indirizzo" value="<?php echo $h($bozza['indirizzo'] ?? ''); ?>" maxlength="255" placeholder="Via e numero civico"></div>
                    <div class="col-4 col-lg-1"><label class="form-label small mb-0" for="na-cap">CAP</label><input class="form-control form-control-sm" id="na-cap" name="cap" value="<?php echo $h($bozza['cap'] ?? ''); ?>" maxlength="10"></div>
                    <div class="col-8 col-lg-3"><label class="form-label small mb-0" for="na-com">Comune</label><input class="form-control form-control-sm" id="na-com" name="comune" value="<?php echo $h($bozza['comune'] ?? ''); ?>" maxlength="100"></div>
                    <div class="col-lg-4"><label class="form-label small mb-0" for="na-prov">Provincia</label><select class="form-select form-select-sm" id="na-prov" name="provincia"><option value="">—</option>
                        <?php foreach ($province as $sig => [$nomeP]): ?><option value="<?php echo $h($sig); ?>"<?php echo ($bozza['provincia'] ?? '') === $sig ? ' selected' : ''; ?>><?php echo $h($nomeP . ' (' . $sig . ')'); ?></option><?php endforeach; ?></select></div>
                    <div class="col-lg-6"><label class="form-label small mb-0" for="na-set">Settore (ATECO)</label><input class="form-control form-control-sm" id="na-set" name="settore" value="<?php echo $h($bozza['settore'] ?? ''); ?>" maxlength="255" placeholder="es. Consulenza ambientale"></div>
                    <div class="col-lg-6"><label class="form-label small mb-0" for="na-sito">Sito web</label><input class="form-control form-control-sm" id="na-sito" name="sito" value="<?php echo $h($bozza['sito'] ?? ''); ?>" maxlength="255" placeholder="facoltativo"></div>
                    <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="pubblica" value="1" id="na-pub" checked><label class="form-check-label small" for="na-pub">Pubblica nell'elenco</label></div>
                        <button class="btn btn-danger btn-sm fw-bold ms-auto" name="nuova_azienda" value="1" type="submit"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Aggiungi l'azienda</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6"><form method="POST" enctype="multipart/form-data" class="border rounded p-3 bg-white h-100"><?php csrf_field(); ?>
            <h2 class="h6 fw-bold"><i class="fa fa-file-excel me-1 text-success" aria-hidden="true"></i>Carica il report del TSP</h2>
            <p class="small text-secondary mb-2">Il file «Albo aziende» scaricato dal TSP (.xlsx). Entrano le aziende con la «x» in «Esiste convenzione attiva»; chi l'ha persa esce dall'elenco ma conserva l'indirizzo. Si può caricare anche il file «da completare» con gli indirizzi: stesse regole, riconosciuto dalla colonna «Codice azienda».</p>
            <div class="input-group"><input type="file" name="file" class="form-control form-control-sm" accept=".xlsx,.csv" required aria-label="File Excel o CSV"><button class="btn btn-danger btn-sm" name="importa" value="1" type="submit">Carica</button></div>
        </form></div>
        <div class="col-lg-6"><div class="border rounded p-3 bg-white h-100">
            <h2 class="h6 fw-bold"><i class="fa fa-pen-to-square me-1 text-secondary" aria-hidden="true"></i>Indirizzi</h2>
            <p class="small text-secondary mb-2">Scrivili qui sotto, riga per riga, oppure scarica l'elenco delle aziende senza indirizzo, completalo in Excel (indirizzo, CAP, comune, provincia, sito web) e ricaricalo a sinistra.</p>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo $h($url('esterni', '&da_completare=1')); ?>"><i class="fa fa-download me-1" aria-hidden="true"></i>Elenco da completare (<?php echo (int)$cont['senza_indirizzo']; ?>)</a>
            <?php if ($cont['da_confermare']): ?>
            <form method="POST" class="d-inline"><?php csrf_field(); ?><button class="btn btn-outline-success btn-sm" name="conferma" value="1" type="submit" data-confirm="Confermare tutti gli indirizzi trovati sul web? Da quel momento le aziende si pubblicano.">Conferma i <?php echo (int)$cont['da_confermare']; ?> trovati sul web</button></form>
            <?php endif; ?>
        </div></div>
    </div>

    <form method="GET" class="d-flex gap-2 mb-3" role="search"><input type="hidden" name="tab" value="esterni"><input type="hidden" name="f" value="<?php echo $h($filtro); ?>">
        <input class="form-control form-control-sm" name="q" value="<?php echo $h($cerca); ?>" placeholder="Cerca per nome, comune, partita IVA" aria-label="Cerca"><button class="btn btn-outline-secondary btn-sm" type="submit">Cerca</button></form>

    <?php if (!$elenco): ?><p class="text-muted small"><?php echo $cont['totale'] ? 'Nessuna azienda in questo elenco.' : 'Carica il report del TSP per cominciare.'; ?></p><?php endif; ?>
    <?php foreach ($elenco as $a):
        $web = $a['origine'] === 'web' && !(int)$a['verificato'];
        $pub = (int)$a['pubblica'] && $a['comune'] !== '' && !$web;
        $manuale = $az->eManuale($a); ?>
    <div class="tir-riga<?php echo $pub ? '' : ' off'; ?>" id="a<?php echo (int)$a['id']; ?>">
        <form method="POST"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                <div><h3><?php echo $h($a['nome']); ?><?php if ($manuale): ?> <span class="badge text-bg-info fw-normal align-middle">Inserita a mano</span><?php endif; ?></h3><div class="sub"><?php echo $manuale ? 'Codice ' : 'TSP '; ?><?php echo $h($a['cod_tsp']); ?> · <?php echo $h($a['partita_iva'] !== '' ? 'P.IVA ' . $a['partita_iva'] : ($a['codice_fiscale'] !== '' ? 'CF ' . $a['codice_fiscale'] : 'senza P.IVA')); ?></div></div>
                <div>
                    <?php if ($pub): ?><span class="badge text-bg-success">Pubblicata</span>
                    <?php elseif ($web): ?><span class="badge text-bg-warning">Indirizzo da confermare</span>
                    <?php elseif ($a['comune'] === ''): ?><span class="badge text-bg-secondary">Senza indirizzo</span>
                    <?php else: ?><span class="badge text-bg-secondary">Non pubblica</span><?php endif; ?>
                </div>
            </div>
            <div class="row g-2">
                <?php if ($manuale): ?>
                <div class="col-lg-6"><label class="form-label small mb-0">Nome dell'azienda</label><input class="form-control form-control-sm" name="nome" value="<?php echo $h($a['nome']); ?>" maxlength="255" required></div>
                <div class="col-6 col-lg-3"><label class="form-label small mb-0">Partita IVA</label><input class="form-control form-control-sm" name="partita_iva" value="<?php echo $h($a['partita_iva']); ?>" maxlength="20"></div>
                <div class="col-6 col-lg-3"><label class="form-label small mb-0">Codice fiscale</label><input class="form-control form-control-sm" name="codice_fiscale" value="<?php echo $h($a['codice_fiscale']); ?>" maxlength="20"></div>
                <?php endif; ?>
                <div class="col-lg-4"><label class="form-label small mb-0">Indirizzo</label><input class="form-control form-control-sm" name="indirizzo" value="<?php echo $h($a['indirizzo']); ?>" maxlength="255" placeholder="Via e numero civico"></div>
                <div class="col-4 col-lg-1"><label class="form-label small mb-0">CAP</label><input class="form-control form-control-sm" name="cap" value="<?php echo $h($a['cap']); ?>" maxlength="10"></div>
                <div class="col-8 col-lg-3"><label class="form-label small mb-0">Comune</label><input class="form-control form-control-sm" name="comune" value="<?php echo $h($a['comune']); ?>" maxlength="100"></div>
                <div class="col-lg-2"><label class="form-label small mb-0">Provincia</label><select class="form-select form-select-sm" name="provincia"><option value="">—</option>
                    <?php foreach ($province as $sig => [$nomeP]): ?><option value="<?php echo $h($sig); ?>"<?php echo $a['provincia'] === $sig ? ' selected' : ''; ?>><?php echo $h($nomeP . ' (' . $sig . ')'); ?></option><?php endforeach; ?></select></div>
                <div class="col-lg-2"><label class="form-label small mb-0">Sito web</label><input class="form-control form-control-sm" name="sito" value="<?php echo $h($a['sito']); ?>" maxlength="255" placeholder="facoltativo"></div>
                <div class="col-lg-8"><label class="form-label small mb-0">Settore (ATECO)</label><input class="form-control form-control-sm" name="settore" value="<?php echo $h($a['settore']); ?>" maxlength="255"></div>
                <div class="col-lg-4 d-flex align-items-end gap-2">
                    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="pubblica" value="1" id="p<?php echo (int)$a['id']; ?>"<?php echo (int)$a['pubblica'] ? ' checked' : ''; ?>><label class="form-check-label small" for="p<?php echo (int)$a['id']; ?>">Pubblica nell'elenco</label></div>
                    <?php if ($manuale): ?><button class="btn btn-outline-danger btn-sm ms-auto" name="elimina_azienda" value="1" type="submit" data-confirm="Togliere questa azienda dall'elenco? Si può solo per quelle inserite a mano.">Elimina</button><?php endif; ?>
                    <button class="btn btn-danger btn-sm<?php echo $manuale ? '' : ' ms-auto'; ?>" name="salva_azienda" value="1" type="submit">Salva</button>
                </div>
            </div>
            <?php if ($web): ?><div class="small mt-2 text-warning-emphasis"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Indirizzo trovato sul web, da controllare.
                <button class="btn btn-outline-success btn-sm py-0 ms-1" name="conferma" value="1" type="submit">È giusto: conferma</button>
                <button class="btn btn-outline-danger btn-sm py-0" name="scarta" value="1" type="submit" data-confirm="Togliere l'indirizzo trovato sul web?">Toglilo</button></div><?php endif; ?>
        </form>
    </div>
    <?php endforeach; ?>
</section>
<?php else: ?>
<section class="scu-sez">
    <form method="POST" class="border rounded p-3 bg-white mb-3"><?php csrf_field(); ?>
        <p class="small text-secondary mb-2">I laboratori si scaricano dalle API pubbliche di Ateneo per i dipartimenti scelti in <a href="anagrafe_personale.php?vista=strutture">Anagrafi › Strutture e aggiornamento</a>; si aggiornano con l'anagrafe (ogni settimana) o da qui. Qui si può nascondere un laboratorio dall'elenco pubblico.</p>
        <button class="btn btn-outline-secondary btn-sm" name="aggiorna_lab" value="1" type="submit"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Aggiorna ora dalle API di Ateneo</button>
    </form>
    <?php if (!$labs): ?><p class="text-muted small">Nessun laboratorio scaricato: premi «Aggiorna ora» (serve almeno un dipartimento nell'anagrafe).</p><?php endif; ?>
    <?php foreach ($labs as $l): ?>
    <div class="tir-riga<?php echo (int)$l['pubblica'] ? '' : ' off'; ?>" id="l<?php echo (int)$l['id']; ?>">
        <form method="POST" class="d-flex flex-wrap justify-content-between gap-2 align-items-start"><?php csrf_field(); ?>
            <div><h3><?php echo $h($l['nome'] . ($l['sigla'] !== '' ? ' (' . $l['sigla'] . ')' : '')); ?></h3>
                <div class="sub"><?php echo $h(implode(' · ', array_filter([$l['dipartimento'], $l['area'], $l['direttore'] !== '' ? 'Responsabile: ' . $l['direttore'] : '', $l['ambiti']]))); ?></div></div>
            <input type="hidden" name="pubblica_lab" value="<?php echo (int)$l['id']; ?>">
            <button class="btn btn-sm <?php echo (int)$l['pubblica'] ? 'btn-outline-secondary' : 'btn-outline-success'; ?>" name="valore" value="<?php echo (int)$l['pubblica'] ? '0' : '1'; ?>" type="submit"><?php echo (int)$l['pubblica'] ? 'Nascondi' : 'Mostra'; ?></button>
        </form>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php require_once 'admin_footer.php'; ?>
