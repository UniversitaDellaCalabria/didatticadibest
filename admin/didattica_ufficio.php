<?php
// didattica_ufficio.php - Ricevimento: sportello a appuntamenti dell'Ufficio didattico. Uffici e persone sono in Gestione del portale → Uffici (uffici.php).
// Scheda del pannello Didattica: si apre da didattica.php?tab=ufficio (stesso indirizzo di sempre), mai direttamente.
// $fase = 'azioni': gestisce i POST della scheda (ognuno risponde con un rimando e termina); $fase = 'vista': la pagina.
if (!defined('DIDATTICA_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

if ($fase === 'azioni') {
    // Gli uffici e le persone si gestiscono in Gestione del portale → Uffici (uffici.php); qui resta lo sportello di ricevimento
    if (isset($_POST['crea_sportello'])) {
        $pag = (int)($_POST['pagina_id'] ?? 0);
        $ok_area = $pag && tipo_area(db_riga($conn, "SELECT * FROM pagine_eventi WHERE id = ?", [$pag]) ?: []) === 'calendario';
        $rid = $ok_area ? crea_sportello_ufficio($conn, $pag, (string)($_POST['nome'] ?? ''), (string)($_POST['luogo'] ?? '')) : 0;
        if ($rid) registra_log_audit($conn, "Ufficio didattico: sportello di ricevimento creato", ["Risorsa" => $rid]);
        flash_set($rid ? "Sportello creato: ora imposta giorni e orari del ricevimento." : "Scegli un'area di Prenotazioni e risorse.", $rid ? 'success' : 'danger');
        admin_redirect("$base&tab=ufficio&sez=sportello&r=" . time());
    }
    return;
}
?>
<?php
    $sportelli_uff = sportelli_ufficio_didattica($conn);
    $aree_cal = array_values(array_filter($conn->query("SELECT * FROM pagine_eventi ORDER BY titolo")->fetch_all(MYSQLI_ASSOC), fn($a) => tipo_area($a) === 'calendario'));
    $sono_operatore = utente_operatore_ufficio($conn, $utente_admin);
    $sez = 'sportello';
?>
    <div class="alert alert-light border small"><i class="fa fa-sitemap me-1" aria-hidden="true"></i>Gli <strong>uffici</strong> (protocollo, uffici dei corsi di studio, segreteria…) e le <strong>persone</strong> che ne fanno parte si gestiscono ora in <a class="fw-bold" href="uffici.php">Gestione del portale → Uffici e persone</a>: un'unica anagrafe degli uffici, usata anche da tutorato, prenotazioni e convenzioni.</div>
<?php if ($sez === 'sportello'): ?>
    <div class="row g-3">
        <div class="col-lg-8 col-xl-6">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h5 class="fw-bold mb-1"><i class="fa fa-user-clock me-1" style="color:#7c3aed;" aria-hidden="true"></i>Ricevimento dell'ufficio</h5>
                <p class="small text-secondary">Sportello a appuntamenti per gli studenti: si prenota dalla pagina pubblica (anche dalla Modulistica); gli operatori impostano giorni, orari e assenze e vedono gli appuntamenti.</p>
                <?php foreach ($sportelli_uff as $sp):
                    $orari = db_righe($conn, "SELECT giorno, dalle, alle FROM risorse_orari WHERE risorsa_id = ? ORDER BY giorno, dalle", [(int)$sp['id']]);
                    $n_app = (int)db_valore($conn, "SELECT COUNT(*) FROM prenotazioni_risorse WHERE risorsa_id = ? AND stato IN ('confermata', 'da_approvare') AND fine >= NOW()", [(int)$sp['id']]); ?>
                    <div class="border rounded p-2 mb-2">
                        <div class="fw-bold"><?php echo $h($sp['nome']); ?> <?php echo (int)$sp['attiva'] ? '<span class="badge bg-success">prenotabile</span>' : '<span class="badge bg-secondary">non prenotabile</span>'; ?></div>
                        <div class="small text-secondary"><?php echo $h($sp['area_titolo']); ?><?php echo $sp['luogo'] ? ' · ' . $h($sp['luogo']) : ''; ?> · <?php echo $n_app; ?> appuntamenti in programma</div>
                        <div class="small"><?php echo $orari ? $h(implode(', ', array_map(fn($o) => GIORNI_SETTIMANA[(int)$o['giorno']] . ' ' . substr($o['dalle'], 0, 5) . '–' . substr($o['alle'], 0, 5), $orari))) : '<span class="text-danger">Orari non ancora impostati</span>'; ?></div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <?php if ($sono_operatore): ?><a class="btn btn-sm btn-primary fw-bold py-0" href="../ricevimento.php"><i class="fa fa-clock me-1" aria-hidden="true"></i>Orari e appuntamenti</a><?php endif; ?>
                            <?php if ($is_full_admin): ?><a class="btn btn-sm btn-outline-primary py-0" href="risorse.php?p_id=<?php echo (int)$sp['pagina_id']; ?>&amp;modifica=<?php echo (int)$sp['id']; ?>">Impostazioni</a>
                                <a class="btn btn-sm btn-outline-dark py-0" href="prenotazioni_risorse.php?p_id=<?php echo (int)$sp['pagina_id']; ?>">Prenotazioni</a><?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary py-0" href="../<?php echo $h($sp['area_slug']); ?>.php?risorsa=<?php echo (int)$sp['id']; ?>" target="_blank" rel="noopener">Pagina di prenotazione</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$sono_operatore && $sportelli_uff): ?><p class="small text-secondary">Gli orari li imposta un operatore dell'ufficio dalla sua Area personale → Il mio ricevimento.</p><?php endif; ?>
                <form method="POST" class="bg-light rounded p-2 mt-2">
                    <?php csrf_field(); ?>
                    <h6 class="fw-bold small mb-2"><?php echo $sportelli_uff ? 'Aggiungi un altro sportello' : 'Crea lo sportello di ricevimento'; ?></h6>
                    <?php if (!$aree_cal): ?><div class="small text-danger">Serve un'area di tipo «Aule, laboratori e sportelli» in Prenotazioni e risorse.</div><?php else: ?>
                    <label class="form-label small fw-bold mb-0" for="spArea">Area di Prenotazioni e risorse</label>
                    <select class="form-select form-select-sm mb-1" id="spArea" name="pagina_id"><?php foreach ($aree_cal as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo $h($a['titolo']); ?></option><?php endforeach; ?></select>
                    <input type="text" class="form-control form-control-sm mb-1" name="nome" value="Ufficio didattico – ricevimento studenti" maxlength="150" aria-label="Nome dello sportello">
                    <input type="text" class="form-control form-control-sm mb-2" name="luogo" placeholder="Luogo (es. Cubo 4B, piano terra)" maxlength="255" aria-label="Luogo">
                    <button type="submit" name="crea_sportello" value="1" class="btn btn-sm fw-bold text-white" style="background:#7c3aed;"><i class="fa fa-plus me-1" aria-hidden="true"></i>Crea</button>
                    <?php endif; ?>
                </form>
            </div></div>
        </div>
    </div>
<?php endif; ?>
