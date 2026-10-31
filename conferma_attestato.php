<?php
// conferma_attestato.php - Il docente conferma (o non conferma) nel portale le righe di un attestato dello studente (frequenza alle lezioni, esame
// sostenuto): link personale dall'email, accesso con le credenziali di Ateneo (la stessa email a cui è arrivato il link). Non è una firma digitale:
// si registra chi ha confermato, quando e con quale accesso. Se tutti i docenti confermano la pratica prosegue; se uno non conferma torna allo studente
// con il motivo. Logica in src/Didattica/ServizioConfermeDocenti.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$svc = \App\Core\App::per($conn)->get(\App\Didattica\ServizioConfermeDocenti::class);
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$c = $svc->perToken($tok);
$p = $c ? pratica($conn, (int)$c['pratica_id']) : null;
$io = $c && $p && $svc->puoConfermare($c, $user_info);
$in_attesa = $c && $c['stato'] === 'attesa' && $p && in_array($p['stato'], ['inviata', 'in_lavorazione'], true);

if ($c && $p && $io && $in_attesa && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = null; $fatto = '';
    if (isset($_POST['non_confermo'])) {
        $err = $svc->rifiuta($c, (string)($_POST['motivo'] ?? ''));
        $fatto = "Hai indicato che non confermi: lo studente è stato avvisato e potrà correggere la richiesta.";
        if (!$err) registra_log_audit($conn, "Pratica: conferma del docente rifiutata", ["Pratica" => $p['codice']]);
    } elseif (isset($_POST['confermo'])) {
        if (empty($_POST['dichiaro'])) $err = "Spunta la dichiarazione per confermare.";
        else $err = $svc->conferma($c);
        $fatto = "Confermato: grazie.";
        if (!$err) {
            registra_log_audit($conn, "Pratica: conferma del docente", ["Pratica" => $p['codice']]);
            // Con tutte le conferme la pratica passa al passo successivo dell'iter (la conferma è il passo «passo»: il successivo ha indice «passo»)
            if ($svc->tutteConfermate((int)$p['id'])) {
                [$e2] = \App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->trasmettiAlPasso((int)$p['id'], (int)$c['passo'], 0, 'Conferma dei docenti');
                if ($e2) \App\Core\App::per($conn)->get(\App\Didattica\StoricoPratica::class)->evento((int)$p['id'], 'messaggio', 'ufficio', 0, null, 'Dopo le conferme: ' . $e2, null, null, true);
            }
        }
    }
    flash_set($err ?? $fatto, $err ? 'danger' : 'success');
    header('Location: conferma_attestato.php?t=' . urlencode($tok) . '&r=' . time()); exit;
}

$page_cfg['titolo'] = 'Conferma della richiesta';
require_once 'header.php';
$altre = $user_info ? array_values(array_filter($svc->inAttesaPerEmail((string)($user_info['email'] ?? '')), fn($x) => !$c || (int)$x['id'] !== (int)$c['id'])) : [];
?>
<div class="container my-4" style="max-width: 900px;">
    <?php echo flash_html(); ?>
    <?php if (!$c || !$p): ?>
        <h1 class="fw-bold h3">Richiesta non disponibile</h1><p>Il link non è valido o la richiesta è stata annullata.</p>
    <?php elseif (!$io): ?>
        <h1 class="fw-bold h3">Conferma della richiesta</h1>
        <div class="alert alert-warning">Questa pagina è per <strong><?php echo $h($svc->nomeVisibile((string)$c['nome'])); ?></strong>: accedi con le sue credenziali (Unical ID, SPID o CIE).</div>
        <a class="btn btn-outline-secondary" href="esci.php">Esci</a>
    <?php else:
        $righe = $svc->righeDelDocente($p, $c);
        $col = $righe ? array_keys($righe[0]) : []; ?>
        <h1 class="fw-bold h3 mb-1"><?php echo $h($p['modulo_titolo']); ?></h1>
        <p class="text-secondary mb-3">Richiesto da <strong><?php echo $h(trim($p['nome'] . ' ' . $p['cognome'])); ?></strong><?php echo $p['matricola'] !== '' ? ' · matricola ' . $h($p['matricola']) : ''; ?> · pratica <?php echo $h($p['codice']); ?>.</p>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h6 fw-bold mb-2">Cosa ti chiediamo di confermare</h2>
            <p class="small mb-2"><?php $tit_m = mb_strtolower((string)$p['modulo_titolo']); echo str_contains($tit_m, 'attestato') ? 'Lo studente indica di aver ' . (str_contains($tit_m, 'esame') ? 'sostenuto l\'esame' : 'seguito le lezioni') . ' qui sotto con te come docente.' : 'Lo studente ti ha indicato come docente nella richiesta qui sotto: controlla i dati.'; ?><?php echo count($righe) > 1 ? ' Sono ' . count($righe) . ' righe.' : ''; ?></p>
            <?php if ($righe && count($righe) === 1 && count($col) > 3): ?>
            <dl class="row small mb-0"><?php foreach ($col as $n): $v_c = (string)($righe[0][$n] ?? ''); ?>
                <dt class="col-sm-4 border-top pt-2"><?php echo $h($n); ?></dt><dd class="col-sm-8 border-top pt-2"><?php echo $h(preg_match('/^\d{4}-\d{2}-\d{2}$/', $v_c) ? date('d/m/Y', strtotime($v_c)) : $v_c); ?></dd>
            <?php endforeach; ?></dl>
            <?php elseif ($righe): ?>
            <div class="table-responsive"><table class="table table-sm table-bordered small mb-0">
                <thead class="table-light"><tr><?php foreach ($col as $n): ?><th><?php echo $h($n); ?></th><?php endforeach; ?></tr></thead>
                <tbody><?php foreach ($righe as $r): ?><tr><?php foreach ($col as $n): ?><td><?php echo $h(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r[$n] ?? '')) ? date('d/m/Y', strtotime($r[$n])) : ($r[$n] ?? '')); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
            </table></div>
            <?php else: ?><p class="text-secondary small mb-0">Nessuna riga trovata.</p><?php endif; ?>
        </div></div>
        <?php if ($in_attesa): ?>
        <div class="row g-3">
            <div class="col-md-6"><form method="POST" class="card border-0 shadow-sm h-100" style="border-left:5px solid #15803d !important;"><div class="card-body">
                <?php csrf_field(); ?>
                <h2 class="h6 fw-bold"><i class="fa fa-circle-check me-1 text-success" aria-hidden="true"></i>Confermo</h2>
                <label class="form-check small mb-3"><input class="form-check-input" type="checkbox" name="dichiaro" value="1" required> Confermo che quanto indicato corrisponde al vero. Sono consapevole delle responsabilità previste per le dichiarazioni non veritiere (artt. 46, 47 e 76 D.P.R. 445/2000).</label>
                <button type="submit" name="confermo" value="1" class="btn btn-success fw-bold"><i class="fa fa-check me-1" aria-hidden="true"></i>Confermo</button>
                <div class="form-text mt-2">Resta registrato chi ha confermato, quando e con quale accesso (<?php echo $h(\App\Tutorato\Costanti::METODI_ACCESSO[($_SESSION['auth_meta']['metodo'] ?? 'ateneo')] ?? 'credenziali di Ateneo'); ?>).</div>
            </div></form></div>
            <div class="col-md-6"><form method="POST" class="card border-0 shadow-sm h-100"><div class="card-body">
                <?php csrf_field(); ?>
                <h2 class="h6 fw-bold"><i class="fa fa-circle-xmark me-1 text-danger" aria-hidden="true"></i>Non confermo</h2>
                <label class="form-label small mb-1" for="cfM">Scrivi cosa non torna: lo studente lo riceve per email e può correggere la richiesta.</label>
                <textarea class="form-control form-control-sm mb-2" id="cfM" name="motivo" rows="3" maxlength="500" required></textarea>
                <button type="submit" name="non_confermo" value="1" class="btn btn-outline-danger btn-sm fw-bold" data-confirm="Non confermare? La richiesta torna allo studente.">Non confermo</button>
            </div></form></div>
        </div>
        <?php elseif ($c['stato'] === 'confermata'): ?>
            <div class="alert alert-success">Hai già confermato<?php echo $c['esito_il'] ? ' il ' . date('d/m/Y H:i', strtotime($c['esito_il'])) : ''; ?>: grazie.</div>
        <?php elseif ($c['stato'] === 'rifiutata'): ?>
            <div class="alert alert-secondary">Hai indicato che non confermi: <?php echo $h($c['motivo']); ?></div>
        <?php else: ?>
            <div class="alert alert-secondary">Questa richiesta di conferma non è più attiva (la pratica è stata modificata o annullata).</div>
        <?php endif; ?>
        <?php if ($altre): ?>
        <div class="card border-0 shadow-sm mt-3"><div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h2 class="h6 fw-bold mb-0">Altre richieste che aspettano la tua conferma</h2><a class="btn btn-sm btn-outline-success fw-bold ms-auto" href="mie_conferme.php">Confermale tutte insieme</a></div>
            <ul class="mb-0 small"><?php foreach ($altre as $a): ?><li><a href="conferma_attestato.php?t=<?php echo $h(urlencode($a['token'])); ?>"><?php echo $h($a['modulo_titolo']); ?> · <?php echo $h(trim($a['studente_nome'] . ' ' . $a['studente_cognome'])); ?></a> <span class="text-secondary">(dal <?php echo date('d/m/Y', strtotime($a['richiesta_il'])); ?>)</span></li><?php endforeach; ?></ul>
        </div></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
