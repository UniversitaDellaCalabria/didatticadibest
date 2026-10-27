<?php
// assistenza_storico.php - Le mie conversazioni (Area personale): lo storico delle chat con Didacticus e con l'assistenza, da riaprire e rileggere.
// La chat di assistenza parte sempre da una conversazione nuova; qui si ritrovano le precedenti (assistenza.php?c=…).
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$uid = (int)$_SESSION['utente_id'];
$app = \App\Core\App::per($conn);
$Cost = \App\Assistenza\Costanti::class;
$conversazioni = $app->get(\App\Assistenza\ConversazioniRepository::class)->storicoDi($uid, 100);
try { $assist_on = $app->get(\App\Assistenza\OrariAssistenza::class)->attiva(); } catch (\Throwable $e_as) { $assist_on = false; }

// Come lo stato appare a chi ha chiesto aiuto (colore del distintivo, parole)
$stati = [
    $Cost::STATO_BOT => ['Con Didacticus', 'bg-info text-dark'],
    $Cost::STATO_NUOVA => ['In attesa dell\'ufficio', 'bg-warning text-dark'],
    $Cost::STATO_ASSEGNATA => ['In carico all\'ufficio', 'bg-primary'],
    $Cost::STATO_CHIUSA => ['Chiusa', 'bg-secondary'],
];
$quando = fn($s) => $s ? date('d/m/Y H:i', strtotime((string)$s)) : '';

$page_cfg['titolo'] = 'Le mie conversazioni';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 860px;">
    <nav aria-label="breadcrumb" class="small mb-2"><a href="area_personale.php">Area personale</a> <span class="text-secondary">/</span> Le mie conversazioni</nav>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h3 fw-bold mb-0 me-auto"><i class="fa fa-clock-rotate-left text-danger me-2" aria-hidden="true"></i>Le mie conversazioni</h1>
        <?php if ($assist_on): ?><a class="btn btn-danger fw-bold" style="background-color:#B80000;border:none;" href="assistenza.php"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuova richiesta</a><?php endif; ?>
    </div>
    <p class="text-secondary">Qui trovi le chat che hai fatto con Didacticus e con l'assistenza. Ogni volta che apri la chat si parte da una conversazione nuova: quelle precedenti restano qui per 12 mesi.</p>

    <?php if (!$conversazioni): ?>
        <div class="alert alert-light border"><i class="fa fa-comments me-1" aria-hidden="true"></i>Non hai ancora fatto nessuna richiesta all'assistenza.</div>
    <?php else: ?>
        <ul class="list-group shadow-sm" aria-label="Le tue conversazioni">
        <?php foreach ($conversazioni as $c):
            [$nome_stato, $classe_stato] = $stati[$c['stato']] ?? [$c['stato'], 'bg-secondary'];
            $anteprima = trim((string)preg_replace('/\s+/u', ' ', (string)$c['primo_testo']));
            if (mb_strlen($anteprima) > 140) $anteprima = mb_substr($anteprima, 0, 140) . '…';
            $da_leggere = (int)$c['non_letti']; ?>
            <li class="list-group-item p-0">
                <a class="d-flex flex-wrap align-items-center gap-2 p-3 text-decoration-none text-dark" href="assistenza.php?c=<?php echo (int)$c['id']; ?>">
                    <span class="flex-grow-1" style="min-width: 220px;">
                        <span class="d-block fw-bold"><?php echo $h($Cost::nomeArgomento((string)$c['argomento'])); ?>
                            <?php if ($da_leggere > 0 && $c['stato'] !== $Cost::STATO_CHIUSA): ?><span class="badge bg-danger ms-1"><?php echo $da_leggere; ?> da leggere</span><?php endif; ?></span>
                        <span class="d-block small text-secondary"><?php echo $h($anteprima !== '' ? $anteprima : '(nessun messaggio)'); ?></span>
                        <?php if ((string)$c['contesto_testo'] !== ''): ?><span class="d-block small text-secondary"><i class="fa fa-link me-1" aria-hidden="true"></i><?php echo $h($c['contesto_testo']); ?></span><?php endif; ?>
                    </span>
                    <span class="text-md-end small">
                        <span class="badge <?php echo $h($classe_stato); ?>"><?php echo $h($nome_stato); ?></span>
                        <span class="d-block text-secondary mt-1"><?php echo $h($quando($c['ultimo_il'] ?: $c['creato_il'])); ?> · <?php echo (int)$c['n_messaggi']; ?> messaggi</span>
                    </span>
                    <span class="btn btn-sm btn-outline-secondary fw-bold">Apri</span>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require_once 'footer.php';
