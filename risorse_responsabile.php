<?php
// risorse_responsabile.php - Le mie risorse (Area personale): per chi è responsabile di aule, laboratori, auto… scelto dall'admin dall'anagrafe di Ateneo.
// Le richieste da approvare (approva / rifiuta con una nota) e le prenotazioni future di ognuna delle sue risorse. Logica in src/Risorse/ServizioResponsabiliRisorse.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$svc = \App\Core\App::per($conn)->get(\App\Risorse\ServizioResponsabiliRisorse::class);
$mie = $svc->risorseDi($user_info);
$cont_r = \App\Core\App::per($conn)->get(\App\Risorse\ServizioContatoriRisorse::class);
$diario_r = \App\Core\App::per($conn)->get(\App\Risorse\ServizioDiarioBordo::class);
$scad_r = [];
foreach (\App\Core\App::per($conn)->get(\App\Risorse\ServizioScadenzeRisorsa::class)->daControllare(array_map(fn($x) => (int)$x['id'], $mie)) as $sc_x) $scad_r[(int)$sc_x['risorsa_id']][] = $sc_x;

// Diario di bordo in Excel (solo per i responsabili della risorsa)
if (isset($_GET['diario_xlsx']) && $svc->responsabileDi($user_info, (int)$_GET['diario_xlsx']) && ($r_x = risorsa($conn, (int)$_GET['diario_xlsx'])) && (int)$r_x['diario_attivo']) {
    $f_x = $diario_r->esporta($r_x);
    if ($f_x) { while (ob_get_level() > 0) ob_end_clean(); invia_file_scaricabile($f_x, 'Diario_di_bordo_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string)$r_x['nome']) . '_' . date('Ymd') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decidi'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $azione = (string)($_POST['decidi'] ?? '');
    $err = $svc->decidi($user_info, (int)($_POST['pren_id'] ?? 0), $azione, (string)($_POST['nota'] ?? ''));
    if (!$err) registra_log_audit($conn, "Prenotazione risorsa (responsabile): " . $azione, ["Prenotazione" => (int)($_POST['pren_id'] ?? 0)]);
    flash_set($err ?? "Fatto: chi ha prenotato è stato avvisato.", $err ? 'danger' : 'success');
    header('Location: risorse_responsabile.php?r=' . time() . (isset($_POST['risorsa']) ? '#ris' . (int)$_POST['risorsa'] : '')); exit;
}

$page_cfg['titolo'] = 'Le mie risorse';
require_once 'header.php';
$NOMI_STATO = ['confermata' => ['Confermata', 'success'], 'da_approvare' => ['Da approvare', 'warning']];
?>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="area_personale.php">Area personale</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Le mie risorse</span></nav>
    <h1 class="fw-bold h3 mb-1"><i class="fa fa-user-shield me-2 text-success" aria-hidden="true"></i>Le mie risorse</h1>
    <p class="text-secondary">Le risorse di cui sei responsabile: aule, laboratori, auto… Qui accetti o rifiuti le richieste e vedi le prenotazioni in arrivo. Chi ha prenotato riceve un'email a ogni tua decisione.</p>
    <?php echo flash_html(); ?>
    <?php if (!$mie): ?>
        <div class="alert alert-light border">Non sei responsabile di nessuna risorsa. Se pensi di esserlo, scrivi all'amministratore del portale.</div>
    <?php endif; ?>
    <?php foreach ($mie as $r): $rid = (int)$r['id']; $pren = $svc->prenotazioni($user_info, $rid); $puo = (int)$r['puo_approvare'] === 1; ?>
        <div class="card border-0 shadow-sm mb-4" id="ris<?php echo $rid; ?>"><div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <h2 class="h5 fw-bold mb-0"><?php echo $h($r['nome']); ?></h2>
                <span class="text-secondary small"><?php echo $h(implode(' · ', array_filter([\App\Risorse\Costanti::TIPI[$r['tipo']][0] ?? '', $r['luogo']]))); ?></span>
                <?php if (!(int)$r['attiva']): ?><span class="badge bg-secondary">non prenotabile</span><?php endif; ?>
                <a class="btn btn-sm btn-outline-primary" href="risorse_cruscotto.php?r=<?php echo $rid; ?>"><i class="fa fa-chart-line me-1" aria-hidden="true"></i>Cruscotto</a>
                <span class="ms-auto small"><?php echo (int)$r['da_approvare'] ? '<span class="badge text-bg-warning">' . (int)$r['da_approvare'] . ' da approvare</span> ' : ''; ?><span class="text-secondary"><?php echo (int)$r['future']; ?> confermate in programma</span></span>
            </div>
            <?php foreach ($scad_r[$rid] ?? [] as $sc): ?><div class="alert alert-<?php echo $sc['stato'] === 'scaduta' ? 'danger' : 'warning'; ?> small py-2"><i class="fa fa-bell me-1" aria-hidden="true"></i><strong><?php echo $h($sc['nome']); ?></strong> <?php echo $sc['stato'] === 'scaduta' ? 'scaduta il ' : 'scade il '; ?><?php echo date('d/m/Y', strtotime($sc['scade_il'])); ?> · <a href="risorse_cruscotto.php?r=<?php echo $rid; ?>#scadenze">segna il rinnovo</a></div><?php endforeach; ?>
            <?php if (!$puo): ?><div class="alert alert-light border small py-2">Sei responsabile di questa risorsa ma non abilitato a decidere sulle richieste: ricevi le informazioni, l'approvazione la fa un altro responsabile.</div><?php endif; ?>
            <?php if (!$pren): ?><p class="small text-muted mb-0">Nessuna prenotazione in programma.</p>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
                <thead class="table-light"><tr><th>Quando</th><th>Chi</th><th>Motivo</th><th>Stato</th><th></th></tr></thead><tbody>
                <?php foreach ($pren as $p): $st = $NOMI_STATO[$p['stato']] ?? [$p['stato'], 'secondary']; ?>
                    <tr<?php echo $p['stato'] === 'da_approvare' ? ' class="table-warning"' : ''; ?>>
                        <td class="text-nowrap"><strong><?php echo $h(\App\Risorse\Prenotazioni::quando($p)); ?></strong><div class="text-secondary"><?php echo $h($p['codice']); ?></div></td>
                        <td><?php echo $h(trim($p['nome'] . ' ' . $p['cognome'])); ?><div class="text-secondary"><?php echo $h($p['email']); ?></div></td>
                        <td><?php echo $h($p['motivo']); ?>
                            <?php $usi_p = $cont_r->usi((int)$p['id']); if ($usi_p): ?><div><span class="text-secondary">Quantità:</span> <strong><?php echo $h(\App\Risorse\ServizioContatoriRisorse::testoUsi($usi_p)); ?></strong></div><?php endif; ?>
                            <?php foreach (\App\Risorse\ServizioCampiRisorsa::coppie($p['risposte_json'] ?? null) as [$k_d, $v_d]): ?><div><span class="text-secondary"><?php echo $h($k_d); ?>:</span> <?php echo $h($v_d); ?></div><?php endforeach; ?></td>
                        <td><span class="badge text-bg-<?php echo $st[1]; ?>"><?php echo $h($st[0]); ?></span></td>
                        <td class="text-end">
                            <?php if ($puo): ?>
                            <form method="POST" class="d-flex flex-wrap gap-1 justify-content-end m-0"><?php csrf_field(); ?><input type="hidden" name="pren_id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="risorsa" value="<?php echo $rid; ?>">
                                <input type="text" name="nota" class="form-control form-control-sm" style="max-width:150px;" maxlength="500" placeholder="nota (facoltativa)" aria-label="Nota per chi ha prenotato">
                                <?php if ($p['stato'] === 'da_approvare'): ?>
                                    <button type="submit" name="decidi" value="approva" class="btn btn-sm btn-success fw-bold">Approva</button>
                                    <button type="submit" name="decidi" value="rifiuta" class="btn btn-sm btn-outline-danger">Rifiuta</button>
                                <?php else: ?>
                                    <button type="submit" name="decidi" value="annulla" class="btn btn-sm btn-outline-secondary" data-confirm="Annullare la prenotazione? Chi l'ha fatta riceve un'email.">Annulla</button>
                                <?php endif; ?>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
            <?php if ((int)$r['diario_attivo']): $reg = $diario_r->registro($rid); $manca = $diario_r->mancanti($rid); ?>
            <div class="border-top mt-3 pt-3" id="diario<?php echo $rid; ?>">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <h3 class="h6 fw-bold mb-0"><i class="fa fa-book-open me-1" style="color:#0369a1;" aria-hidden="true"></i>Diario di bordo</h3>
                    <span class="small text-secondary"><?php echo (int)$reg['totali']['viaggi']; ?> viaggi · <?php echo (int)$reg['totali']['km']; ?> km · <?php echo number_format($reg['totali']['litri'], 1, ',', '.'); ?> litri</span>
                    <a class="btn btn-sm btn-outline-success ms-auto" href="risorse_responsabile.php?diario_xlsx=<?php echo $rid; ?>"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a>
                </div>
                <?php if ($manca): ?><div class="alert alert-warning small py-2"><i class="fa fa-circle-exclamation me-1" aria-hidden="true"></i><strong><?php echo count($manca); ?> viaggi senza diario:</strong>
                    <?php foreach (array_slice($manca, 0, 6) as $mm): ?><a href="diario_bordo.php?c=<?php echo urlencode($mm['codice']); ?>" class="ms-2 text-nowrap"><?php echo date('d/m', strtotime($mm['inizio'])) . ' ' . $h(trim($mm['cognome'] . ' ' . $mm['nome'])); ?></a><?php endforeach; ?></div><?php endif; ?>
                <?php if ($reg['righe']): ?>
                <div class="table-responsive"><table class="table table-sm small align-middle mb-0">
                    <thead class="table-light"><tr><th>Data</th><th>Conducente</th><th class="text-end">Km iniziali</th><th class="text-end">Km finali</th><th class="text-end">Percorsi</th><th class="text-end">Litri</th><th></th></tr></thead><tbody>
                    <?php foreach (array_slice($reg['righe'], 0, 15) as $d): ?>
                        <tr><td><?php echo $d['data'] ? date('d/m/Y', strtotime($d['data'])) : '—'; ?></td><td><?php echo $h($d['conducente']); ?></td>
                            <td class="text-end"><?php echo $d['km_iniziali'] !== null ? (int)$d['km_iniziali'] : ''; ?></td><td class="text-end"><?php echo $d['km_finali'] !== null ? (int)$d['km_finali'] : ''; ?></td>
                            <td class="text-end fw-bold"><?php echo $d['km_percorsi'] !== null ? (int)$d['km_percorsi'] : ''; ?></td><td class="text-end"><?php echo $d['litri'] !== null ? number_format((float)$d['litri'], 1, ',', '.') : ''; ?></td>
                            <td class="text-end text-nowrap"><?php echo $d['verificato_il'] ? '<span class="badge text-bg-success" title="Verificato da ' . $h($d['verificato_da']) . '">verificato</span>' : ''; ?> <a href="diario_bordo.php?c=<?php echo urlencode($d['codice']); ?>" class="btn btn-sm btn-outline-secondary py-0">Apri</a></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                <?php else: ?><p class="small text-muted mb-0">Nessun diario compilato finora.</p><?php endif; ?>
            </div>
            <?php endif; ?>
        </div></div>
    <?php endforeach; ?>
</div>
<?php require_once 'footer.php'; ?>
