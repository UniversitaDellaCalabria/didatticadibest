<?php
// admin/assistenza.php - Assistenza a studenti e utenti: la casella delle chat (da prendere, mie, tutte, chiuse) e la chat aperta, che si aggiorna da sola.
// Il lavoro vero lo fa admin/assistenza_ajax.php (App\Assistenza\ChatApi); qui c'è solo la pagina.
require_once 'admin_header.php';

if (!$puo_assistenza) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Le chat di assistenza le gestiscono le persone dell'ufficio «Assistenza».</div>";
    require_once 'admin_footer.php';
    exit;
}

$orari_as = \App\Core\App::get(\App\Assistenza\OrariAssistenza::class);
// Orari e servizio acceso/spento (li può cambiare chi risponde alle chat)
if (isset($_POST['salva_orari'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err_or = $orari_as->salva(!empty($_POST['attiva']), array_map('strval', (array)($_POST['orario'] ?? [])));
    if ($err_or === null) registra_log_audit($conn, 'Orari dell\'assistenza modificati', ['Attiva' => !empty($_POST['attiva']) ? 'sì' : 'no', 'Orari' => $orari_as->descrizione()]);
    flash_set($err_or ?? '✅ Orari salvati.', $err_or ? 'danger' : 'success');
    echo "<script>window.location.replace('assistenza.php?p_id=" . (int)$filtro_p . "&orari=1#orari');</script>";
    exit;
}
$imp_orari = $orari_as->impostazioni();
$vista_as = in_array($_GET['vista'] ?? '', ['da_prendere', 'mie', 'tutte', 'chiuse', 'bot'], true) ? $_GET['vista'] : 'da_prendere';
$conv_as = (int)($_GET['c'] ?? 0);
$sezioni_as = ['da_prendere' => 'Da prendere', 'mie' => 'Mie', 'tutte' => 'Tutte le aperte', 'bot' => 'Con Didacticus', 'chiuse' => 'Chiuse'];
$scheda_as = ($_GET['scheda'] ?? '') === 'statistiche' ? 'statistiche' : 'chat';
// Statistiche: periodo scelto (predefinito, gli ultimi 90 giorni)
$data_ok = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime((string)$d) !== false;
$stat_al = $data_ok($_GET['al'] ?? '') ? $_GET['al'] : date('Y-m-d');
$stat_dal = $data_ok($_GET['dal'] ?? '') ? $_GET['dal'] : date('Y-m-d', strtotime('-90 days'));
if ($stat_dal > $stat_al) [$stat_dal, $stat_al] = [$stat_al, $stat_dal];
$stat_as = $scheda_as === 'statistiche' ? \App\Core\App::get(\App\Assistenza\StatisticheAssistenza::class)->riepilogo($stat_dal, $stat_al) : null;
$dur = fn($m) => \App\Assistenza\StatisticheAssistenza::durata($m);
?>
<style>
    #as-log { height: 52vh; min-height: 280px; overflow-y: auto; background: #f8fafc; }
    #as-elenco { max-height: 66vh; overflow-y: auto; }
</style>

<h4 class="fw-bold text-dark mb-1"><i class="fa fa-headset text-danger me-2" aria-hidden="true"></i> Assistenza</h4>
<p class="text-secondary mb-3">Le richieste di aiuto di studenti, personale e utenti esterni. Scegli una conversazione: la chat si aggiorna da sola e chi scrive riceve la tua risposta subito (o per email, se ha chiuso la pagina).</p>

<?php echo flash_html(); ?>
<ul class="nav nav-pills gap-1 mb-3 flex-wrap" style="--bs-nav-pills-link-active-bg:#1e293b;">
    <li class="nav-item"><a class="nav-link fw-bold <?php echo $scheda_as === 'chat' ? 'active' : 'bg-light text-dark'; ?>" style="font-size:.9rem;" href="assistenza.php?p_id=<?php echo (int)$filtro_p; ?>"><i class="fa fa-comments me-1" aria-hidden="true"></i>Chat</a></li>
    <li class="nav-item"><a class="nav-link fw-bold <?php echo $scheda_as === 'statistiche' ? 'active' : 'bg-light text-dark'; ?>" style="font-size:.9rem;" href="assistenza.php?p_id=<?php echo (int)$filtro_p; ?>&amp;scheda=statistiche"><i class="fa fa-chart-column me-1" aria-hidden="true"></i>Statistiche</a></li>
    <li class="nav-item"><a class="nav-link fw-bold bg-light text-dark" style="font-size:.9rem;" href="didacticus.php?p_id=<?php echo (int)$filtro_p; ?>"><i class="fa fa-robot me-1" aria-hidden="true"></i>Istruisci Didacticus</a></li>
</ul>
<?php $push_as = \App\Core\App::get(\App\Assistenza\Push\ServizioPush::class); ?>
<?php if ($scheda_as === 'chat'): ?>
<!-- NOTIFICHE A FINESTRELLA su questo computer (Web Push) -->
<?php if ($push_as->attivo()): ?>
<div id="as-push" class="card border-0 shadow-sm mb-3 d-none" data-url="push_ajax.php" data-csrf="<?php echo htmlspecialchars((string)csrf_token()); ?>" data-chiave="<?php echo htmlspecialchars($push_as->chiavePubblica()); ?>">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <span class="fw-bold"><i class="fa fa-bell text-danger me-1" aria-hidden="true"></i>Notifiche sul computer</span>
        <button type="button" class="btn btn-danger btn-sm fw-bold" id="as-push-attiva"><i class="fa fa-bell me-1" aria-hidden="true"></i>Attiva le notifiche su questo computer</button>
        <span id="as-push-attive" class="d-none"><span class="d-inline-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-success" id="as-push-stato">Attive su questo computer</span>
            <label class="visually-hidden" for="as-push-silenzia">Silenzia le notifiche</label>
            <select id="as-push-silenzia" class="form-select form-select-sm" style="width:auto;">
                <option value="0">Non silenziare</option><option value="60">Silenzia per 1 ora</option><option value="240">Silenzia per 4 ore</option><option value="1440">Silenzia fino a domani</option><option value="si" hidden>Silenziate</option>
            </select>
            <span class="form-check m-0"><input class="form-check-input" type="checkbox" id="as-push-solomie"><label class="form-check-label small" for="as-push-solomie">Solo le chat in carico a me</label></span>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="as-push-prova">Prova</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="as-push-disattiva">Disattiva</button>
        </span></span>
        <span id="as-push-msg" class="small text-secondary" role="status"></span>
    </div>
</div>
<?php else: ?>
<p class="small text-secondary"><i class="fa fa-bell-slash me-1" aria-hidden="true"></i>Le notifiche a finestrella non sono ancora attive sul server (mancano le chiavi <code>PUSH_VAPID_*</code> nel file <code>.env</code>): intanto gli avvisi arrivano per email.</p>
<?php endif; ?>
<div id="as-avviso" class="alert alert-warning py-2 d-none" role="status"></div>

<div id="assistenza-app" data-url="assistenza_ajax.php" data-csrf="<?php echo htmlspecialchars((string)csrf_token()); ?>" data-vista="<?php echo $vista_as; ?>" data-c="<?php echo $conv_as; ?>" data-io="<?php echo (int)$u_id_curr; ?>">
    <div class="row g-3">
        <div class="col-lg-4">
            <ul class="nav nav-pills flex-wrap gap-1 mb-2" id="as-schede" role="tablist" aria-label="Elenco delle chat">
                <?php foreach ($sezioni_as as $k_as => $et_as): ?>
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link fw-bold <?php echo $k_as === $vista_as ? 'active' : 'bg-light text-dark'; ?>" style="--bs-nav-pills-link-active-bg:#1e293b;font-size:.85rem;" role="tab" aria-selected="<?php echo $k_as === $vista_as ? 'true' : 'false'; ?>" data-vista="<?php echo $k_as; ?>">
                            <?php echo $et_as; ?><?php if ($k_as !== 'chiuse'): // il contatore delle chiuse non serve ?> <span class="badge bg-secondary" id="as-n-<?php echo $k_as; ?>">0</span><?php endif; ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="card shadow-sm border-0"><div class="list-group list-group-flush" id="as-elenco" aria-live="polite"></div></div>
        </div>

        <div class="col-lg-8">
            <div id="as-vuoto" class="card shadow-sm border-0"><div class="card-body text-center text-muted py-5">
                <i class="fa fa-comments fs-1 d-block mb-2 opacity-50" aria-hidden="true"></i>Scegli una conversazione dall'elenco.
            </div></div>

            <div id="as-chat" class="card shadow-sm border-0 d-none">
                <div class="card-header bg-dark text-white">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-bold fs-6"><span id="as-nome"></span> <span id="as-stato" class="badge ms-1"></span></div>
                            <div class="small opacity-75" id="as-dettagli"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <button type="button" class="btn btn-warning btn-sm fw-bold d-none" id="as-prendi"><i class="fa fa-hand me-1" aria-hidden="true"></i>Prendo io</button>
                            <span id="as-passa-gruppo" class="d-none"><label class="visually-hidden" for="as-passa-a">Passa la chat a un collega</label>
                                <select id="as-passa-a" class="form-select form-select-sm" style="width:auto;"><option value="">Passa a…</option></select></span>
                            <button type="button" class="btn btn-outline-light btn-sm fw-bold d-none" id="as-chiudi"><i class="fa fa-check me-1" aria-hidden="true"></i>Chiudi</button>
                            <button type="button" class="btn btn-outline-light btn-sm fw-bold d-none" id="as-riapri"><i class="fa fa-rotate-left me-1" aria-hidden="true"></i>Riapri</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-3" id="as-log" role="log" aria-live="polite" aria-label="Messaggi della conversazione"></div>
                <div class="px-3 pt-2 small text-secondary" id="as-valutazione"></div>
                <form id="as-form" class="card-footer bg-white" autocomplete="off">
                    <label class="form-label small fw-bold mb-1" for="as-testo">Scrivi una risposta <span class="fw-normal text-secondary">(Invio per mandare, Maiusc+Invio per andare a capo)</span></label>
                    <textarea id="as-testo" class="form-control" rows="3" maxlength="<?php echo \App\Assistenza\Costanti::MAX_CARATTERI; ?>"></textarea>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                        <div class="form-check m-0">
                            <input class="form-check-input" type="checkbox" id="as-nota">
                            <label class="form-check-label small" for="as-nota">Nota interna: la vedono solo gli operatori</label>
                        </div>
                        <button type="submit" class="btn btn-danger fw-bold px-4" id="as-invia" style="background-color:#B80000;border:none;"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<details class="card shadow-sm border-0 mt-4" id="orari"<?php echo isset($_GET['orari']) ? ' open' : ''; ?>>
    <summary class="card-header fw-bold" style="cursor:pointer;"><i class="fa fa-clock me-1" aria-hidden="true"></i> Orari e servizio <span class="fw-normal text-secondary">· <?php echo $imp_orari['attiva'] ? ($orari_as->descrizione() !== '' ? htmlspecialchars($orari_as->descrizione()) : 'sempre aperto') : '<strong class="text-danger">sospeso</strong>'; ?></span></summary>
    <form method="POST" action="assistenza.php?p_id=<?php echo (int)$filtro_p; ?>" class="card-body">
        <?php csrf_field(); ?>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="as-attiva" name="attiva" value="1" <?php echo $imp_orari['attiva'] ? 'checked' : ''; ?>>
            <label class="form-check-label fw-bold" for="as-attiva">Servizio acceso</label>
            <div class="form-text">Spento: il pulsante «Serve aiuto?» sparisce dal sito e non si aprono nuove chat (quelle in corso restano).</div>
        </div>
        <p class="small text-secondary">Scrivi per ogni giorno gli orari in cui risponde una persona, come <code>09:00-13:00, 14:00-16:00</code>; lascia vuoto un giorno senza assistenza. Se non scrivi nessun orario il servizio è considerato sempre aperto. Fuori orario le persone possono scrivere lo stesso: ricevono un avviso con gli orari e la risposta per email.</p>
        <div class="row g-2">
            <?php foreach (\App\Assistenza\OrariAssistenza::GIORNI as $n_g => $nome_g): ?>
                <div class="col-md-6 col-lg-4"><label class="form-label small fw-bold mb-1" for="or-<?php echo $n_g; ?>"><?php echo $nome_g; ?></label>
                    <input class="form-control form-control-sm" id="or-<?php echo $n_g; ?>" name="orario[<?php echo $n_g; ?>]" maxlength="60" placeholder="09:00-13:00, 14:00-16:00" value="<?php echo htmlspecialchars($imp_orari['orari'][$n_g]); ?>"></div>
            <?php endforeach; ?>
        </div>
        <button type="submit" name="salva_orari" value="1" class="btn btn-dark fw-bold mt-3"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva</button>
    </form>
</details>

<script src="../assets/js/assistenza-operatori.js?v=2"></script>
<?php if ($push_as->attivo()): ?>
<script src="../assets/js/assistenza-push.js?v=1"></script>
<script src="../assets/js/assistenza-push-operatori.js?v=1"></script>
<?php endif; ?>
<?php else: // STATISTICHE ?>
<form method="GET" class="d-flex flex-wrap align-items-end gap-2 mb-3"><input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="scheda" value="statistiche">
    <div><label class="form-label small fw-bold mb-1" for="st-dal">Dal</label><input type="date" class="form-control" id="st-dal" name="dal" value="<?php echo htmlspecialchars($stat_dal); ?>"></div>
    <div><label class="form-label small fw-bold mb-1" for="st-al">Al</label><input type="date" class="form-control" id="st-al" name="al" value="<?php echo htmlspecialchars($stat_al); ?>"></div>
    <button class="btn btn-dark fw-bold" type="submit">Mostra</button>
    <span class="text-secondary small ms-2">Conta le chat aperte in quel periodo.</span></form>
<?php if (!$stat_as['totale']): ?>
    <div class="alert alert-light border text-center py-4"><i class="fa fa-chart-column fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>Nessuna chat in questo periodo.</div>
<?php else:
    $kpi = [
        ['Chat aperte', $stat_as['totale'], 'fa-comments', '#1e293b', $stat_as['con_login'] . ' con accesso · ' . $stat_as['esterni'] . ' esterni'],
        ['Chiuse', $stat_as['chiuse'], 'fa-circle-check', '#047857', $stat_as['aperte'] . ' ancora aperte'],
        ['Risolte da Didacticus', $stat_as['risolte_da_didacticus'], 'fa-robot', '#0056B3', $stat_as['passate_a_operatore'] . ' passate a un operatore'],
        ['Prima risposta (media)', $dur($stat_as['prima_risposta_min']), 'fa-clock', '#b45309', $stat_as['senza_risposta'] . ' senza risposta di un operatore'],
        ['Ti è stato utile? sì', $stat_as['utili'], 'fa-thumbs-up', '#047857', $stat_as['non_utili'] . ' no · ' . $stat_as['senza_valutazione'] . ' senza voto'],
    ]; ?>
    <div class="row g-3 mb-4">
        <?php foreach ($kpi as [$et_k, $val_k, $ico_k, $col_k, $sub_k]): ?>
            <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100" style="border-left:5px solid <?php echo $col_k; ?> !important;"><div class="card-body py-3">
                <div class="small text-secondary fw-bold"><i class="fa <?php echo $ico_k; ?> me-1" style="color:<?php echo $col_k; ?>;" aria-hidden="true"></i><?php echo $et_k; ?></div>
                <div class="fs-3 fw-bold"><?php echo htmlspecialchars((string)$val_k); ?></div><div class="small text-muted"><?php echo htmlspecialchars($sub_k); ?></div></div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="row g-4">
        <div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-header bg-white d-flex justify-content-between align-items-center"><span class="fw-bold">Per argomento</span><?php echo pn_scarica_tabella('tabArgomenti', 'Assistenza per argomento dal ' . date('d/m/Y', strtotime($stat_dal)) . ' al ' . date('d/m/Y', strtotime($stat_al)), 'assistenza_argomenti_' . $stat_dal . '_' . $stat_al); ?></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="tabArgomenti"><thead><tr><th class="ps-3">Argomento</th><th>Chat</th><th>Chiuse</th><th>Prima risposta</th><th>Utile</th><th>Non utile</th></tr></thead><tbody>
                <?php foreach ($stat_as['per_argomento'] as $x): ?><tr><td class="ps-3"><?php echo htmlspecialchars($x['nome']); ?></td><td><?php echo $x['n']; ?></td><td><?php echo $x['chiuse']; ?></td><td><?php echo $dur($x['prima_risposta_min']); ?></td><td><?php echo $x['utili']; ?></td><td><?php echo $x['non_utili']; ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        <div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-header bg-white d-flex justify-content-between align-items-center"><span class="fw-bold">Per operatore</span><?php echo pn_scarica_tabella('tabOperatori', 'Assistenza per operatore dal ' . date('d/m/Y', strtotime($stat_dal)) . ' al ' . date('d/m/Y', strtotime($stat_al)), 'assistenza_operatori_' . $stat_dal . '_' . $stat_al); ?></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="tabOperatori"><thead><tr><th class="ps-3">Operatore</th><th>Chat prese</th><th>Prima risposta</th></tr></thead><tbody>
                <?php if (!$stat_as['per_operatore']): ?><tr><td colspan="3" class="text-muted text-center py-3">Nessuna chat è stata ancora presa in carico.</td></tr><?php endif; ?>
                <?php foreach ($stat_as['per_operatore'] as $x): ?><tr><td class="ps-3"><?php echo htmlspecialchars($x['nome']); ?></td><td><?php echo $x['n']; ?></td><td><?php echo $dur($x['prima_risposta_min']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
    </div>
    <p class="small text-secondary mt-3">I numeri non contengono nomi né messaggi: restano anche dopo la cancellazione delle chat vecchie (12 mesi).</p>
<?php endif; ?>
<?php endif; ?>
<?php require_once 'admin_footer.php'; ?>
