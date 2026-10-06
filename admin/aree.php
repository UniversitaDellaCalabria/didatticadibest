<?php
// aree.php - Elenco delle aree di lavoro visibili all'utente (in base alle abilitazioni):
// da qui si entra nella gestione di un'area; gli amministratori creano, nascondono ed eliminano le aree.
require_once 'admin_header.php';

// Tipo dell'area (macroarea): solo amministratori
if ($is_full_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tipo_area_pagina'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id_t = (int)$_POST['tipo_area_pagina'];
    $tipo_t = (string)($_POST['tipo_area'] ?? '');
    if ($tipo_t !== '' && (!isset(TIPI_AREA[$tipo_t]) || !TIPI_AREA[$tipo_t]['disponibile'])) $tipo_t = '';
    \App\Core\App::per($conn)->get(\App\Eventi\AreaRepository::class)->impostaTipo($id_t, $tipo_t);
    registra_log_audit($conn, "Tipo di area", ["Area" => $id_t, "Tipo" => $tipo_t !== '' ? TIPI_AREA[$tipo_t]['nome'] : 'non assegnata']);
    if (function_exists('invalidate_configurazione_portale_cache')) invalidate_configurazione_portale_cache();
    flash_set("Tipo dell'area aggiornato.");
    echo "<script>window.location.replace(" . json_encode("aree.php?p_id=$filtro_p") . ");</script>"; exit;
}

$righe_aree = [];
$repo_aree = \App\Core\App::per($conn)->get(\App\Eventi\AreaRepository::class);
foreach ($pagine_disponibili as $a) {
    $id_a = (int)$a['id'];
    // Numeri dell'area: solo per amministratori e gestori dell'intera area (chi gestisce un solo evento vede solo l'elenco)
    $gestisce_area = $is_full_admin || in_array((int)$u_id_curr, ids_gestori_da_campi($a['gestore_utente_id'] ?? 0, $a['gestori_utenti_ids'] ?? '', $a['permessi_gestori_json'] ?? ''), true);
    $num = null;
    if ($gestisce_area) {
        $num = $repo_aree->numeri($id_a);
    }
    // Per la finestra di eliminazione (solo amministratori): tutto ciò che verrebbe cancellato, archivio compreso
    $cancella = null;
    if ($is_full_admin) {
        $cancella = $repo_aree->numeriEliminazione($id_a);
    }
    $righe_aree[] = ['a' => $a, 'num' => $num, 'gestisce' => $gestisce_area, 'cancella' => $cancella];
}
?>
<style>
.aree-tab td { vertical-align: middle; }
.aree-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.aree-tab tr.corrente { background: #f8fafc; }
.aree-num { font-size: .78rem; color: #475569; }
.aree-num strong { color: #0f172a; }
</style>

<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-layer-group me-2 text-primary" aria-hidden="true"></i>Aree</h4>
    <?php if ($is_full_admin): ?>
        <a href="nuova_area.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuova area</a>
    <?php endif; ?>
</div>
<p class="text-secondary small mb-3"><?php echo $is_full_admin ? "Tutte le aree del portale, raggruppate per macroarea: il <strong>tipo</strong> colloca l'area nella home e propone le impostazioni adatte ai nuovi eventi e progetti (le attività esistenti non cambiano)." : "Le aree in cui sei abilitato."; ?> Con <strong>Gestisci</strong> entri nell'area: nel menu a sinistra trovi eventi, progetti, iscritti, scanner, sondaggi, statistiche e impostazioni di quell'area.</p>

<?php if (!$righe_aree): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">Nessuna area disponibile.</div></div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table aree-tab mb-0">
            <thead class="table-light small">
                <tr><th>Area</th><th class="d-none d-md-table-cell">Indirizzo</th><th class="d-none d-lg-table-cell">Contenuti</th><th>Stato</th><th class="text-end">Azioni</th></tr>
            </thead>
            <tbody>
            <?php
            // Righe divise per macroarea (in fondo le aree non assegnate)
            $gruppi_righe = array_fill_keys(array_merge(array_keys(SEZIONI_PORTALE), ['']), []);
            foreach ($righe_aree as $r_a) $gruppi_righe[sezione_area($r_a['a'])][] = $r_a;
            foreach (array_filter($gruppi_righe) as $sez_k => $righe_sez):
                $sez_i = SEZIONI_PORTALE[$sez_k] ?? ['nome' => 'Aree non assegnate', 'icona' => 'fa-circle-question', 'descr' => 'Si comportano come eventi generici: scegli il tipo per collocarle'];
            ?>
                <tr class="table-light"><td colspan="5" class="small fw-bold text-uppercase" style="letter-spacing:.05em;"><i class="fa <?php echo $sez_i['icona']; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($sez_i['nome']); ?> <span class="fw-normal text-secondary" style="text-transform:none;letter-spacing:0;">· <?php echo htmlspecialchars($sez_i['descr']); ?></span></td></tr>
            <?php foreach ($righe_sez as ['a' => $a, 'num' => $num]):
                $id_a = (int)$a['id'];
                $col_a = colore_valido($a['colore_primario'] ?? '', '#0056B3');
                $visibile = (int)($a['visibile'] ?? 1) === 1;
                $url_pub = '../' . $a['slug'] . '.php';
            ?>
                <tr class="<?php echo $id_a === (int)$filtro_p ? 'corrente' : ''; ?>">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="aree-dot" style="background: <?php echo $col_a; ?>;" aria-hidden="true"></span>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($a['titolo']); ?></div>
                                <?php if ($id_a === (int)$filtro_p): ?><span class="badge bg-secondary-subtle text-secondary-emphasis" style="font-size:.65rem;">Area corrente</span><?php endif; ?>
                                <?php if ($is_full_admin): ?>
                                    <form method="POST" class="mt-1">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="tipo_area_pagina" value="<?php echo $id_a; ?>">
                                        <label class="visually-hidden" for="tipo<?php echo $id_a; ?>">Tipo dell'area <?php echo htmlspecialchars($a['titolo']); ?></label>
                                        <?php echo html_scelta_tipo_area('tipo_area', tipo_area($a), 'id="tipo' . $id_a . '" onchange="this.form.submit()" style="max-width:260px;font-size:.75rem;"'); ?>
                                    </form>
                                <?php elseif (tipo_area($a) !== ''): ?>
                                    <span class="small text-secondary"><?php echo htmlspecialchars(TIPI_AREA[tipo_area($a)]['nome']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell small"><a href="<?php echo htmlspecialchars($url_pub); ?>" target="_blank" rel="noopener" class="text-decoration-none font-monospace"><?php echo htmlspecialchars($a['slug']); ?>.php <i class="fa fa-up-right-from-square ms-1" style="font-size:.7rem;" aria-hidden="true"></i></a></td>
                    <td class="d-none d-lg-table-cell aree-num">
                        <?php if ($num): ?>
                            <strong><?php echo (int)$num['eventi']; ?></strong> eventi<?php if ((int)$num['progetti'] > 0): ?> · <strong><?php echo (int)$num['progetti']; ?></strong> progetti<?php endif; ?> · <strong><?php echo (int)$num['iscritti']; ?></strong> iscritti
                            <?php if (!empty($num['prossimo'])): ?><div>Prossimo turno: <?php echo date('d/m/Y', strtotime($num['prossimo'])); ?></div><?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_full_admin): ?>
                            <form method="POST" class="m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="pagina_id" value="<?php echo $id_a; ?>">
                                <input type="hidden" name="stato_visibile" value="<?php echo $visibile ? 0 : 1; ?>">
                                <button type="submit" name="toggle_visibilita_pagina" value="1" class="btn btn-sm fw-bold <?php echo $visibile ? 'btn-outline-success' : 'btn-warning text-dark'; ?>"
                                        title="<?php echo $visibile ? 'Clicca per nasconderla al pubblico' : 'Clicca per renderla visibile'; ?>"
                                        <?php echo $visibile ? 'data-confirm="Nascondere l\'area al pubblico? La vedranno solo i gestori."' : ''; ?>>
                                    <i class="fa <?php echo $visibile ? 'fa-eye' : 'fa-eye-slash'; ?> me-1" aria-hidden="true"></i><?php echo $visibile ? 'Visibile' : 'Nascosta'; ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="badge <?php echo $visibile ? 'bg-success-subtle text-success-emphasis' : 'bg-warning text-dark'; ?>"><?php echo $visibile ? 'Visibile' : 'Nascosta'; ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                            <a href="dashboard.php?p_id=<?php echo $id_a; ?>" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-sliders me-1" aria-hidden="true"></i>Gestisci</a>
                            <a href="<?php echo htmlspecialchars($url_pub); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Apri la pagina pubblica" aria-label="Apri la pagina pubblica di <?php echo htmlspecialchars($a['titolo']); ?>"><i class="fa fa-eye" aria-hidden="true"></i></a>
                            <?php if ($is_full_admin): ?>
                                <a href="impostazioni_area.php?p_id=<?php echo $id_a; ?>" class="btn btn-sm btn-outline-secondary" title="Impostazioni area" aria-label="Impostazioni di <?php echo htmlspecialchars($a['titolo']); ?>"><i class="fa fa-paint-brush" aria-hidden="true"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#elimArea<?php echo $id_a; ?>" title="Elimina area" aria-label="Elimina <?php echo htmlspecialchars($a['titolo']); ?>"><i class="fa fa-trash-alt" aria-hidden="true"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($is_full_admin): foreach ($righe_aree as ['a' => $a, 'cancella' => $c]):
    $id_a = (int)$a['id'];
    $tit_a = (string)$a['titolo'];
    $visibile = (int)($a['visibile'] ?? 1) === 1;
    $c = $c ?: ['eventi' => 0, 'turni' => 0, 'prenotazioni' => 0, 'studenti' => 0, 'sondaggi' => 0];
    $vuota = (int)$c['eventi'] === 0;
?>
<!-- Eliminazione dell'area: si conferma scrivendo il nome (controllato anche dal server) -->
<div class="modal fade" id="elimArea<?php echo $id_a; ?>" tabindex="-1" aria-labelledby="elimAreaTit<?php echo $id_a; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <form method="POST" class="elim-area-form">
                <?php csrf_field(); ?>
                <input type="hidden" name="pagina_id_del" value="<?php echo $id_a; ?>">
                <div class="modal-header bg-danger text-white py-2">
                    <h5 class="modal-title fs-6 fw-bold" id="elimAreaTit<?php echo $id_a; ?>"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Eliminare l'area "<?php echo htmlspecialchars($tit_a); ?>"?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <?php if ($vuota): ?>
                        <p class="mb-2">L'area non contiene eventi né progetti.</p>
                    <?php else: ?>
                        <p class="mb-2">Verranno cancellati <strong>definitivamente</strong>, archivio compreso:</p>
                        <ul class="small mb-3">
                            <li><strong><?php echo (int)$c['eventi']; ?></strong> eventi e progetti, con <strong><?php echo (int)$c['turni']; ?></strong> turni/edizioni</li>
                            <li><strong><?php echo (int)$c['prenotazioni']; ?></strong> prenotazioni (con messaggi, presenze e dati dei moduli)</li>
                            <?php if ((int)$c['studenti'] > 0): ?><li><strong><?php echo (int)$c['studenti']; ?></strong> studenti degli elenchi: i loro attestati non risulteranno più verificabili</li><?php endif; ?>
                            <?php if ((int)$c['sondaggi'] > 0): ?><li><strong><?php echo (int)$c['sondaggi']; ?></strong> sondaggi con le risposte</li><?php endif; ?>
                            <li>campi del modulo, sezioni e voce di menu dell'area</li>
                        </ul>
                    <?php endif; ?>
                    <?php if ($visibile && !$vuota): ?>
                        <div class="alert alert-info small py-2"><i class="fa fa-eye-slash me-1" aria-hidden="true"></i>Se vuoi solo toglierla dal sito, <strong>nascondila</strong>: i dati restano e puoi ripubblicarla quando vuoi.</div>
                    <?php endif; ?>
                    <label for="elimNome<?php echo $id_a; ?>" class="form-label small fw-bold">Per confermare scrivi il nome dell'area: <span class="font-monospace text-danger"><?php echo htmlspecialchars($tit_a); ?></span></label>
                    <input type="text" name="conferma_nome" id="elimNome<?php echo $id_a; ?>" class="form-control elim-nome" data-nome="<?php echo htmlspecialchars(mb_strtolower(trim(preg_replace('/\s+/', ' ', $tit_a)))); ?>" autocomplete="off" required>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="del_pagina_completa" value="1" class="btn btn-danger btn-sm fw-bold elim-invia" disabled><i class="fa fa-trash-alt me-1" aria-hidden="true"></i>Elimina definitivamente</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<script>
// Il pulsante si attiva solo quando il nome scritto coincide (senza distinguere maiuscole e spazi in più)
document.querySelectorAll('.elim-area-form').forEach(function (f) {
    var campo = f.querySelector('.elim-nome'), btn = f.querySelector('.elim-invia');
    campo.addEventListener('input', function () {
        btn.disabled = campo.value.trim().replace(/\s+/g, ' ').toLowerCase() !== campo.dataset.nome;
    });
});
</script>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
