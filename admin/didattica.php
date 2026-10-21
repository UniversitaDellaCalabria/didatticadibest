<?php
// didattica.php - Modulo Didattica (Ufficio didattico):
// - Pratiche: elenco con filtri, dettaglio, stato, messaggi, istruttoria (campi dell'ufficio, seduta, delibera), Excel e Word;
// - Sedute e verbali: sedute del Consiglio con o.d.g. e presenze, pratiche assegnate, verbale in Word ed Excel;
// - Moduli e documenti: documenti da scaricare e moduli online (campi guidati dalle anagrafi, tabelle, campi dell'ufficio,
//   come compaiono nel verbale), con modelli pronti;
// - Ricevimento: sportello a appuntamenti dell'Ufficio didattico (uffici e persone sono in uffici.php).
// Pagine pubbliche: modulistica.php, modulo.php, pratiche.php.
// Ogni scheda sta in un suo file (didattica_pratiche.php, didattica_sedute.php, didattica_moduli.php, didattica_ufficio.php,
// didattica_statistiche.php) con le sue azioni e la sua pagina; questo file prepara i dati comuni e le include.
require_once 'admin_header.php';
define('DIDATTICA_PANNELLO', true);
const SCHEDE_DIDATTICA = ['home', 'pratiche', 'sedute', 'moduli', 'ufficio', 'statistiche'];

if (!$puo_didattica) nega_accesso();
function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tab = in_array($_GET['tab'] ?? '', SCHEDE_DIDATTICA, true) ? $_GET['tab'] : 'home';
$base = "didattica.php?p_id=" . (int)$filtro_p;
$uid = (int)$u_id_curr;
$ids_post = fn() => array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
$autore_nome = nome_autore_ufficio($conn, $utente_admin);
$io_operatore = operatore_ufficio($conn, 0, $utente_admin);
if ($io_operatore) $io_operatore['_sostituisce'] = \App\Core\App::per($conn)->get(\App\Didattica\SostituzioniOperatori::class)->sostituisce((int)$io_operatore['id']); // colleghi assenti che sostituisco oggi
// Referenti dei consigli (senza gli altri permessi della Didattica): solo le sedute dei loro consigli
$solo_ref = !$puo_didattica_tutto;
if ($solo_ref) { $tab = 'sedute'; $autore_nome = trim(($utente_admin['nome'] ?? '') . ' ' . ($utente_admin['cognome'] ?? '')) . ' · Referente del consiglio'; }
$consigli = consigli_didattica($conn);
$puo_consiglio = fn(int $cid) => $puo_didattica_tutto || in_array($cid, $consigli_referente, true);
$puo_seduta = fn(?array $s) => $s && ($puo_didattica_tutto || in_array((int)$s['consiglio_id'], $consigli_referente, true));

// ==============================================================================
// AZIONI
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    // I referenti dei consigli fanno solo le azioni delle sedute (ognuna controlla anche il consiglio)
    if ($solo_ref && !array_intersect(array_keys($_POST), ['salva_seduta', 'elimina_seduta', 'assegna_seduta', 'salva_presenze', 'salva_punti', 'importa_verbali', 'salva_verbale_importato', 'elimina_verbale_importato', 'salva_decisioni', 'applica_esiti', 'aggiungi_persona_consiglio', 'togli_persona_consiglio', 'salva_qualifiche', 'importa_componenti', 'invia_convocazione', 'invia_verbale_firma', 'carica_verbale_firmato'])) nega_accesso();

    $fase = 'azioni';
    foreach (SCHEDE_DIDATTICA as $scheda) require __DIR__ . '/didattica_' . $scheda . '.php';
}

// ==============================================================================
// DATI
// ==============================================================================
$moduli = \App\Core\App::per($conn)->get(\App\Didattica\ModuloRepository::class)->elenco();
$categorie = array_values(array_unique(array_column($moduli, 'categoria')));
$n_aperte = \App\Core\App::per($conn)->get(\App\Didattica\PraticaRepository::class)->contaAperte();
$sedute = \App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->elenco($solo_ref ? $consigli_referente : null);
$sedute_future = array_values(array_filter($sedute, fn($s) => !$s['data'] || $s['data'] >= date('Y-m-d', strtotime('-60 days'))));
$operatori = operatori_ufficio($conn);

// Filtri dell'elenco delle pratiche (servono anche per l'esportazione)
$f_stato = isset(STATI_PRATICA[$_GET['stato'] ?? '']) ? $_GET['stato'] : (in_array($_GET['stato'] ?? '', ['tutte', 'aperte'], true) ? $_GET['stato'] : 'aperte');
$f_mod = (int)($_GET['modulo'] ?? 0); $f_q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$f_sed = (string)($_GET['seduta'] ?? '');
$f_car = in_array($_GET['carico'] ?? '', ['me', 'smistare', 'seguite', 'ufficio'], true) ? $_GET['carico'] : '';
$f_dal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['dal'] ?? '')) ? $_GET['dal'] : '';
$f_al = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['al'] ?? '')) ? $_GET['al'] : '';
$elenco_pratiche = fn() => \App\Core\App::per($conn)->get(\App\Didattica\PraticaRepository::class)->elenco($f_stato, $f_mod, $f_sed, $f_car, $f_dal, $f_al, $f_q, $io_operatore);

// ── Verbale in PDF (da firmare o firmato in PAdES) ──
if (($_GET['esporta'] ?? '') === 'verbale_pdf' && $tab === 'sedute') {
    $seduta_exp = seduta_didattica($conn, (int)($_GET['id'] ?? 0));
    if (!$puo_seduta($seduta_exp) || !($f = percorso_verbale_pdf($seduta_exp))) nega_accesso();
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($f));
    header('Content-Disposition: attachment; filename="Verbale_' . ($seduta_exp['data'] ? date('d_m_Y', strtotime($seduta_exp['data'])) : 'seduta') . ($seduta_exp['verbale_stato'] === 'firmato' ? '_firmato' : '') . '.pdf"');
    readfile($f); exit;
}
// ── Allegato di un verbale letto dalla cartella dell'archivio (PDF, Excel, Word…) ──
if (($_GET['esporta'] ?? '') === 'allegato_verbale' && $tab === 'sedute') {
    $seduta_all = seduta_didattica($conn, (int)($_GET['id'] ?? 0));
    if (!$seduta_all || !$puo_seduta($seduta_all)) nega_accesso();
    $lista_all = json_decode((string)($seduta_all['allegati_json'] ?? ''), true) ?: [];
    $voce_all = $lista_all[(int)($_GET['n'] ?? -1)] ?? null;
    $f_all = $voce_all ? \App\Core\App::per($conn)->get(\App\Didattica\ArchivioVerbali::class)->percorsoAllegato((string)$voce_all['p']) : null;
    if (!$f_all) nega_accesso();
    $tipi_all = ['pdf' => 'application/pdf', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'zip' => 'application/zip', 'txt' => 'text/plain', 'csv' => 'text/csv'];
    $ext_all = strtolower(pathinfo($f_all, PATHINFO_EXTENSION));
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . ($tipi_all[$ext_all] ?? 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($f_all));
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n", '/', '\\\\'], '_', basename($f_all)) . '"');
    readfile($f_all); exit;
}
// ── Esportazioni (Excel e Word): scartano la pagina già prodotta dall'intestazione ──
if (isset($_GET['esporta']) && in_array($_GET['esporta'], ['pdf', 'csv'], true)) {
    $seduta_exp = $tab === 'sedute' ? seduta_didattica($conn, (int)($_GET['id'] ?? 0)) : null;
    if ($solo_ref && !$puo_seduta($seduta_exp)) nega_accesso();
    $repo_exp = \App\Core\App::per($conn)->get(\App\Didattica\PraticaRepository::class);
    if ($seduta_exp) $ids_e = \App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->idPratiche((int)$seduta_exp['id']);
    elseif (!empty($_GET['ids'])) $ids_e = array_map('intval', explode(',', (string)$_GET['ids']));
    else $ids_e = null;
    $righe_e = $ids_e === null ? $elenco_pratiche() : array_values(array_filter($repo_exp->elenco('tutte', 0, '', '', '', '', '', null), fn($x) => in_array((int)$x['id'], $ids_e, true)));
    $uff_e = uffici_didattica($conn);
    $dati_e = array_map(function ($x) use ($uff_e) {
        $fase_e = \App\Didattica\FasePratica::di($x, !empty($x['ufficio_id']) ? (string)($uff_e[(int)$x['ufficio_id']]['tipo'] ?? '') : '');
        return [$x['codice'], $x['modulo_titolo'], trim($x['cognome'] . ' ' . $x['nome']), $x['matricola'], $x['email'], \App\Didattica\FasePratica::FASI[$fase_e][0],
                $x['seduta_data'] ? date('d/m/Y', strtotime($x['seduta_data'])) : '', date('d/m/Y', strtotime($x['aggiornata_il'] ?: $x['creata_il']))];
    }, $righe_e);
    registra_log_audit($conn, "Pratiche esportate", ["Formato" => $_GET['esporta'], "Pratiche" => count($dati_e)]);
    (new \App\Infrastructure\Documenti\TabellaEsportabile($seduta_exp ? 'Pratiche della seduta' : 'Pratiche degli studenti', ['Codice', 'Tipo di pratica', 'Studente', 'Matricola', 'Email', 'A che punto è', 'Seduta', 'Aggiornata il'], $dati_e,
        'Stampato il ' . date('d/m/Y'), [2.2, 3.5, 3, 1.6, 3.5, 2.6, 1.4, 1.6]))
        ->scarica($_GET['esporta'] === 'pdf' ? 'pdf' : 'csv', ($seduta_exp ? 'Pratiche_seduta_' : 'Pratiche_') . date('d_m_Y'));
}
if (isset($_GET['esporta']) && in_array($_GET['esporta'], ['xlsx', 'docx'], true)) {
    $seduta_exp = $tab === 'sedute' ? seduta_didattica($conn, (int)($_GET['id'] ?? 0)) : null;
    if ($solo_ref && !$puo_seduta($seduta_exp)) nega_accesso();
    if ($seduta_exp) $ids = \App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->idPratiche((int)$seduta_exp['id']);
    elseif (!empty($_GET['ids'])) $ids = array_map('intval', explode(',', (string)$_GET['ids']));
    else $ids = array_column($elenco_pratiche(), 'id');
    $pr_exp = pratiche_per_esportazione($conn, $ids);
    $nome_f = ($seduta_exp ? 'Verbale_' . ($seduta_exp['data'] ? date('d_m_Y', strtotime($seduta_exp['data'])) : 'seduta') : 'Pratiche_' . date('d_m_Y'));
    $file = $_GET['esporta'] === 'xlsx' ? genera_excel_pratiche($conn, $pr_exp) : genera_verbale_pratiche($conn, $seduta_exp, $pr_exp);
    if ($file) {
        registra_log_audit($conn, "Pratiche esportate", ["Formato" => $_GET['esporta'], "Pratiche" => count($pr_exp)]);
        invia_file_scaricabile($file, ($_GET['esporta'] === 'xlsx' ? str_replace('Verbale_', 'Pratiche_seduta_', $nome_f) : $nome_f) . '.' . $_GET['esporta'],
            $_GET['esporta'] === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    flash_set("Esportazione non riuscita (manca l'estensione ZIP di PHP).", 'danger');
}
?>
<style>
.dd-ev { border-left: 3px solid #e2e8f0; padding: 4px 0 10px 14px; position: relative; }
.dd-ev::before { content: ''; position: absolute; left: -7px; top: 6px; width: 11px; height: 11px; border-radius: 50%; background: #94a3b8; }
.dd-ev.uff::before { background: #047857; } .dd-ev.stu::before { background: #0056B3; }
.dd-campo { display: grid; grid-template-columns: 2fr 1.4fr 2fr auto auto 1.6fr 34px 34px; gap: .4rem; align-items: center; margin-bottom: .4rem; padding: .3rem; border-radius: 6px; }
.dd-campo.dd-testa { margin-bottom: 0; padding-bottom: 0; }
.dd-logica { grid-column: 1 / -1; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 6px; padding: .5rem; }
.dd-col-riga { display: grid; grid-template-columns: 2fr 1.6fr 2fr 30px; gap: .3rem; margin-bottom: .25rem; }
.dd-campo.uff { background: #ecfdf5; }
@media (max-width: 991.98px) { .dd-campo { grid-template-columns: 1fr 1fr; } }
.dd-preset { border: 1px dashed #94a3b8; border-radius: 10px; padding: .6rem .8rem; background: #f8fafc; }
</style>

<?php
// Intestazione comune: titolo, una frase per scheda e schede uniformi (Pratiche · Sedute · Moduli · Ufficio · Statistiche · Tutorato)
$aiuto_did = ['home' => 'Qui vedi solo quello che devi fare. Le altre sezioni sono nel menu a sinistra e nell\'elenco qui sotto.',
              'pratiche' => 'Le domande degli studenti: ogni riga dice a che punto è la pratica e che cosa tocca fare.',
              'sedute' => 'Consigli dei corsi di studio: sedute, presenze, decisioni sulle pratiche e verbali da firmare.',
              'moduli' => 'Modulistica da scaricare e moduli online che gli studenti compilano.',
              'ufficio' => 'Chi lavora nell\'ufficio, quali compiti ha e quando riceve gli studenti.',
              'statistiche' => 'Quante pratiche, di che tipo e quanto tempo servono.'][$tab] ?? '';
// Azioni rare nel menu «Altro» (la pagina pubblica della modulistica e i testi delle email)
$voci_altro_did = ['<a class="dropdown-item" href="../modulistica.php" target="_blank" rel="noopener"><i class="fa fa-up-right-from-square me-2" aria-hidden="true"></i>Pagina pubblica della modulistica</a>'];
if ($puo_didattica_tutto) $voci_altro_did[] = '<a class="dropdown-item" href="email.php?p_id=' . (int)$filtro_p . '&amp;gruppo=Didattica"><i class="fa fa-envelope-open-text me-2" aria-hidden="true"></i>Testi delle email</a>';
echo pn_testata('Didattica · Ufficio didattico', 'fa-graduation-cap', $aiuto_did, pn_altro($voci_altro_did));
// Le sezioni sono nel menu a sinistra: qui solo il ritorno alla prima schermata
if ($tab !== 'home' && !$solo_ref && !isset($_GET['id']) && !isset($_GET['modifica'])) echo pn_indietro($base . '&tab=home', 'Torna a «Cosa devo fare»');
?>

<?php
$fase = 'vista';
require __DIR__ . '/didattica_' . $tab . '.php';
require_once 'admin_footer.php';
