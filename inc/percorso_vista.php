<?php
// inc/percorso_vista.php - Pagina «a passi» (1-2-3) per un percorso dello studente (tesi, tirocinio…): titolo, introduzione, passi con pulsanti, rimando alle domande frequenti e ai contatti.
// $p: titolo, intro, argomento (chiave delle domande frequenti), passi = [[titolo, testo, [[etichetta, indirizzo, esterno?], …], nota?], …]
if (!function_exists('percorso_vista')) {
    function percorso_vista(array $p): void
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        ?>
<style>
.pc-passo { display: flex; gap: 16px; padding: 18px 20px; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; margin-bottom: 12px; }
.pc-num { flex: 0 0 44px; width: 44px; height: 44px; border-radius: 50%; background: #990000; color: #fff; font-weight: 700; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; }
.pc-passo h2 { font-size: 1.15rem; font-weight: 700; margin: 0 0 4px; }
.pc-passo p { margin: 0 0 8px; line-height: 1.6; }
.pc-azioni { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.pc-nota { font-size: .9rem; color: #546072; margin-top: 8px; }
</style>
<div class="container my-4" style="max-width: 860px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <?php echo $h($p['titolo']); ?></nav>
    <h1 class="fw-bold mb-1"><?php echo $h($p['titolo']); ?></h1>
    <p class="text-secondary mb-4"><?php echo $h($p['intro']); ?></p>
    <ol class="list-unstyled m-0 p-0">
    <?php foreach ($p['passi'] as $i => [$titolo, $testo, $azioni, $nota]): ?>
        <li class="pc-passo">
            <span class="pc-num" aria-hidden="true"><?php echo $i + 1; ?></span>
            <div>
                <h2><span class="visually-hidden">Passo <?php echo $i + 1; ?>: </span><?php echo $h($titolo); ?></h2>
                <p><?php echo $h($testo); ?></p>
                <?php if ($azioni): ?><div class="pc-azioni"><?php foreach ($azioni as [$et, $url, $esterno]): ?>
                    <a class="btn btn-sm btn-outline-danger" href="<?php echo $h($url); ?>"<?php echo $esterno ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo $h($et); ?></a>
                <?php endforeach; ?></div><?php endif; ?>
                <?php if ($nota !== ''): ?><div class="pc-nota"><i class="fa fa-circle-info me-1" aria-hidden="true"></i><?php echo $h($nota); ?></div><?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    </ol>
    <div class="card border-0 shadow-sm mt-4" style="border-radius:14px;background:#f8fafc;"><div class="card-body d-flex flex-wrap align-items-center gap-3">
        <i class="fa fa-circle-question fs-3 text-danger" aria-hidden="true"></i>
        <div class="flex-grow-1"><strong>Hai ancora un dubbio?</strong><div class="small text-secondary">Guarda le domande frequenti su questo argomento, controlla a chi rivolgerti o scrivi all'assistenza.</div></div>
        <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-secondary" href="domande_frequenti.php?argomento=<?php echo $h($p['argomento']); ?>">Domande frequenti</a>
            <a class="btn btn-outline-secondary" href="contatti.php">Chi contattare</a><a class="btn btn-danger fw-bold" href="assistenza.php">Assistenza</a></div>
    </div></div>
</div>
<?php
    }
}
