<?php
// =========================================================================
// Il mio calendario: in un posto solo gli eventi prenotati, le aule e i laboratori, il ricevimento e le scadenze, con il file .ics da scaricare e il
// calendario a cui abbonare il telefono (link segreto, che si rigenera da qui). Si arriva dal cruscotto (area_personale.php).
// Logica in src/Portale/CalendarioPersonale.php; il file .ics lo serve calendario_ics.php.
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

$cal = \App\Core\App::per($conn)->get(\App\Portale\CalendarioPersonale::class);

// Nuovo link di abbonamento: il vecchio smette di funzionare
if (isset($_POST['rigenera_link'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $cal->rigenera($u_id);
    $_SESSION['msg_area_pers'] = '<div class="alert alert-success shadow-sm">Nuovo link creato: il calendario sul telefono va abbonato di nuovo con il link qui sotto.</div>';
    header('Location: calendario.php#abbonati');
    exit;
}

$oggi = date('Y-m-d');
$voci = $cal->voci($user_info, $oggi, date('Y-m-d', strtotime('+90 days')));
$tipi_presenti = array_values(array_unique(array_column($voci, 'tipo')));
$per_giorno = [];
foreach ($voci as $v) {
    $per_giorno[substr($v['inizio'], 0, 10)][] = $v;
}
$link_feed = url_base_sito() . '/calendario_ics.php?t=' . $cal->token($u_id);
$link_webcal = preg_replace('#^https?://#', 'webcal://', $link_feed);
$settimana = count(array_filter($voci, static fn (array $v): bool => substr($v['inizio'], 0, 10) <= date('Y-m-d', strtotime('+6 days'))));
$giorni_it = \App\Risorse\Costanti::GIORNI;

$page_cfg['titolo'] = 'Il mio calendario';
require_once 'header.php';
echo ui_tema_script();
?>

<style>
    .cal-giorno { font-weight: 700; font-size: .95rem; margin: 22px 0 8px; color: var(--ui-testo); }
    .cal-giorno small { font-weight: 600; color: var(--ui-testo-2); }
    .cal-voce { display: flex; gap: 14px; align-items: flex-start; padding: 12px 14px; margin-bottom: 8px; background: var(--ui-card); border: 1px solid var(--ui-bordo); border-left: 5px solid var(--tc); border-radius: 12px; }
    .cal-ora { flex: 0 0 74px; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--ui-testo); }
    .cal-ora small { display: block; font-weight: 500; color: var(--ui-testo-2); }
    .cal-titolo { font-weight: 700; color: var(--ui-testo); text-decoration: none; overflow-wrap: anywhere; }
    .cal-meta { font-size: .82rem; color: var(--ui-testo-2); margin-top: 2px; }
    .cal-filtri .btn { border-radius: 999px; }
</style>

<div class="container my-4" style="max-width: 900px;">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <?php echo ui_intestazione('Il mio calendario', 'area_personale.php', 'Area personale', 'Eventi, aule, ricevimenti e scadenze dei prossimi 90 giorni, tutti qui.'); ?>
        <div class="d-print-none"><?php echo ui_tema_pulsante(); ?></div>
    </div>

    <?php
    if (isset($_SESSION['msg_area_pers'])) {
        echo $_SESSION['msg_area_pers'];
        unset($_SESSION['msg_area_pers']);
    }
    ?>

    <div class="ui-numeri">
        <div class="ui-numero"><div class="val"><?php echo $settimana; ?></div><div class="lbl">Questa settimana</div></div>
        <div class="ui-numero"><div class="val"><?php echo count($voci); ?></div><div class="lbl">Nei 90 giorni</div></div>
        <a href="calendario_ics.php" class="ui-numero ui-azione">
            <span class="fw-bold"><i class="fa fa-download me-2" aria-hidden="true"></i>Scarica il calendario</span>
            <span class="small" style="opacity:.85;">File .ics per Google, Apple, Outlook</span>
        </a>
    </div>

    <?php if (!$voci): ?>
        <?php echo ui_vuoto('fa-calendar-day', 'Nessun appuntamento in vista', 'Quando prenoterai un evento, un\'aula o un appuntamento lo troverai qui, con le scadenze da ricordare.', 'index.php', 'Guarda gli eventi', '#0d6efd'); ?>
    <?php else: ?>
        <div class="cal-filtri d-flex flex-wrap gap-2 mb-2" role="group" aria-label="Filtra per tipo">
            <button type="button" class="btn btn-sm btn-primary fw-bold" data-tipo="">Tutto</button>
            <?php foreach ($tipi_presenti as $tp): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" data-tipo="<?php echo htmlspecialchars($tp); ?>"><i class="fa <?php echo \App\Portale\CalendarioPersonale::TIPI[$tp][1]; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars(\App\Portale\CalendarioPersonale::TIPI[$tp][0]); ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($per_giorno as $giorno => $lista): $t_g = strtotime($giorno); ?>
            <div class="cal-sezione" data-giorno="<?php echo $giorno; ?>">
                <div class="cal-giorno"><?php echo $giorni_it[(int)date('N', $t_g)] . ' ' . date('d/m/Y', $t_g); ?><?php echo $giorno === $oggi ? ' <small>· oggi</small>' : ($giorno === date('Y-m-d', strtotime('+1 day')) ? ' <small>· domani</small>' : ''); ?></div>
                <?php foreach ($lista as $v): $tp_v = \App\Portale\CalendarioPersonale::TIPI[$v['tipo']]; ?>
                    <div class="cal-voce" data-tipo="<?php echo htmlspecialchars($v['tipo']); ?>" style="--tc: <?php echo $tp_v[2]; ?>;">
                        <div class="cal-ora"><?php echo $v['tutto_giorno'] ? 'Giornata' : date('H:i', strtotime($v['inizio'])); ?><?php if (!$v['tutto_giorno'] && substr($v['fine'], 0, 16) !== substr($v['inizio'], 0, 16)): ?><small>fino alle <?php echo date('H:i', strtotime($v['fine'])); ?></small><?php endif; ?></div>
                        <div class="flex-grow-1" style="min-width:0;">
                            <a href="<?php echo htmlspecialchars($v['link']); ?>" class="cal-titolo"><?php echo htmlspecialchars($v['titolo']); ?></a>
                            <div class="cal-meta"><i class="fa <?php echo $tp_v[1]; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($tp_v[0]); ?><?php echo $v['luogo'] !== '' ? ' · <i class="fa fa-map-marker-alt me-1" aria-hidden="true"></i>' . htmlspecialchars($v['luogo']) : ''; ?></div>
                        </div>
                        <?php echo ui_stato($v['stato'], $v['stato_et']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mt-5" id="abbonati" style="border-radius: 14px;"><div class="card-body p-4">
        <h2 class="h5 fw-bold"><i class="fa fa-mobile-screen me-2" aria-hidden="true"></i>Sul calendario del telefono</h2>
        <p class="small text-secondary mb-3">Per averlo sempre aggiornato, <strong>abbona</strong> il telefono a questo calendario: nuove prenotazioni e annullamenti compaiono da soli, senza scaricare nulla. Il link è <strong>personale</strong>: non darlo ad altri (chi lo ha vede i tuoi appuntamenti). Se lo hai diffuso per sbaglio, creane uno nuovo.</p>
        <div class="input-group mb-3">
            <input type="text" class="form-control font-monospace small" id="linkFeed" value="<?php echo htmlspecialchars($link_feed); ?>" readonly onclick="this.select()" aria-label="Link del calendario">
            <button type="button" class="btn btn-outline-secondary fw-bold" onclick="var i=document.getElementById('linkFeed');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='Copiato';"><i class="fa fa-copy me-1" aria-hidden="true"></i>Copia</button>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a href="<?php echo htmlspecialchars($link_webcal); ?>" class="btn btn-primary fw-bold rounded-pill px-4"><i class="fa fa-calendar-plus me-1" aria-hidden="true"></i>Abbonati (iPhone, Mac, Outlook)</a>
            <a href="https://calendar.google.com/calendar/r?cid=<?php echo urlencode($link_webcal); ?>" target="_blank" rel="noopener" class="btn btn-outline-primary fw-bold rounded-pill px-4"><i class="fab fa-google me-1" aria-hidden="true"></i>Google Calendar</a>
            <form method="POST" class="ms-auto m-0"><?php csrf_field(); ?>
                <button type="submit" name="rigenera_link" value="1" class="btn btn-sm btn-outline-danger fw-bold" data-confirm="Creare un nuovo link? Il calendario già abbonato sul telefono smette di aggiornarsi."><i class="fa fa-rotate me-1" aria-hidden="true"></i>Nuovo link</button>
            </form>
        </div>
        <p class="small text-secondary mt-3 mb-0">Su Android apri il link con Google Calendar («Altri calendari» → «Da URL»). I calendari abbonati si aggiornano ogni qualche ora, secondo il telefono.</p>
    </div></div>
</div>

<script>
document.querySelectorAll('.cal-filtri [data-tipo]').forEach(function (b) {
    b.addEventListener('click', function () {
        var t = b.getAttribute('data-tipo');
        document.querySelectorAll('.cal-filtri [data-tipo]').forEach(function (x) { var on = x === b; x.classList.toggle('btn-primary', on); x.classList.toggle('btn-outline-secondary', !on); });
        document.querySelectorAll('.cal-voce').forEach(function (v) { v.style.display = (!t || v.getAttribute('data-tipo') === t) ? '' : 'none'; });
        document.querySelectorAll('.cal-sezione').forEach(function (s) { s.style.display = s.querySelector('.cal-voce:not([style*="display: none"])') ? '' : 'none'; });
    });
});
</script>

<?php require_once 'footer.php'; ?>
