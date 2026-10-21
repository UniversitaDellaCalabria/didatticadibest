<?php
// =========================================================================
// Schermate per la «Mappa del portale» (documentazione/mappa_portale.html): documentazione/schermate/map_*.png.
//   Prima: bash strumenti/locale/avvia.sh --nuovo, php strumenti/locale/scenario_demo.php e il menu del sito (Organizzazione proposta).
//   Poi:   php -d extension=gd strumenti/locale/schermate_mappa.php [prefisso]     (con un prefisso, per esempio «map_adm_», rifà solo quelle)
// Usa Chrome in modalità headless (una schermata per volta: l'accesso di prova è dentro lo stesso indirizzo) e GD per ritagliare.
// =========================================================================
$radice = realpath(__DIR__ . '/../..');
$S = json_decode(file_get_contents("$radice/cache/scenario_demo.json"), true);
$CHROME = getenv('CHROME') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
$BASE = 'http://127.0.0.1:8080';
$USCITA = "$radice/documentazione/schermate";
$prefisso = $argv[1] ?? '';

$pub = fn(string $n, int $u, string $url, int $alt = 800, bool $testata = false) => ['n' => $n, 'u' => $u, 'url' => "/eventi/$url", 'y' => $testata ? 0 : 175, 'alt' => $alt];
$pan = fn(string $n, int $u, string $url, int $alt = 760) => ['n' => $n, 'u' => $u, 'url' => "/eventi/$url", 'y' => 0, 'alt' => $alt];

$elenco = [
    $pub('map_pub_home.png', 0, 'index.php', 1000, true),
    $pub('map_pub_agenda.png', 5, 'agenda.php', 900),
    $pub('map_pub_orientamento.png', 5, 'orientamento.php', 900),
    $pub('map_pub_area.png', 5, 'openlab.php', 900),
    $pub('map_pub_fsl.png', 5, 'fsl.php', 900),
    $pub('map_pub_evento.png', 5, 'openlab.php?evento=11', 900),
    $pub('map_pub_avvisi.png', 5, 'avvisi.php', 760),
    $pub('map_pub_ricerca.png', 5, 'ricerca.php?q=genetica', 700),
    $pub('map_pub_guida.png', 5, 'guida.php', 900),
    $pub('map_pub_verifica.png', 5, 'verifica_attestato.php', 560),
    $pub('map_pub_ricevimento.png', 6, 'ricevimento.php', 800),
    $pub('map_pub_risorse.png', 5, 'aule-laboratori.php', 900),
    $pub('map_pub_programma_fsl.png', 6, 'programma_fsl.php', 800),
    $pub('map_pub_registro_tutorato.png', 5, 'registro_tutorato.php', 760),
    $pub('map_area_studente.png', 5, 'area_personale.php', 900),
    $pub('map_area_docente.png', 6, 'area_personale.php', 1100),
    $pub('map_area_direttore.png', 7, 'area_personale.php', 900),
    $pub('map_profilo.png', 5, 'profilo.php', 760),
    $pan('map_adm_inizio.png', 1, 'admin/inizio.php'),
    $pan('map_adm_aree.png', 1, 'admin/aree.php'),
    $pan('map_adm_dashboard.png', 1, 'admin/dashboard.php?p_id=1', 900),
    $pan('map_adm_eventi.png', 1, 'admin/eventi.php?p_id=1', 800),
    $pan('map_adm_progetti.png', 1, 'admin/progetti.php?p_id=2', 800),
    $pan('map_adm_form.png', 1, 'admin/form_builder.php?p_id=1', 800),
    $pan('map_adm_iscritti.png', 1, 'admin/iscritti.php?p_id=1', 800),
    $pan('map_adm_sondaggi.png', 1, 'admin/sondaggi.php?p_id=1', 760),
    $pan('map_adm_statistiche.png', 1, 'admin/statistiche.php?p_id=1', 900),
    $pan('map_adm_impostazioni_area.png', 1, 'admin/impostazioni_area.php?p_id=1', 900),
    $pan('map_adm_archivio.png', 1, 'admin/archivio.php?p_id=1', 700),
    $pan('map_adm_report_ambiti.png', 1, 'admin/report_ambiti.php', 900),
    $pan('map_adm_fsl_home.png', 1, 'admin/fsl.php', 760),
    $pan('map_adm_fsl_scuola.png', 1, 'admin/fsl.php?tab=scuola&k=CSTF00003C', 1000),
    $pan('map_adm_fsl_registro.png', 1, 'admin/fsl.php?tab=convenzioni', 900),
    $pan('map_adm_fsl_riepilogo.png', 1, 'admin/fsl.php?tab=riepilogo', 900),
    $pan('map_adm_fsl_report.png', 1, 'admin/fsl.php?tab=report', 900),
    $pan('map_adm_fsl_stipulare.png', 1, 'admin/fsl.php?tab=stipulare', 900),
    $pan('map_adm_risorse.png', 1, 'admin/risorse.php?p_id=20', 900),
    $pan('map_adm_did_home.png', 1, 'admin/didattica.php', 900),
    $pan('map_adm_portale_home.png', 1, 'admin/inizio.php?sezione=portale', 900),
    $pan('map_adm_did_pratiche.png', 1, 'admin/didattica.php?tab=pratiche', 800),
    $pan('map_adm_did_pratica.png', 1, 'admin/didattica.php?tab=pratiche&id=4', 1100),
    $pan('map_adm_did_seduta.png', 1, 'admin/didattica.php?tab=sedute&id=1', 1000),
    $pan('map_adm_did_sedute.png', 1, 'admin/didattica.php?tab=sedute', 800),
    $pan('map_adm_did_moduli.png', 1, 'admin/didattica.php?tab=moduli', 900),
    $pan('map_adm_did_ufficio.png', 1, 'admin/didattica.php?tab=ufficio', 900),
    $pan('map_adm_did_statistiche.png', 1, 'admin/didattica.php?tab=statistiche', 900),
    $pan('map_adm_tutorato.png', 1, 'admin/tutorato.php', 800),
    $pan('map_adm_scuole.png', 1, 'admin/scuole.php', 800),
    $pan('map_adm_anagrafe_docenti.png', 1, 'admin/anagrafe_docenti.php', 800),
    $pan('map_adm_anagrafe_strutture.png', 1, 'admin/anagrafe_personale.php?vista=strutture', 800),
    $pan('map_adm_testata.png', 1, 'admin/testata.php', 1000),
    $pan('map_adm_menu.png', 1, 'admin/menu.php', 1000),
    $pan('map_adm_utenti.png', 1, 'admin/utenti.php', 800),
    $pan('map_adm_uffici.png', 1, 'admin/uffici.php?u=6', 900),
    $pan('map_adm_sistema.png', 1, 'admin/sistema.php', 900),
    $pan('map_adm_audit.png', 1, 'admin/audit_log.php', 760),
    $pan('map_adm_messaggi.png', 1, 'admin/messaggi.php?p_id=1', 700),
    $pan('map_adm_nuova_area.png', 1, 'admin/nuova_area.php', 900),
    $pub('map_dir_cruscotto.png', 7, 'cruscotto_direttore.php', 900),
    $pan('map_adm_uffici_direzione.png', 1, 'admin/uffici.php?u=direzione', 900),
    $pan('map_adm_uffici_ateneo.png', 1, 'admin/uffici.php?a=ateneo', 760),
    $pub('map_dir_assente.png', 7, 'direttore_assente.php', 520),
    $pan('map_adm_did_accessi.png', 1, 'admin/didattica.php?tab=sedute&accessi=1', 1000),
    $pan('map_adm_did_archivio.png', 1, 'admin/didattica.php?tab=sedute&archivio=1', 900),
    $pan('map_adm_fsl_iter.png', 1, 'admin/fsl.php?tab=iter', 1000),
    $pan('map_adm_convenzioni_tesi.png', 1, 'admin/convenzioni_tesi.php', 900),
    $pan('map_adm_sistema_promemoria.png', 1, 'admin/sistema.php', 1300),
    $pub('map_pub_verbali.png', 1, 'verbali.php', 900),
    $pub('map_pub_verbali_cerca.png', 1, 'verbali.php#cerca', 900),
    $pub('map_pub_tirocini_accesso.png', 0, 'tirocini.php', 560),
    $pub('map_pub_tirocini.png', 5, 'tirocini.php?regione=Calabria&provincia=CS', 900),
    $pub('map_pub_tirocini_lab.png', 5, 'tirocini.php?tipo=interni', 900),
    $pan('map_adm_tirocini.png', 1, 'admin/tirocini.php', 900),
    $pan('map_adm_tirocini_lab.png', 1, 'admin/tirocini.php?tab=interni', 800),
    $pub('map_pub_modulistica.png', 5, 'modulistica.php', 900),
    $pub('map_pub_pratiche.png', 5, 'pratiche.php', 800),
    $pub('map_pub_guida_studente.png', 5, 'guida.php?r=studente', 900),
    $pub('map_pub_convenzione_tesi_richiesta.png', 0, 'richiedi_convenzione_tesi.php', 800),
    $pub('map_risorse_cruscotto.png', 10, 'risorse_cruscotto.php', 900),
    $pub('map_risorse_responsabile.png', 10, 'risorse_responsabile.php', 800),
];

$profilo = sys_get_temp_dir() . '/schermate_mappa_' . getmypid();
foreach ($elenco as $v) {
    if ($prefisso !== '' && !str_starts_with($v['n'], $prefisso)) continue;
    $tmp = sys_get_temp_dir() . '/sm_' . getmypid() . '.png';
    @unlink($tmp);
    $h = $v['y'] + $v['alt'] + 130; // oltre il ritaglio: il banner dei cookie sta in fondo alla finestra
    $url = $v['u'] ? "$BASE/__accesso?u={$v['u']}&vai=" . urlencode($v['url']) : $BASE . $v['url']; // utente 0 = senza accesso
    $cmd = sprintf('"%s" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=1 --lang=it-IT --user-data-dir=%s --window-size=1280,%d --virtual-time-budget=5000 --screenshot=%s %s 2>&1',
        $CHROME, "\"$profilo\"", $h, "\"$tmp\"", "\"$url\""); // senza escapeshellarg: su Windows toglie i «%» dell'indirizzo
    exec($cmd);
    if (!is_file($tmp)) { echo "MANCA {$v['n']}\n"; continue; }
    $im = imagecreatefrompng($tmp);
    $w = imagesx($im); $hh = imagesy($im);
    $out = imagecrop($im, ['x' => 0, 'y' => min($v['y'], $hh - 1), 'width' => $w, 'height' => max(1, min($v['alt'], $hh - $v['y']))]);
    imagepng($out, "$USCITA/{$v['n']}");
    echo "ok {$v['n']}\n";
}
