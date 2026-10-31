<?php
// corsi.php - I corsi di studio del Dipartimento (dall'anagrafe di Ateneo) e i loro insegnamenti dell'anno accademico in corso, con ricerca per insegnamento o docente.
// I corsi mostrati sono quelli resi visibili dal pannello (Anagrafe › Corsi); i dati degli insegnamenti arrivano dalle API pubbliche del portale Unical.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$app = \App\Core\App::per($conn);
$corsi_srv = $app->get(\App\Anagrafi\ServizioCorsi::class);
$ins_rep = $app->get(\App\Anagrafi\InsegnamentoRepository::class);

$gruppi = $corsi_srv->visibili(); // tipo (es. Laurea) => corsi
$codice = trim((string)($_GET['corso'] ?? ''));
$corso = $codice !== '' ? $corsi_srv->corso($codice) : null;
if ($corso && (int)($corso['visibile'] ?? 1) !== 1) { $corso = null; }
$cerca = trim((string)($_GET['q'] ?? ''));

$dip_proprio = $ins_rep->dipartimentoProprio(); // la pagina mostra i corsi del Dipartimento, anche se l'archivio ha tutto l'Ateneo
$anno = $ins_rep->contaPresenti(\App\Anagrafi\Anagrafe::annoAccademico(new \DateTimeImmutable()), $dip_proprio) ? \App\Anagrafi\Anagrafe::annoAccademico(new \DateTimeImmutable()) : $ins_rep->annoPiuRecente($dip_proprio);
$insegnamenti = [];
if ($corso) {
    $insegnamenti = $anno ? $ins_rep->elenco($anno, (string)$corso['codice'], 0, '', $cerca, $dip_proprio) : [];
} elseif ($cerca !== '' && $anno) {
    $insegnamenti = $ins_rep->elenco($anno, '', 0, '', $cerca, $dip_proprio);
}
if ($corso && empty($corso['regdid_id'])) { $corso = $corsi_srv->completaRegdid($corso); }
$url_corso = $corso ? \App\Anagrafi\Testi::urlCorsoStudio($corso) : '';

$page_cfg['titolo'] = $corso ? (string)$corso['nome'] : 'Corsi di studio';
require_once 'header.php';
?>
<style>
.cs-card { display: block; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; padding: 16px 18px; color: inherit; text-decoration: none; height: 100%; transition: box-shadow .15s, border-color .15s; }
.cs-card:hover, .cs-card:focus-visible { border-color: #990000; box-shadow: 0 6px 18px rgba(0,0,0,.08); color: inherit; }
.cs-card strong { display: block; font-size: 1.05rem; color: #1f2937; }
</style>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span>
        <?php if ($corso || $cerca !== ''): ?><a href="corsi.php">Corsi di studio</a> <span class="text-secondary mx-1">/</span> <?php echo $corso ? $h($corso['nome']) : 'Ricerca'; ?><?php else: ?>Corsi di studio<?php endif; ?></nav>
    <h1 class="fw-bold mb-1"><?php echo $corso ? $h($corso['nome']) : 'Corsi di studio e insegnamenti'; ?></h1>
    <?php if ($corso): ?>
        <p class="text-secondary"><?php echo $h(implode(' · ', array_filter([(string)$corso['tipo_descrizione'], !empty($corso['durata']) ? $corso['durata'] . ' anni' : '', (string)$corso['lingua']], fn($x) => $x !== ''))); ?></p>
        <?php if ($url_corso !== ''): ?><p><a class="btn btn-outline-danger" href="<?php echo $h($url_corso); ?>" target="_blank" rel="noopener noreferrer"><i class="fa fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>Pagina ufficiale del corso (piano di studi, regolamento)</a></p><?php endif; ?>
    <?php else: ?>
        <p class="text-secondary">I corsi del Dipartimento e gli insegnamenti dell'anno accademico<?php echo $anno ? ' ' . (int)$anno . '/' . ((int)$anno + 1) : ''; ?>. Scegli un corso oppure cerca un insegnamento o un docente.</p>
    <?php endif; ?>

    <form method="GET" class="d-flex gap-2 mb-4" role="search">
        <?php if ($corso): ?><input type="hidden" name="corso" value="<?php echo $h($corso['codice']); ?>"><?php endif; ?>
        <label class="visually-hidden" for="cq">Cerca un insegnamento</label>
        <input class="form-control form-control-lg" id="cq" name="q" value="<?php echo $h($cerca); ?>" placeholder="<?php echo $corso ? 'Cerca tra gli insegnamenti di questo corso' : 'Cerca un insegnamento, un docente o un codice'; ?>" autocomplete="off">
        <button class="btn btn-danger btn-lg px-4" type="submit"><i class="fa fa-search" aria-hidden="true"></i><span class="visually-hidden"> Cerca</span></button>
    </form>

    <?php if (!$corso && $cerca === ''): ?>
        <?php if (!$gruppi): ?><div class="alert alert-light border">L'elenco dei corsi non è ancora disponibile.</div><?php endif; ?>
        <?php foreach ($gruppi as $tipo => $elenco): ?>
            <h2 class="h5 fw-bold mt-4 mb-2"><?php echo $h($tipo); ?></h2>
            <div class="row g-3">
            <?php foreach ($elenco as $c): ?>
                <div class="col-md-6"><a class="cs-card" href="corsi.php?corso=<?php echo urlencode($c['codice']); ?>"><strong><?php echo $h($c['nome']); ?></strong><span class="small text-secondary"><?php echo $h(implode(' · ', array_filter([!empty($c['durata']) ? $c['durata'] . ' anni' : '', (string)$c['lingua']], fn($x) => $x !== ''))); ?></span></a></div>
            <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <?php if (!$insegnamenti): ?>
            <div class="alert alert-light border"><?php echo $cerca !== '' ? 'Nessun insegnamento corrisponde alla ricerca.' : 'Gli insegnamenti di questo corso non sono ancora disponibili.'; ?> <?php echo $url_corso !== '' ? 'Li trovi sulla <a href="' . $h($url_corso) . '" target="_blank" rel="noopener noreferrer">pagina ufficiale del corso</a>.' : ''; ?></div>
        <?php else:
            $per_anno = [];
            foreach ($insegnamenti as $i) { $per_anno[$corso ? (int)$i['anno_corso'] : 0][] = $i; }
            ksort($per_anno);
            foreach ($per_anno as $a_c => $righe): ?>
            <h2 class="h5 fw-bold mt-4 mb-2"><?php echo $corso ? ($a_c > 0 ? $h($a_c . '° anno') : 'Altri insegnamenti') : count($insegnamenti) . ' insegnamenti trovati'; ?></h2>
            <div class="table-responsive"><table class="table table-sm align-middle bg-white border">
                <thead><tr><th scope="col">Insegnamento</th><?php if (!$corso): ?><th scope="col">Corso</th><?php endif; ?><th scope="col">Docente</th><th scope="col" class="text-end">CFU</th><th scope="col">Semestre</th></tr></thead>
                <tbody><?php foreach ($righe as $i): ?><tr>
                    <td><?php echo $h($i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : '')); ?><?php if ($i['ssd_cod'] !== ''): ?> <span class="small text-secondary"><?php echo $h($i['ssd_cod']); ?></span><?php endif; ?></td>
                    <?php if (!$corso): ?><td class="small"><a href="corsi.php?corso=<?php echo urlencode((string)$i['cds_cod']); ?>"><?php echo $h($i['cds_nome']); ?></a></td><?php endif; ?>
                    <td><?php echo $i['docente'] !== '' ? '<a href="docenti.php?q=' . urlencode(explode(' ', trim((string)$i['docente']))[0]) . '">' . $h($i['docente']) . '</a>' : '<span class="text-secondary">—</span>'; ?></td>
                    <td class="text-end"><?php echo $i['cfu'] !== null ? $h(rtrim(rtrim(number_format((float)$i['cfu'], 1, ',', ''), '0'), ',')) : '—'; ?></td>
                    <td class="small"><?php echo $h($i['semestre']); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endforeach; endif; ?>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
