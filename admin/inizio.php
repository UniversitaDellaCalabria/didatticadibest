<?php
// admin/inizio.php - Pagina di ingresso della gestione: prima si sceglie la sezione (Orientamento, Didattica,
// Calendari e risorse), poi l'area della sezione; cliccando l'area si entra nella sua dashboard.
// Con aree di una sola sezione si salta direttamente alla scelta dell'area.
require_once 'admin_header.php';

$gruppi_sez = raggruppa_aree_per_sezione($pagine_disponibili);
$info_sez = fn(string $k) => SEZIONI_PORTALE[$k] ?? ['nome' => 'Aree non assegnate', 'icona' => 'fa-circle-question', 'descr' => 'Aree senza tipo: si comportano come eventi generici' . ($is_full_admin ? ' (il tipo si sceglie in Aree)' : '')];
$sez_sel = isset($_GET['sezione']) ? (string)$_GET['sezione'] : null;
if ($sez_sel !== null && !isset($gruppi_sez[$sez_sel])) $sez_sel = null;
if ($sez_sel === null && count($gruppi_sez) === 1) $sez_sel = (string)array_key_first($gruppi_sez);

// Numeri di un'area (solo per chi la gestisce tutta)
$numeri_area = function (array $a) use ($conn, $is_full_admin, $u_id_curr): ?array {
    if (!$is_full_admin && !in_array((int)$u_id_curr, ids_gestori_da_campi($a['gestore_utente_id'] ?? 0, $a['gestori_utenti_ids'] ?? '', $a['permessi_gestori_json'] ?? ''), true)) return null;
    $id = (int)$a['id'];
    if (tipo_area($a) === 'calendario') {
        $r = @$conn->query("SELECT (SELECT COUNT(*) FROM risorse WHERE pagina_id = $id AND attiva = 1) AS risorse,
                (SELECT COUNT(*) FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = $id AND pr.stato = 'confermata' AND pr.inizio >= NOW()) AS prossime,
                (SELECT COUNT(*) FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = $id AND pr.stato = 'da_approvare' AND pr.inizio >= NOW()) AS da_approvare");
        $x = $r ? $r->fetch_assoc() : null;
        return $x ? [[(int)$x['risorse'], 'risorse'], [(int)$x['prossime'], 'prenotazioni in arrivo'], [(int)$x['da_approvare'], 'da approvare', true]] : null;
    }
    $r = $conn->query("SELECT
            (SELECT COUNT(*) FROM eventi e WHERE e.pagina_id = $id AND e.archiviato = 0 AND IFNULL(e.tipo, 'evento') <> 'progetto') AS eventi,
            (SELECT COUNT(*) FROM eventi e WHERE e.pagina_id = $id AND e.archiviato = 0 AND e.tipo = 'progetto') AS progetti,
            (SELECT COUNT(*) FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
              WHERE e.pagina_id = $id AND e.archiviato = 0 AND IFNULL(p.stato, 'confermata') = 'confermata') AS iscritti");
    $x = $r ? $r->fetch_assoc() : null;
    if (!$x) return null;
    $out = [[(int)$x['eventi'], 'eventi']];
    if ((int)$x['progetti'] > 0) $out[] = [(int)$x['progetti'], 'progetti'];
    $out[] = [(int)$x['iscritti'], 'iscritti'];
    return $out;
};
?>
<style>
.ini-card { border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; transition: transform .12s, box-shadow .12s, border-color .12s; color: inherit; text-decoration: none; display: flex; flex-direction: column; height: 100%; }
.ini-card:hover, .ini-card:focus-visible { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(15,23,42,.10); border-color: #94a3b8; color: inherit; }
.ini-ico { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
.ini-aree { font-size: .8rem; color: #475569; }
.ini-aree span { display: inline-block; background: #f1f5f9; border-radius: 999px; padding: 2px 9px; margin: 2px 4px 2px 0; }
.ini-area-barra { height: 6px; border-radius: 14px 14px 0 0; }
.ini-num { font-size: .8rem; color: #475569; }
.ini-num strong { color: #0f172a; font-size: 1rem; }
.ini-num .ini-avviso strong { color: #b45309; }
[data-bs-theme="dark"] .ini-card { background: #2b2f33; border-color: #3a3f46; }
[data-bs-theme="dark"] .ini-aree span { background: #1e2227; color: #cbd5e1; }
[data-bs-theme="dark"] .ini-num strong { color: #f1f5f9; }
</style>

<?php if (!$pagine_disponibili): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">Non risulti assegnato a nessuna area di lavoro.</div></div>
<?php elseif ($sez_sel === null): ?>
    <h4 class="fw-bold text-dark mb-1"><i class="fa fa-house me-2 text-primary" aria-hidden="true"></i>Da dove vuoi iniziare?</h4>
    <p class="text-secondary small mb-4">Scegli la sezione, poi l'area su cui lavorare.</p>
    <div class="row g-3">
        <?php foreach ($gruppi_sez as $k => $aree_s): $s = $info_sez($k); $col = ['orientamento' => '#0056B3', 'didattica' => '#047857', 'calendari' => '#7c3aed'][$k] ?? '#64748b'; ?>
        <div class="col-md-6 col-xl-4">
            <a class="ini-card p-4" href="inizio.php?sezione=<?php echo urlencode($k); ?>&amp;p_id=<?php echo (int)$filtro_p; ?>">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="ini-ico" style="background: <?php echo $col; ?>1a; color: <?php echo $col; ?>;"><i class="fa <?php echo $s['icona']; ?>" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="h5 fw-bold mb-0"><?php echo htmlspecialchars($s['nome']); ?></h2>
                        <div class="small text-secondary"><?php echo count($aree_s); ?> <?php echo count($aree_s) === 1 ? 'area' : 'aree'; ?></div>
                    </div>
                </div>
                <p class="small text-secondary mb-3"><?php echo htmlspecialchars($s['descr']); ?></p>
                <div class="ini-aree mt-auto"><?php foreach ($aree_s as $a): ?><span><?php echo htmlspecialchars($a['titolo']); ?></span><?php endforeach; ?></div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: $s = $info_sez($sez_sel); ?>
    <nav aria-label="Percorso" class="small mb-2">
        <?php if (count($gruppi_sez) > 1): ?><a href="inizio.php?p_id=<?php echo (int)$filtro_p; ?>" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le sezioni</a><?php endif; ?>
    </nav>
    <h4 class="fw-bold text-dark mb-1"><i class="fa <?php echo $s['icona']; ?> me-2 text-primary" aria-hidden="true"></i><?php echo htmlspecialchars($s['nome']); ?></h4>
    <p class="text-secondary small mb-4"><?php echo htmlspecialchars($s['descr']); ?>. Scegli l'area: entri nella sua dashboard.</p>
    <div class="row g-3">
        <?php foreach ($gruppi_sez[$sez_sel] as $a): $col_a = colore_valido($a['colore_primario'] ?? '', '#0056B3'); $num = $numeri_area($a); $t = tipo_area($a); ?>
        <div class="col-md-6 col-xl-4">
            <a class="ini-card" href="dashboard.php?p_id=<?php echo (int)$a['id']; ?>">
                <div class="ini-area-barra" style="background: <?php echo $col_a; ?>;"></div>
                <div class="p-4 d-flex flex-column flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <h2 class="h5 fw-bold mb-0" style="color: <?php echo $col_a; ?>;"><?php echo htmlspecialchars($a['titolo']); ?></h2>
                        <?php if ((int)($a['visibile'] ?? 1) === 0): ?><span class="badge bg-warning text-dark" style="font-size:.65rem;"><i class="fa fa-eye-slash me-1" aria-hidden="true"></i>Nascosta</span><?php endif; ?>
                    </div>
                    <div class="small text-secondary mb-3"><?php echo $t !== '' ? htmlspecialchars(TIPI_AREA[$t]['nome']) : 'Eventi generici'; ?><?php if ((int)$a['id'] === (int)$filtro_p): ?> · <span class="fw-bold">ultima usata</span><?php endif; ?></div>
                    <?php if ($num): ?>
                        <div class="ini-num d-flex flex-wrap gap-3 mt-auto">
                            <?php foreach ($num as $n): ?><div class="<?php echo !empty($n[2]) && $n[0] > 0 ? 'ini-avviso' : ''; ?>"><strong><?php echo (int)$n[0]; ?></strong> <?php echo htmlspecialchars($n[1]); ?></div><?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="ini-num mt-auto">Le attività che gestisci in quest'area</div>
                    <?php endif; ?>
                    <div class="mt-3 small fw-bold" style="color: <?php echo $col_a; ?>;">Entra <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($is_full_admin && $pagine_disponibili): ?>
    <p class="small text-secondary mt-4 mb-0"><i class="fa fa-layer-group me-1" aria-hidden="true"></i>Per creare, nascondere o assegnare il tipo alle aree vai in <a href="aree.php?p_id=<?php echo (int)$filtro_p; ?>">Aree</a>.</p>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
