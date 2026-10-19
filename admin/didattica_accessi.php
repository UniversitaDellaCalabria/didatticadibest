<?php
// didattica_accessi.php - Accesso ai verbali (vista della scheda Sedute e verbali, ?accessi=1): le password per la pagina pubblica dei verbali
// (verbali.php), con i consigli che possono vedere e la scadenza, e il registro di chi è entrato. Solo Ufficio didattico. Azioni in didattica_sedute.php.
if (!defined('DIDATTICA_PANNELLO') || !$puo_didattica_tutto) { http_response_code(403); exit('Accesso negato.'); }

$rep_acc = \App\Core\App::per($conn)->get(\App\Didattica\AccessoVerbaliRepository::class);
$accessi = $rep_acc->elenco();
$registro = $rep_acc->registro(60);
$nuova = $_SESSION['verbali_nuova_password'] ?? null; unset($_SESSION['verbali_nuova_password']);
$mod_acc = (int)($_GET['modifica'] ?? 0);
$url_pubblico = rtrim((string)\App\Core\App::get(\App\Core\Sito::class)->urlBase(), '/') . '/verbali.php';
$n_pubblici = count(\App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->pubblici());
$nome_consigli = fn(array $a) => in_array('*', (array)json_decode((string)$a['ambiti_json'], true), true) ? 'tutti i consigli'
    : implode(', ', array_map(fn($i) => mb_strimwidth($consigli[(int)$i]['nome'] ?? '—', 0, 60, '…'), (array)json_decode((string)$a['ambiti_json'], true)));
$azioni_log = ['accesso' => 'è entrato', 'visualizza' => 'ha letto', 'scarica' => 'ha scaricato', 'rifiutato' => 'password rifiutata'];
$uff_sv_v = \App\Core\App::per($conn)->get(\App\Uffici\ServizioUffici::class);
$uff_scarico_v = $uff_sv_v->perChiave('verbali_scarico');
$scaricatori = $uff_scarico_v ? $uff_sv_v->persone((int)$uff_scarico_v['id']) : [];
$form_consigli = function (?array $a) use ($consigli, $h) {
    $amb = $a ? (array)json_decode((string)$a['ambiti_json'], true) : [];
    $tutti = in_array('*', $amb, true);
    $out = '<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="tutti" value="1" id="ct' . (int)($a['id'] ?? 0) . '"' . ($tutti ? ' checked' : '') . '><label class="form-check-label small fw-bold" for="ct' . (int)($a['id'] ?? 0) . '">Tutti i consigli</label></div>';
    $out .= '<div class="small" style="max-height:150px;overflow:auto;">';
    foreach ($consigli as $k => $c) {
        $out .= '<label class="d-block"><input class="form-check-input me-1" type="checkbox" name="consigli_ids[]" value="' . (int)$k . '"' . (in_array((int)$k, array_map('intval', array_diff($amb, ['*'])), true) ? ' checked' : '') . '>' . $h(mb_strimwidth($c['nome'], 0, 90, '…')) . '</label>';
    }

    return $out . '</div>';
};
?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;tab=sedute&amp;archivio=1" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Archivio verbali</a></nav>
    <h2 class="h5 fw-bold mb-1"><i class="fa fa-key me-1" style="color:var(--pn-mod);" aria-hidden="true"></i>Accesso ai verbali (pagina pubblica)</h2>
    <p class="small text-secondary mb-3">Chi non ha le credenziali di Ateneo (per esempio i rappresentanti esterni) entra con una <strong>password</strong> che gli dai tu, nella pagina <code><?php echo $h($url_pubblico); ?></code>. Li <strong>legge nella pagina senza poterli scaricare né stampare</strong> (il download è riservato a chi ha le credenziali di Ateneo ed è abilitato, vedi a destra). Vede solo i verbali <strong>firmati e segnati come pubblicabili</strong> dei consigli che hai scelto <strong>e degli altri organi dello stesso corso di studi</strong> (commissione, riesame…), organizzati per organo e con i filtri per organo e anno accademico, fino alla scadenza. Ogni accesso, lettura e scaricamento si registra (IP anonimizzato, conservato <?php echo \App\Didattica\ServizioVerbaliPubblici::MESI_REGISTRO; ?> mesi). Ora sono pubblicabili <strong><?php echo $n_pubblici; ?></strong> verbali: si scelgono dall'archivio (icona del mondo).</p>

    <?php if ($nuova): $acc_n = $rep_acc->perId((int)$nuova[0]); ?>
    <div class="alert alert-success"><div class="fw-bold mb-1"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Password di «<?php echo $h($acc_n['etichetta'] ?? ''); ?>»: copiala adesso, non si potrà rivedere.</div>
        <code class="fs-5 user-select-all"><?php echo $h($nuova[1]); ?></code>
        <div class="small mt-1">Comunicala a parte (non nello stesso messaggio del link). Se la perdi, creane una nuova: la vecchia smette di funzionare.</div></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Accessi (<?php echo count($accessi); ?>)</h6>
                <?php foreach ($accessi as $a): $scad = $a['scade_il'] && $a['scade_il'] < date('Y-m-d'); $on = (int)$a['attivo'] && !$scad; ?>
                <div class="border rounded p-2 mb-2<?php echo $on ? '' : ' bg-light'; ?>">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <strong><?php echo $h($a['etichetta']); ?></strong>
                        <?php echo $on ? '<span class="badge text-bg-success">attivo</span>' : ($scad ? '<span class="badge text-bg-secondary">scaduto</span>' : '<span class="badge text-bg-secondary">revocato</span>'); ?>
                        <span class="small text-secondary ms-auto"><?php echo $a['scade_il'] ? 'fino al ' . date('d/m/Y', strtotime($a['scade_il'])) : 'senza scadenza'; ?></span>
                    </div>
                    <div class="small text-secondary">Consigli: <?php echo $h($nome_consigli($a)); ?> · <?php echo (int)$a['n_accessi']; ?> accessi<?php echo $a['ultimo_accesso_il'] ? ' · ultimo il ' . date('d/m/Y H:i', strtotime($a['ultimo_accesso_il'])) : ''; ?></div>
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <a class="btn btn-sm btn-outline-secondary py-0" href="<?php echo $base; ?>&amp;tab=sedute&amp;accessi=1&amp;modifica=<?php echo (int)$a['id']; ?>"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica</a>
                        <form method="POST" class="d-inline"><?php csrf_field(); ?><button type="submit" name="nuova_password_verbali" value="<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-outline-primary py-0" data-confirm="Creare una nuova password? Quella attuale smette di funzionare."><i class="fa fa-rotate me-1" aria-hidden="true"></i>Nuova password</button></form>
                        <form method="POST" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="attiva" value="<?php echo (int)$a['attivo'] ? '0' : '1'; ?>"><button type="submit" name="stato_accesso_verbali" value="<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-outline-<?php echo (int)$a['attivo'] ? 'warning' : 'success'; ?> py-0"><i class="fa fa-<?php echo (int)$a['attivo'] ? 'ban' : 'check'; ?> me-1" aria-hidden="true"></i><?php echo (int)$a['attivo'] ? 'Revoca' : 'Riattiva'; ?></button></form>
                        <form method="POST" class="d-inline"><?php csrf_field(); ?><button type="submit" name="elimina_accesso_verbali" value="<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare questo accesso?"><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina</button></form>
                    </div>
                    <?php if ($mod_acc === (int)$a['id']): ?>
                    <form method="POST" class="border-top mt-2 pt-2"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                        <div class="row g-2">
                            <div class="col-md-8"><label class="form-label small fw-bold mb-0" for="meE">A chi è destinato</label><input class="form-control form-control-sm" id="meE" name="etichetta" maxlength="150" value="<?php echo $h($a['etichetta']); ?>" required></div>
                            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="meS">Scade il (vuoto = mai)</label><input type="date" class="form-control form-control-sm" id="meS" name="scade_il" value="<?php echo $h($a['scade_il']); ?>"></div>
                            <div class="col-12"><div class="small fw-bold">Consigli visibili</div><?php echo $form_consigli($a); ?></div>
                        </div>
                        <button type="submit" name="salva_accesso_verbali" value="1" class="btn btn-sm btn-primary fw-bold mt-2"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (!$accessi): ?><p class="small text-muted mb-0">Nessun accesso: creane uno a destra.</p><?php endif; ?>
            </div></div>

            <div class="card border-0 shadow-sm mt-3"><div class="card-body">
                <h6 class="fw-bold"><i class="fa fa-clock-rotate-left me-1 text-primary" aria-hidden="true"></i>Chi è entrato (ultimi 60 eventi)</h6>
                <div class="table-responsive" style="max-height:360px;overflow:auto;"><table class="table table-sm small mb-0">
                    <?php foreach ($registro as $r): ?><tr class="<?php echo $r['azione'] === 'rifiutato' ? 'table-warning' : ''; ?>"><td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($r['creato_il'])); ?></td><td><?php echo $h($r['etichetta'] ?: 'password sbagliata'); ?></td><td><?php echo $h($azioni_log[$r['azione']] ?? $r['azione']); ?><?php echo $r['seduta_id'] ? ' · verbale del ' . ($r['seduta_data'] ? date('d/m/Y', strtotime($r['seduta_data'])) : '—') : ''; ?></td><td class="text-secondary"><?php echo $h($r['ip']); ?></td></tr><?php endforeach; ?>
                    <?php if (!$registro): ?><tr><td class="text-muted">Nessun accesso finora.</td></tr><?php endif; ?>
                </table></div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3" id="scaricatori" style="border-left:5px solid #15803d !important;"><div class="card-body">
                <h6 class="fw-bold"><i class="fa fa-download me-1 text-success" aria-hidden="true"></i>Chi può scaricare i verbali</h6>
                <p class="small text-secondary">Con la sola password i verbali si <strong>leggono nella pagina ma non si scaricano né si stampano</strong>. Scarica chi accede con le <strong>credenziali di Ateneo</strong> ed è in questo elenco (persone dell'anagrafe di Ateneo) oppure è amministratore: vede tutti i verbali pubblicabili.</p>
                <?php foreach ($scaricatori as $sc): ?>
                    <form method="POST" class="d-flex gap-2 align-items-center border-bottom py-1"><?php csrf_field(); ?>
                        <div class="flex-grow-1 small"><strong><?php echo $h($sc['nominativo']); ?></strong><div class="text-secondary"><?php echo $h($sc['email']); ?><?php echo $sc['struttura'] ? ' · ' . $h($sc['struttura']) : ''; ?></div></div>
                        <button type="submit" name="togli_scaricatore_verbali" value="<?php echo (int)$sc['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Togliere <?php echo $h($sc['nominativo']); ?> dal download dei verbali?" aria-label="Togli"><i class="fa fa-user-minus" aria-hidden="true"></i></button>
                    </form>
                <?php endforeach; ?>
                <?php if (!$scaricatori): ?><p class="small text-muted mb-2">Nessuna persona: per ora scaricano solo gli amministratori.</p><?php endif; ?>
                <form method="POST" class="mt-2 dd-scelta-persona" id="formScaricatore"><?php csrf_field(); ?><input type="hidden" name="persona_id" value=""><input type="hidden" name="aggiungi_scaricatore_verbali" value="1">
                    <div class="small fw-bold mb-1">Abilita una persona dall'anagrafe</div>
                    <?php echo html_ricerca_personale($conn, 'Abilita'); ?>
                </form>
                <script>
                (function () {
                    var f = document.getElementById('formScaricatore'); if (!f) return;
                    f.addEventListener('persona-scelta', function (e) { f.querySelector('[name=persona_id]').value = e.detail.id; f.submit(); });
                    f.addEventListener('submit', function (e) { if (!f.querySelector('[name=persona_id]').value) e.preventDefault(); });
                })();
                </script>
            </div></div>
            <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
                <?php csrf_field(); ?>
                <h6 class="fw-bold"><i class="fa fa-plus me-1 text-success" aria-hidden="true"></i>Nuovo accesso</h6>
                <label class="form-label small fw-bold mb-0" for="naE">A chi è destinato <span class="text-danger">*</span></label>
                <input class="form-control form-control-sm mb-2" id="naE" name="etichetta" maxlength="150" required placeholder="es. Rappresentanti degli studenti">
                <label class="form-label small fw-bold mb-0" for="naS">Scade il (facoltativo)</label>
                <input type="date" class="form-control form-control-sm mb-2" id="naS" name="scade_il">
                <div class="small fw-bold">Consigli visibili <span class="text-danger">*</span></div>
                <div class="mb-2"><?php echo $form_consigli(null); ?></div>
                <label class="form-label small fw-bold mb-0" for="naP">Password (vuoto = la genero io)</label>
                <input class="form-control form-control-sm mb-2" id="naP" name="password" autocomplete="off" maxlength="100" placeholder="almeno <?php echo \App\Didattica\ServizioVerbaliPubblici::MIN_PASSWORD; ?> caratteri">
                <button type="submit" name="crea_accesso_verbali" value="1" class="btn btn-sm btn-success fw-bold"><i class="fa fa-key me-1" aria-hidden="true"></i>Crea l'accesso</button>
            </div></form>
        </div>
    </div>
