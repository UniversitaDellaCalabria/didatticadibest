<?php
// pagamenti_tutorato.php - Ufficio pagamenti del tutorato (serve l'accesso con le credenziali Unical): le persone associate a un ufficio di Ateneo
// (admin/uffici_ateneo.php) vedono le pratiche che il referente ha validato e passato, con tutti i documenti (decreti, UNILAV, lettera di incarico
// protocollata, registro, fine attività, dichiarazione individuale), le lavorano e segnano «pagato» (data e riferimento) oppure le rimandano al
// referente con una nota. L'IBAN è mascherato: mostrarlo si registra. Logica in src/Tutorato/ServizioPagamenti.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pag = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioPagamenti::class);
$uffici_sv = \App\Core\App::per($conn)->get(\App\Ateneo\ServizioUfficiAteneo::class);
$dich_sv = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioDichiarazione::class);
$incarichi = \App\Core\App::per($conn)->get(\App\Tutorato\IncaricoRepository::class);
$miei_uffici = $uffici_sv->ufficiDellUtente($user_info);
$id = (int)($_GET['id'] ?? 0);
$i = $id ? $incarichi->perId($id) : null;
if ($i && (!$pag->dellUfficioDi($user_info, $i) || !in_array((string)$i['pagamento_stato'], ['in_ufficio', 'pagata'], true))) $i = null;
$mostra_iban = false;

if ($i && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['mostra_iban'])) {
        // L'IBAN si mostra solo su richiesta, nella stessa risposta, e resta registrato chi l'ha aperto
        $dich_sv->apri((int)$i['id'], $user_info, 'iban');
        $mostra_iban = true;
    } else {
        $err = null; $ok = '';
        if (isset($_POST['pagata'])) { $err = $pag->pagata((int)$i['id'], (string)($_POST['data'] ?? ''), (string)($_POST['riferimento'] ?? ''), $user_info); $ok = "Pagamento registrato: " . trim($i['nome'] . ' ' . $i['cognome']) . " riceve l'email di conferma."; }
        elseif (isset($_POST['rimanda'])) { $err = $pag->rimandaAlReferente((int)$i['id'], (string)($_POST['nota'] ?? ''), $user_info); $ok = "Pratica rimandata al referente: ha ricevuto l'email con il motivo."; }
        flash_set($err ?? $ok, $err ? 'danger' : 'success');
        header('Location: ' . ($err ? 'pagamenti_tutorato.php?id=' . (int)$i['id'] : 'pagamenti_tutorato.php')); exit;
    }
}

$vista = ($_GET['vista'] ?? '') === 'pagati' ? 'pagati' : 'da_pagare';
$page_cfg['titolo'] = 'Ufficio pagamenti del tutorato';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 1100px;">
    <?php echo flash_html(); ?>
    <?php if (!$miei_uffici): ?>
        <h1 class="fw-bold h3">Ufficio pagamenti del tutorato</h1>
        <div class="alert alert-light border">Non risulti associato a un ufficio di Ateneo che paga il tutorato. Se dovresti esserlo, chiedi all'amministratore del portale di aggiungerti (Gestione del portale › Uffici di Ateneo).</div>

    <?php elseif ($i):
        $c = $pag->compenso($i); $ds = $dich_sv->stato((int)$i['id']);
        $dati = $dich_sv->apri((int)$i['id'], $user_info, 'scheda');
        $a = (array)($dati['passo2'] ?? []); $b3 = (array)($dati['passo3'] ?? []);
        $iban = (string)($a['iban'] ?? '');
        $pagata = (string)$i['pagamento_stato'] === 'pagata'; ?>
        <nav class="small mb-2"><a href="pagamenti_tutorato.php" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Pratiche dell'ufficio</a></nav>
        <h1 class="fw-bold h3 mb-0"><?php echo $h(trim($i['cognome'] . ' ' . $i['nome'])); ?></h1>
        <p class="text-secondary"><?php echo $h($i['bando_titolo']); ?> · <?php echo $h(mb_strimwidth((string)preg_replace('/\s+/', ' ', (string)$i['attivita']), 0, 90, '…')); ?> ·
            <?php echo $pagata ? '<span class="badge bg-success">Pagato il ' . date('d/m/Y', strtotime($i['pagamento_data'])) . '</span>' : '<span class="badge bg-primary">Da pagare</span> dal ' . date('d/m/Y', strtotime($i['pagamento_passato_il'])); ?></p>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h2 class="h6 fw-bold">Documenti della pratica</h2>
                    <?php foreach ($pag->documenti($i) as $d): ?>
                        <div class="d-flex justify-content-between align-items-center gap-2 border-top py-2 small">
                            <div><i class="fa fa-file-pdf text-danger me-1" aria-hidden="true"></i><strong><?php echo $h($d['etichetta']); ?></strong><?php echo $d['dettaglio'] !== '' ? '<div class="text-secondary">' . $h($d['dettaglio']) . '</div>' : ''; ?></div>
                            <?php if ($d['disponibile']): ?><a class="btn btn-sm btn-outline-secondary py-0" href="documento_tutorato.php?i=<?php echo (int)$i['id']; ?>&amp;f=<?php echo $h($d['chiave']); ?>" target="_blank" rel="noopener">Apri</a>
                            <?php else: ?><span class="badge bg-light text-secondary border">manca</span><?php endif; ?></div>
                    <?php endforeach; ?>
                </div></div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h2 class="h6 fw-bold">Compenso</h2>
                    <div class="row g-2"><div class="col-6"><div class="small text-secondary">Lordo ente · <?php echo $h(\App\Tutorato\DatiLettera::oreTesto($c['ore'])); ?> ore × € 25,00</div><div class="fs-5 fw-bold">€ <?php echo number_format($c['lordo'], 2, ',', '.'); ?></div></div>
                        <div class="col-6"><div class="small text-secondary">In lettera (÷ 1,24, al netto degli oneri)</div><div class="fs-5 fw-bold">€ <?php echo number_format($c['lettera'], 2, ',', '.'); ?></div></div></div>
                    <?php if ($c['cambiato']): ?><div class="small text-secondary mt-1">Il tutor ha fatto <?php echo $h(\App\Tutorato\DatiLettera::oreTesto($c['ore'])); ?> ore su <?php echo $h(\App\Tutorato\DatiLettera::oreTesto($i['ore'])); ?>: compenso ricalcolato.</div><?php endif; ?>
                </div></div>
                <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h2 class="h6 fw-bold">Dichiarazione individuale (dati per il pagamento)</h2>
                    <?php if (!$ds['completa'] || !$dati): ?><p class="small text-danger mb-0">La dichiarazione non è disponibile o non è completa.</p>
                    <?php else: ?>
                    <dl class="row small mb-2">
                        <dt class="col-4 text-secondary fw-normal">Codice fiscale</dt><dd class="col-8 font-monospace"><?php echo $h($i['codice_fiscale']); ?></dd>
                        <dt class="col-4 text-secondary fw-normal">Pagamento</dt><dd class="col-8">Accredito su c/c</dd>
                        <dt class="col-4 text-secondary fw-normal">IBAN</dt><dd class="col-8"><span class="font-monospace"><?php echo $h($mostra_iban ? \App\Tutorato\DichiarazioneIndividuale::ibanLeggibile($iban) : \App\Tutorato\DichiarazioneIndividuale::ibanMascherato($iban)); ?></span>
                            <?php if (!$mostra_iban): ?><form method="POST" class="d-inline"><?php csrf_field(); ?><button class="btn btn-sm btn-outline-secondary py-0 ms-1" name="mostra_iban" value="1">Mostra</button></form><?php endif; ?></dd>
                        <dt class="col-4 text-secondary fw-normal">Banca</dt><dd class="col-8"><?php echo $h(trim(($a['banca'] ?? '') . ' ' . ($a['banca_citta'] ?? '') . (!empty($a['swift']) ? ' · SWIFT ' . $a['swift'] : ''))); ?></dd>
                        <dt class="col-4 text-secondary fw-normal">IRPEF</dt><dd class="col-8"><?php echo $h($a['irpef'] ?? ''); ?> %</dd>
                        <dt class="col-4 text-secondary fw-normal">INPS</dt><dd class="col-8"><?php echo $h(\App\Tutorato\DichiarazioneIndividuale::INPS[$b3['inps'] ?? ''] ?? ''); ?></dd>
                        <dt class="col-4 text-secondary fw-normal">INAIL</dt><dd class="col-8"><?php echo $h(implode('; ', array_map(fn($k) => \App\Tutorato\DichiarazioneIndividuale::INAIL[$k] ?? $k, (array)($b3['inail'] ?? [])))); ?></dd>
                    </dl>
                    <p class="small text-secondary mb-1"><i class="fa fa-lock me-1" aria-hidden="true"></i>Ogni volta che si apre la scheda o si mostra l'IBAN resta registrato chi lo ha fatto.</p>
                    <a class="btn btn-sm btn-outline-secondary" href="documento_tutorato.php?i=<?php echo (int)$i['id']; ?>&amp;f=dichiarazione&amp;scarica=1"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Dichiarazione compilata (PDF)</a>
                    <?php endif; ?>
                </div></div>
                <?php if (!$pagata): ?>
                <div class="card border-0 shadow-sm mb-3" style="border-left:5px solid #15803d !important;"><div class="card-body"><h2 class="h6 fw-bold">Segna come pagato</h2>
                    <form method="POST" class="row g-2"><?php csrf_field(); ?>
                        <div class="col-md-5"><label class="form-label small fw-bold" for="pData">Data del pagamento <span class="text-danger">*</span></label><input type="date" class="form-control" id="pData" name="data" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
                        <div class="col-md-7"><label class="form-label small fw-bold" for="pRif">Riferimento (mandato, n. ordinativo) <span class="text-danger">*</span></label><input class="form-control" id="pRif" name="riferimento" maxlength="100" required></div>
                        <div class="col-12"><button class="btn btn-success fw-bold" name="pagata" value="1" data-confirm="Segnare la pratica come pagata? Il tutor riceve l'email.">Pagato: avvisa il tutor</button></div>
                    </form>
                    <details class="mt-3"><summary class="small fw-bold text-danger" style="cursor:pointer;">Rimanda al referente con una nota</summary>
                        <form method="POST" class="mt-2"><?php csrf_field(); ?><textarea class="form-control form-control-sm mb-2" name="nota" rows="3" maxlength="500" required placeholder="Cosa non va (il referente lo riceve per email)" aria-label="Nota per il referente"></textarea>
                            <button class="btn btn-sm btn-outline-danger" name="rimanda" value="1">Rimanda al referente</button></form></details>
                </div></div>
                <?php else: ?><div class="alert alert-success small">Pagamento disposto il <?php echo date('d/m/Y', strtotime($i['pagamento_data'])); ?> · <?php echo $h($i['pagamento_riferimento']); ?> · <?php echo $h($i['pagato_da']); ?></div><?php endif; ?>
            </div>
        </div>

    <?php else:
        $lista = [];
        foreach ($miei_uffici as $u) foreach ($pag->dellUfficio((int)$u['id'], $vista === 'pagati' ? ['pagata'] : ['in_ufficio']) as $x) $lista[] = $x;
        $n_da_pagare = $pag->daPagare($user_info); ?>
        <h1 class="fw-bold h3 mb-1">Ufficio pagamenti del tutorato</h1>
        <p class="text-secondary"><?php echo $h(implode(' · ', array_column($miei_uffici, 'nome'))); ?></p>
        <ul class="nav nav-pills mb-3">
            <li class="nav-item"><a class="nav-link<?php echo $vista === 'da_pagare' ? ' active' : ''; ?>" href="pagamenti_tutorato.php">Da pagare <span class="badge bg-warning text-dark"><?php echo (int)$n_da_pagare; ?></span></a></li>
            <li class="nav-item"><a class="nav-link<?php echo $vista === 'pagati' ? ' active' : ''; ?>" href="pagamenti_tutorato.php?vista=pagati">Pagati</a></li>
        </ul>
        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle small mb-0">
            <thead class="table-light"><tr><th>Tutor</th><th>Bando</th><th class="text-center">Ore</th><th>Compenso in lettera</th><th><?php echo $vista === 'pagati' ? 'Pagato il' : 'Passata il'; ?></th><th></th></tr></thead><tbody>
            <?php if (!$lista): ?><tr><td colspan="6" class="text-center text-secondary py-4"><?php echo $vista === 'pagati' ? 'Nessun pagamento ancora.' : 'Niente da pagare: quando il referente passa delle pratiche arriva una notifica.'; ?></td></tr><?php endif; ?>
            <?php foreach ($lista as $x): $cx = $x['_compenso']; ?>
                <tr><td><a class="fw-bold text-decoration-none" href="pagamenti_tutorato.php?id=<?php echo (int)$x['id']; ?>"><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?></a></td><td><?php echo $h($x['bando_titolo']); ?></td>
                    <td class="text-center"><?php echo $h(\App\Tutorato\DatiLettera::oreTesto($cx['ore'])); ?></td><td>€ <?php echo number_format($cx['lettera'], 2, ',', '.'); ?></td>
                    <td><?php echo date('d/m/Y', strtotime($vista === 'pagati' ? $x['pagamento_data'] : $x['pagamento_passato_il'])); ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary py-0" href="pagamenti_tutorato.php?id=<?php echo (int)$x['id']; ?>"><?php echo $vista === 'pagati' ? 'Apri' : 'Lavora'; ?></a></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
