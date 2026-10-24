<?php
// comunicazioni.php - Sito pubblico › Notizie e pagine: si scrive una comunicazione in un passo (titolo, testo, a chi si rivolge) e si pubblica.
// Le «notizie» hanno una data e compaiono nell'elenco, in home (se «in evidenza») e nel feed; le «pagine informative» non hanno data (es. «Come si chiede la tesi»).
// Una «data da ricordare» le porta anche in «Scadenze e date utili»; «valida fino al» le toglie da sole dopo quel giorno. Logica in src/Portale/ComunicazioniRepository.php.
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

use App\Assistenza\Costanti as CostC;
use App\Assistenza\Didacticus\VociRepository;

$rc = \App\Core\App::per($conn)->get(\App\Portale\ComunicazioniRepository::class);
$upload = \App\Core\App::per($conn)->get(\App\Infrastructure\Storage\Upload::class);
$cartella_up = dirname(__DIR__) . '/uploads/comunicazioni/';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$url = 'comunicazioni.php?p_id=' . (int)$filtro_p;
const ESTENSIONI_COMUNICAZIONI = ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'doc', 'xlsx', 'xls', 'odt', 'ods', 'txt'];
const MIME_COMUNICAZIONI = ['application/pdf', 'image/png', 'image/jpeg', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword', 'application/CDFV2',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
    'text/plain', 'application/zip', 'application/octet-stream'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['salva'])) {
        $id = (int)$_POST['salva'];
        $esistente = $id > 0 ? $rc->perId($id) : null;
        $link = [];
        $scartati = 0;
        foreach ((array)($_POST['link_u'] ?? []) as $k => $u) {
            $u = trim((string)$u);
            if ($u === '') continue;
            if (!VociRepository::urlValido($u)) { ++$scartati; continue; }
            $link[] = ['e' => mb_substr(trim((string)($_POST['link_e'][$k] ?? '')) ?: $u, 0, 100), 'u' => $u];
        }
        $allegati = null;
        if ($esistente || !empty($_FILES['allegati']['name'][0])) {
            $allegati = [];
            $togli = array_map('intval', (array)($_POST['allegato_togli'] ?? []));
            foreach ($esistente ? VociRepository::allegati($esistente) : [] as $i => $a) {
                if (in_array($i, $togli, true)) { @unlink($cartella_up . $a['f']); continue; }
                $allegati[] = ['n' => mb_substr(trim((string)($_POST['allegato_nome'][$i] ?? '')) ?: $a['n'], 0, 120), 'f' => $a['f']];
            }
            $non_caricati = [];
            foreach ((array)($_FILES['allegati']['name'] ?? []) as $k => $nome_f) {
                if ($nome_f === '' || ($_FILES['allegati']['error'][$k] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $f = ['name' => $nome_f, 'tmp_name' => $_FILES['allegati']['tmp_name'][$k], 'error' => $_FILES['allegati']['error'][$k], 'size' => $_FILES['allegati']['size'][$k]];
                $salvato = $f['size'] <= 10 * 1024 * 1024 ? $upload->salva($f, $cartella_up, ESTENSIONI_COMUNICAZIONI, MIME_COMUNICAZIONI) : null;
                if ($salvato === null) { $non_caricati[] = $nome_f; continue; }
                $upload->cartellaProtetta($cartella_up, 'I file delle comunicazioni si scaricano solo da comunicazione_file.php');
                $allegati[] = ['n' => mb_substr(pathinfo($nome_f, PATHINFO_FILENAME), 0, 120), 'f' => $salvato];
            }
        }
        $err = $rc->salva($id, $_POST, $link, $allegati, $id_salvato);
        if ($err !== '') {
            flash_set($err, 'danger');
            admin_redirect($url . '&modifica=' . ($id ?: 0));
        }
        $avvisi = array_filter([$scartati ? "$scartati link scartati perché l'indirizzo non è valido" : '', !empty($non_caricati) ? 'File non caricati (tipo non ammesso o più di 10 MB): ' . implode(', ', $non_caricati) : '']);
        registra_log_audit($conn, $id ? 'Comunicazione modificata' : 'Comunicazione creata', ['Titolo' => (string)($_POST['titolo'] ?? '')]);
        flash_set((!empty($_POST['pubblicata']) ? 'Pubblicata.' : 'Salvata come bozza: non si vede ancora sul sito.') . ($avvisi ? ' ⚠️ ' . implode(' · ', $avvisi) : ''), $avvisi ? 'warning' : 'success');
    } elseif (isset($_POST['pubblica'])) {
        $rc->pubblica((int)$_POST['pubblica'], ($_POST['valore'] ?? '') === '1');
        flash_set(($_POST['valore'] ?? '') === '1' ? 'Ora si vede sul sito.' : 'Ora è nascosta (bozza).');
    } elseif (isset($_POST['elimina'])) {
        $c = $rc->perId((int)$_POST['elimina']);
        foreach ($c ? VociRepository::allegati($c) : [] as $a) @unlink($cartella_up . $a['f']);
        $rc->elimina((int)$_POST['elimina']);
        registra_log_audit($conn, 'Comunicazione eliminata', ['Id' => (int)$_POST['elimina']]);
        flash_set('Eliminata.');
    }
    admin_redirect($url);
}

$elenco = $rc->tutte();
$modifica = isset($_GET['modifica']) ? (int)$_GET['modifica'] : -1;
$mod = $modifica > 0 ? $rc->perId($modifica) : null;
$oggi = date('Y-m-d');

$modulo = function (array $c) use ($h): string {
    $id = (int)($c['id'] ?? 0);
    $pubblici = CostC::pubblici((string)($c['pubblico'] ?? ''));
    $link = $id ? VociRepository::link($c) : [];
    $allegati = $id ? VociRepository::allegati($c) : [];
    while (count($link) < 2) $link[] = ['e' => '', 'u' => ''];
    $altre = $id > 0 && ((string)($c['data_utile'] ?? '') !== '' || (string)($c['valida_fino'] ?? '') !== '' || $allegati || array_filter(array_column($link, 'u')) || (int)($c['in_evidenza'] ?? 0) || ($c['tipo'] ?? '') === 'pagina');
    $o = '<form method="POST" enctype="multipart/form-data" class="row g-3">' . (new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->campo();
    $o .= '<div class="col-12"><label class="form-label fw-bold fs-5" for="cm-titolo">Titolo</label><input class="form-control form-control-lg" id="cm-titolo" name="titolo" maxlength="200" required value="' . $h($c['titolo'] ?? '') . '"></div>';
    $o .= '<div class="col-12"><label class="form-label fw-bold fs-5" for="cm-testo">Testo</label><textarea class="form-control" id="cm-testo" name="testo" rows="9" required>' . $h($c['testo'] ?? '') . '</textarea>'
        . '<div class="form-text">Riga vuota = nuovo paragrafo · righe che iniziano con «- » = elenco puntato · <code>**testo**</code> = grassetto · gli indirizzi web diventano link da soli.</div></div>';
    $o .= '<div class="col-12"><fieldset><legend class="fw-bold fs-6 mb-1">A chi si rivolge</legend><div class="d-flex flex-wrap gap-4">';
    foreach (CostC::PUBBLICI as $k => $n) { $o .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="pubblico[]" value="' . $k . '" id="cm-p-' . $k . '"' . (in_array($k, $pubblici, true) ? ' checked' : '') . '><label class="form-check-label" for="cm-p-' . $k . '">' . $h($n) . '</label></div>'; }
    $o .= '</div><div class="form-text">Se non spunti nulla vale per tutti.</div></fieldset></div>';
    $o .= '<div class="col-12"><details' . ($altre ? ' open' : '') . '><summary class="fw-bold" style="cursor:pointer;">Altre opzioni (date, in evidenza, link, allegati, pagina informativa)</summary><div class="row g-3 mt-1">'
        . '<div class="col-md-4"><label class="form-label fw-bold" for="cm-data">Data della notizia</label><input class="form-control" type="date" id="cm-data" name="data_pubblicazione" value="' . $h($c['data_pubblicazione'] ?? '') . '"><div class="form-text">Vuota = oggi. Una data futura la mostra quel giorno.</div></div>'
        . '<div class="col-md-4"><label class="form-label fw-bold" for="cm-utile">Data da ricordare</label><input class="form-control" type="date" id="cm-utile" name="data_utile" value="' . $h($c['data_utile'] ?? '') . '"><div class="form-text">Una scadenza o un\'apertura: compare in «Scadenze e date utili».</div></div>'
        . '<div class="col-md-4"><label class="form-label fw-bold" for="cm-fino">Valida fino al</label><input class="form-control" type="date" id="cm-fino" name="valida_fino" value="' . $h($c['valida_fino'] ?? '') . '"><div class="form-text">Dopo questo giorno si nasconde da sola.</div></div>'
        . '<div class="col-12 d-flex flex-wrap gap-4"><label class="form-check"><input class="form-check-input" type="checkbox" name="in_evidenza" value="1"' . (!empty($c['in_evidenza']) ? ' checked' : '') . '> <span class="fw-bold">In evidenza nella home</span></label>'
        . '<label class="form-check"><input class="form-check-input" type="checkbox" name="tipo" value="pagina"' . (($c['tipo'] ?? '') === 'pagina' ? ' checked' : '') . '> <span class="fw-bold">È una pagina informativa</span> <span class="text-secondary">(senza data, non compare tra le notizie)</span></label></div>'
        . '<div class="col-12"><div class="fw-bold mb-1">Link (pulsanti sotto il testo)</div>';
    foreach ($link as $l) { $o .= '<div class="row g-2 mb-1"><div class="col-md-4"><input class="form-control form-control-sm" name="link_e[]" maxlength="100" placeholder="Testo del pulsante" aria-label="Testo del pulsante" value="' . $h($l['e']) . '"></div><div class="col-md-8"><input class="form-control form-control-sm" name="link_u[]" maxlength="500" placeholder="https://… oppure una pagina del portale, es. modulistica.php" aria-label="Indirizzo del link" value="' . $h($l['u']) . '"></div></div>'; }
    $o .= '</div><div class="col-12"><div class="fw-bold mb-1">Allegati <span class="fw-normal text-secondary">(PDF, immagini, Word, Excel… al massimo 10 MB l\'uno)</span></div>';
    foreach ($allegati as $i => $a) { $o .= '<div class="row g-2 align-items-center mb-1"><div class="col-md-6"><input class="form-control form-control-sm" name="allegato_nome[' . $i . ']" maxlength="120" aria-label="Nome del file mostrato" value="' . $h($a['n']) . '"></div><div class="col-md-3"><a class="small" target="_blank" href="../comunicazione_file.php?c=' . $id . '&i=' . $i . '&anteprima=1">apri il file</a></div><div class="col-md-3"><label class="form-check small"><input class="form-check-input" type="checkbox" name="allegato_togli[]" value="' . $i . '"> Elimina</label></div></div>'; }
    $o .= '<label class="form-label small mb-1" for="cm-file">Aggiungi file</label><input class="form-control form-control-sm" type="file" id="cm-file" name="allegati[]" multiple accept=".pdf,.png,.jpg,.jpeg,.docx,.doc,.xlsx,.xls,.odt,.ods,.txt"></div>'
        . '<div class="col-12"><label class="form-label fw-bold" for="cm-somm">Frase di presentazione <span class="fw-normal text-secondary">(facoltativa: se vuota si usa l\'inizio del testo)</span></label><input class="form-control" id="cm-somm" name="sommario" maxlength="400" value="' . $h($c['sommario'] ?? '') . '"></div>'
        . '</div></details></div>';
    $o .= '<div class="col-12 d-flex flex-wrap align-items-center gap-3"><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="pubblicata" value="1"' . (!$id || !empty($c['pubblicata']) ? ' checked' : '') . '> <span class="fw-bold">Pubblica sul sito</span></label>'
        . '<button type="submit" name="salva" value="' . $id . '" class="btn btn-azione btn-lg fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>' . ($id ? 'Salva' : 'Pubblica') . '</button>'
        . '<a class="btn btn-outline-secondary btn-lg" href="comunicazioni.php">Annulla</a></div></form>';

    return $o;
};
?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div><h1 class="pn-titolo"><i class="fa fa-bullhorn me-2" aria-hidden="true"></i>Notizie e pagine</h1>
        <p class="pn-aiuto">Scrivi una notizia per studenti, scuole, aziende o docenti: titolo, testo e a chi si rivolge. Si vede nell'elenco delle notizie e, se la metti «in evidenza», anche in home.</p></div>
    <div class="d-flex gap-2"><a href="../comunicazioni.php" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="fa fa-external-link-alt me-1" aria-hidden="true"></i>Guarda le notizie</a>
        <?php if ($modifica < 0): ?><a href="comunicazioni.php?modifica=0&amp;p_id=<?php echo (int)$filtro_p; ?>" class="btn btn-azione fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i>Scrivi una notizia</a><?php endif; ?></div>
</div>

<?php if ($modifica === 0 || $mod): ?>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 fw-bold mb-3"><?php echo $mod ? 'Modifica «' . $h($mod['titolo']) . '»' : 'Nuova notizia'; ?></h2><?php echo $modulo($mod ?: []); ?></div></div>
<?php endif; ?>

<?php if (!$elenco && $modifica !== 0): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5"><i class="fa fa-bullhorn fs-1 text-secondary mb-3 d-block" aria-hidden="true"></i><h2 class="h5 fw-bold">Non c'è ancora nessuna notizia</h2><p class="text-secondary mb-3">Premi «Scrivi una notizia»: bastano il titolo e il testo.</p></div></div>
<?php endif; ?>

<div class="d-flex flex-column gap-2">
<?php foreach ($elenco as $c): $id = (int)$c['id'];
    $scaduta = $c['valida_fino'] && $c['valida_fino'] < $oggi; $futura = $c['data_pubblicazione'] && $c['data_pubblicazione'] > $oggi;
    $stato = !(int)$c['pubblicata'] ? pn_stato('Bozza (non si vede)', 'spento', 'fa-eye-slash') : ($scaduta ? pn_stato('Scaduta', 'spento', 'fa-clock') : ($futura ? pn_stato('Uscirà il ' . date('d/m/Y', strtotime($c['data_pubblicazione'])), 'attesa', 'fa-calendar') : pn_stato('Si vede sul sito', 'ok', 'fa-eye'))); ?>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1" style="min-width:240px;">
            <div class="fw-bold fs-6"><?php echo $h($c['titolo']); ?> <?php echo $stato; ?></div>
            <div class="small text-secondary"><?php echo $c['tipo'] === 'pagina' ? 'Pagina informativa' : 'Notizia del ' . $h(date('d/m/Y', strtotime((string)($c['data_pubblicazione'] ?: $c['creato_il'])))); ?>
                · <?php echo implode(', ', array_map(fn($p) => CostC::PUBBLICI[$p], CostC::pubblici((string)$c['pubblico']))) ?: 'Per tutti'; ?>
                <?php echo (int)$c['in_evidenza'] ? ' · in evidenza' : ''; ?><?php echo $c['data_utile'] ? ' · da ricordare il ' . $h(date('d/m/Y', strtotime($c['data_utile']))) : ''; ?></div></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="../comunicazione.php?id=<?php echo $id; ?>&amp;anteprima=1">Vedi</a>
            <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="valore" value="<?php echo (int)$c['pubblicata'] ? 0 : 1; ?>"><button type="submit" name="pubblica" value="<?php echo $id; ?>" class="btn btn-outline-secondary"><?php echo (int)$c['pubblicata'] ? 'Nascondi' : 'Pubblica'; ?></button></form>
            <a class="btn btn-azione fw-bold" href="comunicazioni.php?modifica=<?php echo $id; ?>&amp;p_id=<?php echo (int)$filtro_p; ?>">Modifica</a>
            <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="elimina" value="<?php echo $id; ?>" class="btn btn-outline-danger" data-confirm="Eliminare «<?php echo $h($c['titolo']); ?>»?" aria-label="Elimina «<?php echo $h($c['titolo']); ?>»"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
        </div></div></div></div>
<?php endforeach; ?>
</div>
<?php require_once 'admin_footer.php'; ?>
