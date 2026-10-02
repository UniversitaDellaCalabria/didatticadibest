<?php
// ricevimento.php - "Il mio ricevimento": il docente gestisce il proprio sportello di ricevimento (risorsa di
// Prenotazioni e risorse collegata alla sua scheda dell'anagrafe, risorse.persona_id): giorni e orari, durata degli
// appuntamenti, approvazione, chiusure (ferie, missioni) e appuntamenti prenotati dagli studenti.
// Lo sportello lo crea la segreteria in Risorse, scegliendo il docente.
// Gli operatori dell'Ufficio didattico gestiscono qui anche lo sportello dell'ufficio (risorse.ufficio = 'didattica').
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ora_ok = fn($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t) ? $t . ':00' : null;
$pid_utente = (string)($user_info['persona_id'] ?? '');
// Il proprio sportello (docente) e quelli dell'Ufficio didattico per i suoi operatori
$sportelli = sportelli_utente($conn, $user_info);
$ids_sportelli = array_map('intval', array_column($sportelli, 'id'));
$mio = fn(int $rid) => in_array($rid, $ids_sportelli, true);
$torna = function () { header('Location: ricevimento.php?r=' . time()); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $rid = (int)($_POST['risorsa_id'] ?? 0);
    if (!$mio($rid)) { flash_set("Sportello non trovato.", 'danger'); $torna(); }
    $r = risorsa($conn, $rid);

    if (isset($_POST['salva_ricevimento'])) {
        $durata = in_array((int)($_POST['durata_slot'] ?? 15), [10, 15, 20, 30, 45, 60], true) ? (int)$_POST['durata_slot'] : 15;
        $max_g = max(1, min(180, (int)($_POST['max_giorni'] ?? 30)));
        $anticipo = max(0, min(168, (int)($_POST['anticipo_ore'] ?? 12)));
        $appr = isset($_POST['approvazione']) ? 1 : 0; $attiva = isset($_POST['attiva']) ? 1 : 0;
        $luogo = mb_substr(trim((string)($_POST['luogo'] ?? '')), 0, 255);
        $descr = mb_substr(trim(strip_tags((string)($_POST['descrizione'] ?? ''))), 0, 2000);
        $st = $conn->prepare("UPDATE risorse SET durata_slot = ?, max_giorni = ?, anticipo_ore = ?, approvazione = ?, attiva = ?, luogo = ?, descrizione = ?, max_slot = 1 WHERE id = ?");
        $st->bind_param("iiiiissi", $durata, $max_g, $anticipo, $appr, $attiva, $luogo, $descr, $rid); $st->execute();
        $conn->query("DELETE FROM risorse_orari WHERE risorsa_id = $rid");
        $ins = $conn->prepare("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (?, ?, ?, ?)");
        $avvisi = [];
        foreach (GIORNI_SETTIMANA as $g => $nome_g) foreach ([1, 2] as $f) {
            $da = $ora_ok($_POST["dalle_{$g}_{$f}"] ?? ''); $a = $ora_ok($_POST["alle_{$g}_{$f}"] ?? '');
            if (!$da && !$a) continue;
            if (!$da || !$a || $a <= $da) { $avvisi[] = "$nome_g, fascia $f"; continue; }
            $ins->bind_param("iiss", $rid, $g, $da, $a); $ins->execute();
        }
        registra_log_audit($conn, "Ricevimento aggiornato dal docente", ["Sportello" => $r['nome']]);
        flash_set("Ricevimento salvato." . ($avvisi ? " Orari ignorati perché incompleti o al contrario: " . implode('; ', $avvisi) . "." : ''), $avvisi ? 'warning' : 'success');
    } elseif (isset($_POST['aggiungi_chiusura'])) {
        $dal = (string)($_POST['dal'] ?? ''); $al = (string)($_POST['al'] ?? '') ?: $dal;
        $motivo = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 255);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $al) || $al < $dal) {
            flash_set("Date non valide.", 'danger');
        } else {
            $pag = (int)$r['pagina_id'];
            $st = $conn->prepare("INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al, motivo) VALUES (?, ?, ?, ?, ?)");
            $st->bind_param("iisss", $pag, $rid, $dal, $al, $motivo); $st->execute();
            $n_c = (int)$conn->query("SELECT COUNT(*) n FROM prenotazioni_risorse WHERE risorsa_id = $rid AND stato IN ('confermata', 'da_approvare') AND DATE(inizio) BETWEEN '"
                                     . $conn->real_escape_string($dal) . "' AND '" . $conn->real_escape_string($al) . "'")->fetch_assoc()['n'];
            flash_set("Assenza registrata: in quei giorni il ricevimento non è prenotabile." . ($n_c ? " Ci sono già $n_c appuntamenti in quei giorni: annullali qui sotto per avvisare gli studenti." : ''), $n_c ? 'warning' : 'success');
        }
    } elseif (isset($_POST['elimina_chiusura'])) {
        $cid = (int)$_POST['elimina_chiusura'];
        $st = $conn->prepare("DELETE FROM risorse_chiusure WHERE id = ? AND risorsa_id = ?");
        $st->bind_param("ii", $cid, $rid); $st->execute();
        flash_set("Assenza tolta.");
    } elseif (isset($_POST['azione_app'])) {
        $nuovo = ['approva' => 'confermata', 'rifiuta' => 'rifiutata', 'annulla' => 'annullata'][$_POST['azione_app']] ?? null;
        $p = $nuovo ? prenotazione_risorsa($conn, (int)($_POST['pren_id'] ?? 0)) : null;
        if ($p && (int)$p['risorsa_id'] === $rid) {
            $nota = mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 500);
            if ($nota !== '') { $st = $conn->prepare("UPDATE prenotazioni_risorse SET nota_gestore = ? WHERE id = ?"); $id_p = (int)$p['id']; $st->bind_param("si", $nota, $id_p); $st->execute(); }
            $ok = cambia_stato_prenotazione_risorsa($conn, (int)$p['id'], $nuovo, true);
            flash_set($ok ? "Appuntamento aggiornato: lo studente riceve un'email." : "Appuntamento già gestito.", $ok ? 'success' : 'warning');
        }
    }
    $torna();
}

$page_cfg['titolo'] = 'Il mio ricevimento';
require_once 'header.php';
?>
<style>
.ric-orari { display: grid; grid-template-columns: 110px repeat(2, minmax(0, 1fr)); gap: .4rem .75rem; align-items: center; }
.ric-orari .ric-fascia { display: flex; gap: .3rem; align-items: center; }
@media (max-width: 575.98px) { .ric-orari { grid-template-columns: 1fr; } }
</style>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="small mb-2"><a href="area_personale.php">Area personale</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Il mio ricevimento</span></nav>
    <h1 class="fw-bold h2 mb-1"><i class="fa fa-user-clock me-2 text-primary" aria-hidden="true"></i>Il mio ricevimento</h1>
    <p class="text-secondary">Scegli giorni e orari in cui ricevi: gli studenti prenotano un appuntamento dalla tua pagina pubblica e ricevono conferma e promemoria per email.</p>
    <?php echo flash_html(); ?>

    <?php if (!$sportelli): ?>
        <div class="alert alert-info"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Non hai ancora uno sportello di ricevimento. Lo attiva la segreteria didattica (Prenotazioni e risorse → Risorse, scegliendo il tuo nome come docente del ricevimento).<?php echo $pid_utente === '' ? ' Il tuo account non risulta ancora collegato all\'anagrafe di Ateneo: accedi con le credenziali Unical.' : ''; ?></div>
    <?php endif; ?>

    <?php foreach ($sportelli as $r):
        $rid = (int)$r['id'];
        $orari = orari_risorsa($conn, $rid);
        $chiusure = $conn->query("SELECT * FROM risorse_chiusure WHERE risorsa_id = $rid AND al >= CURDATE() ORDER BY dal")->fetch_all(MYSQLI_ASSOC);
        $app = $conn->query("SELECT * FROM prenotazioni_risorse WHERE risorsa_id = $rid AND stato IN ('confermata', 'da_approvare') AND fine >= NOW() ORDER BY inizio LIMIT 200")->fetch_all(MYSQLI_ASSOC);
        $url_pub = $r['area_slug'] . '.php?risorsa=' . $rid;
    ?>
    <section class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h2 class="h4 fw-bold mb-0"><?php echo $h($r['nome']); ?></h2>
                    <div class="small text-secondary"><?php echo $h($r['area_titolo']); ?> · <?php echo (int)$r['attiva'] ? '<span class="text-success fw-bold">prenotabile</span>' : '<span class="text-danger fw-bold">non prenotabile</span>'; ?></div>
                </div>
                <a href="<?php echo $h($url_pub); ?>" class="btn btn-sm btn-outline-primary fw-bold" target="_blank" rel="noopener"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Pagina di prenotazione</a>
            </div>

            <h3 class="h6 fw-bold">Prossimi appuntamenti (<?php echo count($app); ?>)</h3>
            <?php if (!$app): ?><p class="small text-muted">Nessun appuntamento in programma.</p><?php else: ?>
            <div class="table-responsive mb-3"><table class="table table-sm align-middle small mb-0">
                <thead class="table-light"><tr><th>Quando</th><th>Studente</th><th>Motivo</th><th class="text-end">Azioni</th></tr></thead>
                <tbody>
                <?php foreach ($app as $p): ?>
                    <tr>
                        <td class="text-nowrap"><strong><?php echo $h(quando_risorsa($p)); ?></strong><?php if ($p['stato'] === 'da_approvare'): ?> <span class="badge bg-warning text-dark">da approvare</span><?php endif; ?></td>
                        <td><?php echo $h(trim($p['nome'] . ' ' . $p['cognome'])); ?><div><a href="mailto:<?php echo $h($p['email']); ?>"><?php echo $h($p['email']); ?></a></div></td>
                        <td style="max-width:280px;"><?php echo $h($p['motivo']); ?></td>
                        <td class="text-end">
                            <form method="POST" class="d-inline-flex gap-1 flex-wrap justify-content-end align-items-center">
                                <?php csrf_field(); ?><input type="hidden" name="risorsa_id" value="<?php echo $rid; ?>"><input type="hidden" name="pren_id" value="<?php echo (int)$p['id']; ?>">
                                <input type="text" name="nota" class="form-control form-control-sm" style="width:140px;" placeholder="Nota (facolt.)" aria-label="Nota per lo studente" maxlength="500">
                                <?php if ($p['stato'] === 'da_approvare'): ?>
                                    <button type="submit" name="azione_app" value="approva" class="btn btn-sm btn-success" title="Approva"><i class="fa fa-check" aria-hidden="true"></i><span class="visually-hidden">Approva</span></button>
                                    <button type="submit" name="azione_app" value="rifiuta" class="btn btn-sm btn-outline-danger" title="Rifiuta" onclick="return confirm('Rifiutare la richiesta? Lo studente riceverà un\'email.');"><i class="fa fa-xmark" aria-hidden="true"></i><span class="visually-hidden">Rifiuta</span></button>
                                <?php else: ?>
                                    <button type="submit" name="azione_app" value="annulla" class="btn btn-sm btn-outline-danger" title="Annulla" onclick="return confirm('Annullare l\'appuntamento? Lo studente riceverà un\'email.');"><i class="fa fa-ban" aria-hidden="true"></i><span class="visually-hidden">Annulla</span></button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>

            <form method="POST" class="border-top pt-3">
                <?php csrf_field(); ?><input type="hidden" name="risorsa_id" value="<?php echo $rid; ?>">
                <h3 class="h6 fw-bold">Giorni e orari</h3>
                <p class="small text-secondary mb-2">Fino a due fasce al giorno; lascia vuoto un giorno in cui non ricevi.</p>
                <div class="ric-orari mb-3">
                    <?php foreach (GIORNI_SETTIMANA as $g => $nome_g): $fasce = $orari[$g] ?? []; ?>
                        <strong class="small"><?php echo $nome_g; ?></strong>
                        <?php foreach ([1, 2] as $f): $x = $fasce[$f - 1] ?? ['', '']; ?>
                            <span class="ric-fascia">
                                <input type="time" class="form-control form-control-sm" name="dalle_<?php echo $g; ?>_<?php echo $f; ?>" value="<?php echo $h(substr((string)$x[0], 0, 5)); ?>" aria-label="<?php echo $nome_g; ?>, fascia <?php echo $f; ?>, dalle">
                                <span class="small">–</span>
                                <input type="time" class="form-control form-control-sm" name="alle_<?php echo $g; ?>_<?php echo $f; ?>" value="<?php echo $h(substr((string)$x[1], 0, 5)); ?>" aria-label="<?php echo $nome_g; ?>, fascia <?php echo $f; ?>, alle">
                            </span>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6 col-md-3"><label class="form-label small fw-bold" for="rDur<?php echo $rid; ?>">Durata dell'appuntamento</label>
                        <select class="form-select form-select-sm" id="rDur<?php echo $rid; ?>" name="durata_slot"><?php foreach ([10, 15, 20, 30, 45, 60] as $m): ?><option value="<?php echo $m; ?>"<?php echo (int)$r['durata_slot'] === $m ? ' selected' : ''; ?>><?php echo $m; ?> minuti</option><?php endforeach; ?></select></div>
                    <div class="col-6 col-md-3"><label class="form-label small fw-bold" for="rAnt<?php echo $rid; ?>">Preavviso minimo (ore)</label><input type="number" min="0" max="168" class="form-control form-control-sm" id="rAnt<?php echo $rid; ?>" name="anticipo_ore" value="<?php echo (int)$r['anticipo_ore']; ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label small fw-bold" for="rMax<?php echo $rid; ?>">Prenotabile fino a (giorni)</label><input type="number" min="1" max="180" class="form-control form-control-sm" id="rMax<?php echo $rid; ?>" name="max_giorni" value="<?php echo (int)$r['max_giorni']; ?>"></div>
                    <div class="col-6 col-md-3"><label class="form-label small fw-bold" for="rLuo<?php echo $rid; ?>">Dove</label><input type="text" class="form-control form-control-sm" id="rLuo<?php echo $rid; ?>" name="luogo" value="<?php echo $h($r['luogo']); ?>" placeholder="es. Cubo 6C, studio 12, o Teams"></div>
                    <div class="col-12"><label class="form-label small fw-bold" for="rDes<?php echo $rid; ?>">Indicazioni per gli studenti</label><textarea class="form-control form-control-sm" id="rDes<?php echo $rid; ?>" name="descrizione" rows="2" maxlength="2000"><?php echo $h($r['descrizione']); ?></textarea></div>
                </div>
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <label class="form-check small"><input class="form-check-input" type="checkbox" name="approvazione" value="1"<?php echo (int)$r['approvazione'] ? ' checked' : ''; ?>> Approvo io ogni richiesta</label>
                    <label class="form-check small"><input class="form-check-input" type="checkbox" name="attiva" value="1"<?php echo (int)$r['attiva'] ? ' checked' : ''; ?>> Ricevimento prenotabile</label>
                </div>
                <button type="submit" name="salva_ricevimento" value="1" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
            </form>

            <div class="border-top pt-3 mt-3">
                <h3 class="h6 fw-bold">Assenze (ferie, missioni, sospensioni)</h3>
                <?php foreach ($chiusure as $c): ?>
                    <form method="POST" class="d-flex align-items-center gap-2 small mb-1">
                        <?php csrf_field(); ?><input type="hidden" name="risorsa_id" value="<?php echo $rid; ?>">
                        <span><strong><?php echo date('d/m/Y', strtotime($c['dal'])) . ($c['al'] !== $c['dal'] ? ' – ' . date('d/m/Y', strtotime($c['al'])) : ''); ?></strong> <?php echo $h($c['motivo']); ?></span>
                        <button type="submit" name="elimina_chiusura" value="<?php echo (int)$c['id']; ?>" class="btn btn-link btn-sm text-danger p-0">Togli</button>
                    </form>
                <?php endforeach; ?>
                <form method="POST" class="row g-2 align-items-end mt-1">
                    <?php csrf_field(); ?><input type="hidden" name="risorsa_id" value="<?php echo $rid; ?>">
                    <div class="col-6 col-md-3"><label class="form-label small mb-0" for="cDal<?php echo $rid; ?>">Dal</label><input type="date" class="form-control form-control-sm" id="cDal<?php echo $rid; ?>" name="dal" required></div>
                    <div class="col-6 col-md-3"><label class="form-label small mb-0" for="cAl<?php echo $rid; ?>">Al</label><input type="date" class="form-control form-control-sm" id="cAl<?php echo $rid; ?>" name="al"></div>
                    <div class="col-md-4"><label class="form-label small mb-0" for="cMot<?php echo $rid; ?>">Motivo (facoltativo)</label><input type="text" class="form-control form-control-sm" id="cMot<?php echo $rid; ?>" name="motivo" maxlength="255" placeholder="es. Missione"></div>
                    <div class="col-md-2"><button type="submit" name="aggiungi_chiusura" value="1" class="btn btn-sm btn-outline-secondary fw-bold w-100">Aggiungi</button></div>
                </form>
            </div>
        </div>
    </section>
    <?php endforeach; ?>
</div>
<?php require_once 'footer.php'; ?>
