<?php
// simula.php - Sito pubblico › Entra come…: l'amministratore vede il portale come lo vede un'altra persona (studente, docente, scuola, azienda, Direttore…),
// per dare indicazioni precise su cosa si può fare. Solo lettura, un'ora al massimo, registrata. Logica in src/Auth/SimulazioneAccesso.php.
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

use App\Auth\SimulazioneAccesso;
use App\Portale\Area\Profili;
use App\Portale\Area\ProfiliPersona;

$app_s = \App\Core\App::per($conn);
$sim_s = $app_s->get(SimulazioneAccesso::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }
$db_s = \App\Core\Database::per($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $target_s = (int)($_POST['utente_id'] ?? 0);
    $riga_s = $app_s->get(\App\Auth\UtenteRepository::class)->perId($target_s);
    $profili_s = $riga_s ? implode(', ', array_map(fn($p) => Profili::NOMI[$p] ?? $p, $app_s->get(ProfiliPersona::class)->di($riga_s))) : '';
    $errore_s = $sim_s->avvia($target_s, $profili_s, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($errore_s === null) {
        admin_redirect('../area_personale.php');
    }
    flash_set($errore_s, 'warning');
    admin_redirect('simula.php');
}

$q_s = trim((string)($_GET['q'] ?? ''));
$trovati = [];
if (mb_strlen($q_s) >= 2) {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q_s)) . '%';
    $trovati = $db_s->righe(
        "SELECT * FROM utenti WHERE (LOWER(CONCAT(cognome, ' ', nome)) LIKE ? OR LOWER(CONCAT(nome, ' ', cognome)) LIKE ? OR LOWER(email) LIKE ?) ORDER BY cognome, nome LIMIT 30",
        [$like, $like, $like]
    );
}

// Una persona d'esempio per ogni profilo: si guardano gli ultimi accessi finché ogni profilo ne ha tre
$esempi = array_fill_keys(array_keys(Profili::NOMI), []);
$pp_s = $app_s->get(ProfiliPersona::class);
$recenti = $db_s->righe("SELECT * FROM utenti ORDER BY (ultimo_accesso IS NULL), ultimo_accesso DESC LIMIT 400");
foreach ($recenti as $u_s) {
    if (SimulazioneAccesso::eAmministratore($u_s) || (int)$u_s['id'] === (int)$u_id) continue;
    foreach ($pp_s->di($u_s) as $p_s) {
        if (count($esempi[$p_s]) < 3) $esempi[$p_s][] = $u_s;
    }
    if (!in_array(true, array_map(fn($e) => count($e) < 3, $esempi), true)) break;
}

$riga = function (array $u) use ($h, $pp_s): string {
    $profili = implode(', ', array_map(fn($p) => Profili::NOMI[$p] ?? $p, $pp_s->di($u)));
    $admin = SimulazioneAccesso::eAmministratore($u);
    $o = '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 border-bottom"><div><strong>' . $h(trim(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? ''))) . '</strong>'
        . ' <span class="text-secondary small">' . $h($u['email'] ?? '') . '</span><br><span class="small">' . $h($profili) . '</span></div>';
    if ($admin) {
        $o .= '<span class="small text-secondary">Amministratore: non si può simulare</span>';
    } else {
        $o .= '<form method="POST" class="m-0">' . (new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->campo() . '<input type="hidden" name="utente_id" value="' . (int)$u['id'] . '">'
            . '<button class="btn btn-sm btn-azione fw-bold"><i class="fa fa-user-secret me-1" aria-hidden="true"></i>Entra come</button></form>';
    }

    return $o . '</div>';
};
?>
<div class="mb-3">
    <h1 class="pn-titolo"><i class="fa fa-user-secret me-2" aria-hidden="true"></i>Entra come…</h1>
    <p class="pn-aiuto">Vedi il portale come lo vede un'altra persona, per dare indicazioni precise su cosa può fare (area personale, pratiche, prenotazioni…).
        È <strong>solo una visione</strong>: non si può modificare, inviare o prenotare niente a nome suo. Dura al massimo un'ora; per uscire premi «Torna a te» nella barra in fondo alla pagina.
        Ogni ingresso resta nel registro delle operazioni. Un altro amministratore non si può simulare.</p>
</div>

<form method="GET" class="row g-2 mb-4" style="max-width:640px;">
    <div class="col"><label class="form-label fw-bold" for="q-s">Cerca una persona (cognome, nome o email)</label><input id="q-s" type="search" name="q" value="<?php echo $h($q_s); ?>" class="form-control" minlength="2" placeholder="per esempio rossi"></div>
    <div class="col-auto d-flex align-items-end"><button class="btn btn-azione fw-bold"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Cerca</button></div>
</form>

<?php if ($q_s !== ''): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <h2 class="h6 fw-bold">Risultati per «<?php echo $h($q_s); ?>»</h2>
    <?php if (!$trovati): ?><p class="text-secondary mb-0">Nessuna persona trovata.</p><?php endif; ?>
    <?php foreach ($trovati as $u_s) echo $riga($u_s); ?>
</div></div>
<?php endif; ?>

<h2 class="h5 fw-bold mb-2">Una persona per ogni profilo</h2>
<p class="small text-secondary">Sono persone vere, scelte tra chi ha acceduto di recente: per un profilo senza nessuno non c'è un esempio. Se serve un caso preciso (una scuola, un'azienda) cercalo per nome qui sopra.</p>
<div class="row g-3">
<?php foreach (Profili::NOMI as $k => $nome_p): ?>
    <div class="col-12 col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h3 class="h6 fw-bold"><i class="fa <?php echo Profili::ICONE[$k]; ?> me-1" aria-hidden="true"></i><?php echo $h($nome_p); ?></h3>
        <?php if (!$esempi[$k]): ?><p class="small text-secondary mb-0"><?php echo $k === Profili::AMMINISTRATORE ? 'Gli amministratori non si simulano: è il tuo profilo.' : 'Nessuna persona di questo profilo tra gli ultimi accessi.'; ?></p><?php endif; ?>
        <?php foreach ($esempi[$k] as $u_s) echo $riga($u_s); ?>
    </div></div></div>
<?php endforeach; ?>
</div>
<?php require_once 'admin_footer.php'; ?>
