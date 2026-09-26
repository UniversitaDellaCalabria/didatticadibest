<?php
// elenco_studenti.php - Il docente che ha iscritto la scuola a un progetto inserisce l'elenco degli studenti
// (per gli attestati di partecipazione): righe scritte o incollate da Excel, oppure file .xlsx/.csv dal modello.
// Dopo l'invio degli attestati l'elenco non è più modificabile dal docente (resta modificabile dall'admin).
require_once 'config.php';
require_once 'functions.php';
sync_sso_user($conn);

$code = trim((string)($_GET['code'] ?? $_POST['code'] ?? ''));
if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{4,50}$/', $code)) { http_response_code(400); die("Codice non valido."); }
if (empty($_SESSION['utente_id'])) { header('Location: saml_login.php?redirect=' . urlencode('elenco_studenti.php?code=' . $code)); exit; }

$r = $conn->query("SELECT id FROM prenotazioni WHERE codice_prenotazione = '" . $conn->real_escape_string($code) . "' LIMIT 1");
$pr_id = ($r && $row = $r->fetch_assoc()) ? (int)$row['id'] : 0;
$p = $pr_id ? prenotazione_per_attestati($conn, $pr_id) : null;
if (!$p || !puo_vedere_prenotazione($p)) { http_response_code(403); die("Accesso negato."); }
if (($p['evento_tipo'] ?? '') !== 'progetto' || (int)($p['per_scuole'] ?? 1) !== 1 || (int)($p['attestati'] ?? 0) !== 1) {
    die("Questo progetto non prevede l'elenco degli studenti.");
}
if (($p['stato'] ?? 'confermata') !== 'confermata') die("L'elenco degli studenti si compila quando l'iscrizione è confermata.");

$dett = get_dettagli_progetti($conn, [(int)$p['evento_id']])[(int)$p['evento_id']] ?? null;
$max = max_partecipanti_prenotazione($p, $dett);
$bloccato = !empty($p['attestato_inviato']);
$url_self = 'elenco_studenti.php?code=' . urlencode($code);

// Scarica il modello (con i nomi già inseriti)
if (isset($_GET['modello'])) {
    invia_modello_elenco(array_map(fn($x) => ['cognome' => $x['cognome'], 'nome' => $x['nome']], get_partecipanti_prenotazione($conn, $pr_id)), 'elenco_studenti_' . $code);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloccato) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $testo = null; $errore = null;
    if (isset($_POST['carica_file']) && !empty($_FILES['file_elenco']['name'])) $testo = testo_da_file_elenco($_FILES['file_elenco'], $errore);
    elseif (isset($_POST['salva_elenco'])) $testo = (string)($_POST['elenco'] ?? '');
    if ($testo !== null) {
        $righe = leggi_elenco_partecipanti($testo);
        if (count($righe) > $max) $errore = "Hai inserito " . count($righe) . " nomi, ma l'iscrizione è per $max studenti. Correggi l'elenco (o chiedi alla segreteria di aggiornare il numero).";
        else {
            salva_elenco_partecipanti($conn, $pr_id, $righe);
            flash_set(count($righe) ? "Elenco salvato: " . count($righe) . " studenti." : "Elenco svuotato.");
            header("Location: $url_self"); exit;
        }
    }
    if ($errore) flash_set($errore, 'danger');
    header("Location: $url_self"); exit;
}

$studenti = get_partecipanti_prenotazione($conn, $pr_id);
$testo_attuale = implode("\n", array_map(fn($s) => $s['cognome'] . ($s['nome'] !== '' ? ';' . $s['nome'] : ''), $studenti));
$col = colore_valido($p['colore_primario'] ?? '', '#0056B3');

$page_cfg['titolo'] = "Elenco studenti";
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 980px;">
    <a href="area_personale.php" class="fw-bold text-decoration-none d-inline-block mb-3"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Area personale</a>
    <?php echo flash_html(); ?>

    <div class="card border-0 shadow-sm mb-4" style="border-top: 4px solid <?php echo $col; ?> !important; border-radius: 12px;">
        <div class="card-body p-4">
            <div class="small text-uppercase fw-bold text-secondary mb-1" style="letter-spacing: .05em;">Elenco degli studenti per gli attestati</div>
            <h1 class="fw-bold fs-3 mb-2"><?php echo h($p['evento_titolo']); ?></h1>
            <div class="d-flex flex-wrap gap-3 small text-secondary fw-semibold">
                <?php if (!empty($p['nome_turno']) && !in_array($p['nome_turno'], ['Iscrizione scuole', 'Iscrizioni'], true)): ?><span><i class="fa fa-clone me-1" aria-hidden="true"></i><?php echo h($p['nome_turno']); ?></span><?php endif; ?>
                <span><i class="fa fa-calendar-days me-1" aria-hidden="true"></i><?php echo h(periodo_progetto($p)); ?></span>
                <span><i class="fa fa-user-tie me-1" aria-hidden="true"></i><?php echo h($p['nome'] . ' ' . $p['cognome']); ?></span>
                <span><i class="fa fa-hashtag me-1" aria-hidden="true"></i><?php echo h($p['codice_prenotazione']); ?></span>
            </div>
            <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
                <span class="badge fs-6 <?php echo count($studenti) === 0 ? 'bg-warning text-dark' : 'bg-success'; ?>"><?php echo count($studenti); ?> / <?php echo $max; ?> studenti</span>
                <?php if (!empty($p['data_fine'])): ?><span class="small text-secondary">Gli attestati partono dopo la fine del progetto (<?php echo date('d/m/Y', strtotime($p['data_fine'])); ?>), per le classi di cui è stata registrata la presenza.</span><?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($bloccato): ?>
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-3">
            <i class="fa fa-graduation-cap fs-3" aria-hidden="true"></i>
            <div class="flex-grow-1"><strong>Gli attestati sono stati inviati.</strong> L'elenco non è più modificabile da qui: per correzioni scrivi alla segreteria dall'Area personale (Assistenza).</div>
            <a href="attestati_gruppo.php?code=<?php echo urlencode($code); ?>" target="_blank" class="btn btn-success fw-bold"><i class="fa fa-print me-1" aria-hidden="true"></i>Apri gli attestati</a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100" style="border-radius: 12px;">
                    <div class="card-body p-4">
                        <h2 class="fs-5 fw-bold mb-2"><i class="fa fa-keyboard me-1" aria-hidden="true"></i>Scrivi o incolla l'elenco</h2>
                        <p class="small text-secondary">Uno studente per riga, <strong>Cognome;Nome</strong>. Puoi anche selezionare in Excel le due colonne Cognome e Nome, copiarle e incollarle qui.</p>
                        <form method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="code" value="<?php echo h($code); ?>">
                            <label for="elencoTesto" class="visually-hidden">Elenco degli studenti</label>
                            <textarea name="elenco" id="elencoTesto" class="form-control font-monospace" rows="14" placeholder="Rossi;Mario&#10;Bianchi;Giulia&#10;De Luca;Anna Maria"><?php echo h($testo_attuale); ?></textarea>
                            <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
                                <span class="small text-secondary" id="elencoConta" aria-live="polite"></span>
                                <button type="submit" name="salva_elenco" value="1" class="btn fw-bold px-4" style="background: <?php echo $col; ?>; color: <?php echo colore_testo_su($col); ?>;"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva l'elenco</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
                    <div class="card-body p-4">
                        <h2 class="fs-5 fw-bold mb-2"><i class="fa fa-file-excel me-1 text-success" aria-hidden="true"></i>Usa il modello Excel</h2>
                        <ol class="small text-secondary ps-3 mb-3">
                            <li>Scarica il modello e compila le colonne Cognome e Nome.</li>
                            <li>Salvalo e caricalo qui sotto: l'elenco attuale viene sostituito.</li>
                        </ol>
                        <a href="<?php echo h($url_self); ?>&modello=1" class="btn btn-outline-success fw-bold w-100 mb-3"><i class="fa fa-download me-1" aria-hidden="true"></i>Scarica il modello</a>
                        <form method="POST" enctype="multipart/form-data">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="code" value="<?php echo h($code); ?>">
                            <label for="fileElenco" class="form-label small fw-bold">Carica il file compilato (.xlsx o .csv)</label>
                            <input type="file" name="file_elenco" id="fileElenco" class="form-control form-control-sm mb-2" accept=".xlsx,.csv,.txt" required>
                            <button type="submit" name="carica_file" value="1" class="btn btn-success fw-bold w-100" onclick="return confirm('L\'elenco attuale verrà sostituito da quello del file. Continuare?');"><i class="fa fa-upload me-1" aria-hidden="true"></i>Carica e sostituisci</button>
                        </form>
                    </div>
                </div>
                <div class="alert alert-light border small mb-0">
                    <i class="fa fa-shield-halved me-1" aria-hidden="true"></i>
                    I nomi degli studenti servono solo a generare gli attestati di partecipazione e sono visibili a te e alla segreteria del dipartimento. Trascorsi <?php echo (int)MESI_CONSERVAZIONE_STUDENTI; ?> mesi dalla fine del progetto vengono ridotti alle iniziali.
                </div>
            </div>
        </div>
        <script>
        (function () {
            var ta = document.getElementById('elencoTesto'), out = document.getElementById('elencoConta'), max = <?php echo (int)$max; ?>;
            function conta() {
                var n = ta.value.split(/\r?\n/).filter(function (r) { return r.trim() !== ''; }).length;
                out.textContent = n + ' / ' + max + ' studenti' + (n > max ? ' — troppi nomi' : '');
                out.className = 'small fw-semibold ' + (n > max ? 'text-danger' : 'text-secondary');
            }
            ta.addEventListener('input', conta); conta();
        })();
        </script>
    <?php endif; ?>

    <?php if ($studenti): ?>
        <div class="card border-0 shadow-sm mt-4" style="border-radius: 12px;">
            <div class="card-body p-4">
                <h2 class="fs-5 fw-bold mb-3">Studenti in elenco</h2>
                <ol class="mb-0 row row-cols-1 row-cols-md-2 g-1 ps-4">
                    <?php foreach ($studenti as $s): ?>
                        <li class="col"><?php echo h(nome_partecipante($s)); ?><?php if (!empty($s['escluso'])): ?> <span class="badge bg-secondary">senza attestato</span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
