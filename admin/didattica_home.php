<?php
// didattica_home.php - «Cosa devo fare»: la prima schermata della Didattica. Mostra solo i numeri di quello che aspetta chi lavora
// in ufficio (pratiche, sedute, verbali) con un pulsante per ognuno, e sotto i collegamenti alle altre sezioni (Tirocini compresi).
// Scheda del pannello Didattica: si apre da didattica.php (o ?tab=home), mai direttamente. Non ha azioni proprie.
if (!defined('DIDATTICA_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

if ($fase === 'azioni') {

    return;
}

$repo_h = \App\Core\App::per($conn)->get(\App\Didattica\PraticaRepository::class);
$uffici_h = uffici_didattica($conn);
$tipo_h = fn(array $x) => !empty($x['ufficio_id']) ? (string)($uffici_h[(int)$x['ufficio_id']]['tipo'] ?? '') : '';
$fasi_h = \App\Didattica\FasePratica::conta($repo_h->elenco('aperte', 0, '', '', '', '', '', $io_operatore), $tipo_h);
$n = fn(string ...$k) => array_sum(array_map(fn($x) => (int)($fasi_h[$x] ?? 0), $k));
// Verbali e sedute
$sedute_ref_h = $solo_ref ? $consigli_referente : null;
$n_verbali_h = \App\Core\App::get(\App\Portale\CoseDaFare::class)->verbaliInFirma($sedute_ref_h);
$consigli_h = array_filter($consigli, fn($c) => $puo_consiglio((int)$c['id']));
$senza_esito_h = array_sum(array_intersect_key(\App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->praticheSenzaEsitoPerConsiglio(), $consigli_h));
$n_senza_data_h = count(array_filter($sedute, fn($x) => !$x['data']));
$n_senza_comp_h = count(array_filter($consigli_h, fn($c, $k) => !persone_consiglio($conn, (int)$k), ARRAY_FILTER_USE_BOTH));
$url = fn(string $t, string $extra = '') => $base . '&tab=' . $t . $extra;

$compiti_h = [];
if (!$solo_ref) {
    $compiti_h[] = ['numero' => $n('protocollare', 'prendere', 'lavorazione'), 'titolo' => 'Pratiche da lavorare', 'testo' => 'Da protocollare, prendere in carico o istruire.', 'href' => $url('pratiche'), 'bottone' => 'Apri le pratiche', 'tono' => 'tuo', 'vuoto' => 'Nessuna pratica da lavorare.'];
    $compiti_h[] = ['numero' => $n('smistare'), 'titolo' => 'Da assegnare', 'testo' => 'Nessuna persona dell\'ufficio le segue ancora.', 'href' => $url('pratiche', '&fase=smistare'), 'bottone' => 'Assegna', 'tono' => 'tuo', 'vuoto' => 'Tutte le pratiche hanno una persona.'];
}
$compiti_h[] = ['numero' => $n_verbali_h, 'titolo' => 'Verbali da firmare', 'testo' => 'Dal segretario o dal coordinatore del consiglio.', 'href' => $url('sedute') . '#sedute-elenco', 'bottone' => 'Apri le sedute', 'tono' => 'tuo', 'vuoto' => 'Nessun verbale da firmare.'];
$compiti_h[] = ['numero' => $senza_esito_h + ($solo_ref ? 0 : $n('consiglio', 'decisa')), 'titolo' => 'Da decidere in seduta', 'testo' => 'Pratiche portate in seduta, senza decisione o senza esito comunicato.', 'href' => $url('sedute') . '#sedute-elenco', 'bottone' => 'Apri le sedute', 'tono' => 'tuo', 'vuoto' => 'Nessuna pratica da decidere.'];
if (!$solo_ref) {
    $compiti_h[] = ['numero' => $n('firma', 'integrazione', 'segreteria'), 'titolo' => 'In attesa di altri', 'testo' => 'Firme, integrazioni dello studente o segreteria: non devi fare altro.', 'href' => $url('pratiche'), 'bottone' => 'Vedi', 'tono' => 'attesa', 'vuoto' => 'Nessuna pratica in attesa.'];
}
echo pn_compiti($compiti_h, 'Cosa devo fare nella Didattica');
if ($n_senza_data_h || $n_senza_comp_h): ?>
<div class="alert alert-warning py-2 small"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>
    <?php echo $n_senza_data_h ? '<strong>' . $n_senza_data_h . ($n_senza_data_h === 1 ? ' seduta senza data</strong>' : ' sedute senza data</strong>') . ' (da fissare)' : ''; ?>
    <?php echo $n_senza_data_h && $n_senza_comp_h ? ' · ' : ''; ?>
    <?php echo $n_senza_comp_h ? '<strong>' . $n_senza_comp_h . ($n_senza_comp_h === 1 ? ' consiglio senza componenti</strong>' : ' consigli senza componenti</strong>') . ' (servono per le presenze)' : ''; ?>
    · <a class="fw-bold" href="<?php echo $h($url('sedute')); ?>">Apri «Sedute e verbali»</a></div>
<?php endif; ?>

<form method="GET" action="didattica.php" class="pn-linea">
    <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="pratiche"><input type="hidden" name="stato" value="tutte">
    <?php if (!$solo_ref): ?>
    <input type="search" name="q" class="form-control" style="max-width:420px;" placeholder="Cerca una pratica per nome, matricola o codice" aria-label="Cerca una pratica">
    <button class="btn btn-outline-secondary fw-bold" type="submit"><i class="fa fa-search me-1" aria-hidden="true"></i>Cerca</button>
    <span class="pn-spazio small text-secondary"><?php echo (int)$n_aperte; ?> pratiche aperte in tutto</span>
    <?php endif; ?>
</form>

<?php
// Le altre sezioni: le stesse voci del menu a sinistra, con una riga che dice a cosa servono (Tirocini compresi)
$sezioni_h = [];
if ($puo_didattica_tutto) {
    $sezioni_h[] = ['fa-inbox', 'Pratiche degli studenti', 'L\'elenco di tutte le pratiche, con filtri e fasi.', $url('pratiche')];
}
$sezioni_h[] = ['fa-gavel', $puo_didattica_tutto ? 'Sedute e verbali' : 'Sedute del consiglio', 'Consigli, presenze, decisioni e verbali da firmare.', $url('sedute')];
if ($puo_didattica_tutto) {
    $sezioni_h[] = ['fa-file-lines', 'Moduli e documenti', 'Modulistica da scaricare e moduli online.', $url('moduli')];
    $sezioni_h[] = ['fa-user-clock', 'Ricevimento', 'Chi riceve gli studenti e quando.', $url('ufficio')];
    $sezioni_h[] = ['fa-briefcase', 'Tirocini', 'Aziende convenzionate e laboratori interni per i tirocini.', 'tirocini.php?p_id=' . (int)$filtro_p];
    $sezioni_h[] = ['fa-people-arrows', 'Sostituzioni', 'Chi sostituisce chi quando un collega è assente.', 'sostituzioni.php?p_id=' . (int)$filtro_p];
    $sezioni_h[] = ['fa-chart-column', 'Statistiche', 'Quante pratiche, di che tipo e in quanto tempo.', $url('statistiche')];
}
if ($puo_conv_tesi) $sezioni_h[] = ['fa-handshake', 'Convenzioni di tesi', 'Enti e aziende che accolgono studenti per la tesi.', 'convenzioni_tesi.php?p_id=' . (int)$filtro_p];
if ($puo_tutorato) $sezioni_h[] = ['fa-user-graduate', 'Tutorato · lettere di incarico', 'Bandi, lettere, firme e pagamenti dei tutor.', 'tutorato.php?p_id=' . (int)$filtro_p];
?>
<h2 class="h6 fw-bold text-uppercase text-secondary mt-4 mb-2" style="letter-spacing:.05em;">Tutte le sezioni</h2>
<div class="pn-sezioni">
    <?php foreach ($sezioni_h as [$ico_s, $tit_s, $txt_s, $href_s]): ?>
        <a class="pn-sezione" href="<?php echo $h($href_s); ?>"><i class="fa <?php echo $h($ico_s); ?>" aria-hidden="true"></i><span><strong><?php echo $h($tit_s); ?></strong><small><?php echo $h($txt_s); ?></small></span></a>
    <?php endforeach; ?>
</div>
