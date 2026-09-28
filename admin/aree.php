<?php
// aree.php - Elenco delle aree di lavoro visibili all'utente (in base alle abilitazioni):
// da qui si entra nella gestione di un'area; gli amministratori creano, nascondono ed eliminano le aree.
require_once 'admin_header.php';

$righe_aree = [];
foreach ($pagine_disponibili as $a) {
    $id_a = (int)$a['id'];
    // Numeri dell'area: solo per amministratori e gestori dell'intera area (chi gestisce un solo evento vede solo l'elenco)
    $gestisce_area = $is_full_admin || in_array((int)$u_id_curr, ids_gestori_da_campi($a['gestore_utente_id'] ?? 0, $a['gestori_utenti_ids'] ?? '', $a['permessi_gestori_json'] ?? ''), true);
    $num = null;
    if ($gestisce_area) {
        $r_n = $conn->query("SELECT
                (SELECT COUNT(*) FROM eventi e WHERE e.pagina_id = $id_a AND e.archiviato = 0 AND IFNULL(e.tipo, 'evento') <> 'progetto') AS eventi,
                (SELECT COUNT(*) FROM eventi e WHERE e.pagina_id = $id_a AND e.archiviato = 0 AND e.tipo = 'progetto') AS progetti,
                (SELECT COUNT(*) FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                  WHERE e.pagina_id = $id_a AND e.archiviato = 0 AND IFNULL(p.stato, 'confermata') = 'confermata') AS iscritti,
                (SELECT MIN(t.data_turno) FROM turni t JOIN eventi e ON t.evento_id = e.id
                  WHERE e.pagina_id = $id_a AND e.archiviato = 0 AND t.data_turno >= CURDATE()) AS prossimo");
        $num = $r_n ? $r_n->fetch_assoc() : null;
    }
    $righe_aree[] = ['a' => $a, 'num' => $num, 'gestisce' => $gestisce_area];
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
        <button type="button" class="btn btn-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modNuovaAreaTop"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuova area</button>
    <?php endif; ?>
</div>
<p class="text-secondary small mb-3"><?php echo $is_full_admin ? "Tutte le aree del portale." : "Le aree in cui sei abilitato."; ?> Con <strong>Gestisci</strong> entri nell'area: nel menu a sinistra trovi eventi, progetti, iscritti, scanner, sondaggi, statistiche e impostazioni di quell'area.</p>

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
            <?php foreach ($righe_aree as ['a' => $a, 'num' => $num]):
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
                                <form method="POST" class="m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="pagina_id_del" value="<?php echo $id_a; ?>">
                                    <button type="submit" name="del_pagina_completa" value="1" class="btn btn-sm btn-outline-danger" title="Elimina area" aria-label="Elimina <?php echo htmlspecialchars($a['titolo']); ?>"
                                            data-confirm="Eliminare definitivamente l'area &quot;<?php echo htmlspecialchars($a['titolo']); ?>&quot; con tutti i suoi eventi, turni, iscrizioni e sondaggi? L'operazione non si può annullare."><i class="fa fa-trash-alt" aria-hidden="true"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
