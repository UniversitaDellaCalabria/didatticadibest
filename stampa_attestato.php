<?php
// stampa_attestato.php - Attestato personale (PDF da stampare), con codice e QR di verifica.
// Eventi: dopo il check-in. Progetti: solo se prevedono attestati e a progetto concluso; nei progetti
// per le scuole gli attestati sono quelli degli studenti (attestati_gruppo.php).
require_once 'config.php'; // apre la sessione con i parametri sicuri del cookie
require_once 'functions.php';

sync_sso_user($conn);

$code = trim($_GET['code'] ?? '');
if (empty($code)) { die("Codice di sicurezza non valido."); }

$base = get_attestato($conn, $code);
if (!$base) { die("Nessun dato trovato per questo codice."); }
$p = prenotazione_per_attestati($conn, (int)$base['id']);

$avviso = fn($titolo, $testo) => die("<div style='text-align:center; font-family:sans-serif; margin-top:50px;'><h2 style='color:#dc3545;'>" . htmlspecialchars($titolo) . "</h2><p>" . $testo . "</p></div>");

// CONTROLLO DI SICUREZZA
if ((int)$p['presente'] !== 1) {
    $avviso("Attestato non disponibile", "Questo attestato viene generato solo per gli utenti che hanno fisicamente partecipato all'evento (check-in effettuato).");
}
if (!puo_vedere_prenotazione($p)) { die("Accesso negato. Non sei autorizzato a visualizzare questo attestato."); }

// Regole dei progetti
$regola = regola_attestato_evento($conn, (int)$p['evento_id']);
if ($regola === 'no') $avviso("Attestato non previsto", "Questo progetto non prevede attestati di partecipazione.");
if ($regola === 'attendi') $avviso("Attestato non ancora disponibile", "L'attestato sarà disponibile al termine del progetto.");
if ($regola === 'gruppo') {
    header('Location: attestati_gruppo.php?code=' . urlencode($code));
    exit;
}

$dati = dati_attestato($p, trim($p['nome'] . ' ' . $p['cognome']), (string)$p['codice_prenotazione'], (string)($base['matricola_effettiva'] ?? ''));
echo pagina_attestati([$dati], 'Attestato - ' . $p['nome'] . ' ' . $p['cognome']);
