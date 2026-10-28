<?php
// Pagina pubblica della disponibilità dei docenti per tesi (elenco) o tirocini (carte): la includono disponibilita_tesi.php e disponibilita_tirocini.php,
// che prima impostano $tipo_disp ('tesi' o 'tirocinio'). Compare solo chi ha messo la disponibilità; il semaforo si calcola dai posti liberi.
require_once __DIR__ . '/disponibilita_vista.php';

$app_d = \App\Core\App::per($conn);
$tesi = $tipo_disp === 'tesi';
$tutti_d = $app_d->get(\App\Disponibilita\DisponibilitaRepository::class)->elenco($tipo_disp);
$ora_d = new DateTimeImmutable('now');
$h = static fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$cerca = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$f_corso = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($_GET['corso'] ?? ''));
$solo_disp = !empty($_GET['disponibili']);
$corsi_usati = [];
foreach ($tutti_d as &$r_d) {
    $r_d['sem'] = \App\Disponibilita\Semaforo::di($r_d, $ora_d);
    foreach ($r_d['corsi_lista'] as $cod => $nome) $corsi_usati[$cod] = $nome;
}
unset($r_d);
asort($corsi_usati);

$elenco = array_values(array_filter($tutti_d, function (array $r) use ($cerca, $f_corso, $solo_disp): bool {
    if ($solo_disp && !in_array($r['sem']['stato'], ['verde', 'arancione'], true)) return false;
    // «nessun corso scelto» vuol dire tutti i corsi
    if ($f_corso !== '' && $r['corsi_lista'] && !isset($r['corsi_lista'][$f_corso])) return false;
    foreach (array_filter(preg_split('/\s+/u', $cerca)) as $w) {
        if (mb_stripos($r['cognome'] . ' ' . $r['nome'] . ' ' . $r['temi'] . ' ' . $r['attivita'] . ' ' . $r['obiettivi'] . ' ' . $r['sede'] . ' ' . ($r['ssd'] ?? ''), $w) === false) return false;
    }
    return true;
}));
// Prima chi ha posti, poi l'ultimo posto, poi il completo, poi chi non accetta o è da riconfermare; a parità, per cognome
$ordine = ['verde' => 0, 'arancione' => 1, 'rosso' => 2, 'grigio' => 3];
usort($elenco, fn($a, $b) => [$ordine[$a['sem']['stato']], mb_strtolower($a['cognome'])] <=> [$ordine[$b['sem']['stato']], mb_strtolower($b['cognome'])]);

$titolo_d = $tesi ? 'Disponibilità dei docenti per la tesi' : 'Disponibilità dei docenti per i tirocini';
$page_cfg['titolo'] = $titolo_d;
require_once 'header.php';
echo disp_stile();
$contatta = function (array $r) use ($h, $tesi): string {
    $mail = trim((string)($r['email_pubblica'] ?? ''));
    if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) return '';
    $ogg = rawurlencode(($tesi ? 'Richiesta di tesi' : 'Richiesta di tirocinio') . ' - ' . $r['cognome']);
    return '<a class="btn btn-sm btn-outline-primary fw-bold" href="mailto:' . $h($mail) . '?subject=' . $ogg . '"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Scrivi al docente</a>';
};
?>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <?php echo $h($titolo_d); ?></nav>
    <h1 class="fw-bold mb-1"><?php echo $h($titolo_d); ?></h1>
    <p class="text-secondary mb-3"><?php echo $tesi
        ? 'I docenti che hanno indicato se accettano nuovi tesisti, con i posti rimasti, i temi e gli obiettivi formativi. Il semaforo si aggiorna quando il docente cambia i suoi posti.'
        : 'I docenti che hanno indicato se accettano nuovi tirocinanti, con i posti rimasti, le attività e gli obiettivi formativi. I tirocini si attivano sulla piattaforma TSP 2.0 di Ateneo.'; ?></p>

    <form method="GET" class="row g-2 align-items-end mb-3">
        <div class="col-md-4"><label class="form-label small fw-semibold" for="q">Cerca</label><input type="search" class="form-control" id="q" name="q" maxlength="80" value="<?php echo $h($cerca); ?>" placeholder="Docente, tema, laboratorio"></div>
        <div class="col-md-5"><label class="form-label small fw-semibold" for="corso">Corso di laurea</label>
            <select class="form-select" id="corso" name="corso"><option value="">Tutti i corsi</option>
                <?php foreach ($corsi_usati as $cod => $nome): ?><option value="<?php echo $h($cod); ?>" <?php echo $f_corso === (string)$cod ? 'selected' : ''; ?>><?php echo $h($nome); ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-3 d-flex align-items-center gap-3">
            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="disp" name="disponibili" value="1" <?php echo $solo_disp ? 'checked' : ''; ?>><label class="form-check-label small" for="disp">Solo con posti</label></div>
            <button type="submit" class="btn btn-primary fw-bold">Filtra</button>
        </div>
    </form>
    <div class="small text-secondary disp-legenda mb-3">
        <?php foreach ([['verde', 'Disponibile'], ['arancione', 'Ultimo posto'], ['rosso', 'Al completo'], ['grigio', 'Non accetta o da riconfermare']] as [$st, $et]): ?>
            <span><?php echo disp_semaforo(['stato' => $st, 'etichetta' => $et, 'dettaglio' => ''], false); ?></span>
        <?php endforeach; ?>
    </div>

    <?php if (!$elenco): ?>
        <?php echo function_exists('ui_vuoto') ? ui_vuoto('fa-user-clock', $tutti_d ? 'Nessun docente corrisponde alla ricerca' : 'Nessuna disponibilità indicata per ora', $tutti_d ? 'Prova a togliere un filtro.' : 'I docenti la inseriscono dalla loro area personale: ricontrolla tra poco.', $tutti_d ? ($tesi ? 'disponibilita_tesi.php' : 'disponibilita_tirocini.php') : '', $tutti_d ? 'Azzera i filtri' : '') : ''; ?>
    <?php elseif ($tesi): ?>
        <div class="d-grid gap-2">
        <?php foreach ($elenco as $r): $s = $r['sem']; ?>
            <article class="disp-riga">
                <div class="d-flex flex-wrap align-items-start gap-3">
                    <div style="min-width:0;flex:1 1 260px;">
                        <h2 class="h6 fw-bold mb-0"><?php echo $h($r['cognome'] . ' ' . $r['nome']); ?></h2>
                        <?php if (!empty($r['ssd'])): ?><div class="small text-secondary"><?php echo $h($r['ssd']); ?><?php echo $r['sede'] !== '' ? ' · ' . $h($r['sede']) : ''; ?></div><?php elseif ($r['sede'] !== ''): ?><div class="small text-secondary"><?php echo $h($r['sede']); ?></div><?php endif; ?>
                        <div class="mt-1"><?php if ($r['corsi_lista']): foreach ($r['corsi_lista'] as $nome): ?><span class="disp-chip"><?php echo $h($nome); ?></span><?php endforeach; else: ?><span class="disp-chip">Tutti i corsi</span><?php endif; ?></div>
                    </div>
                    <div style="flex:1 1 220px;"><?php echo disp_semaforo($s); ?><div class="small text-secondary mt-1">Aggiornata <?php echo $h(\App\Disponibilita\Semaforo::quandoTesto($s['giorni'])); ?></div></div>
                    <div class="ms-auto"><?php echo in_array($s['stato'], ['verde', 'arancione'], true) ? $contatta($r) : ''; ?></div>
                </div>
                <?php if ($r['temi'] !== '' || $r['attivita'] !== '' || $r['obiettivi'] !== '' || $r['note'] !== ''): ?>
                    <details class="mt-2"><summary class="small fw-semibold" style="cursor:pointer;">Temi, attività e obiettivi</summary>
                        <?php if ($r['temi'] !== ''): ?><p class="small mb-1 mt-2"><strong>Temi:</strong> <?php echo $h($r['temi']); ?></p><?php endif; ?>
                        <?php if ($r['attivita'] !== ''): ?><p class="small mb-1"><strong>Attività:</strong> <?php echo nl2br($h($r['attivita'])); ?></p><?php endif; ?>
                        <?php if ($r['obiettivi'] !== ''): ?><p class="small mb-1"><strong>Obiettivi formativi:</strong> <?php echo nl2br($h($r['obiettivi'])); ?></p><?php endif; ?>
                        <?php if ($r['note'] !== ''): ?><p class="small mb-0"><strong>Come candidarsi:</strong> <?php echo $h($r['note']); ?></p><?php endif; ?>
                    </details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="row g-3">
        <?php foreach ($elenco as $r): $s = $r['sem']; $posti = (int)$r['posti']; $occ = min($posti, (int)$r['occupati']); ?>
            <div class="col-md-6 col-lg-4"><article class="disp-riga h-100 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start gap-2"><h2 class="h6 fw-bold mb-0"><?php echo $h($r['cognome'] . ' ' . $r['nome']); ?></h2></div>
                <div class="small text-secondary mb-2"><?php echo $h(trim(($r['ssd'] ?? '') . ($r['sede'] !== '' ? ' · ' . $r['sede'] : ''), ' ·')); ?></div>
                <div class="mb-2"><?php echo disp_semaforo($s, false); ?></div>
                <?php if ($posti > 0 && $s['stato'] !== 'grigio'): ?>
                    <div class="disp-barra mb-1" role="img" aria-label="<?php echo $occ . ($occ === 1 ? ' tirocinante' : ' tirocinanti'); ?> su <?php echo $posti; ?>">
                        <?php for ($i = 0; $i < $posti; $i++): ?><span style="<?php echo $i < $occ ? 'background:' . ($s['stato'] === 'rosso' ? '#b91c1c' : ($s['stato'] === 'arancione' ? '#d97706' : '#15803d')) : ''; ?>"></span><?php endfor; ?>
                    </div>
                    <div class="small"><?php echo $occ . ($occ === 1 ? ' tirocinante' : ' tirocinanti'); ?> su <?php echo $posti; ?><?php echo $s['liberi'] > 0 ? ' · ' . $s['liberi'] . ($s['liberi'] === 1 ? ' posto libero' : ' posti liberi') : ''; ?></div>
                <?php endif; ?>
                <div class="mt-2"><?php if ($r['corsi_lista']): foreach ($r['corsi_lista'] as $nome): ?><span class="disp-chip"><?php echo $h($nome); ?></span><?php endforeach; else: ?><span class="disp-chip">Tutti i corsi</span><?php endif; ?></div>
                <?php if ($r['temi'] !== ''): foreach (array_filter(array_map('trim', explode(',', $r['temi']))) as $t): ?><span class="disp-chip" style="background:rgba(14,116,144,.14);"><?php echo $h($t); ?></span><?php endforeach; endif; ?>
                <?php if ($r['attivita'] !== '' || $r['obiettivi'] !== '' || $r['note'] !== ''): ?>
                    <details class="mt-2"><summary class="small fw-semibold" style="cursor:pointer;">Attività e obiettivi</summary>
                        <?php if ($r['attivita'] !== ''): ?><p class="small mb-1 mt-2"><strong>Attività:</strong> <?php echo nl2br($h($r['attivita'])); ?></p><?php endif; ?>
                        <?php if ($r['obiettivi'] !== ''): ?><p class="small mb-1"><strong>Obiettivi formativi:</strong> <?php echo nl2br($h($r['obiettivi'])); ?></p><?php endif; ?>
                        <?php if ($r['note'] !== ''): ?><p class="small mb-0"><strong>Come candidarsi:</strong> <?php echo $h($r['note']); ?></p><?php endif; ?>
                    </details>
                <?php endif; ?>
                <div class="mt-auto pt-2 d-flex justify-content-between align-items-center"><span class="small text-secondary">Aggiornata <?php echo $h(\App\Disponibilita\Semaforo::quandoTesto($s['giorni'])); ?></span><?php echo in_array($s['stato'], ['verde', 'arancione'], true) ? $contatta($r) : ''; ?></div>
            </article></div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="small text-secondary mt-4">
        <?php if ($tesi): ?>Cerchi un tirocinio? <a href="disponibilita_tirocini.php">Disponibilità per i tirocini</a> · <a href="percorso_tesi.php">Come si chiede la tesi</a>
        <?php else: ?>Cerchi una tesi? <a href="disponibilita_tesi.php">Disponibilità per la tesi</a> · <a href="percorso_tirocini.php">Come funzionano i tirocini</a> · <a href="https://unical.tsp.esse3.cineca.it" target="_blank" rel="noopener">Piattaforma TSP 2.0</a><?php endif; ?>
    </p>
</div>
<?php require_once 'footer.php'; ?>
