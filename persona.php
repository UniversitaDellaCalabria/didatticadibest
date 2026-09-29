<?php
// persona.php - Pagina pubblica di un referente scelto dall'anagrafe di Ateneo: foto, ruolo, settore,
// recapiti e ricevimento (dalle API pubbliche del portale Unical) con le attività del portale in cui è referente.
// Si vede solo per chi è referente di almeno un evento o progetto di un'area visibile.
require_once 'config.php';
require_once 'functions.php';

$id_p = trim((string)($_GET['id'] ?? ''));
$pers = persona_ateneo($conn, $id_p);

// Attività in cui la persona è referente (aree visibili)
$attivita = [];
if ($pers) {
    $like = '%"persona_id":"' . addcslashes(str_replace('"', '', $pers['id']), '%_\\') . '"%';
    $st = $conn->prepare("SELECT e.id, e.titolo, e.tipo, e.archiviato, e.locandina_path, p.slug, p.titolo AS area, d.referenti_json, d.data_inizio, d.data_fine
                          FROM progetti_dettagli d JOIN eventi e ON e.id = d.evento_id JOIN pagine_eventi p ON p.id = e.pagina_id
                          WHERE d.referenti_json LIKE ? AND IFNULL(p.visibile, 1) = 1 ORDER BY e.archiviato ASC, e.id DESC");
    $st->bind_param("s", $like); $st->execute();
    $res = $st->get_result();
    while ($res && $a = $res->fetch_assoc()) {
        foreach (json_decode((string)$a['referenti_json'], true) ?: [] as $rf) {
            if (($rf['persona_id'] ?? '') === $pers['id']) { $a['ruolo_ref'] = $rf['ruolo'] ?? ''; $a['email_ref'] = $rf['email'] ?? ''; $attivita[] = $a; break; }
        }
    }
}
if (!$pers || !$attivita) {
    http_response_code(404);
    $page_cfg['titolo'] = "Pagina non trovata";
    require_once 'header.php';
    echo '<div class="container my-5" style="max-width:800px;"><h1 class="fw-bold">Pagina non trovata</h1><p>La persona cercata non è referente di attività pubblicate sul portale.</p><p><a href="index.php">Torna alla home</a></p></div>';
    require_once 'footer.php';
    exit;
}

$det = dettaglio_persona($conn, $pers); // aggiornata dal portale di Ateneo al massimo una volta a settimana
$nome = nome_persona($pers);
$email = $pers['email'] ?: ($attivita[0]['email_ref'] ?? '');
$telefoni = !empty($det['telefoni']) ? $det['telefoni'] : array_filter([$pers['telefono']]);
$ufficio = ($det['ufficio'] ?? '') !== '' ? $det['ufficio'] : $pers['ufficio'];
$bio = ($det['bio'] ?? '') !== '' ? $det['bio'] : ($det['cv_breve'] ?? '');

$page_cfg['titolo'] = $nome;
require_once 'header.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
.ps h2 { font-size: 1.15rem; font-weight: 700; margin-top: 1.75rem; margin-bottom: .6rem; }
.ps-scheda { border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; }
.ps-att { display: flex; gap: .75rem; padding: .75rem 0; border-top: 1px solid #f1f5f9; }
.ps-att:first-child { border-top: 0; }
.ps-testo { white-space: pre-line; line-height: 1.65; }
</style>
<div class="container my-4 ps" style="max-width: 1100px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Referenti</span> <span class="text-secondary mx-1">/</span> <span class="text-secondary"><?php echo $h($nome); ?></span></nav>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
                <?php echo html_avatar_persona($det['foto'] ?? '', 'Foto di ' . $nome, 112); ?>
                <div>
                    <h1 class="fw-bold mb-1"><?php echo $h($nome); ?></h1>
                    <?php if ($pers['ruolo'] !== ''): ?><div class="fw-semibold"><?php echo $h($pers['ruolo']); ?></div><?php endif; ?>
                    <?php if ($pers['ssd'] !== ''): ?><div class="text-secondary">Settore <?php echo $h($pers['ssd_cod'] . ' – ' . $pers['ssd']); ?></div><?php endif; ?>
                    <?php if ($pers['struttura'] !== ''): ?><div class="text-secondary small"><?php echo $h($pers['struttura']); ?></div><?php endif; ?>
                    <?php if (!$pers['attivo']): ?><span class="badge bg-secondary mt-1">Non più in servizio presso la struttura</span><?php endif; ?>
                </div>
            </div>

            <?php if ($bio !== ''): ?>
                <h2>Profilo</h2>
                <div class="ps-testo"><?php echo $h($bio); ?></div>
            <?php endif; ?>

            <h2>Attività sul portale</h2>
            <div>
                <?php foreach ($attivita as $a):
                    $url_a = $a['slug'] . '.php?' . ($a['tipo'] === 'progetto' ? 'progetto' : 'evento') . '=' . (int)$a['id']; ?>
                    <div class="ps-att">
                        <div class="text-secondary pt-1" aria-hidden="true"><i class="fa <?php echo $a['tipo'] === 'progetto' ? 'fa-diagram-project' : 'fa-calendar-day'; ?>"></i></div>
                        <div>
                            <a href="<?php echo $h($url_a); ?>" class="fw-bold"><?php echo $h($a['titolo']); ?></a>
                            <div class="small text-secondary">
                                <?php echo $h($a['area']); ?><?php if ($a['ruolo_ref'] !== ''): ?> · <?php echo $h($a['ruolo_ref']); ?><?php endif; ?>
                                <?php if (!empty($a['archiviato'])): ?> · <span class="badge bg-light text-secondary border">Conclusa</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <aside class="col-lg-4">
            <div class="ps-scheda p-4">
                <h2 class="mt-0">Contatti</h2>
                <?php if ($email !== ''): ?><div class="mb-1"><i class="fa fa-envelope me-2 text-secondary" aria-hidden="true"></i><a href="mailto:<?php echo $h($email); ?>"><?php echo $h($email); ?></a></div><?php endif; ?>
                <?php foreach ($telefoni as $tel): ?><div class="mb-1"><i class="fa fa-phone me-2 text-secondary" aria-hidden="true"></i><a href="tel:<?php echo $h(preg_replace('/[^0-9+]/', '', $tel)); ?>"><?php echo $h($tel); ?></a></div><?php endforeach; ?>
                <?php if ($ufficio !== ''): ?><div class="mb-1"><i class="fa fa-location-dot me-2 text-secondary" aria-hidden="true"></i><?php echo $h($ufficio); ?></div><?php endif; ?>
                <?php if (($det['orcid'] ?? '') !== ''): ?><div class="mb-1"><i class="fa fa-id-card me-2 text-secondary" aria-hidden="true"></i>ORCID <a href="https://orcid.org/<?php echo $h($det['orcid']); ?>" target="_blank" rel="noopener"><?php echo $h($det['orcid']); ?><span class="visually-hidden"> (si apre in una nuova scheda)</span></a></div><?php endif; ?>

                <?php if (($det['ricevimento'] ?? '') !== ''): ?>
                    <h2>Ricevimento</h2>
                    <div class="ps-testo small"><?php echo $h($det['ricevimento']); ?></div>
                <?php endif; ?>

                <h2>Approfondisci</h2>
                <ul class="list-unstyled mb-0">
                    <li class="mb-1"><a href="<?php echo $h(url_portale_persona($pers)); ?>" target="_blank" rel="noopener"><i class="fa fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>Pagina sul portale di Ateneo<span class="visually-hidden"> (si apre in una nuova scheda)</span></a></li>
                    <?php if (($det['cv_ita'] ?? '') !== ''): ?><li class="mb-1"><a href="<?php echo $h($det['cv_ita']); ?>" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Curriculum (PDF)<span class="visually-hidden"> (si apre in una nuova scheda)</span></a></li><?php endif; ?>
                    <?php if (($det['cv_en'] ?? '') !== ''): ?><li class="mb-1"><a href="<?php echo $h($det['cv_en']); ?>" target="_blank" rel="noopener" hreflang="en"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Curriculum in inglese (PDF)<span class="visually-hidden"> (si apre in una nuova scheda)</span></a></li><?php endif; ?>
                    <?php foreach ($det['siti'] ?? [] as $sito): ?><li class="mb-1"><a href="<?php echo $h($sito); ?>" target="_blank" rel="noopener"><i class="fa fa-globe me-1" aria-hidden="true"></i><?php echo $h(parse_url($sito, PHP_URL_HOST)); ?><span class="visually-hidden"> (si apre in una nuova scheda)</span></a></li><?php endforeach; ?>
                </ul>
                <p class="small text-secondary mt-3 mb-0">Dati dal portale dell'Università della Calabria.</p>
            </div>
        </aside>
    </div>
</div>
<?php require_once 'footer.php'; ?>
