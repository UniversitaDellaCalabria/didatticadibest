<?php
// =========================================================================
// Le mie conferme: le richieste di conferma degli attestati degli studenti (frequenza alle lezioni, esame sostenuto) che aspettano il docente, tutte
// in una pagina. Si possono confermare più richieste insieme, con una sola dichiarazione; per non confermare (serve il motivo) si apre la richiesta.
// Logica in App\Didattica\ConfermeInBlocco. Si arriva da area_personale.php e dal link «Conferma tutte» delle richieste di conferma.
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$blocco_mc = \App\Core\App::per($conn)->get(\App\Didattica\ConfermeInBlocco::class);

$esiti_mc = [];
$errore_mc = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($user_info['id'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $scelte_mc = array_values(array_filter(array_map('intval', (array) ($_POST['scelte'] ?? []))));
    if (!$scelte_mc) {
        $errore_mc = 'Seleziona almeno una richiesta da confermare.';
    } elseif (empty($_POST['dichiaro'])) {
        $errore_mc = 'Spunta la dichiarazione per confermare.';
    } else {
        $esiti_mc = $blocco_mc->conferma($user_info, $scelte_mc);
    }
}

$richieste_mc = [];
try {
    $richieste_mc = $blocco_mc->inAttesa($user_info ?? null);
} catch (\Throwable $e_mc) { error_log('[Mie conferme] non disponibili: ' . $e_mc->getMessage()); }
$n_mc = count($richieste_mc);

$page_cfg['titolo'] = 'Le mie conferme';
require_once 'header.php';
echo ui_tema_script();
?>
<style>
    .mc-riga { border-top: 1px solid #e2e8f0; padding: .6rem 0; }
</style>
<div class="container my-4" style="max-width: 900px;">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <?php echo ui_intestazione('Le mie conferme', 'area_personale.php', 'Area personale', $n_mc > 0 ? $n_mc . ($n_mc === 1 ? ' richiesta aspetta la tua conferma' : ' richieste aspettano la tua conferma') : ''); ?>
        <div class="d-print-none"><?php echo ui_tema_pulsante(); ?></div>
    </div>
    <?php if ($esiti_mc): $ok_mc = count(array_filter($esiti_mc, static fn ($e): bool => $e['ok'])); ?>
        <div class="card border-0 shadow-sm mb-3" style="border-radius: 12px;"><div class="card-body">
            <h2 class="h6 fw-bold mb-2"><?php echo $ok_mc; ?> su <?php echo count($esiti_mc); ?> conferme registrate</h2>
            <?php foreach ($esiti_mc as $e_mc): ?>
                <div class="mc-riga small"><i class="fa <?php echo $e_mc['ok'] ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger'; ?> me-1" aria-hidden="true"></i><strong><?php echo $h($e_mc['titolo'] !== '' ? $e_mc['titolo'] : 'Richiesta ' . $e_mc['id']); ?></strong><span class="d-block text-secondary"><?php echo $h($e_mc['messaggio']); ?></span></div>
            <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
    <?php if ($errore_mc !== ''): ?><div class="alert alert-warning py-2"><?php echo $h($errore_mc); ?></div><?php endif; ?>
    <?php if (!$richieste_mc): ?>
        <?php echo ui_vuoto('fa-circle-check', 'Nessuna conferma da dare', 'Quando uno studente indica te come docente di una lezione o di un esame, la richiesta compare qui e ti arriva anche per email.', 'area_personale.php', 'Torna all\'area personale', '#0369a1'); ?>
    <?php else: ?>
        <form method="post" action="mie_conferme.php">
            <?php csrf_field(); ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2 d-print-none">
                <label class="small fw-semibold mb-0"><input type="checkbox" class="form-check-input me-1" id="mc-tutti"> Seleziona tutte</label>
            </div>
            <div class="card border-0 shadow-sm" style="border-radius: 12px;"><div class="card-body">
                <?php foreach ($richieste_mc as $r_mc): $col_mc = $r_mc['righe'] ? array_keys($r_mc['righe'][0]) : []; ?>
                    <div class="mc-riga">
                        <div class="d-flex align-items-start gap-2">
                            <input type="checkbox" class="form-check-input mt-1 mc-sel" name="scelte[]" value="<?php echo (int) $r_mc['id']; ?>" aria-label="Seleziona <?php echo $h($r_mc['modulo_titolo'] . ' ' . $r_mc['studente_cognome']); ?>">
                            <div class="flex-grow-1">
                                <span class="fw-semibold"><?php echo $h($r_mc['modulo_titolo']); ?> · <?php echo $h(trim($r_mc['studente_nome'] . ' ' . $r_mc['studente_cognome'])); ?></span><?php if ((int) $r_mc['gg'] >= \App\Portale\DaFarePersona::GIORNI_FERMA): ?> <span class="badge text-bg-danger"><?php echo (int) $r_mc['gg']; ?> giorni</span><?php endif; ?>
                                <span class="d-block small text-secondary">pratica <?php echo $h($r_mc['codice']); ?></span>
                                <?php if ($r_mc['righe']): ?>
                                    <details class="small mt-1"><summary class="text-secondary">Cosa devi confermare</summary>
                                        <div class="table-responsive mt-1"><table class="table table-sm table-bordered mb-0">
                                            <thead class="table-light"><tr><?php foreach ($col_mc as $n_c): ?><th><?php echo $h($n_c); ?></th><?php endforeach; ?></tr></thead>
                                            <tbody><?php foreach ($r_mc['righe'] as $rg_mc): ?><tr><?php foreach ($col_mc as $n_c): $v_mc = (string) ($rg_mc[$n_c] ?? ''); ?><td><?php echo $h(preg_match('/^\d{4}-\d{2}-\d{2}$/', $v_mc) ? date('d/m/Y', (int) strtotime($v_mc)) : $v_mc); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                                        </table></div>
                                    </details>
                                <?php endif; ?>
                            </div>
                            <a class="btn btn-sm btn-outline-secondary text-nowrap" href="conferma_attestato.php?t=<?php echo $h(urlencode((string) $r_mc['token'])); ?>">Apri / non confermo</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div></div>
            <div class="card border-0 shadow-sm mt-3 d-print-none" style="border-radius: 12px; border-left: 5px solid #15803d !important;"><div class="card-body">
                <label class="form-check small mb-3"><input class="form-check-input" type="checkbox" name="dichiaro" value="1"> Confermo che quanto indicato nelle richieste selezionate corrisponde al vero. Sono consapevole delle responsabilità previste per le dichiarazioni non veritiere (artt. 46, 47 e 76 del D.P.R. 445/2000).</label>
                <button type="submit" class="btn btn-success fw-bold"><i class="fa fa-check me-1" aria-hidden="true"></i>Confermo le selezionate</button>
                <div class="form-text mt-2">Per ogni conferma resta registrato chi l'ha data e quando. Se una richiesta non torna, aprila con «Apri / non confermo» e scrivi il motivo: lo studente lo riceve per email.</div>
            </div></div>
        </form>
        <script>
        (function () {
            var tutti = document.getElementById('mc-tutti');
            if (tutti) tutti.addEventListener('change', function () { document.querySelectorAll('.mc-sel').forEach(function (c) { c.checked = tutti.checked; }); });
        })();
        </script>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
