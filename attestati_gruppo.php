<?php
// attestati_gruppo.php - Attestati degli studenti di un'iscrizione a un progetto per le scuole:
// tutti in un'unica pagina (uno per foglio A4), con codice e QR di verifica. ?solo=CODICE per uno soltanto.
// Accesso: il docente che ha iscritto la scuola (dopo l'invio degli attestati) oppure amministratori/gestori.
require_once 'config.php';
require_once 'functions.php';
sync_sso_user($conn);

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{4,50}$/', $code)) { http_response_code(400); die("Codice non valido."); }
if (empty($_SESSION['utente_id'])) { header('Location: saml_login.php?redirect=' . urlencode('attestati_gruppo.php?code=' . $code)); exit; }

$r = $conn->query("SELECT id FROM prenotazioni WHERE codice_prenotazione = '" . $conn->real_escape_string($code) . "' LIMIT 1");
$pr_id = ($r && $row = $r->fetch_assoc()) ? (int)$row['id'] : 0;
$p = $pr_id ? prenotazione_per_attestati($conn, $pr_id) : null;
if (!$p || !puo_vedere_prenotazione($p)) { http_response_code(403); die("Accesso negato."); }

$ruolo = (int)($_SESSION['utente_ruolo_id'] ?? 5);
$sec = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
$is_staff = in_array($ruolo, [1, 2], true) || in_array('1', $sec, true) || in_array('2', $sec, true);

$msg = null;
if (!attestati_di_classe($p)) $msg = "Questa attività non prevede attestati per gli studenti.";
elseif ((int)$p['presente'] !== 1 || ($p['stato'] ?? '') !== 'confermata') $msg = "Gli attestati sono disponibili dopo la registrazione della presenza della classe.";
elseif (empty($p['attestato_inviato']) && !$is_staff) $msg = "Gli attestati non sono ancora stati emessi: riceverai un'email quando saranno pronti.";
if ($msg) die("<div style='text-align:center;font-family:sans-serif;margin-top:60px;'><h2 style='color:#b45309;'>Attestati non disponibili</h2><p>" . htmlspecialchars($msg) . "</p></div>");

assegna_codici_partecipanti($conn, $pr_id); // anteprima dello staff prima dell'invio: codici già definitivi
$solo = trim((string)($_GET['solo'] ?? ''));
$lista = [];
foreach (get_partecipanti_prenotazione($conn, $pr_id) as $s) {
    if (!empty($s['escluso'])) continue;
    if ($solo !== '' && $s['codice'] !== $solo) continue;
    $lista[] = dati_attestato($p, trim($s['nome'] . ' ' . $s['cognome']), (string)$s['codice'])
             + ['file' => 'attestato_' . slug_file($s['cognome'] . ' ' . $s['nome'])];
}
if (!$lista) die("<div style='text-align:center;font-family:sans-serif;margin-top:60px;'><h2>Nessun attestato</h2><p>L'elenco degli studenti è vuoto.</p></div>");

echo pagina_attestati($lista, 'Attestati - ' . $p['evento_titolo']);
