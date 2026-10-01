<?php
// calendario_area.php - Pagina pubblica delle aree "Calendari e risorse": elenco delle risorse (aule, laboratori,
// sportelli) e, scelta una risorsa, la settimana con gli slot liberi da prenotare (uno o più di seguito,
// anche ogni settimana se la risorsa lo consente). Incluso da master_template.php dopo i controlli di visibilità:
// usa $page_cfg, $p_id, $utente_logged, $current_filename, $banner_manutenzione_admin.
if (!isset($page_cfg) || tipo_area($page_cfg) !== 'calendario') { http_response_code(404); exit; }

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pagina_url = $current_filename . '.php';
$col = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3');
$col_txt = colore_testo_su($col);
$utente = null;
if ($utente_logged) {
    $st_u = $conn->prepare("SELECT * FROM utenti WHERE id = ?");
    $uid_s = (int)$_SESSION['utente_id'];
    $st_u->bind_param("i", $uid_s); $st_u->execute();
    $utente = $st_u->get_result()->fetch_assoc() ?: null;
}

// ── Prenotazione ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prenota_risorsa'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $r = risorsa($conn, (int)($_POST['risorsa_id'] ?? 0));
    $torna = $pagina_url . '?risorsa=' . (int)($r['id'] ?? 0) . '&dal=' . urlencode((string)($_POST['settimana'] ?? ''));
    if (!$utente) { flash_set("Accedi per prenotare.", 'warning'); }
    elseif (!$r || (int)$r['pagina_id'] !== $p_id) { flash_set("Risorsa non trovata.", 'danger'); }
    elseif ((int)$r['chiede_motivo'] && trim((string)($_POST['motivo'] ?? '')) === '') { flash_set("Indica il motivo della prenotazione.", 'danger'); }
    elseif (!check_rate_limit($conn, 'prenota_risorsa', 30, 600)) { flash_set("Troppe richieste: riprova tra qualche minuto.", 'danger'); }
    else {
        $esito = prenota_risorsa($conn, $r, $utente, (string)($_POST['inizio'] ?? ''), (int)($_POST['n_slot'] ?? 1), (string)($_POST['motivo'] ?? ''),
                                 !empty($_POST['ripeti']) ? (string)($_POST['ripeti_fino'] ?? '') : null);
        if ($esito['errore']) flash_set($h($esito['errore']), 'danger');
        else {
            $pren = array_values(array_filter(array_map(fn($c) => prenotazione_risorsa($conn, $c), $esito['codici'])));
            if ($pren) {
                email_prenotazione_risorsa($conn, $pren[0], 'registrata', array_slice($pren, 1), $esito['saltate']);
                notifica_gestori_risorsa($conn, $pren[0], 'nuova', count($pren));
            }
            $msg = ($esito['stato'] === 'da_approvare' ? "Richiesta inviata: riceverai un'email quando sarà approvata." : "Prenotazione confermata.")
                 . (count($pren) > 1 ? " Date prenotate: " . count($pren) . "." : '')
                 . ($esito['saltate'] ? " Non disponibili e quindi saltate: " . $h(implode(', ', array_map(fn($d) => date('d/m', strtotime($d)), array_keys($esito['saltate'])))) . "." : '')
                 . " Ti abbiamo inviato un'email di riepilogo.";
            flash_set($msg, $esito['saltate'] ? 'warning' : 'success');
        }
    }
    header('Location: ' . $torna); exit;
}

// ── Annullamento da parte di chi ha prenotato ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['annulla_pren_risorsa'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $p = prenotazione_risorsa($conn, (int)$_POST['annulla_pren_risorsa']);
    if ($utente && $p && (int)$p['utente_id'] === (int)$utente['id'] && strtotime($p['inizio']) > time()
        && cambia_stato_prenotazione_risorsa($conn, (int)$p['id'], 'annullata', false)) flash_set("Prenotazione annullata.");
    else flash_set("Non è stato possibile annullare la prenotazione.", 'warning');
    header('Location: ' . $pagina_url . (isset($_POST['risorsa_id']) ? '?risorsa=' . (int)$_POST['risorsa_id'] : '')); exit;
}

$risorse = [];
$r_ris = $conn->query("SELECT * FROM risorse WHERE pagina_id = $p_id AND attiva = 1 ORDER BY ordine, nome");
while ($r_ris && $x = $r_ris->fetch_assoc()) $risorse[] = $x;
$sel = null;
if (isset($_GET['risorsa'])) foreach ($risorse as $x) if ((int)$x['id'] === (int)$_GET['risorsa']) $sel = $x;

// Settimana mostrata (da lunedì); non prima di questa settimana
$lun_oggi = strtotime('monday this week', strtotime('today'));
$dal = isset($_GET['dal']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dal']) ? strtotime('monday this week', strtotime($_GET['dal'])) : $lun_oggi;
if ($dal < $lun_oggi) $dal = $lun_oggi;
$ultimo = $sel ? strtotime('+' . (int)$sel['max_giorni'] . ' days', strtotime('today')) : $dal;
if ($dal > $ultimo) $dal = strtotime('monday this week', $ultimo);

$mie = [];
if ($utente) {
    $r_m = $conn->query("SELECT pr.*, r.nome AS risorsa_nome, r.luogo FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id
                         WHERE r.pagina_id = $p_id AND pr.utente_id = " . (int)$utente['id'] . " AND pr.stato IN ('confermata', 'da_approvare') AND pr.fine >= NOW() ORDER BY pr.inizio LIMIT 20");
    while ($r_m && $x = $r_m->fetch_assoc()) $mie[] = $x;
}
$puo = $sel && $utente ? puo_prenotare_risorsa($conn, $sel, $utente) : false;
$url_login = 'saml_login.php?redirect=' . urlencode($pagina_url . ($sel ? '?risorsa=' . (int)$sel['id'] : ''));

require_once 'header.php';
?>
<style>
.cal-wrap { max-width: <?php echo $h($page_cfg['larghezza_contenitore'] ?? '85%'); ?>; }
@media (max-width: 767px) { .cal-wrap { max-width: 100%; } }
.ris-card { border-top: 4px solid <?php echo $col; ?> !important; border-radius: 12px; height: 100%; }
.ris-card::after { display: none; }
.sett { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .75rem; }
.giorno { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: .6rem; }
.giorno.oggi { border-color: <?php echo $col; ?>; box-shadow: 0 0 0 1px <?php echo $col; ?>; }
.giorno h3 { font-size: .85rem; font-weight: 700; margin: 0 0 .5rem; text-transform: capitalize; }
.slot { display: block; width: 100%; border-radius: 6px; font-size: .8rem; padding: .3rem .2rem; margin-bottom: .3rem; text-align: center; border: 1px solid transparent; font-weight: 600; }
.slot-libero { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; cursor: pointer; }
.slot-libero:hover, .slot-libero:focus-visible { background: <?php echo $col; ?>; color: <?php echo $col_txt; ?>; border-color: <?php echo $col; ?>; }
.slot-libero.scelto { background: <?php echo $col; ?>; color: <?php echo $col_txt; ?>; outline: 2px solid #0f172a; }
.slot-no { background: #f1f5f9; color: #94a3b8; text-decoration: line-through; }
.slot-chiuso { background: #fef2f2; color: #991b1b; font-weight: 500; font-size: .75rem; }
.legenda span { display: inline-flex; align-items: center; gap: .3rem; margin-right: 1rem; font-size: .8rem; }
.legenda i { width: 14px; height: 14px; border-radius: 3px; display: inline-block; }
</style>

<div class="container cal-wrap my-4">
    <?php echo $banner_manutenzione_admin; ?>
    <?php echo flash_html(); ?>

    <div class="card shadow-sm border-0 p-4 mb-4" style="border-left: 5px solid <?php echo $col; ?> !important; border-radius: 12px;">
        <h1 class="fw-bold m-0" style="color: <?php echo $col; ?>;"><?php echo $h($page_cfg['titolo']); ?></h1>
        <?php if (!empty($page_cfg['sottotitolo'])): ?><div class="fs-5 fw-semibold text-secondary"><?php echo $h($page_cfg['sottotitolo']); ?></div><?php endif; ?>
        <?php if (!empty($page_cfg['hero_descrizione'])): ?><div class="mt-2"><?php echo $page_cfg['hero_descrizione']; ?></div><?php endif; ?>
        <?php if (!$utente): ?>
            <div class="mt-3"><a href="<?php echo $h($url_login); ?>" class="btn btn-sm fw-bold" style="background: <?php echo $col; ?>; color: <?php echo $col_txt; ?>;"><i class="fa fa-key me-1" aria-hidden="true"></i>Accedi per prenotare</a>
                <span class="small text-secondary ms-2">Le disponibilità si vedono anche senza accesso.</span></div>
        <?php endif; ?>
    </div>

    <?php if ($mie): ?>
    <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px;">
        <div class="card-body">
            <h2 class="h6 fw-bold mb-2"><i class="fa fa-bookmark me-1" style="color: <?php echo $col; ?>;" aria-hidden="true"></i>Le tue prossime prenotazioni</h2>
            <ul class="list-unstyled mb-0 small">
            <?php foreach ($mie as $m): ?>
                <li class="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom">
                    <strong><?php echo $h(quando_risorsa($m)); ?></strong> · <?php echo $h($m['risorsa_nome']); ?>
                    <?php if ($m['stato'] === 'da_approvare'): ?><span class="badge bg-warning text-dark">In attesa di approvazione</span><?php endif; ?>
                    <span class="ms-auto d-flex gap-2 align-items-center">
                        <a href="risorsa_ics.php?code=<?php echo urlencode($m['codice']); ?>" class="text-decoration-none" title="Aggiungi al calendario"><i class="fa fa-calendar-plus" aria-hidden="true"></i><span class="visually-hidden">Aggiungi al calendario</span></a>
                        <form method="POST" class="m-0"><?php csrf_field(); ?><?php if ($sel): ?><input type="hidden" name="risorsa_id" value="<?php echo (int)$sel['id']; ?>"><?php endif; ?>
                            <button type="submit" name="annulla_pren_risorsa" value="<?php echo (int)$m['id']; ?>" class="btn btn-link btn-sm text-danger p-0" onclick="return confirm('Annullare la prenotazione?');">Annulla</button></form>
                    </span>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$risorse): ?>
        <div class="alert alert-info">Al momento non ci sono risorse prenotabili in quest'area.</div>
    <?php elseif (!$sel): ?>
        <div class="row g-3">
        <?php foreach ($risorse as $r): $orari = orari_risorsa($conn, (int)$r['id']); ?>
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm border-0 ris-card">
                    <div class="card-body d-flex flex-column">
                        <h2 class="h5 fw-bold mb-1"><i class="fa <?php echo TIPI_RISORSA[$r['tipo']][1] ?? 'fa-cube'; ?> me-1" style="color: <?php echo $col; ?>;" aria-hidden="true"></i><?php echo $h($r['nome']); ?></h2>
                        <div class="small text-secondary mb-2"><?php echo $h(implode(' · ', array_filter([TIPI_RISORSA[$r['tipo']][0] ?? '', $r['luogo'], $r['capienza'] ? $r['capienza'] . ' posti' : '']))); ?></div>
                        <?php if (trim((string)$r['descrizione']) !== ''): ?><p class="small mb-2"><?php echo nl2br($h($r['descrizione'])); ?></p><?php endif; ?>
                        <div class="small text-secondary mb-3">
                            <?php foreach ($orari as $g => $fasce): ?><div><strong><?php echo GIORNI_SETTIMANA[$g]; ?></strong> <?php echo $h(implode(', ', array_map(fn($x) => substr($x[0], 0, 5) . '–' . substr($x[1], 0, 5), $fasce))); ?></div><?php endforeach; ?>
                            <div class="mt-1">Slot da <?php echo (int)$r['durata_slot']; ?> minuti<?php echo (int)$r['approvazione'] ? ' · su approvazione' : ''; ?> · <?php echo $h(ACCESSI_RISORSA[$r['accesso']] ?? ''); ?></div>
                        </div>
                        <a href="<?php echo $h($pagina_url . '?risorsa=' . (int)$r['id']); ?>" class="btn fw-bold mt-auto" style="background: <?php echo $col; ?>; color: <?php echo $col_txt; ?>;"><i class="fa fa-calendar-days me-1" aria-hidden="true"></i>Vedi disponibilità</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php else:
        $giorni = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("+$i days", $dal));
            if (empty(orari_risorsa($conn, (int)$sel['id'])[(int)date('N', strtotime($d))])) continue;
            $giorni[$d] = slot_risorsa($conn, $sel, $d);
        }
        $prec = strtotime('-7 days', $dal); $succ = strtotime('+7 days', $dal);
    ?>
        <nav aria-label="Percorso" class="small mb-2"><a href="<?php echo $h($pagina_url); ?>" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le risorse</a></nav>
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
            <div>
                <h2 class="h4 fw-bold mb-0"><?php echo $h($sel['nome']); ?></h2>
                <div class="small text-secondary"><?php echo $h(implode(' · ', array_filter([TIPI_RISORSA[$sel['tipo']][0] ?? '', $sel['luogo'], $sel['capienza'] ? $sel['capienza'] . ' posti' : '', $sel['referente'] ? 'Referente: ' . $sel['referente'] : '']))); ?></div>
            </div>
            <div class="btn-group" role="group" aria-label="Settimana">
                <a class="btn btn-sm btn-outline-secondary<?php echo $prec < $lun_oggi ? ' disabled' : ''; ?>" href="<?php echo $h($pagina_url . '?risorsa=' . (int)$sel['id'] . '&dal=' . date('Y-m-d', $prec)); ?>"<?php echo $prec < $lun_oggi ? ' aria-disabled="true" tabindex="-1"' : ''; ?>><i class="fa fa-chevron-left" aria-hidden="true"></i><span class="visually-hidden">Settimana precedente</span></a>
                <span class="btn btn-sm btn-light fw-bold" aria-live="polite"><?php echo date('d/m', $dal) . ' – ' . date('d/m/Y', strtotime('+6 days', $dal)); ?></span>
                <a class="btn btn-sm btn-outline-secondary<?php echo $succ > $ultimo ? ' disabled' : ''; ?>" href="<?php echo $h($pagina_url . '?risorsa=' . (int)$sel['id'] . '&dal=' . date('Y-m-d', $succ)); ?>"<?php echo $succ > $ultimo ? ' aria-disabled="true" tabindex="-1"' : ''; ?>><i class="fa fa-chevron-right" aria-hidden="true"></i><span class="visually-hidden">Settimana successiva</span></a>
            </div>
        </div>
        <?php if (trim((string)$sel['descrizione']) !== ''): ?><p class="small"><?php echo nl2br($h($sel['descrizione'])); ?></p><?php endif; ?>
        <div class="legenda mb-2 text-secondary"><span><i style="background:#ecfdf5;border:1px solid #a7f3d0;"></i>Libero</span><span><i style="background:#f1f5f9;"></i>Occupato o non prenotabile</span><span><i style="background:#fef2f2;"></i>Chiuso</span></div>

        <?php if (!$giorni): ?>
            <div class="alert alert-info">Nessun orario di apertura in questa settimana.</div>
        <?php else: ?>
        <div class="sett mb-4" id="settimana">
            <?php foreach ($giorni as $d => $slot): $chiuso = $slot && $slot[0]['stato'] === 'chiuso' ? chiusura_risorsa($conn, $sel, $d) : null; ?>
            <div class="giorno<?php echo $d === date('Y-m-d') ? ' oggi' : ''; ?>">
                <h3><?php echo GIORNI_SETTIMANA[(int)date('N', strtotime($d))] . ' ' . date('d/m', strtotime($d)); ?></h3>
                <?php if ($chiuso !== null): ?>
                    <div class="slot slot-chiuso"><?php echo $h($chiuso); ?></div>
                <?php else: foreach ($slot as $k => $s): $t = date('H:i', strtotime($s['inizio'])) . '–' . date('H:i', strtotime($s['fine'])); ?>
                    <?php if ($s['stato'] === 'libero' && $puo): ?>
                        <button type="button" class="slot slot-libero" data-inizio="<?php echo $h($s['inizio']); ?>" data-fine="<?php echo $h($s['fine']); ?>" data-fascia="<?php echo $d . '_' . $s['fascia']; ?>" data-k="<?php echo $k; ?>" data-giorno="<?php echo $d; ?>" aria-label="Prenota <?php echo $h(GIORNI_SETTIMANA[(int)date('N', strtotime($d))] . ' ' . date('d/m', strtotime($d)) . ' ' . $t); ?>"><?php echo $t; ?></button>
                    <?php elseif ($s['stato'] === 'libero'): ?>
                        <span class="slot slot-libero" style="cursor:default;"><?php echo $t; ?></span>
                    <?php else: ?>
                        <span class="slot slot-no" title="<?php echo ['occupato' => 'Occupato', 'passato' => 'Non più prenotabile', 'lontano' => 'Non ancora prenotabile'][$s['stato']] ?? ''; ?>"><span class="visually-hidden"><?php echo ['occupato' => 'Occupato', 'passato' => 'Non più prenotabile', 'lontano' => 'Non ancora prenotabile'][$s['stato']] ?? ''; ?>: </span><?php echo $t; ?></span>
                    <?php endif; ?>
                <?php endforeach; endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$utente): ?>
            <div class="alert alert-light border"><a href="<?php echo $h($url_login); ?>" class="fw-bold">Accedi</a> per prenotare uno slot libero.</div>
        <?php elseif (!$puo): ?>
            <div class="alert alert-warning">Questa risorsa è prenotabile solo da: <strong><?php echo $h(ACCESSI_RISORSA[$sel['accesso']] ?? ''); ?></strong>. Se pensi di averne diritto scrivi ai gestori dell'area.</div>
        <?php else: ?>
        <form method="POST" id="formPrenRis" class="card shadow-sm border-0 p-3 mb-4" style="border-radius:12px;display:none;" aria-live="polite">
            <?php csrf_field(); ?>
            <input type="hidden" name="risorsa_id" value="<?php echo (int)$sel['id']; ?>">
            <input type="hidden" name="settimana" value="<?php echo date('Y-m-d', $dal); ?>">
            <input type="hidden" name="inizio" id="prInizio">
            <h2 class="h6 fw-bold mb-2" id="prTitolo">Prenotazione</h2>
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label small fw-bold" for="prSlot">Durata</label><select class="form-select form-select-sm" name="n_slot" id="prSlot"></select></div>
                <div class="col-md-8"><label class="form-label small fw-bold" for="prMotivo">Motivo<?php echo (int)$sel['chiede_motivo'] ? ' *' : ''; ?></label>
                    <input type="text" class="form-control form-control-sm" name="motivo" id="prMotivo" maxlength="500" placeholder="es. Esercitazione del corso di …, ricevimento per tesi"<?php echo (int)$sel['chiede_motivo'] ? ' required' : ''; ?>></div>
                <?php if ((int)$sel['ripetizione']): ?>
                <div class="col-12 d-flex flex-wrap align-items-center gap-2">
                    <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="ripeti" value="1" id="prRip"><label class="form-check-label small" for="prRip">Ripeti ogni settimana fino al</label></div>
                    <input type="date" class="form-control form-control-sm" style="max-width:170px;" name="ripeti_fino" id="prRipFino" min="<?php echo date('Y-m-d', strtotime('+7 days', $dal)); ?>" max="<?php echo date('Y-m-d', $ultimo); ?>" aria-label="Ripeti fino al">
                    <span class="small text-secondary">Le settimane non disponibili vengono saltate e te le indichiamo.</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" name="prenota_risorsa" value="1" class="btn fw-bold" style="background: <?php echo $col; ?>; color: <?php echo $col_txt; ?>;"><i class="fa fa-check me-1" aria-hidden="true"></i><?php echo (int)$sel['approvazione'] ? 'Invia la richiesta' : 'Conferma la prenotazione'; ?></button>
                <button type="button" class="btn btn-outline-secondary" id="prAnnulla">Annulla</button>
            </div>
            <?php if ((int)$sel['approvazione']): ?><div class="small text-secondary mt-2">Questa risorsa richiede l'approvazione dei gestori: lo slot resta riservato finché non rispondono.</div><?php endif; ?>
        </form>
        <script>
        (function () {
            var form = document.getElementById('formPrenRis'), max = <?php echo (int)$sel['max_slot']; ?>, durata = <?php echo (int)$sel['durata_slot']; ?>;
            var selSlot = document.getElementById('prSlot'), titolo = document.getElementById('prTitolo'), inizio = document.getElementById('prInizio');
            var giorni = <?php echo json_encode(array_map(fn($d) => GIORNI_SETTIMANA[(int)date('N', strtotime($d))] . ' ' . date('d/m/Y', strtotime($d)), array_combine(array_keys($giorni), array_keys($giorni)))); ?>;
            function hhmm(s) { return s.substr(11, 5); }
            document.querySelectorAll('#settimana .slot-libero[data-inizio]').forEach(function (b) {
                b.addEventListener('click', function () {
                    document.querySelectorAll('#settimana .scelto').forEach(function (x) { x.classList.remove('scelto'); });
                    b.classList.add('scelto');
                    // Slot liberi consecutivi nella stessa fascia (fino al massimo consentito)
                    var seguenti = [b], k = +b.dataset.k, giorno = b.closest('.giorno');
                    while (seguenti.length < max) {
                        var n = giorno.querySelector('.slot-libero[data-k="' + (k + seguenti.length) + '"]');
                        if (!n || n.dataset.fascia !== b.dataset.fascia) break;
                        seguenti.push(n);
                    }
                    selSlot.innerHTML = '';
                    seguenti.forEach(function (s, i) {
                        var o = document.createElement('option'); o.value = i + 1;
                        var min = (i + 1) * durata;
                        o.textContent = hhmm(b.dataset.inizio) + '–' + hhmm(s.dataset.fine) + ' (' + (min < 60 ? min + ' min' : (min / 60) + (min === 60 ? ' ora' : ' ore')) + ')';
                        selSlot.appendChild(o);
                    });
                    inizio.value = b.dataset.inizio;
                    titolo.textContent = 'Prenota: ' + (giorni[b.dataset.giorno] || '') + ', dalle ' + hhmm(b.dataset.inizio);
                    form.style.display = '';
                    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    document.getElementById('prMotivo').focus({ preventScroll: true });
                });
            });
            document.getElementById('prAnnulla').addEventListener('click', function () {
                form.style.display = 'none';
                var s = document.querySelector('#settimana .scelto'); if (s) { s.classList.remove('scelto'); s.focus(); }
            });
            var rip = document.getElementById('prRip'), fino = document.getElementById('prRipFino');
            if (rip) rip.addEventListener('change', function () { fino.required = rip.checked; if (rip.checked && !fino.value) fino.focus(); });
            if (fino) fino.addEventListener('input', function () { if (fino.value) rip.checked = true; });
        })();
        </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require_once 'footer.php';
