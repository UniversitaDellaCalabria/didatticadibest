<?php
// admin/didacticus.php - Didacticus, l'assistente virtuale dell'assistenza: si istruisce da qui. Quattro schede: le voci della base di conoscenza (domande, parole chiave,
// risposta con link e allegati), le domande a cui non ha saputo rispondere (da trasformare in voci con un clic), la prova («cosa risponderebbe?») e le impostazioni
// (acceso/spento, saluto, soglia di sicurezza, immagine di profilo). Chi può: chi risponde alle chat (ufficio «Assistenza») e gli amministratori.
require_once 'admin_header.php';

if (!$puo_assistenza) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Didacticus lo istruiscono le persone dell'ufficio «Assistenza» e gli amministratori.</div>";
    require_once 'admin_footer.php';
    exit;
}

use App\Assistenza\Costanti as CostD;
use App\Assistenza\Didacticus\ImpostazioniDidacticus;
use App\Assistenza\Didacticus\MancateRepository;
use App\Assistenza\Didacticus\Motore;
use App\Assistenza\Didacticus\ServizioDidacticus;
use App\Assistenza\Didacticus\VociRepository;

$app_d = \App\Core\App::per($conn);
$voci_rep = $app_d->get(VociRepository::class);
$manc_rep = $app_d->get(MancateRepository::class);
$imp_rep = $app_d->get(ImpostazioniDidacticus::class);
$did = $app_d->get(ServizioDidacticus::class);
$upload = $app_d->get(\App\Infrastructure\Storage\Upload::class);
$cartella_up = dirname(__DIR__) . '/uploads/didacticus/';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$redirect_d = function (string $qs) use ($filtro_p) { echo "<script>window.location.replace('didacticus.php?p_id=" . (int)$filtro_p . ($qs !== '' ? '&' . $qs : '') . "');</script>"; exit; };

const ESTENSIONI_ALLEGATI = ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'doc', 'xlsx', 'xls', 'odt', 'ods', 'txt'];
const MIME_ALLEGATI = ['application/pdf', 'image/png', 'image/jpeg', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword', 'application/CDFV2',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
    'text/plain', 'application/zip', 'application/octet-stream'];
const MAX_BYTE_ALLEGATO = 10 * 1024 * 1024;

$scheda = in_array($_GET['scheda'] ?? '', ['voci', 'mancate', 'prova', 'impostazioni'], true) ? $_GET['scheda'] : 'voci';

// ----- Esportazione dell'elenco delle voci (Excel, PDF, CSV)
if (isset($_GET['scarica'])) {
    $righe_e = [];
    foreach ($voci_rep->tutte() as $v) {
        $link_e = implode("\n", array_map(fn($l) => $l['e'] . '|' . $l['u'], VociRepository::link($v)));
        $righe_e[] = [CostD::nomeArgomento((string)$v['argomento']), $v['titolo'], $v['domande'], $v['parole_chiave'], $v['risposta'], $link_e, (int)$v['attiva'] ? 'sì' : 'no', (int)$v['usi'], (int)$v['utile'], (int)$v['non_utile'], (int)$v['proponi_operatore'] ? 'sì' : 'no', (int)$v['solo_login'] ? 'sì' : 'no'];
    }
    (new \App\Infrastructure\Documenti\TabellaEsportabile('Didacticus: base di conoscenza', ['Argomento', 'Titolo', 'Domande', 'Parole chiave', 'Risposta', 'Link (etichetta|indirizzo)', 'Attiva', 'Usi', 'Utile', 'Non utile', 'Propone operatore', 'Solo con accesso'], $righe_e, 'Esportata il ' . date('d/m/Y'), [1, 2, 2, 2, 4, 2, 1, 1, 1, 1, 1, 1]))
        ->scarica((string)$_GET['scarica'], 'didacticus_voci_' . date('Ymd'));
    exit;
}

// ----- Azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $azione = (string)($_POST['azione'] ?? '');
    $adesso_d = date('Y-m-d H:i:s');

    if ($azione === 'salva_voce') {
        $id_v = (int)($_POST['id'] ?? 0);
        $esistente = $id_v > 0 ? $voci_rep->perId($id_v) : null;
        $titolo = trim((string)($_POST['titolo'] ?? ''));
        $risposta = trim(str_replace("\r\n", "\n", (string)($_POST['risposta'] ?? '')));
        if ($titolo === '' || $risposta === '') {
            flash_set('Scrivi almeno il titolo e la risposta.', 'danger');
            $redirect_d('scheda=voci&voce=' . ($id_v ?: 'nuova'));
        }
        // link: righe con etichetta e indirizzo; gli indirizzi non validi si scartano dicendolo
        $link = [];
        $scartati = 0;
        foreach ((array)($_POST['link_u'] ?? []) as $k => $u) {
            $u = trim((string)$u);
            if ($u === '') continue;
            if (!VociRepository::urlValido($u)) { ++$scartati; continue; }
            $link[] = ['e' => mb_substr(trim((string)($_POST['link_e'][$k] ?? '')) ?: $u, 0, 100), 'u' => $u];
        }
        // allegati: quelli già presenti (tolti se spuntati) e i nuovi
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
            $salvato = $f['size'] <= MAX_BYTE_ALLEGATO ? $upload->salva($f, $cartella_up, ESTENSIONI_ALLEGATI, MIME_ALLEGATI) : null;
            if ($salvato === null) { $non_caricati[] = $nome_f; continue; }
            $upload->cartellaProtetta($cartella_up, 'I file di Didacticus si scaricano solo da didacticus_file.php');
            $allegati[] = ['n' => mb_substr(pathinfo($nome_f, PATHINFO_FILENAME), 0, 120), 'f' => $salvato];
        }
        $id_salvato = $voci_rep->salva($id_v, [
            'argomento' => CostD::argomentoValido((string)($_POST['argomento'] ?? '')), 'titolo' => $titolo, 'domande' => trim((string)($_POST['domande'] ?? '')),
            'parole_chiave' => trim((string)($_POST['parole_chiave'] ?? '')), 'risposta' => $risposta, 'link' => $link, 'allegati' => $allegati,
            'solo_login' => !empty($_POST['solo_login']), 'proponi_operatore' => !empty($_POST['proponi_operatore']), 'attiva' => !empty($_POST['attiva']), 'ordine' => (int)($esistente['ordine'] ?? 0),
        ], $adesso_d);
        if (!empty($_POST['mancata_id'])) $manc_rep->segna((int)$_POST['mancata_id'], MancateRepository::FATTA);
        registra_log_audit($conn, ($id_v ? 'Voce di Didacticus modificata' : 'Voce di Didacticus creata'), ['Voce' => $titolo, 'Id' => $id_salvato]);
        $avvisi = array_filter([$scartati ? "$scartati link scartati perché l'indirizzo non è valido (serve https://… oppure una pagina del portale)" : '', $non_caricati ? 'File non caricati (tipo non ammesso o più di 10 MB): ' . implode(', ', $non_caricati) : '']);
        flash_set('✅ Voce salvata. Provala nella scheda «Prova».' . ($avvisi ? ' ⚠️ ' . implode(' · ', $avvisi) : ''), $avvisi ? 'warning' : 'success');
        $redirect_d('scheda=voci');
    }
    if ($azione === 'elimina_voce' || $azione === 'attiva_voce') {
        $v = $voci_rep->perId((int)($_POST['id'] ?? 0));
        if ($v) {
            if ($azione === 'elimina_voce') {
                foreach (VociRepository::allegati($v) as $a) @unlink($cartella_up . $a['f']);
                $voci_rep->elimina((int)$v['id']);
                registra_log_audit($conn, 'Voce di Didacticus eliminata', ['Voce' => $v['titolo']]);
                flash_set('✅ Voce eliminata.');
            } else {
                $voci_rep->attiva((int)$v['id'], !(int)$v['attiva']);
                flash_set(!(int)$v['attiva'] ? '✅ Voce attivata: Didacticus la userà.' : '✅ Voce disattivata: Didacticus non la usa più (resta qui, puoi riattivarla).');
            }
        }
        $redirect_d('scheda=voci' . (!empty($_POST['argomento_filtro']) ? '&argomento=' . urlencode((string)$_POST['argomento_filtro']) : ''));
    }
    if ($azione === 'mancata_segna') {
        $manc_rep->segna((int)($_POST['id'] ?? 0), (string)($_POST['stato'] ?? ''));
        $redirect_d('scheda=mancate' . (($_POST['stato'] ?? '') === MancateRepository::DA_VEDERE ? '&elenco=archivio' : ''));
    }
    if ($azione === 'salva_impostazioni') {
        $imp_rep->salva(!empty($_POST['attivo']), (string)($_POST['saluto'] ?? ''), (int)($_POST['soglia'] ?? ImpostazioniDidacticus::SOGLIA_PREDEFINITA));
        $avatar_attuale = $imp_rep->leggi()['avatar'];
        $msg_av = '';
        if (!empty($_POST['avatar_ripristina']) && $avatar_attuale !== '') {
            @unlink($cartella_up . $avatar_attuale);
            $imp_rep->impostaAvatar('');
        } elseif (!empty($_FILES['avatar']['name']) && ($_FILES['avatar']['error'] ?? 0) === UPLOAD_ERR_OK) {
            $nuovo = ($_FILES['avatar']['size'] ?? 0) <= 2 * 1024 * 1024 ? $upload->salva($_FILES['avatar'], $cartella_up, ['png', 'jpg', 'jpeg', 'webp'], ['image/png', 'image/jpeg', 'image/webp']) : null;
            if ($nuovo === null) {
                $msg_av = ' ⚠️ L\'immagine non è stata caricata (serve PNG, JPG o WEBP, al massimo 2 MB).';
            } else {
                $upload->cartellaProtetta($cartella_up, 'I file di Didacticus si scaricano solo da didacticus_file.php');
                $finale = 'avatar_' . substr($nuovo, 0, 16) . '.' . pathinfo($nuovo, PATHINFO_EXTENSION);
                rename($cartella_up . $nuovo, $cartella_up . $finale);
                if ($avatar_attuale !== '') @unlink($cartella_up . $avatar_attuale);
                $imp_rep->impostaAvatar($finale);
            }
        }
        registra_log_audit($conn, 'Impostazioni di Didacticus modificate', ['Attivo' => !empty($_POST['attivo']) ? 'sì' : 'no', 'Soglia' => (int)($_POST['soglia'] ?? 0)]);
        flash_set('✅ Impostazioni salvate.' . $msg_av, $msg_av ? 'warning' : 'success');
        $redirect_d('scheda=impostazioni');
    }
    if ($azione === 'importa') {
        $tmp = (string)($_FILES['csv']['tmp_name'] ?? '');
        if ($tmp === '' || ($_FILES['csv']['error'] ?? 1) !== UPLOAD_ERR_OK || ($_FILES['csv']['size'] ?? 0) > 3 * 1024 * 1024) {
            flash_set('Scegli un file CSV (al massimo 3 MB), per esempio quello scaricato dalla scheda «Voci» in formato CSV.', 'danger');
            $redirect_d('scheda=voci');
        }
        [$nuove, $aggiornate, $saltate] = $voci_rep->importaCsv((string)file_get_contents($tmp), $adesso_d);
        registra_log_audit($conn, 'Voci di Didacticus importate da CSV', ['Nuove' => $nuove, 'Aggiornate' => $aggiornate, 'Saltate' => $saltate]);
        flash_set("✅ Importazione fatta: $nuove voci nuove, $aggiornate aggiornate" . ($saltate ? ", $saltate saltate (mancava il titolo o la risposta)" : '') . '.');
        $redirect_d('scheda=voci');
    }
}

// ----- Dati per la pagina
$conteggio_mancate = $manc_rep->contaDaVedere();
$imp = $imp_rep->leggi();
$n_attive = $voci_rep->contaAttive();
$url_scheda = fn(string $s, string $extra = '') => 'didacticus.php?p_id=' . (int)$filtro_p . '&scheda=' . $s . $extra;
$schede_d = ['voci' => ['Voci', 'fa-book-open'], 'mancate' => ['Domande senza risposta', 'fa-circle-question'], 'prova' => ['Prova', 'fa-flask'], 'impostazioni' => ['Impostazioni', 'fa-sliders']];
?>
<style>.did-testo{white-space:pre-wrap;overflow-wrap:anywhere}</style>
<div class="d-flex flex-wrap align-items-center gap-3 mb-2">
    <img src="../didacticus_file.php?avatar=1" alt="" width="64" height="64" class="rounded-circle shadow-sm" style="object-fit:cover;">
    <div>
        <h1 class="h4 fw-bold text-dark mb-0">Didacticus <span class="badge <?php echo $imp['attivo'] && $n_attive ? 'bg-success' : 'bg-secondary'; ?> fs-6 align-middle"><?php echo $imp['attivo'] ? ($n_attive ? 'acceso' : 'acceso, senza voci') : 'spento'; ?></span></h1>
        <p class="text-secondary mb-0">L'assistente virtuale dell'assistenza: risponde alle domande più comuni usando solo quello che scrivi qui, e passa la parola a un operatore quando non basta. <strong><?php echo $n_attive; ?></strong> voci attive.</p>
    </div>
</div>
<?php echo flash_html(); ?>

<ul class="nav nav-pills gap-1 mb-3 flex-wrap" style="--bs-nav-pills-link-active-bg:#1e293b;">
    <?php foreach ($schede_d as $k_s => [$nome_s, $ico_s]): $on_s = $k_s === $scheda; ?>
        <li class="nav-item"><a class="nav-link fw-bold <?php echo $on_s ? 'active' : 'bg-light text-dark'; ?>" style="font-size:.9rem;" href="<?php echo $h($url_scheda($k_s)); ?>"<?php echo $on_s ? ' aria-current="page"' : ''; ?>><i class="fa <?php echo $ico_s; ?> me-1" aria-hidden="true"></i><?php echo $nome_s; ?><?php if ($k_s === 'mancate' && $conteggio_mancate): ?> <span class="badge bg-danger"><?php echo $conteggio_mancate; ?></span><?php endif; ?></a></li>
    <?php endforeach; ?>
    <li class="nav-item ms-auto"><a class="nav-link bg-light text-dark fw-bold" style="font-size:.9rem;" href="assistenza.php?p_id=<?php echo (int)$filtro_p; ?>"><i class="fa fa-headset me-1" aria-hidden="true"></i>Torna alle chat</a></li>
</ul>

<?php if ($scheda === 'voci'):
    $arg_filtro = isset(CostD::ARGOMENTI[$_GET['argomento'] ?? '']) ? $_GET['argomento'] : '';
    $cerca_d = trim((string)($_GET['q'] ?? ''));
    $voce_sel = $_GET['voce'] ?? '';
    // Da una domanda rimasta senza risposta: la voce parte già con la domanda e, se c'era, la risposta data dall'operatore
    $da_mancata = !empty($_GET['da_mancata']) ? $manc_rep->perId((int)$_GET['da_mancata']) : null;
    if ($voce_sel === 'nuova' || (int)$voce_sel > 0):
        $v_mod = (int)$voce_sel > 0 ? $voci_rep->perId((int)$voce_sel) : null;
        if ((int)$voce_sel > 0 && !$v_mod) { echo '<div class="alert alert-warning">Voce non trovata.</div>'; require_once 'admin_footer.php'; exit; }
        $d = $v_mod ?: ['id' => 0, 'argomento' => $da_mancata['argomento'] ?? 'altro', 'titolo' => $da_mancata ? mb_substr((string)$da_mancata['domanda'], 0, 120) : '', 'domande' => $da_mancata['domanda'] ?? '',
            'parole_chiave' => '', 'risposta' => $da_mancata ? $manc_rep->rispostaOperatore((int)$da_mancata['conversazione_id']) : '', 'attiva' => 1, 'solo_login' => 0, 'proponi_operatore' => 0];
        $link_form = $v_mod ? VociRepository::link($v_mod) : [];
        $allegati_form = $v_mod ? VociRepository::allegati($v_mod) : [];
        while (count($link_form) < 3) $link_form[] = ['e' => '', 'u' => ''];
?>
<form method="POST" enctype="multipart/form-data" action="<?php echo $h($url_scheda('voci')); ?>" class="card border-0 shadow-sm"><div class="card-body">
    <?php csrf_field(); ?>
    <input type="hidden" name="azione" value="salva_voce"><input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
    <?php if ($da_mancata): ?><input type="hidden" name="mancata_id" value="<?php echo (int)$da_mancata['id']; ?>"><div class="alert alert-info py-2 small"><i class="fa fa-lightbulb me-1" aria-hidden="true"></i>Stai creando una voce da una domanda a cui Didacticus non sapeva rispondere<?php echo $d['risposta'] !== '' ? ': come risposta c\'è già quella data dall\'operatore, da rivedere' : ''; ?>.</div><?php endif; ?>
    <h5 class="fw-bold mb-3"><?php echo $v_mod ? 'Modifica la voce' : 'Nuova voce'; ?></h5>
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label fw-bold" for="d-titolo">Titolo (come lo vedono gli utenti nei suggerimenti)</label><input class="form-control" id="d-titolo" name="titolo" maxlength="200" required value="<?php echo $h($d['titolo']); ?>"></div>
        <div class="col-md-4"><label class="form-label fw-bold" for="d-arg">Argomento</label><select class="form-select" id="d-arg" name="argomento"><?php foreach (CostD::ARGOMENTI as $k_a => $a_a): ?><option value="<?php echo $k_a; ?>" <?php echo $d['argomento'] === $k_a ? 'selected' : ''; ?>><?php echo $h($a_a['nome']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label fw-bold" for="d-dom">Domande che la persona potrebbe fare <span class="fw-normal text-secondary">(una per riga)</span></label>
            <textarea class="form-control" id="d-dom" name="domande" rows="6" placeholder="Come richiedo il certificato di iscrizione&#10;Ho bisogno di un certificato&#10;Dove trovo il modulo per il certificato"><?php echo $h($d['domande']); ?></textarea>
            <div class="form-text">Più modi diversi scrivi, meglio Didacticus capisce. Scrivile come le scriverebbe uno studente, anche con parole semplici.</div></div>
        <div class="col-md-6"><label class="form-label fw-bold" for="d-par">Parole chiave e sinonimi <span class="fw-normal text-secondary">(separate da virgola)</span></label>
            <textarea class="form-control" id="d-par" name="parole_chiave" rows="6" placeholder="certificato, certificati, attestazione, marca da bollo"><?php echo $h($d['parole_chiave']); ?></textarea>
            <div class="form-text">Le parole che, se compaiono nella domanda, indicano questa voce. Anche frasi di più parole («marca da bollo»). Aggiungi qui i sinonimi che gli studenti usano davvero.</div></div>
        <div class="col-12"><label class="form-label fw-bold" for="d-ris">Risposta</label>
            <textarea class="form-control" id="d-ris" name="risposta" rows="8" required><?php echo $h($d['risposta']); ?></textarea>
            <div class="form-text">Scrivi come parleresti a uno studente. Riga vuota = nuovo paragrafo · righe che iniziano con «- » = elenco puntato · <code>**testo**</code> = grassetto · gli indirizzi web diventano link da soli. <strong>Non scrivere nomi di persone o dati personali.</strong></div></div>
        <div class="col-12"><div class="fw-bold mb-1">Link sotto la risposta <span class="fw-normal text-secondary">(pulsanti)</span></div>
            <?php foreach ($link_form as $l): ?>
                <div class="row g-2 mb-1"><div class="col-md-4"><input class="form-control form-control-sm" name="link_e[]" maxlength="100" placeholder="Testo del pulsante (es. Modulistica)" aria-label="Testo del pulsante" value="<?php echo $h($l['e']); ?>"></div>
                    <div class="col-md-8"><input class="form-control form-control-sm" name="link_u[]" maxlength="500" placeholder="https://… oppure una pagina del portale, es. modulistica.php" aria-label="Indirizzo del link" value="<?php echo $h($l['u']); ?>"></div></div>
            <?php endforeach; ?>
            <div class="form-text">Se ti servono più di tre link, salva e riapri la voce: compaiono altre righe vuote.</div></div>
        <div class="col-12"><div class="fw-bold mb-1">Allegati <span class="fw-normal text-secondary">(file da scaricare: PDF, immagini, Word, Excel… al massimo 10 MB l'uno)</span></div>
            <?php foreach ($allegati_form as $i_a => $a): ?>
                <div class="row g-2 align-items-center mb-1"><div class="col-md-5"><input class="form-control form-control-sm" name="allegato_nome[<?php echo $i_a; ?>]" maxlength="120" aria-label="Nome del file mostrato" value="<?php echo $h($a['n']); ?>"></div>
                    <div class="col-md-4"><a class="small" target="_blank" href="../didacticus_file.php?v=<?php echo (int)$d['id']; ?>&i=<?php echo $i_a; ?>"><i class="fa fa-file me-1" aria-hidden="true"></i>apri il file</a></div>
                    <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="allegato_togli[]" value="<?php echo $i_a; ?>" id="tog<?php echo $i_a; ?>"><label class="form-check-label small" for="tog<?php echo $i_a; ?>">Elimina</label></div></div></div>
            <?php endforeach; ?>
            <label class="form-label small mb-1" for="d-file">Aggiungi file (puoi sceglierne più d'uno)</label><input class="form-control form-control-sm" type="file" id="d-file" name="allegati[]" multiple accept=".pdf,.png,.jpg,.jpeg,.docx,.doc,.xlsx,.xls,.odt,.ods,.txt"></div>
        <div class="col-12 d-flex flex-wrap gap-4">
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="d-att" name="attiva" value="1" <?php echo (int)$d['attiva'] ? 'checked' : ''; ?>><label class="form-check-label fw-bold" for="d-att">Attiva</label><div class="form-text">Spenta: Didacticus non la usa.</div></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="d-log" name="solo_login" value="1" <?php echo (int)$d['solo_login'] ? 'checked' : ''; ?>><label class="form-check-label fw-bold" for="d-log">Solo per chi ha fatto l'accesso</label><div class="form-text">Gli esterni vengono invitati ad accedere.</div></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="d-op" name="proponi_operatore" value="1" <?php echo (int)$d['proponi_operatore'] ? 'checked' : ''; ?>><label class="form-check-label fw-bold" for="d-op">Proponi sempre un operatore</label><div class="form-text">Per risposte che spesso non bastano.</div></div>
        </div>
    </div>
    <div class="mt-3 d-flex gap-2"><button class="btn btn-danger fw-bold px-4" type="submit" style="background-color:#B80000;border:none;"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva la voce</button><a class="btn btn-outline-secondary" href="<?php echo $h($url_scheda('voci')); ?>">Annulla</a></div>
</div></form>
<?php else:
    $elenco_v = $voci_rep->tutte($arg_filtro ?: null, $cerca_d);
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-danger fw-bold" style="background-color:#B80000;border:none;" href="<?php echo $h($url_scheda('voci', '&voce=nuova')); ?>"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuova voce</a>
    <form method="GET" class="d-flex flex-wrap gap-2 m-0" role="search"><input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="scheda" value="voci">
        <select name="argomento" class="form-select" style="width:auto;" aria-label="Filtra per argomento" onchange="this.form.submit()"><option value="">Tutti gli argomenti</option><?php foreach (CostD::ARGOMENTI as $k_a => $a_a): ?><option value="<?php echo $k_a; ?>" <?php echo $arg_filtro === $k_a ? 'selected' : ''; ?>><?php echo $h($a_a['nome']); ?></option><?php endforeach; ?></select>
        <input type="search" name="q" class="form-control" style="max-width:240px;" placeholder="Cerca nelle voci" aria-label="Cerca nelle voci" value="<?php echo $h($cerca_d); ?>"><button class="btn btn-outline-secondary" type="submit">Cerca</button></form>
    <span class="ms-auto d-flex gap-2"><?php echo pn_scarica($url_scheda('voci')); ?>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#importa" aria-expanded="false"><i class="fa fa-file-import me-1" aria-hidden="true"></i>Importa da CSV</button></span>
</div>
<div class="collapse mb-3" id="importa"><form method="POST" enctype="multipart/form-data" action="<?php echo $h($url_scheda('voci')); ?>" class="card card-body border-0 shadow-sm"><?php csrf_field(); ?><input type="hidden" name="azione" value="importa">
    <p class="small mb-2">Per scrivere tante voci in una volta: <strong>scarica l'elenco in CSV</strong>, aggiungi o modifica le righe in Excel (colonne: Argomento, Titolo, Domande, Parole chiave, Risposta, Link, Attiva e, facoltative, «Propone operatore» e «Solo con accesso» con sì/no) e caricalo qui. Le voci con lo stesso titolo si aggiornano, le altre si aggiungono; nel campo «Link» scrivi una riga per link nella forma <code>Testo|indirizzo</code>. Gli allegati si aggiungono dalla scheda della singola voce.</p>
    <div class="d-flex gap-2 flex-wrap"><label class="visually-hidden" for="csvfile">File CSV</label><input class="form-control" style="max-width:420px;" type="file" id="csvfile" name="csv" accept=".csv,text/csv" required><button class="btn btn-dark fw-bold" type="submit">Importa</button></div></form></div>

<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0" id="tabVoci">
    <thead class="table-dark"><tr><th class="ps-3">Voce</th><th>Usi</th><th>Utile</th><th>Stato</th><th class="text-end pe-3">Azioni</th></tr></thead><tbody>
    <?php if (!$elenco_v): ?><tr><td colspan="5" class="text-center text-muted py-5">Nessuna voce<?php echo $arg_filtro || $cerca_d ? ' con questo filtro' : ''; ?>.</td></tr><?php endif; ?>
    <?php foreach ($elenco_v as $v): $n_dom = count(Motore::righe((string)$v['domande'])); $n_l = count(VociRepository::link($v)); $n_f = count(VociRepository::allegati($v)); ?>
        <tr class="<?php echo (int)$v['attiva'] ? '' : 'table-secondary'; ?>">
            <td class="ps-3"><span class="badge bg-dark fw-normal"><?php echo $h(CostD::nomeArgomento((string)$v['argomento'])); ?></span> <strong><?php echo $h($v['titolo']); ?></strong>
                <div class="small text-secondary"><?php echo $n_dom; ?> domande<?php echo $n_l ? " · $n_l link" : ''; ?><?php echo $n_f ? " · $n_f allegati" : ''; ?><?php echo (int)$v['solo_login'] ? ' · solo con accesso' : ''; ?><?php echo (int)$v['proponi_operatore'] ? ' · propone operatore' : ''; ?></div></td>
            <td><?php echo (int)$v['usi']; ?></td>
            <td><?php $tot_e = (int)$v['utile'] + (int)$v['non_utile']; echo $tot_e ? '<span class="text-success">' . (int)$v['utile'] . ' 👍</span> <span class="text-danger">' . (int)$v['non_utile'] . ' 👎</span>' : '<span class="text-muted">—</span>'; ?></td>
            <td><?php echo (int)$v['attiva'] ? '<span class="badge bg-success">Attiva</span>' : '<span class="badge bg-secondary">Spenta</span>'; ?></td>
            <td class="text-end pe-3 text-nowrap">
                <a class="btn btn-outline-dark btn-sm fw-bold" href="<?php echo $h($url_scheda('voci', '&voce=' . (int)$v['id'])); ?>"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica</a>
                <form method="POST" action="<?php echo $h($url_scheda('voci')); ?>" class="d-inline m-0"><?php csrf_field(); ?><input type="hidden" name="azione" value="attiva_voce"><input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>"><input type="hidden" name="argomento_filtro" value="<?php echo $h($arg_filtro); ?>">
                    <button class="btn btn-outline-secondary btn-sm" type="submit" title="<?php echo (int)$v['attiva'] ? 'Spegni: Didacticus non la usa più' : 'Riattiva'; ?>"><i class="fa <?php echo (int)$v['attiva'] ? 'fa-toggle-on' : 'fa-toggle-off'; ?>" aria-hidden="true"></i><span class="visually-hidden"><?php echo (int)$v['attiva'] ? 'Spegni' : 'Attiva'; ?></span></button></form>
                <form method="POST" action="<?php echo $h($url_scheda('voci')); ?>" class="d-inline m-0"><?php csrf_field(); ?><input type="hidden" name="azione" value="elimina_voce"><input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>"><input type="hidden" name="argomento_filtro" value="<?php echo $h($arg_filtro); ?>">
                    <button class="btn btn-outline-danger btn-sm" type="submit" data-confirm="Eliminare la voce «<?php echo $h($v['titolo']); ?>»? Non si può annullare (se vuoi solo metterla da parte, spegnila)." title="Elimina"><i class="fa fa-trash" aria-hidden="true"></i><span class="visually-hidden">Elimina</span></button></form>
            </td></tr>
    <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<?php elseif ($scheda === 'mancate'):
    $archivio = ($_GET['elenco'] ?? '') === 'archivio';
    $mancate = $manc_rep->elenco($archivio ? MancateRepository::IGNORATA : MancateRepository::DA_VEDERE);
?>
<p class="text-secondary">Le domande a cui Didacticus non ha saputo rispondere o la cui risposta non è bastata. <strong>Trasformale in voci</strong> e la prossima volta saprà rispondere: se un operatore aveva già risposto, la sua risposta è la bozza della voce.</p>
<div class="mb-2"><a class="btn btn-sm <?php echo !$archivio ? 'btn-dark' : 'btn-outline-dark'; ?>" href="<?php echo $h($url_scheda('mancate')); ?>">Da vedere (<?php echo $conteggio_mancate; ?>)</a> <a class="btn btn-sm <?php echo $archivio ? 'btn-dark' : 'btn-outline-dark'; ?>" href="<?php echo $h($url_scheda('mancate', '&elenco=archivio')); ?>">Messe da parte</a></div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-dark"><tr><th class="ps-3">Domanda</th><th>Cosa è successo</th><th>Quando</th><th class="text-end pe-3">Azioni</th></tr></thead><tbody>
    <?php if (!$mancate): ?><tr><td colspan="4" class="text-center text-muted py-5"><i class="fa fa-check-double fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i><?php echo $archivio ? 'Nessuna domanda messa da parte.' : 'Nessuna domanda da vedere: Didacticus sta rispondendo a tutto.'; ?></td></tr><?php endif; ?>
    <?php foreach ($mancate as $m): ?>
        <tr><td class="ps-3 did-testo" style="max-width:420px;"><span class="badge bg-light text-dark border fw-normal"><?php echo $h(CostD::nomeArgomento((string)$m['argomento'])); ?></span><div><?php echo $h($m['domanda']); ?></div></td>
            <td class="small"><?php echo $m['tipo'] === MancateRepository::NON_UTILE ? '<span class="badge bg-warning text-dark">Risposta non utile</span><div class="text-secondary">' . $h($m['voce_titolo'] ?: 'voce eliminata') . '</div>' : '<span class="badge bg-danger">Senza risposta</span>'; ?></td>
            <td class="small text-nowrap"><?php echo date('d/m/Y H:i', strtotime((string)$m['creato_il'])); ?></td>
            <td class="text-end pe-3 text-nowrap">
                <?php if (!$archivio): ?><a class="btn btn-danger btn-sm fw-bold" style="background-color:#B80000;border:none;" href="<?php echo $h($url_scheda('voci', '&voce=nuova&da_mancata=' . (int)$m['id'])); ?>"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Crea una voce</a><?php endif; ?>
                <?php if (!$archivio && $m['tipo'] === MancateRepository::NON_UTILE && $m['voce_id']): ?><a class="btn btn-outline-dark btn-sm" href="<?php echo $h($url_scheda('voci', '&voce=' . (int)$m['voce_id'])); ?>">Rivedi la voce</a><?php endif; ?>
                <form method="POST" action="<?php echo $h($url_scheda('mancate')); ?>" class="d-inline m-0"><?php csrf_field(); ?><input type="hidden" name="azione" value="mancata_segna"><input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>"><input type="hidden" name="stato" value="<?php echo $archivio ? MancateRepository::DA_VEDERE : MancateRepository::IGNORATA; ?>">
                    <button class="btn btn-outline-secondary btn-sm" type="submit"><?php echo $archivio ? 'Rimetti tra quelle da vedere' : 'Metti da parte'; ?></button></form></td></tr>
    <?php endforeach; ?></tbody></table></div></div>

<?php elseif ($scheda === 'prova'):
    $dom_prova = trim((string)($_GET['q'] ?? ''));
    $arg_prova = isset(CostD::ARGOMENTI[$_GET['argomento'] ?? '']) ? $_GET['argomento'] : '';
    $esito_prova = $dom_prova !== '' ? $did->prova($dom_prova, $arg_prova ?: null) : null;
    $nomi_tipo = ['risposta' => ['Risponderebbe con la voce migliore', 'success'], 'suggerimenti' => ['Non è sicuro: proporrebbe delle voci', 'warning'], 'nessuna' => ['Non saprebbe rispondere', 'danger'], 'operatore' => ['Passerebbe la parola a un operatore', 'info'], 'saluto' => ['Risponderebbe con il saluto', 'secondary']];
?>
<p class="text-secondary">Scrivi una domanda come la scriverebbe uno studente e guarda cosa risponderebbe Didacticus, con il punteggio di ogni voce. Sotto la soglia (<strong><?php echo $imp['soglia']; ?></strong>) non risponde. Se una domanda va male, aggiungi quella frase alle «Domande» della voce giusta, oppure una parola chiave.</p>
<form method="GET" class="card card-body border-0 shadow-sm mb-3"><input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="scheda" value="prova">
    <div class="row g-2"><div class="col-md-8"><label class="form-label fw-bold" for="pq">Domanda</label><input class="form-control" id="pq" name="q" maxlength="300" value="<?php echo $h($dom_prova); ?>" placeholder="Es. come faccio ad avere il certificato di laurea?" autofocus></div>
        <div class="col-md-3"><label class="form-label fw-bold" for="pa">Argomento scelto</label><select class="form-select" id="pa" name="argomento"><option value="">(nessuno)</option><?php foreach (CostD::ARGOMENTI as $k_a => $a_a): ?><option value="<?php echo $k_a; ?>" <?php echo $arg_prova === $k_a ? 'selected' : ''; ?>><?php echo $h($a_a['nome']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1 d-flex align-items-end"><button class="btn btn-dark fw-bold w-100" type="submit">Prova</button></div></div></form>
<?php if ($esito_prova): [$et_p, $col_p] = $nomi_tipo[$esito_prova['tipo']] ?? [$esito_prova['tipo'], 'secondary']; ?>
    <div class="alert alert-<?php echo $col_p; ?> fw-bold"><?php echo $h($et_p); ?></div>
    <?php if ($esito_prova['tipo'] === 'risposta'): ?><div class="card border-0 shadow-sm mb-3"><div class="card-body did-testo"><?php echo $h($esito_prova['testo']); ?></div></div><?php endif; ?>
    <div class="card border-0 shadow-sm"><div class="card-header fw-bold bg-white">Le voci più vicine</div><table class="table mb-0 align-middle"><thead><tr><th class="ps-3">Voce</th><th style="width:30%">Punteggio</th><th>Parole riconosciute</th></tr></thead><tbody>
        <?php if (!$esito_prova['classifica']): ?><tr><td colspan="3" class="text-muted text-center py-4">Nessuna voce ha parole in comune con questa domanda.</td></tr><?php endif; ?>
        <?php foreach ($esito_prova['classifica'] as $c_p): ?>
            <tr><td class="ps-3"><a href="<?php echo $h($url_scheda('voci', '&voce=' . $c_p['id'])); ?>"><?php echo $h($c_p['titolo']); ?></a></td>
                <td><div class="progress" role="progressbar" aria-valuenow="<?php echo $c_p['punteggio']; ?>" aria-valuemin="0" aria-valuemax="100" style="height:18px;"><div class="progress-bar <?php echo $c_p['punteggio'] >= $esito_prova['soglia'] ? 'bg-success' : 'bg-secondary'; ?>" style="width:<?php echo (int)$c_p['punteggio']; ?>%"><?php echo (int)$c_p['punteggio']; ?></div></div></td>
                <td class="small text-secondary"><?php echo $h(implode(', ', $c_p['corrispondenze'])); ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<?php else: // impostazioni ?>
<form method="POST" enctype="multipart/form-data" action="<?php echo $h($url_scheda('impostazioni')); ?>" class="card border-0 shadow-sm" style="max-width:820px;"><div class="card-body">
    <?php csrf_field(); ?><input type="hidden" name="azione" value="salva_impostazioni">
    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" id="i-att" name="attivo" value="1" <?php echo $imp['attivo'] ? 'checked' : ''; ?>><label class="form-check-label fw-bold" for="i-att">Didacticus acceso</label>
        <div class="form-text">Acceso: le nuove chat cominciano con lui. Spento: le chat vanno subito agli operatori (come prima). Le chat già aperte con Didacticus continuano.</div></div>
    <div class="mb-3"><label class="form-label fw-bold" for="i-sal">Saluto (il primo messaggio di Didacticus)</label><textarea class="form-control" id="i-sal" name="saluto" rows="4" maxlength="1000"><?php echo $h($imp['saluto']); ?></textarea>
        <div class="form-text">Lo vede chi scrive «ciao» o apre la chat senza una domanda precisa.</div></div>
    <div class="mb-3"><label class="form-label fw-bold" for="i-sog">Soglia di sicurezza: <output id="i-sog-v"><?php echo (int)$imp['soglia']; ?></output></label>
        <input type="range" class="form-range" id="i-sog" name="soglia" min="<?php echo ImpostazioniDidacticus::SOGLIA_MIN; ?>" max="<?php echo ImpostazioniDidacticus::SOGLIA_MAX; ?>" value="<?php echo (int)$imp['soglia']; ?>" oninput="document.getElementById('i-sog-v').textContent=this.value">
        <div class="form-text">Quanto deve somigliare la domanda a una voce perché Didacticus risponda. <strong>Più alta = più prudente</strong> (risponde meno, passa agli operatori più spesso); più bassa = risponde anche con domande vaghe, ma può sbagliare voce. Valore consigliato: <?php echo ImpostazioniDidacticus::SOGLIA_PREDEFINITA; ?>. Prova gli effetti nella scheda «Prova».</div></div>
    <div class="mb-3"><div class="fw-bold mb-1">Immagine di profilo</div>
        <div class="d-flex align-items-center gap-3 flex-wrap"><img src="../didacticus_file.php?avatar=1&amp;<?php echo time(); ?>" alt="Immagine attuale di Didacticus" width="96" height="96" class="rounded-circle shadow-sm" style="object-fit:cover;">
            <div><label class="form-label small mb-1" for="i-av">Carica un'altra immagine (PNG, JPG o WEBP, quadrata, al massimo 2 MB)</label><input class="form-control form-control-sm" type="file" id="i-av" name="avatar" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                <?php if ($imp['avatar'] !== ''): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="avatar_ripristina" value="1" id="i-avr"><label class="form-check-label small" for="i-avr">Torna al gufo di serie</label></div><?php endif; ?></div></div></div>
    <button class="btn btn-danger fw-bold px-4" type="submit" style="background-color:#B80000;border:none;"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva</button>
</div></form>
<?php endif; ?>
<?php require_once 'admin_footer.php'; ?>
