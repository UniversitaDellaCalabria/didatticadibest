<?php
// dichiarazione_tutorato.php - Dichiarazione individuale del tutor (modello «N. 6» dell'Ateneo) compilata online durante l'accettazione
// dell'incarico (link personale dall'email, accesso con SPID o CIE). Due passi prima della conferma finale in incarico.php:
//   passo 2: dati personali, posizione fiscale e IBAN (il compenso si paga solo con bonifico);
//   passo 3: posizione contributiva (INPS) e rischio INAIL.
// I dati si salvano cifrati (src/Tutorato/ServizioDichiarazione.php): li vedono solo il tutor e l'ufficio pagamenti.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tok = (string)($_GET['t'] ?? '');
$passo = (int)($_GET['passo'] ?? 2) === 3 ? 3 : 2;
$i = incarico_per_token($conn, 'studente', $tok);
$mio = $i && strtoupper(trim((string)($user_info['codice_fiscale'] ?? ''))) === strtoupper($i['codice_fiscale']);
$sv = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioDichiarazione::class);
$aperta = $i && $mio && $i['stato'] === 'inviata' && $sv->richiesta();
$errori = [];
$v = [];

if ($aperta && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    [$errori, $v] = $sv->salvaPasso((int)$i['id'], $passo, $_POST, $i);
    unset($_POST['iban']);
    if (!$errori) {
        flash_set($passo === 2 ? "Dati personali e pagamento salvati: ora la posizione contributiva." : "Dichiarazione completata: ora puoi confermare e accettare l'incarico.");
        header('Location: ' . ($passo === 2 ? 'dichiarazione_tutorato.php?t=' . urlencode($tok) . '&passo=3' : 'incarico.php?t=' . urlencode($tok))); exit;
    }
}
if ($aperta && !$v) $v = $sv->datiPasso((int)$i['id'], $passo);
if ($aperta && $passo === 2) $v += ['telefono' => (string)$i['telefono'], 'email' => (string)$i['email'], 'residenza_invariata' => true, 'non_dipendente' => false];
$st = $aperta ? $sv->stato((int)$i['id']) : ['passo2' => false, 'passo3' => false];
$val = fn(string $k, $def = '') => $h($v[$k] ?? $def);
$chk = fn(string $k) => !empty($v[$k]) ? ' checked' : '';

$page_cfg['titolo'] = 'Dichiarazione individuale';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 860px;">
    <?php echo flash_html(); ?>
    <?php if (!$aperta): ?>
        <h1 class="fw-bold h3">Dichiarazione non disponibile</h1>
        <p>Il link non è valido, la lettera è già stata accettata oppure questa pagina è intestata a un'altra persona. <?php if ($i): ?><a href="incarico.php?t=<?php echo $h(urlencode($tok)); ?>">Torna alla lettera</a><?php endif; ?></p>
    <?php else: ?>
        <ol class="list-unstyled d-flex flex-wrap gap-2 mb-3 small" aria-label="Passi dell'accettazione">
            <li class="px-3 py-1 rounded-pill fw-bold" style="background:#dcfce7;color:#166534;"><i class="fa fa-check me-1" aria-hidden="true"></i>1 · Lettera</li>
            <li class="px-3 py-1 rounded-pill fw-bold" style="<?php echo $passo === 2 ? 'background:#b30000;color:#fff;' : ($st['passo2'] ? 'background:#dcfce7;color:#166534;' : 'background:#e2e8f0;color:#475569;'); ?>"<?php echo $passo === 2 ? ' aria-current="step"' : ''; ?>>2 · Dati personali e pagamento</li>
            <li class="px-3 py-1 rounded-pill fw-bold" style="<?php echo $passo === 3 ? 'background:#b30000;color:#fff;' : ($st['passo3'] ? 'background:#dcfce7;color:#166534;' : 'background:#e2e8f0;color:#475569;'); ?>"<?php echo $passo === 3 ? ' aria-current="step"' : ''; ?>>3 · Posizione contributiva</li>
            <li class="px-3 py-1 rounded-pill fw-bold" style="background:#e2e8f0;color:#475569;">4 · Conferma</li>
        </ol>
        <?php if ($errori): ?><div class="alert alert-danger" role="alert"><strong>Controlla:</strong><ul class="mb-0"><?php foreach ($errori as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <?php if ($passo === 2): ?>
        <h1 class="fw-bold h3">Dichiarazione dei dati personali per assegni di tutorato</h1>
        <p class="text-secondary">Modello «N. 6 – Dichiarazione individuale». I dati che il portale conosce già sono precompilati.</p>
        <form method="POST" autocomplete="off" id="formDichiarazione1"><?php csrf_field(); ?>
            <section class="card border-0 shadow-sm mb-3"><div class="card-body"><h2 class="h6 fw-bold">I tuoi dati</h2>
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label small fw-bold">Nome e cognome</label><input class="form-control" value="<?php echo $h($i['nome'] . ' ' . $i['cognome']); ?>" disabled></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Codice fiscale</label><input class="form-control font-monospace" value="<?php echo $h($i['codice_fiscale']); ?>" disabled></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Nato/a il · a</label><input class="form-control" value="<?php echo $h(($i['data_nascita'] ? date('d/m/Y', strtotime($i['data_nascita'])) : '') . ' · ' . $i['luogo_nascita']); ?>" disabled></div>
                    <div class="col-md-5"><label class="form-label small fw-bold" for="dTel">Telefono <span class="text-danger">*</span></label><input class="form-control" id="dTel" name="telefono" value="<?php echo $val('telefono'); ?>" required maxlength="30" inputmode="tel"></div>
                    <div class="col-md-7"><label class="form-label small fw-bold" for="dEm">Email <span class="text-danger">*</span></label><input type="email" class="form-control" id="dEm" name="email" value="<?php echo $val('email'); ?>" required maxlength="150"></div>
                    <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="residenza_invariata" value="1" id="dResInv"<?php echo $chk('residenza_invariata'); ?>> I dati di residenza sono quelli della lettera di incarico (<?php echo $h($i['indirizzo'] . ' ' . $i['civico'] . ', ' . $i['comune_residenza']); ?>)</label></div>
                    <div class="col-12 row g-2 m-0 p-0" id="dResBox">
                        <div class="col-md-5"><label class="form-label small fw-bold" for="dInd">Via/piazza</label><input class="form-control" id="dInd" name="indirizzo" value="<?php echo $val('indirizzo'); ?>" maxlength="200"></div>
                        <div class="col-md-2"><label class="form-label small fw-bold" for="dCiv">N.</label><input class="form-control" id="dCiv" name="civico" value="<?php echo $val('civico'); ?>" maxlength="20"></div>
                        <div class="col-md-3"><label class="form-label small fw-bold" for="dCit">Città</label><input class="form-control" id="dCit" name="citta" value="<?php echo $val('citta'); ?>" maxlength="120"></div>
                        <div class="col-md-1"><label class="form-label small fw-bold" for="dCap">CAP</label><input class="form-control" id="dCap" name="cap" value="<?php echo $val('cap'); ?>" maxlength="10"></div>
                        <div class="col-md-1"><label class="form-label small fw-bold" for="dPrv">Prov.</label><input class="form-control text-uppercase" id="dPrv" name="prov" value="<?php echo $val('prov'); ?>" maxlength="3"></div>
                    </div>
                    <div class="col-md-8"><label class="form-label small fw-bold" for="dEst">Se risiedi all'estero: codice identificativo estero</label><input class="form-control" id="dEst" name="estero_codice" value="<?php echo $val('estero_codice'); ?>" maxlength="60"></div>
                </div></div></section>
            <section class="card border-0 shadow-sm mb-3"><div class="card-body"><h2 class="h6 fw-bold">Posizione fiscale</h2>
                <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="non_dipendente" value="1" required<?php echo $chk('non_dipendente'); ?>> Dichiaro di <strong>NON essere dipendente UNICAL</strong></label>
                <div class="row g-2"><div class="col-md-4"><label class="form-label small fw-bold" for="dIrp">Aliquota IRPEF massima dei tuoi redditi (%) <span class="text-danger">*</span></label><input class="form-control" id="dIrp" name="irpef" value="<?php echo $val('irpef'); ?>" required maxlength="10" inputmode="decimal" placeholder="es. 23"></div></div>
            </div></section>
            <section class="card border-0 shadow-sm mb-3"><div class="card-body"><h2 class="h6 fw-bold">Dove accreditare il compenso</h2>
                <div class="alert alert-info small py-2">Il compenso si paga <strong>solo con accredito su conto con IBAN</strong> intestato o cointestato a te. 🔒 I dati bancari si conservano cifrati e li vedono solo tu e l'ufficio pagamenti.</div>
                <div class="row g-2">
                    <div class="col-12"><label class="form-label small fw-bold" for="dIban">IBAN <span class="text-danger">*</span></label><input class="form-control font-monospace" id="dIban" name="iban" value="<?php echo $h(\App\Tutorato\DichiarazioneIndividuale::ibanLeggibile((string)($v['iban'] ?? ''))); ?>" required maxlength="40" autocomplete="off" placeholder="IT00 X000 0000 0000 0000 0000 000"><div class="form-text">Il portale controlla che sia scritto correttamente.</div></div>
                    <div class="col-md-6"><label class="form-label small fw-bold" for="dBanca">Banca o istituto postale <span class="text-danger">*</span></label><input class="form-control" id="dBanca" name="banca" value="<?php echo $val('banca'); ?>" required maxlength="150"></div>
                    <div class="col-md-6"><label class="form-label small fw-bold" for="dSw">Codice SWIFT/BIC <small class="fw-normal">(solo per un conto estero)</small></label><input class="form-control text-uppercase" id="dSw" name="swift" value="<?php echo $val('swift'); ?>" maxlength="11"></div>
                    <div class="col-md-7"><label class="form-label small fw-bold" for="dBInd">Indirizzo della filiale</label><input class="form-control" id="dBInd" name="banca_indirizzo" value="<?php echo $val('banca_indirizzo'); ?>" maxlength="200"></div>
                    <div class="col-md-5"><label class="form-label small fw-bold" for="dBCit">Città</label><input class="form-control" id="dBCit" name="banca_citta" value="<?php echo $val('banca_citta'); ?>" maxlength="120"></div>
                </div></div></section>
            <p><a class="btn btn-outline-secondary" href="incarico.php?t=<?php echo $h(urlencode($tok)); ?>">‹ Torna alla lettera</a> <button type="submit" class="btn btn-danger fw-bold">Salva e continua ›</button></p>
        </form>
        <?php echo ui_avviso_modifiche('formDichiarazione1'); ?>
        <script>
        (function () {
            var c = document.getElementById('dResInv'), b = document.getElementById('dResBox');
            function agg() { b.style.display = c.checked ? 'none' : ''; b.querySelectorAll('input').forEach(function (x) { x.disabled = c.checked; }); }
            c.addEventListener('change', agg); agg();
        })();
        </script>

        <?php else: ?>
        <h1 class="fw-bold h3">Titolarità di posizione contributiva a fini previdenziali</h1>
        <p class="text-secondary">Collaborazione individuale · Gestione Separata INPS (art. 2 c. 26 L. 335/95). Scegli la tua situazione.</p>
        <form method="POST" autocomplete="off" id="formDichiarazione2"><?php csrf_field(); ?>
            <section class="card border-0 shadow-sm mb-3"><div class="card-body"><h2 class="h6 fw-bold">Aliquota contributiva INPS <span class="text-danger">*</span></h2>
                <?php foreach (\App\Tutorato\DichiarazioneIndividuale::INPS as $k => $et): ?>
                    <label class="form-check d-block border rounded p-2 mb-2"><input class="form-check-input" type="radio" name="inps" value="<?php echo $k; ?>"<?php echo ($v['inps'] ?? '') === $k ? ' checked' : ''; ?> required> <?php echo $h($et); ?></label>
                <?php endforeach; ?>
                <div class="inps-box" data-per="gs35"><label class="form-label small fw-bold" for="pImp">Imponibile teorico annuo riferito alle sole prestazioni di co.co.co. (€)</label><input class="form-control" id="pImp" name="imponibile" value="<?php echo $val('imponibile'); ?>" maxlength="20" placeholder="0,00" style="max-width:220px;"></div>
                <div class="inps-box row g-2" data-per="gs24">
                    <div class="col-md-6"><label class="form-label small fw-bold" for="pCas">Cassa o ente previdenziale (o pensione diretta) <span class="text-danger">*</span></label><input class="form-control" id="pCas" name="cassa_ente" value="<?php echo $val('cassa_ente'); ?>" maxlength="200"></div>
                    <div class="col-md-6"><label class="form-label small fw-bold" for="pPro">Per la professione di</label><input class="form-control" id="pPro" name="cassa_professione" value="<?php echo $val('cassa_professione'); ?>" maxlength="200"></div>
                    <div class="col-md-3"><label class="form-label small fw-bold" for="pDal">Dal</label><input type="date" class="form-control" id="pDal" name="cassa_dal" value="<?php echo $val('cassa_dal'); ?>"></div>
                    <div class="col-md-3"><label class="form-label small fw-bold" for="pAl">Al</label><input type="date" class="form-control" id="pAl" name="cassa_al" value="<?php echo $val('cassa_al'); ?>"></div>
                </div>
                <div class="inps-box row g-2" data-per="ente">
                    <div class="col-md-6"><label class="form-label small fw-bold" for="pEnt">Denominazione dell'ente <span class="text-danger">*</span></label><input class="form-control" id="pEnt" name="ente_denominazione" value="<?php echo $val('ente_denominazione'); ?>" maxlength="200"></div>
                    <div class="col-md-6"><label class="form-label small fw-bold" for="pEcf">Codice fiscale / P. IVA dell'ente</label><input class="form-control text-uppercase" id="pEcf" name="ente_cf" value="<?php echo $val('ente_cf'); ?>" maxlength="16"></div>
                    <div class="col-md-6"><label class="form-label small fw-bold" for="pEin">Indirizzo</label><input class="form-control" id="pEin" name="ente_indirizzo" value="<?php echo $val('ente_indirizzo'); ?>" maxlength="200"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold" for="pEci">Città</label><input class="form-control" id="pEci" name="ente_citta" value="<?php echo $val('ente_citta'); ?>" maxlength="120"></div>
                    <div class="col-md-2"><label class="form-label small fw-bold" for="pEpr">Prov.</label><input class="form-control text-uppercase" id="pEpr" name="ente_prov" value="<?php echo $val('ente_prov'); ?>" maxlength="3"></div>
                </div>
            </div></section>
            <section class="card border-0 shadow-sm mb-3"><div class="card-body"><h2 class="h6 fw-bold">Rischio INAIL: l'incarico rientra nell'ambito di <span class="text-danger">*</span></h2>
                <?php foreach (\App\Tutorato\DichiarazioneIndividuale::INAIL as $k => $et): ?>
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="inail[]" value="<?php echo $k; ?>"<?php echo in_array($k, (array)($v['inail'] ?? []), true) ? ' checked' : ''; ?>> <?php echo $h($et); ?></label>
                <?php endforeach; ?>
            </div></section>
            <p><a class="btn btn-outline-secondary" href="dichiarazione_tutorato.php?t=<?php echo $h(urlencode($tok)); ?>&amp;passo=2">‹ Indietro</a> <button type="submit" class="btn btn-danger fw-bold">Salva e continua ›</button></p>
        </form>
        <?php echo ui_avviso_modifiche('formDichiarazione2'); ?>
        <style>.inps-box { display:none; margin-top:.5rem; }</style>
        <script>
        (function () {
            function agg() {
                var sel = (document.querySelector('input[name=inps]:checked') || {}).value;
                document.querySelectorAll('.inps-box').forEach(function (b) {
                    var on = b.dataset.per === sel; b.style.display = on ? '' : 'none';
                    b.querySelectorAll('input').forEach(function (x) { x.disabled = !on; });
                });
            }
            document.querySelectorAll('input[name=inps]').forEach(function (r) { r.addEventListener('change', agg); }); agg();
        })();
        </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
