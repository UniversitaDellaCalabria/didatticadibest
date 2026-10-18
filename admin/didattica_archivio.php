<?php
// didattica_archivio.php - Archivio verbali (vista della scheda Sedute e verbali, ?archivio=1): tutti i verbali, quelli del portale e quelli vecchi
// importati in PDF, con filtri e ricerca nel testo; importazione di uno o più file o di una cartella; modifica dei dati dei verbali importati.
// Si include solo da didattica_sedute.php (le azioni stanno lì).
if (!defined('DIDATTICA_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

$rep_sed = \App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class);
$srv_organi = \App\Core\App::per($conn)->get(\App\Didattica\OrganiArchivio::class);
$organi_arch = $puo_didattica_tutto ? array_values(array_filter($srv_organi->tutti(), fn($o) => (int)$o['attivo'] === 1)) : []; // altri organi (commissioni, riesame…): solo l'Ufficio didattico
$imp_id = (int)($_GET['imp'] ?? 0);
$imp = $imp_id ? seduta_didattica($conn, $imp_id) : null;
if ($imp && ($imp['origine'] !== 'importato' || !($imp['consiglio_id'] ? $puo_seduta($imp) : $puo_didattica_tutto))) $imp = null;
$url_arch = "$base&tab=sedute&archivio=1";
// Pubblicazione nella pagina pubblica con password (solo Ufficio didattico)
$pub_id = $puo_didattica_tutto ? (int)($_GET['pub'] ?? 0) : 0;
$pubs = $pub_id ? seduta_didattica($conn, $pub_id) : null;
if ($pubs && !($pubs['verbale_stato'] === 'firmato' || $pubs['origine'] === 'importato')) $pubs = null;
?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;tab=sedute" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Sedute e consigli</a></nav>
<?php if ($pubs): ?>
    <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body">
        <?php csrf_field(); ?>
        <h5 class="fw-bold mb-1"><i class="fa fa-earth-europe me-1 text-primary" aria-hidden="true"></i>Pubblica nella pagina dei verbali</h5>
        <p class="small text-secondary mb-3"><?php echo $h($pubs['organo']); ?> · <?php echo $pubs['data'] ? date('d/m/Y', strtotime($pubs['data'])) : 'senza data'; ?><?php echo $pubs['numero_verbale'] !== '' ? ' · verbale n. ' . $h($pubs['numero_verbale']) : ''; ?>
            · <?php if ($pubs['verbale_pdf']): ?><a href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$pubs['id']; ?>&amp;esporta=verbale_pdf">scarica il PDF</a><?php endif; ?></p>
        <?php if ((int)$pubs['pubblicabile']): ?>
            <div class="alert alert-success small py-2"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Pubblicato il <?php echo date('d/m/Y H:i', strtotime($pubs['pubblicato_il'])); ?><?php echo $pubs['pubblicato_da'] !== '' ? ' da ' . $h($pubs['pubblicato_da']) : ''; ?> · <?php echo $pubs['verbale_pubblico_pdf'] ? 'versione con omissis' : 'PDF firmato così com\'è'; ?>.</div>
        <?php endif; ?>
        <div class="alert alert-warning small"><i class="fa fa-user-shield me-1" aria-hidden="true"></i><strong>Dati personali.</strong> I verbali con le pratiche contengono nomi, matricole ed esiti degli studenti: chi accede alla pagina pubblica potrebbe leggerli. Pubblica il PDF firmato solo se non contiene dati personali, altrimenti carica una versione con gli <em>omissis</em> (il PDF firmato digitalmente non si può modificare: si prepara a parte).</div>
        <div class="form-check mb-2"><input class="form-check-input" type="radio" name="modo" id="pmOm" value="omissis" checked><label class="form-check-label" for="pmOm"><strong>Versione con omissis</strong> (consigliata): carico un PDF senza i dati personali</label></div>
        <div class="ms-4 mb-3"><input type="file" class="form-control form-control-sm" name="pdf_omissis" accept=".pdf,application/pdf" aria-label="PDF con gli omissis"></div>
        <div class="form-check mb-1"><input class="form-check-input" type="radio" name="modo" id="pmInt" value="integrale"><label class="form-check-label" for="pmInt"><strong>PDF firmato così com'è</strong></label></div>
        <div class="form-check ms-4 mb-3"><input class="form-check-input" type="checkbox" name="conferma" id="pmConf" value="1"><label class="form-check-label small" for="pmConf">Ho verificato che il verbale non contiene dati personali degli studenti (o che possono essere resi pubblici).</label></div>
        <div class="d-flex flex-wrap gap-2">
            <button type="submit" name="pubblica_verbale" value="<?php echo (int)$pubs['id']; ?>" class="btn btn-primary fw-bold"><i class="fa fa-earth-europe me-1" aria-hidden="true"></i><?php echo (int)$pubs['pubblicabile'] ? 'Aggiorna la pubblicazione' : 'Pubblica'; ?></button>
            <?php if ((int)$pubs['pubblicabile']): ?><button type="submit" name="ritira_verbale" value="<?php echo (int)$pubs['id']; ?>" class="btn btn-outline-danger" data-confirm="Ritirare il verbale dalla pagina pubblica?"><i class="fa fa-eye-slash me-1" aria-hidden="true"></i>Ritira</button><?php endif; ?>
            <a class="btn btn-outline-secondary" href="<?php echo $h($url_arch); ?>">Annulla</a>
        </div>
    </div></form>
<?php elseif ($imp): ?>
    <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$imp['id']; ?>">
        <h5 class="fw-bold mb-1"><i class="fa fa-pen me-1 text-primary" aria-hidden="true"></i>Dati del verbale importato</h5>
        <p class="small text-secondary mb-3">File: <strong><?php echo $h($imp['nome_originale'] ?: 'senza nome'); ?></strong> · <a href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$imp['id']; ?>&amp;esporta=verbale_pdf"><i class="fa fa-download me-1" aria-hidden="true"></i>scarica il PDF</a></p>
        <div class="row g-2">
            <div class="col-md-8"><label class="form-label small fw-bold" for="iCons">Consiglio</label>
                <select class="form-select" id="iCons" name="consiglio_id">
                    <?php if ($puo_didattica_tutto): ?><option value="0">— nessuno (scrivo l'organo qui sotto) —</option><?php endif; ?>
                    <?php foreach ($consigli_vis as $k => $c): ?><option value="<?php echo $k; ?>"<?php echo (int)$imp['consiglio_id'] === $k ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($c['nome'], 0, 150, '…')); ?></option><?php endforeach; ?>
                    <?php if ($organi_arch): ?><optgroup label="Altri organi (commissioni, riesame, studenti…)"><?php foreach ($organi_arch as $o_s): ?><option value="o<?php echo (int)$o_s['id']; ?>"<?php echo (int)$imp['organo_id'] === (int)$o_s['id'] ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($o_s['nome'], 0, 150, '…')); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                </select></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="iNum">Verbale n.</label><input class="form-control" id="iNum" name="numero_verbale" maxlength="10" value="<?php echo $h($imp['numero_verbale']); ?>"></div>
            <div class="col-12"><label class="form-label small fw-bold" for="iOrg">Organo (solo se non scegli un consiglio)</label><input class="form-control" id="iOrg" name="organo" maxlength="500" value="<?php echo $h($imp['consiglio_id'] || $imp['organo_id'] ? '' : $imp['organo']); ?>"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="iData">Data della seduta</label><input type="date" class="form-control" id="iData" name="data" value="<?php echo $h($imp['data']); ?>"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="iAa">Anno accademico</label><select class="form-select" id="iAa" name="anno_accademico"><option value="">— dalla data —</option><?php foreach (array_unique(array_merge(anni_accademici_scelta(), $imp['anno_accademico'] !== '' ? [$imp['anno_accademico']] : [])) as $a): ?><option<?php echo $a === $imp['anno_accademico'] ? ' selected' : ''; ?>><?php echo $h($a); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="iPdf">Sostituisci il PDF (facoltativo)</label><input type="file" class="form-control" id="iPdf" name="verbale_pdf" accept=".pdf,application/pdf"></div>
            <div class="col-12"><label class="form-label small fw-bold" for="iOdg">Ordine del giorno, in breve (facoltativo)</label><textarea class="form-control" id="iOdg" name="odg" rows="5" maxlength="5000" placeholder="Un punto per riga"><?php echo $h($imp['odg']); ?></textarea>
                <div class="form-text">Aiuta la ricerca: le parole scritte qui si trovano anche se il PDF non è leggibile.</div></div>
        </div>
        <div class="mt-3 d-flex gap-2"><button type="submit" name="salva_verbale_importato" value="1" class="btn btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button><a class="btn btn-outline-secondary" href="<?php echo $h($url_arch); ?>">Annulla</a></div>
    </div></form>
<?php else:
    $filtri = ['consiglio' => (int)($_GET['cons'] ?? 0), 'anno_accademico' => (string)($_GET['aa'] ?? ''), 'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100), 'tipo' => in_array($_GET['tipo'] ?? '', ['portale', 'importato', 'firmato', 'incompleto'], true) ? $_GET['tipo'] : '', 'organo_id' => $puo_didattica_tutto ? (int)($_GET['organo'] ?? 0) : 0, 'corso' => $puo_didattica_tutto ? mb_substr(trim((string)($_GET['corso'] ?? '')), 0, 300) : ''];
    $corsi_filtro = [];
    foreach ($consigli_vis as $c_f) { $corsi_filtro[(string)$c_f['nome']] = (string)$c_f['nome']; }
    foreach ($organi_arch as $o_f) { if ($o_f['corso'] !== '') $corsi_filtro[(string)$o_f['corso']] = (string)$o_f['corso']; }
    natcasesort($corsi_filtro);
    if ($filtri['consiglio'] && !isset($consigli_vis[$filtri['consiglio']])) $filtri['consiglio'] = 0;
    $verbali = $rep_sed->archivio($filtri, $solo_ref ? $consigli_referente : null);
    $anni = $rep_sed->anniAccademici();
    $n_incompleti = count(array_filter($verbali, fn($x) => !$x['data'] || $x['organo'] === ''));
?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <h2 class="h5 fw-bold mb-0"><i class="fa fa-box-archive me-1" style="color:var(--pn-mod);" aria-hidden="true"></i>Archivio verbali</h2>
        <?php if ($puo_didattica_tutto): ?><a class="btn btn-sm btn-outline-secondary ms-auto" href="<?php echo $base; ?>&amp;tab=sedute&amp;accessi=1"><i class="fa fa-key me-1" aria-hidden="true"></i>Accesso ai verbali (pagina pubblica)</a><?php endif; ?>
        <span class="small text-secondary"><?php echo count($verbali); ?> verbali<?php echo count($verbali) >= 300 ? ' (i primi 300: restringi la ricerca)' : ''; ?></span>
    </div>
    <p class="small text-secondary mb-3">Tutti i verbali in un posto solo: quelli scritti nel portale e quelli vecchi caricati in PDF. <?php echo $solo_ref ? 'Vedi i verbali dei tuoi consigli.' : "Chi gestisce un consiglio vede i verbali del proprio consiglio, l'Ufficio didattico tutti."; ?></p>

<?php if ($puo_didattica_tutto):
    $scan_a = \App\Core\App::per($conn)->get(\App\Didattica\ScansioneArchivio::class);
    $non_coll = $scan_a->nonCollegate();
    $ultima = $scan_a->ultimoEsito();
    $mappe_a = $scan_a->mappe();
    $proposte_a = \App\Core\App::per($conn)->get(\App\Didattica\ProposteCartelle::class)->proponi();
    $pausa_a = $scan_a->inPausa();
    $organi_tutti = $srv_organi->tutti();
    $tipi_org = $srv_organi->tipiDisponibili();
    // campo «Tipo»: scelta tra i tipi (predefiniti e già scritti a mano) oppure «Nuovo tipo…» con il nome da scrivere
    $campo_tipo = function (string $attuale) use ($tipi_org, $h): string {
        $o = '<select class="form-select form-select-sm" name="tipo" aria-label="Tipo di organo" onchange="this.nextElementSibling.style.display = this.value === \'@nuovo\' ? \'\' : \'none\';">';
        foreach ($tipi_org as $k_t => $n_t) $o .= '<option value="' . $h($k_t) . '"' . ($attuale === (string)$k_t ? ' selected' : '') . '>' . $h($n_t) . '</option>';
        return $o . '<option value="@nuovo">➕ Nuovo tipo…</option></select><input class="form-control form-control-sm mt-1" name="tipo_nuovo" maxlength="100" placeholder="Nome del nuovo tipo" style="display:none;">';
    };
    // un corso di studi ha più organi: il consiglio (la cartella principale del corso) e gli altri organi (di solito sottocartelle); si raggruppano per corso
    $senza_corso = '(senza corso di studi)';
    $gruppi_corso = [];
    foreach ($consigli as $c_n) { $gruppi_corso[(string)$c_n['nome']]['consigli'][] = $c_n; }
    foreach ($organi_tutti as $o_n) { $gruppi_corso[$o_n['corso'] !== '' ? (string)$o_n['corso'] : $senza_corso]['organi'][] = $o_n; }
    uksort($gruppi_corso, fn($a, $b) => $a === $senza_corso ? 1 : ($b === $senza_corso ? -1 : strnatcasecmp($a, $b)));
    $corsi_noti = array_values(array_filter(array_keys($gruppi_corso), fn($k) => $k !== $senza_corso));
    $e_ult = $ultima['esito'] ?? null;
?>
    <details class="card border-0 shadow-sm mb-3" id="organi"<?php echo !empty($_GET['organi']) || $non_coll || !$organi_tutti ? ' open' : ''; ?>>
        <summary class="card-header bg-white fw-bold small" style="cursor:pointer;"><i class="fa fa-folder-tree me-1 text-primary" aria-hidden="true"></i>Organi per corso di studi e cartelle sul NAS (lettura automatica dei PDF)<?php echo $non_coll ? ' <span class="badge text-bg-warning">' . count($non_coll) . ' cartelle da collegare</span>' : ''; ?></summary>
        <div class="card-body">
            <div class="alert alert-light border small">
                <strong>Come funziona.</strong> Ogni corso di studi ha più organi: il <strong>consiglio</strong> (i cui verbali stanno nella cartella principale del corso, divisi per anno) e gli altri organi — commissione, gruppo di riesame, welcome day… — che di solito stanno in <strong>sottocartelle</strong> della cartella del corso. Qui sotto ogni organo è elencato <strong>sotto il suo corso di studi</strong>.
                <ol class="mb-0 mt-1">
                    <li>Copia le cartelle sul NAS dentro <code><?php echo $h(\App\Didattica\Costanti::DIR_VERBALI); ?>archivio/</code> (per esempio <code>Biologia/2019/Verbale_08.pdf</code>, <code>Biologia/Commissione didattica/2020/…</code>).</li>
                    <li>Le cartelle che il portale non sa ancora a chi assegnare compaiono in <em>«Cartelle da collegare»</em>: dici a quale organo appartiene ciascuna e premi <strong>Collega</strong>.</li>
                    <li>Il portale legge i PDF da solo ogni ora (senza copiarli), o subito con «Scansiona ora». Se un PDF sta in una sottocartella con un organo suo, vale quello della sottocartella.</li>
                </ol>
            </div>
            <form method="POST" class="d-flex flex-wrap gap-2 align-items-center mb-3"><?php csrf_field(); ?>
                <button class="btn btn-sm btn-primary fw-bold" name="scansiona_archivio" value="1"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Scansiona ora</button>
                <button class="btn btn-sm btn-outline-secondary" name="pausa_scansione" value="<?php echo $pausa_a ? '0' : '1'; ?>" title="La scansione automatica legge i PDF nuovi ogni ora"><i class="fa fa-<?php echo $pausa_a ? 'play' : 'pause'; ?> me-1" aria-hidden="true"></i><?php echo $pausa_a ? 'Riattiva la scansione automatica' : 'Sospendi la scansione automatica'; ?></button>
                <?php if ($pausa_a): ?><span class="badge text-bg-warning">scansione automatica sospesa</span><?php endif; ?>
                <span class="small text-secondary"><?php echo $ultima ? 'Ultima scansione: ' . date('d/m/Y H:i', strtotime((string)$ultima['quando'])) . ' — ' . (int)$e_ult['trovati'] . ' PDF trovati, ' . (int)$e_ult['nuovi'] . ' nuovi, ' . (int)$e_ult['rimossi'] . ' tolti' . ((int)$e_ult['restanti'] ? ', ' . (int)$e_ult['restanti'] . ' ancora da leggere' : '') . '.' : 'Non è ancora stata fatta una scansione.'; ?></span>
            </form>
            <?php foreach (array_slice((array)($e_ult['errori'] ?? []), 0, 8) as $er): ?><div class="alert alert-danger small py-1 mb-1"><?php echo $h($er); ?></div><?php endforeach; ?>
            <?php if (!empty($e_ult['allegati'])): ?><div class="alert alert-info small py-2 mb-2"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><strong><?php echo array_sum($e_ult['allegati']); ?> allegati sciolti non importati</strong> (nome che comincia per «Allegato» fuori dalla cartella di un verbale, non agganciabili a un verbale): <?php echo $h(implode(' · ', array_map(fn($c, $n) => "$c ($n)", array_keys($e_ult['allegati']), $e_ult['allegati']))); ?>.</div><?php endif; ?>
            <?php if (!empty($e_ult['senza_verbale'])): ?><div class="alert alert-warning small py-2 mb-2"><i class="fa fa-folder-open me-1" aria-hidden="true"></i><strong><?php echo count($e_ult['senza_verbale']); ?> cartelle senza un verbale riconoscibile</strong> (contengono solo allegati): <?php echo $h(implode(' · ', array_slice($e_ult['senza_verbale'], 0, 6))); ?><?php echo count($e_ult['senza_verbale']) > 6 ? ' …' : ''; ?>. Il PDF del verbale dovrebbe avere lo stesso nome della cartella o cominciare per «Verbale».</div><?php endif; ?>
            <?php foreach (array_slice((array)($e_ult['avvisi'] ?? []), 0, 5) as $er): ?><div class="alert alert-warning small py-1 mb-1"><?php echo $h($er); ?></div><?php endforeach; ?>

            <?php if ($proposte_a): ?>
                <div class="border border-primary rounded p-3 mb-3">
                    <h3 class="h6 fw-bold"><i class="fa fa-wand-magic-sparkles me-1 text-primary" aria-hidden="true"></i>Collegamento automatico: il portale propone</h3>
                    <p class="small text-secondary mb-2">Dalla struttura delle cartelle (<em>Corso / Organo / anno</em>) il portale abbina il consiglio al consiglio già nel portale e crea gli altri organi dello stesso corso. Controlla la proposta: se è giusta, un solo clic.</p>
                    <div class="table-responsive"><table class="table table-sm small align-middle mb-2"><thead class="table-light"><tr><th>Cartella sul NAS</th><th>PDF</th><th>Diventa</th><th>Corso di studi</th></tr></thead><tbody>
                    <?php foreach ($proposte_a as $p_a): ?><tr><td><i class="fa fa-folder me-1" aria-hidden="true"></i><?php echo $h($p_a['cartella']); ?></td><td><?php echo (int)$p_a['n_pdf']; ?></td>
                        <td><?php echo $p_a['azione'] === 'consiglio' ? '<span class="badge text-bg-primary">Consiglio già nel portale</span> ' : '<span class="badge text-bg-success">Organo nuovo</span> '; ?><?php echo $h(mb_strimwidth($p_a['nome'], 0, 90, '…')); ?></td><td><?php echo $h(mb_strimwidth($p_a['corso'], 0, 70, '…')); ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                    <form method="POST" class="m-0"><?php csrf_field(); ?><button class="btn btn-primary fw-bold" name="collega_automatico" value="1" data-confirm="Collegare tutte le cartelle come proposto?"><i class="fa fa-link me-1" aria-hidden="true"></i>Collega tutto come proposto</button> <span class="small text-secondary ms-2">Non legge ancora i PDF: quello lo fai tu con «Scansiona ora».</span></form>
                </div>
            <?php endif; ?>

            <?php if ($non_coll): ?>
                <h3 class="h6 fw-bold mt-3"><i class="fa fa-circle-question me-1 text-warning" aria-hidden="true"></i>Cartelle da collegare <span class="fw-normal text-secondary">(trovate sul NAS, non ancora assegnate a un organo)</span></h3>
                <?php foreach ($non_coll as $cart_n => $n_pdf):
                    $padre = $cart_n !== '' ? \App\Didattica\ScansioneArchivio::trova($mappe_a, dirname($cart_n) === '.' ? '' : dirname($cart_n)) : null;
                    $corso_padre = '';
                    if ($padre) { $corso_padre = $padre['organo_id'] ? (string)($srv_organi->perId((int)$padre['organo_id'])['corso'] ?? '') : (string)$padre['nome']; }
                    $padre_da_collegare = $cart_n !== '' && !$padre && dirname($cart_n) !== '.' && isset($non_coll[dirname($cart_n)]);
                    $tipo_sug = $cart_n !== '' ? \App\Didattica\OrganiArchivio::indovinaTipo(basename($cart_n)) : 'altro';
                    $nome_sug = $cart_n === '' ? '' : \App\Didattica\OrganiArchivio::nomeSuggerito(basename($cart_n), $tipo_sug, $corso_padre);
                ?>
                    <form method="POST" class="row g-2 align-items-end border rounded p-2 mb-2 bg-light"><?php csrf_field(); ?>
                        <input type="hidden" name="cartella" value="<?php echo $h($cart_n); ?>">
                        <div class="col-lg-3"><div class="small fw-bold"><i class="fa fa-folder me-1" aria-hidden="true"></i><?php echo $cart_n === '' ? '(file sparsi nella cartella principale)' : $h($cart_n); ?></div>
                            <div class="small text-secondary"><?php echo (int)$n_pdf; ?> PDF<?php echo $padre ? ' · dentro la cartella di «' . $h(mb_strimwidth($padre['nome'], 0, 50, '…')) . '»' : ''; ?></div>
                            <?php if ($padre_da_collegare): ?><div class="small text-warning-emphasis"><i class="fa fa-lightbulb me-1" aria-hidden="true"></i>Collega prima la cartella principale «<?php echo $h(dirname($cart_n)); ?>» (il consiglio): poi qui il corso di studi si compila da solo.</div><?php endif; ?></div>
                        <?php if ($cart_n === ''): ?><div class="col small text-secondary">Metti i PDF dentro una cartella (per corso e organo): quelli sparsi nella cartella principale non si possono assegnare.</div>
                        <?php else: ?>
                        <div class="col-lg-3"><label class="form-label small mb-0">Di quale organo sono i verbali?</label>
                            <select class="form-select form-select-sm" name="scelta" required aria-label="Organo di <?php echo $h($cart_n); ?>">
                                <?php if (!$padre && !$padre_da_collegare): ?><option value="" selected disabled>— scegli —</option><?php endif; ?>
                                <option value="nuovo"<?php echo $padre || $padre_da_collegare ? ' selected' : ''; ?>>Un organo nuovo (compila a destra)</option>
                                <optgroup label="Consigli già nel portale (senza cartella)"><?php foreach ($consigli as $c_n): if ((string)($c_n['cartella_archivio'] ?? '') !== '') continue; ?><option value="c<?php echo (int)$c_n['id']; ?>">Consiglio: <?php echo $h(mb_strimwidth($c_n['nome'], 0, 70, '…')); ?></option><?php endforeach; ?></optgroup>
                                <optgroup label="Organi già creati (senza cartella)"><?php foreach ($organi_tutti as $o_n): if ($o_n['cartella'] !== '') continue; ?><option value="o<?php echo (int)$o_n['id']; ?>"><?php echo $h(mb_strimwidth($o_n['nome'], 0, 70, '…')); ?></option><?php endforeach; ?></optgroup>
                            </select></div>
                        <div class="col-lg-3"><label class="form-label small mb-0">Se organo nuovo: nome</label><input class="form-control form-control-sm" name="nome" maxlength="300" value="<?php echo $h($nome_sug); ?>">
                            <label class="form-label small mb-0 mt-1">del corso di studi</label><input class="form-control form-control-sm" name="corso" list="dlCorsi" maxlength="300" value="<?php echo $h($corso_padre); ?>" placeholder="a quale corso appartiene"></div>
                        <div class="col-lg-2"><label class="form-label small mb-0">Tipo</label><?php echo $campo_tipo($tipo_sug); ?></div>
                        <div class="col-lg-1"><button class="btn btn-sm btn-primary fw-bold w-100" name="collega_cartella" value="1">Collega</button></div>
                        <?php endif; ?>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>

            <datalist id="dlCorsi"><?php foreach ($corsi_noti as $c_k): ?><option value="<?php echo $h($c_k); ?>"><?php endforeach; ?></datalist>
            <h3 class="h6 fw-bold mt-3">Corsi di studi e i loro organi</h3>
            <?php if (!$gruppi_corso): ?><div class="small text-secondary">Nessun organo ancora: collega le cartelle qui sopra o aggiungi un organo qui sotto.</div><?php endif; ?>
            <?php foreach ($gruppi_corso as $corso_g => $g_c): ?>
                <div class="border rounded mb-3">
                    <div class="bg-light px-3 py-2 fw-bold small"><i class="fa fa-graduation-cap me-1 text-primary" aria-hidden="true"></i><?php echo $h($corso_g); ?>
                        <a class="fw-normal ms-2" href="<?php echo $h($url_arch); ?>&amp;corso=<?php echo urlencode($corso_g); ?>">vedi tutti i verbali del corso</a></div>
                    <div class="p-2">
                        <?php foreach ($g_c['consigli'] ?? [] as $c_n): ?>
                            <form method="POST" class="row g-2 align-items-center mb-1"><?php csrf_field(); ?>
                                <div class="col-lg-6 small"><span class="badge text-bg-primary me-1">Consiglio</span><?php echo $h(mb_strimwidth($c_n['nome'], 0, 120, '…')); ?> <span class="text-secondary">(verbali anche scritti nel portale)</span></div>
                                <div class="col-lg-5"><input class="form-control form-control-sm" name="cartella" maxlength="300" value="<?php echo $h($c_n['cartella_archivio'] ?? ''); ?>" placeholder="cartella sul NAS, per esempio Biologia" aria-label="Cartella del consiglio"></div>
                                <div class="col-lg-1"><button class="btn btn-sm btn-outline-primary w-100" name="cartella_consiglio" value="<?php echo (int)$c_n['id']; ?>">Salva</button></div>
                            </form>
                        <?php endforeach; ?>
                        <?php foreach ($g_c['organi'] ?? [] as $o_n): ?>
                            <form method="POST" class="row g-2 align-items-end mb-2"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$o_n['id']; ?>">
                                <div class="col-lg-3"><label class="form-label small mb-0">Nome</label><input class="form-control form-control-sm" name="nome" maxlength="300" required value="<?php echo $h($o_n['nome']); ?>"></div>
                                <div class="col-lg-2"><label class="form-label small mb-0">Tipo</label><?php echo $campo_tipo(\App\Didattica\OrganiArchivio::valoreScelta($o_n)); ?></div>
                                <div class="col-lg-2"><label class="form-label small mb-0">Corso di studi</label><input class="form-control form-control-sm" name="corso" list="dlCorsi" maxlength="300" value="<?php echo $h($o_n['corso']); ?>"></div>
                                <div class="col-lg-3"><label class="form-label small mb-0">Cartella sul NAS</label><input class="form-control form-control-sm" name="cartella" maxlength="300" value="<?php echo $h($o_n['cartella']); ?>" placeholder="Biologia/Commissione didattica"></div>
                                <div class="col-lg-2 d-flex gap-1">
                                    <button class="btn btn-sm btn-primary fw-bold flex-fill" name="salva_organo" value="1">Salva</button>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo $h($url_arch); ?>&amp;organo=<?php echo (int)$o_n['id']; ?>" title="Vedi i verbali di questo organo"><?php echo (int)$o_n['n_verbali']; ?></a>
                                    <button class="btn btn-sm btn-outline-danger" name="elimina_organo" value="<?php echo (int)$o_n['id']; ?>" data-confirm="Eliminare questo organo?" title="Elimina"><i class="fa fa-trash" aria-hidden="true"></i><span class="visually-hidden">Elimina</span></button>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <form method="POST" class="row g-2 align-items-end border rounded p-2 bg-light mt-2"><?php csrf_field(); ?><input type="hidden" name="id" value="0">
                <div class="col-12 small fw-bold">Aggiungi un organo a un corso di studi</div>
                <div class="col-lg-3"><label class="form-label small mb-0">Nome</label><input class="form-control form-control-sm" name="nome" maxlength="300" required placeholder="Commissione paritetica – Biologia"></div>
                <div class="col-lg-2"><label class="form-label small mb-0">Tipo</label><?php echo $campo_tipo('commissione'); ?></div>
                <div class="col-lg-2"><label class="form-label small mb-0">Corso di studi</label><input class="form-control form-control-sm" name="corso" list="dlCorsi" maxlength="300" placeholder="scegli o scrivi"></div>
                <div class="col-lg-3"><label class="form-label small mb-0">Cartella sul NAS</label><input class="form-control form-control-sm" name="cartella" maxlength="300" placeholder="Biologia/Commissione paritetica"></div>
                <div class="col-lg-2"><button class="btn btn-sm btn-primary fw-bold w-100" name="salva_organo" value="1">Aggiungi</button></div>
            </form>

            <form method="POST" class="row g-2 align-items-end border rounded p-2 bg-light mt-2"><?php csrf_field(); ?>
                <div class="col-12 small fw-bold">Crea in un colpo i tre organi di un corso di studi</div>
                <div class="col-lg-5"><label class="form-label small mb-0">Corso di studi</label><input class="form-control form-control-sm" name="corso" list="dlCorsi" maxlength="300" required placeholder="Corso di laurea in Biologia"></div>
                <div class="col-lg-4"><label class="form-label small mb-0">Cartella del corso sul NAS</label><input class="form-control form-control-sm" name="cartella_corso" maxlength="300" placeholder="Biologia (le tre cartelle ci vanno dentro)"></div>
                <div class="col-lg-3"><button class="btn btn-sm btn-outline-primary fw-bold w-100" name="crea_organi_standard" value="1">Crea i tre organi</button></div>
                <div class="col-12 small text-secondary">Commissione didattica, Gruppo di riesame – Assicurazione della Qualità e Riunione con gli studenti, con le cartelle «Corso/Commissione didattica», «Corso/Gruppo di riesame» e «Corso/Riunioni con gli studenti».</div>
            </form>
        </div>
    </details>
<?php endif; ?>

    <details class="card border-0 shadow-sm mb-3" id="importa"<?php echo !empty($_GET['importa']) || !$verbali ? ' open' : ''; ?>>
        <summary class="card-header bg-white fw-bold small" style="cursor:pointer;"><i class="fa fa-file-import me-1 text-primary" aria-hidden="true"></i>Aggiungi verbali vecchi (PDF)</summary>
        <form class="card-body" id="fImporta" onsubmit="return false;">
            <?php csrf_field(); ?>
            <div class="row g-2">
                <div class="col-md-7"><label class="form-label small fw-bold" for="mCons">Consiglio o organo dei verbali <?php echo $solo_ref ? '<span class="text-danger">*</span>' : ''; ?></label>
                    <select class="form-select" id="mCons" name="consiglio_id">
                        <?php if (!$solo_ref): ?><option value="0">— nessuno (scrivo l'organo qui sotto) —</option><?php endif; ?>
                        <?php foreach ($consigli_vis as $k => $c): ?><option value="<?php echo $k; ?>"<?php echo $filtri['consiglio'] === $k ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($c['nome'], 0, 150, '…')); ?></option><?php endforeach; ?>
                        <?php if ($organi_arch): ?><optgroup label="Altri organi (commissioni, riesame, studenti…)"><?php foreach ($organi_arch as $o_s): ?><option value="o<?php echo (int)$o_s['id']; ?>"<?php echo $filtri['organo_id'] === (int)$o_s['id'] ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($o_s['nome'], 0, 150, '…')); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                    </select></div>
                <div class="col-md-5"><label class="form-label small fw-bold" for="mAa">Anno accademico</label>
                    <select class="form-select" id="mAa" name="anno_accademico"><option value="">— lo ricavo da cartella, nome file o data —</option><?php foreach (anni_accademici_scelta() as $a): ?><option><?php echo $h($a); ?></option><?php endforeach; ?></select></div>
                <?php if (!$solo_ref): ?><div class="col-12" id="mOrgBox"><label class="form-label small fw-bold" for="mOrg">Organo (se non scegli un consiglio)</label><input class="form-control" id="mOrg" name="organo" maxlength="500" placeholder="Consiglio del Corso di Laurea in …"></div><?php endif; ?>
                <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                    <label class="btn btn-outline-primary btn-sm fw-bold mb-0"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Scegli uno o più file<input type="file" id="mFile" accept=".pdf,application/pdf" multiple hidden></label>
                    <label class="btn btn-outline-primary btn-sm fw-bold mb-0"><i class="fa fa-folder-open me-1" aria-hidden="true"></i>Scegli una cartella<input type="file" id="mCartella" webkitdirectory multiple hidden></label>
                    <span class="small text-secondary" id="mConta">Nessun file scelto.</span>
                </div>
            </div>
            <p class="small text-secondary mt-2 mb-2">Si caricano a gruppi, anche tanti insieme. Data, numero e anno accademico si ricavano dal <strong>nome del file</strong> e dalla <strong>cartella</strong> (per esempio <code>2024-2025/Verbale_08_2025-01-20.pdf</code>) o, se mancano, dal testo del PDF; quello che non si riesce a leggere si completa dopo. Un file già presente non si carica due volte. Solo PDF, fino a 30 MB ciascuno.</p>
            <button type="button" id="mAvvia" class="btn btn-primary fw-bold" disabled><i class="fa fa-upload me-1" aria-hidden="true"></i>Importa</button>
            <div class="progress mt-3" style="height:8px;display:none;" id="mBarra"><div class="progress-bar" style="width:0%"></div></div>
            <div id="mEsito" class="small mt-3" aria-live="polite"></div>
        </form>
    </details>

    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="sedute"><input type="hidden" name="archivio" value="1">
        <div style="min-width:240px;flex:1;"><label class="form-label small fw-bold mb-0" for="fQ">Cerca</label><input class="form-control form-control-sm" id="fQ" name="q" value="<?php echo $h($filtri['q']); ?>" placeholder="una parola nel verbale, il numero…"></div>
        <div><label class="form-label small fw-bold mb-0" for="fC">Consiglio</label><select class="form-select form-select-sm" id="fC" name="cons" style="max-width:260px;"><option value="0">Tutti</option><?php foreach ($consigli_vis as $k => $c): ?><option value="<?php echo $k; ?>"<?php echo $filtri['consiglio'] === $k ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($c['nome'], 0, 60, '…')); ?></option><?php endforeach; ?></select></div>
        <?php if ($organi_arch): ?><div><label class="form-label small fw-bold mb-0" for="fK">Corso di studi</label><select class="form-select form-select-sm" id="fK" name="corso" style="max-width:260px;"><option value="">Tutti</option><?php foreach ($corsi_filtro as $c_f): ?><option<?php echo $filtri['corso'] === $c_f ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($c_f, 0, 70, '…')); ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <?php if ($organi_arch): ?><div><label class="form-label small fw-bold mb-0" for="fO">Altro organo</label><select class="form-select form-select-sm" id="fO" name="organo" style="max-width:260px;"><option value="0">Nessuno</option><?php foreach ($organi_arch as $o_s): ?><option value="<?php echo (int)$o_s['id']; ?>"<?php echo $filtri['organo_id'] === (int)$o_s['id'] ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($o_s['nome'], 0, 60, '…')); ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div><label class="form-label small fw-bold mb-0" for="fA">Anno accademico</label><select class="form-select form-select-sm" id="fA" name="aa"><option value="">Tutti</option><?php foreach ($anni as $a): ?><option<?php echo $filtri['anno_accademico'] === $a ? ' selected' : ''; ?>><?php echo $h($a); ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label small fw-bold mb-0" for="fT">Mostra</label><select class="form-select form-select-sm" id="fT" name="tipo"><?php foreach (['' => 'Tutti', 'portale' => 'Scritti nel portale', 'importato' => 'Importati (PDF)', 'firmato' => 'Firmati', 'incompleto' => 'Da completare'] as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo $filtri['tipo'] === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-sm btn-primary fw-bold" type="submit"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Filtra</button>
        <?php if (array_filter($filtri)): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $h($url_arch); ?>">Azzera</a><?php endif; ?>
    </form>
    <?php if ($n_incompleti): ?><div class="alert alert-warning small py-2"><i class="fa fa-circle-exclamation me-1" aria-hidden="true"></i><?php echo $n_incompleti; ?> verbali senza data o senza organo: aprili con la matita e completa i dati.</div><?php endif; ?>

    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle small mb-0">
            <thead class="table-light"><tr><th style="width:105px;">Data</th><th>Organo</th><th style="width:60px;">N.</th><th style="width:105px;">A.a.</th><th style="width:200px;">Tipo</th><th style="width:160px;"></th></tr></thead>
            <tbody>
            <?php foreach ($verbali as $x): $imp_x = $x['origine'] === 'importato'; $inc = !$x['data'] || $x['organo'] === ''; $vs_x = STATI_VERBALE[$x['verbale_stato'] ?? ''] ?? null; ?>
                <tr<?php echo $inc ? ' class="table-warning"' : ''; ?>>
                    <td class="fw-bold"><?php echo $x['data'] ? date('d/m/Y', strtotime($x['data'])) : '<span class="text-danger">senza data</span>'; ?></td>
                    <td><?php echo $x['organo'] !== '' ? $h(mb_strimwidth($x['organo'], 0, 130, '…')) : '<span class="text-danger">senza organo</span>'; ?><?php echo $x['corso'] !== '' && $x['corso'] !== $x['organo'] ? '<div class="text-secondary" style="font-size:.72rem;"><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i>' . $h(mb_strimwidth($x['corso'], 0, 100, '…')) . '</div>' : ''; ?>
                        <?php $all_x = (string)($x['allegati_json'] ?? '') !== '' ? (json_decode((string)$x['allegati_json'], true) ?: []) : []; if ($all_x): ?>
                            <details class="mt-1" style="font-size:.78rem;"><summary class="text-primary" style="cursor:pointer;"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo count($all_x); ?> allegat<?php echo count($all_x) === 1 ? 'o' : 'i'; ?></summary>
                                <ul class="mb-1 ps-3"><?php foreach ($all_x as $i_a => $a_x): ?><li><a href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$x['id']; ?>&amp;esporta=allegato_verbale&amp;n=<?php echo (int)$i_a; ?>"><?php echo $h($a_x['n']); ?></a> <span class="text-secondary">(<?php echo $a_x['d'] >= 1048576 ? number_format($a_x['d'] / 1048576, 1, ',', '') . ' MB' : max(1, round($a_x['d'] / 1024)) . ' KB'; ?>)</span></li><?php endforeach; ?></ul></details>
                        <?php endif; ?><?php echo $imp_x && $x['nome_originale'] ? '<div class="text-secondary" style="font-size:.72rem;">' . $h($x['nome_originale']) . '</div>' : ''; ?></td>
                    <td><?php echo $h($x['numero_verbale']); ?></td>
                    <td><?php echo $h($x['anno_accademico']); ?></td>
                    <td><?php if ($imp_x): ?><span class="badge text-bg-light border"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>importato</span><?php if ((int)$x['da_cartella']): ?> <span class="badge text-bg-info" title="Letto dalla cartella dell'archivio sul NAS"><i class="fa fa-hard-drive me-1" aria-hidden="true"></i>NAS</span><?php endif; ?>
                        <?php else: ?><span class="badge text-bg-light border"><i class="fa fa-gavel me-1" aria-hidden="true"></i>portale</span> <?php if ($vs_x && $x['verbale_stato'] !== ''): ?><span class="badge" style="background:<?php echo $vs_x[1]; ?>;"><?php echo $h($x['verbale_stato'] === 'firmato' ? 'firmato' : 'in firma'); ?></span><?php endif; ?><?php endif; ?>
                        <?php if ((int)$x['pubblicabile']): ?><span class="badge text-bg-success" title="Visibile nella pagina pubblica con password"><i class="fa fa-earth-europe me-1" aria-hidden="true"></i>pubblico</span><?php endif; ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($x['verbale_pdf']): ?><a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$x['id']; ?>&amp;esporta=verbale_pdf" title="Scarica il PDF"><i class="fa fa-download" aria-hidden="true"></i><span class="visually-hidden">Scarica il PDF</span></a><?php endif; ?>
                        <?php if ($puo_didattica_tutto && ($x['verbale_stato'] === 'firmato' || $imp_x) && $x['verbale_pdf']): ?><a class="btn btn-sm btn-outline-<?php echo (int)$x['pubblicabile'] ? 'success' : 'secondary'; ?> py-0" href="<?php echo $h($url_arch); ?>&amp;pub=<?php echo (int)$x['id']; ?>" title="Pagina pubblica dei verbali"><i class="fa fa-earth-europe" aria-hidden="true"></i><span class="visually-hidden">Pagina pubblica dei verbali</span></a><?php endif; ?>
                        <?php if ($imp_x): ?>
                            <a class="btn btn-sm btn-outline-secondary py-0" href="<?php echo $h($url_arch); ?>&amp;imp=<?php echo (int)$x['id']; ?>" title="Modifica i dati"><i class="fa fa-pen" aria-hidden="true"></i><span class="visually-hidden">Modifica i dati</span></a>
                            <?php if (!(int)$x['da_cartella']): ?><form method="POST" class="d-inline"><?php csrf_field(); ?><button type="submit" name="elimina_verbale_importato" value="<?php echo (int)$x['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Togliere questo verbale dall'archivio? Il PDF viene cancellato." title="Elimina"><i class="fa fa-trash" aria-hidden="true"></i><span class="visually-hidden">Elimina</span></button></form><?php endif; ?>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline-secondary py-0" href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$x['id']; ?>" title="Apri la seduta"><i class="fa fa-folder-open" aria-hidden="true"></i><span class="visually-hidden">Apri la seduta</span></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$verbali): ?><tr><td colspan="6" class="text-center text-secondary py-4"><i class="fa fa-box-archive fa-2x d-block mb-2" aria-hidden="true"></i>Nessun verbale<?php echo array_filter($filtri) ? ' con questi filtri' : ' in archivio: aggiungi i PDF vecchi qui sopra'; ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>

    <script>
    (function () {
        var f = document.getElementById('fImporta'); if (!f) return;
        var URL_ARCH = <?php echo json_encode($url_arch); ?>;
        var inFile = document.getElementById('mFile'), inCart = document.getElementById('mCartella'), conta = document.getElementById('mConta'), avvia = document.getElementById('mAvvia');
        var barra = document.getElementById('mBarra'), esito = document.getElementById('mEsito'), scelti = [];
        var LIMITE_FILE = 8, LIMITE_BYTE = 16 * 1024 * 1024;
        function raccogli() {
            scelti = [];
            [inFile, inCart].forEach(function (inp) {
                Array.prototype.forEach.call(inp.files || [], function (fl) { if (/\.pdf$/i.test(fl.name)) scelti.push({ f: fl, p: fl.webkitRelativePath || fl.name }); });
            });
            var altri = (inFile.files.length + inCart.files.length) - scelti.length;
            conta.textContent = scelti.length ? scelti.length + ' PDF scelti' + (altri ? ' (' + altri + ' file non PDF ignorati)' : '') + '.' : (altri ? 'Nessun PDF tra i file scelti.' : 'Nessun file scelto.');
            avvia.disabled = !scelti.length;
        }
        inFile.addEventListener('change', raccogli); inCart.addEventListener('change', raccogli);
        function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
        avvia.addEventListener('click', function () {
            var cons = document.getElementById('mCons').value;
            if (!scelti.length) return;
            if (cons === '0' && !(document.getElementById('mOrg') && document.getElementById('mOrg').value.trim())) { esito.innerHTML = '<div class="text-danger">Scegli il consiglio oppure scrivi l\'organo.</div>'; return; }
            // gruppi: pochi file e pochi MB per richiesta (il server limita file e dimensione di ogni invio)
            var gruppi = [], g = [], byte = 0;
            scelti.forEach(function (s) {
                if (g.length && (g.length >= LIMITE_FILE || byte + s.f.size > LIMITE_BYTE)) { gruppi.push(g); g = []; byte = 0; }
                g.push(s); byte += s.f.size;
            });
            if (g.length) gruppi.push(g);
            avvia.disabled = true; barra.style.display = ''; esito.innerHTML = '';
            var fatti = 0, ok = 0, doppi = 0, errori = 0, daCompletare = 0, righe = [];
            function stato() { barra.firstElementChild.style.width = Math.round(fatti * 100 / scelti.length) + '%'; }
            function riga(nome, cls, testo, id, completa) { righe.push('<tr class="' + cls + '"><td>' + esc(nome) + '</td><td>' + esc(testo) + '</td><td class="text-end">' + (completa ? '<a href="' + esc(URL_ARCH) + '&imp=' + id + '">completa i dati</a>' : '') + '</td></tr>'); }
            function fine() {
                barra.style.display = 'none'; avvia.disabled = false; stato();
                esito.innerHTML = '<div class="fw-bold mb-1">' + ok + ' importati' + (doppi ? ', ' + doppi + ' già presenti' : '') + (errori ? ', ' + errori + ' con errore' : '') + (daCompletare ? ' · ' + daCompletare + ' da completare' : '') + '.</div>'
                    + '<div class="table-responsive" style="max-height:300px;overflow:auto;"><table class="table table-sm small mb-2">' + righe.join('') + '</table></div>'
                    + (ok ? '<a class="btn btn-sm btn-primary fw-bold" href="' + esc(URL_ARCH) + (daCompletare ? '&tipo=incompleto' : '') + '">Vai all\'archivio</a>' : '');
            }
            function prossimo(i) {
                if (i >= gruppi.length) return fine();
                var fd = new FormData();
                fd.append('csrf_token', f.querySelector('[name=csrf_token]').value); fd.append('importa_verbali', '1');
                fd.append('consiglio_id', cons); fd.append('anno_accademico', document.getElementById('mAa').value);
                if (document.getElementById('mOrg')) fd.append('organo', document.getElementById('mOrg').value);
                gruppi[i].forEach(function (s) { fd.append('file[]', s.f, s.f.name); fd.append('percorso[]', s.p); });
                fetch(location.href.split('#')[0], { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { throw new Error('risposta non valida del server (file troppo grandi?)'); } }); })
                    .then(function (j) {
                        if (j.errore) throw new Error(j.errore);
                        j.risultati.forEach(function (r) {
                            if (r.esito === 'ok') { ok++; if (!r.completo) daCompletare++; riga(r.nome, r.completo ? '' : 'table-warning', r.completo ? r.messaggio : r.messaggio + ' · manca data o organo', r.id, !r.completo); }
                            else if (r.esito === 'doppio') { doppi++; riga(r.nome, 'text-secondary', r.messaggio, 0, false); }
                            else { errori++; riga(r.nome, 'table-danger', r.messaggio, 0, false); }
                        });
                    })
                    .catch(function (e) { gruppi[i].forEach(function (s) { errori++; riga(s.f.name, 'table-danger', e.message, 0, false); }); })
                    .then(function () { fatti += gruppi[i].length; stato(); prossimo(i + 1); });
            }
            prossimo(0);
        });
    })();
    </script>
<?php endif; ?>
