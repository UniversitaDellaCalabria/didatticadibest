<?php
// =========================================================================
// Scenario di dimostrazione dell'assistenza e di Didacticus: un'operatrice nell'ufficio «Assistenza», due studenti e chat in ogni stato (con Didacticus,
// da prendere, in carico, risolta da Didacticus, chiusa e valutata), domande senza risposta e numeri d'uso delle voci.
//   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/scenario_assistenza.php
// Cancella le chat di assistenza e le domande senza risposta esistenti. Le email non partono (si salvano in cache/email_locali/).
// Scrive in cache/scenario_assistenza.json gli id utili alle schermate (strumenti/locale/schermate_assistenza.php).
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
chdir(realpath(__DIR__ . '/../..'));
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SESSION = [];
define('AMBIENTE_LOCALE_CLI', 1);
$e = parse_ini_file('.env.locale');
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($e['DB_HOST'], $e['DB_USER'], $e['DB_PASS'], $e['DB_NAME']);
$conn->set_charset('utf8mb4');
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) {
    $dir = 'cache/email_locali/';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . date('Ymd_His') . '_' . substr(md5($subject . $to . microtime()), 0, 6) . '_' . preg_replace('/[^A-Za-z0-9@._-]/', '_', (string) $to) . '.html',
        '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>' . htmlspecialchars((string) $subject) . '</title></head><body><p><b>A:</b> ' . htmlspecialchars((string) $to) . '<br><b>Oggetto:</b> ' . htmlspecialchars((string) $subject) . '</p>' . $body_html . '</body></html>');

    return true;
}
require 'functions.php';

$c = \App\Core\App::per($conn);
$conn->query('UPDATE impostazioni_sistema SET didacticus_attivo = 1, didacticus_soglia = 60 WHERE id = 1');
$conn->query('DELETE FROM didacticus_mancate');
$conn->query('DELETE FROM assistenza_messaggi');
$conn->query('DELETE FROM assistenza_conversazioni');
$conn->query('UPDATE didacticus_voci SET usi = 0, utile = 0, non_utile = 0');
$persone = [['Olga', 'Operatrice', 'olga.operatrice@example.org', 5], ['Sara', 'Studente', 'sara.studente@studenti.example.org', 3], ['Luca', 'Bianchi', 'luca.bianchi@studenti.example.org', 3], ['Anna', 'Neri', 'anna.neri@studenti.example.org', 3]];
foreach ($persone as [$n, $cg, $em, $ruolo]) {
    if (!$conn->query("SELECT id FROM utenti WHERE email = '$em'")->fetch_row()) {
        $conn->query("INSERT INTO utenti (nome, cognome, email, ruolo_id, ruoli_secondari) VALUES ('$n', '$cg', '$em', $ruolo, '')");
    }
}
$conn->query("INSERT IGNORE INTO uffici_persone (ufficio_id, email, nominativo, ruolo, compiti) SELECT id, 'olga.operatrice@example.org', 'Olga Operatrice', '', '' FROM uffici WHERE chiave = 'assistenza'");
$utente = fn(string $email) => $conn->query("SELECT * FROM utenti WHERE email = '$email'")->fetch_assoc();
$olga = (int) $utente('olga.operatrice@example.org')['id'];
$sara = $utente('sara.studente@studenti.example.org');
$luca = $utente('luca.bianchi@studenti.example.org');
$anna = $utente('anna.neri@studenti.example.org');

$orologio = new class implements \App\Core\Orologio {
    public DateTimeImmutable $t;
    public function adesso(): DateTimeImmutable { return $this->t; }
};
$srv = new \App\Assistenza\ServizioAssistenza($c->get(\App\Assistenza\ConversazioniRepository::class), $c->get(\App\Assistenza\MessaggiAssistenzaRepository::class),
    $c->get(\App\Assistenza\OperatoriAssistenza::class), $c->get(\App\Assistenza\NotificheAssistenza::class), new \App\Assistenza\OrariAssistenza(\App\Core\Database::per($conn), $orologio), $orologio,
    $c->get(\App\Assistenza\Didacticus\ServizioDidacticus::class), $c->get(\App\Assistenza\Didacticus\MancateRepository::class));
$op = ['id' => $olga, 'nome' => 'Olga Operatrice'];
$ora = fn(string $m) => $orologio->t = new DateTimeImmutable($m);
$ids = ['operatrice' => $olga, 'studentessa' => (int) $sara['id']];

// 1. Chiusa e valutata (due giorni fa): un'esterna, direttamente con un operatore
$ora('-2 days');
[$cc] = $srv->apriDaEsterno('Paola Neri', 'paola.neri@example.org', 'eventi', "Quando c'è il prossimo Openlab?", '10.0.0.3', null, true);
$ora('-2 days +5 minutes');
$srv->rispondi($srv->perId((int) $cc['id']), $op, 'Il prossimo Openlab è il 12 novembre: puoi iscriverti dalla pagina Eventi.');
$srv->chiudi($srv->perId((int) $cc['id']), 'operatore');
$srv->valuta($srv->perId((int) $cc['id']), true);

// 2. Risolta da Didacticus (ieri)
$ora('-1 day');
[$cr] = $srv->apriDaUtente($luca, 'pratiche', 'Come faccio a vedere a che punto è la mia pratica?', null, '10.0.0.4');
$ora('-1 day +1 minute');
$srv->azioneBot($srv->perId((int) $cr['id']), 'utile');

// 3. In carico a Olga (tre ore fa): passata da Didacticus perché la risposta non bastava
$ora('-3 hours');
[$cs] = $srv->apriDaUtente($anna, 'certificati', 'Ho bisogno di un certificato di iscrizione con marca da bollo', ['tipo' => 'pratica', 'id' => 1, 'testo' => 'la pratica «Certificato di iscrizione»'], '10.0.0.2');
$ora('-3 hours +2 minutes');
$srv->azioneBot($srv->perId((int) $cs['id']), 'non_utile');
$ora('-2 hours -50 minutes');
$srv->rispondi($srv->perId((int) $cs['id']), $op, "Ciao Anna! La marca da bollo la assolvi dal passo indicato nella pratica; se sei esente, scrivimi il motivo e lo verifico con la segreteria studenti.");
$ora('-2 hours -45 minutes');
$srv->scriviComeUtente($srv->perId((int) $cs['id']), 'Sono esente per merito, come faccio?');
$ora('-2 hours -44 minutes');
$srv->nota($srv->perId((int) $cs['id']), $op, "Verificare con la segreteria studenti l'esenzione per merito (voce da aggiungere a Didacticus).");

// 4. Da prendere (40 minuti fa): un'esterna, Didacticus non sapeva rispondere
$ora('-40 minutes');
[$cn] = $srv->apriDaEsterno('Giulia Verdi', 'giulia.verdi@example.org', 'altro', "Salve, sono la responsabile di un'azienda: quanto costa il parcheggio per gli ospiti del Dipartimento?", '10.0.0.1');
$ora('-39 minutes');
$srv->scriviComeUtente($srv->perId((int) $cn['id']), 'E ci sono posti riservati per i tirocinanti?');

// 5. Con Didacticus adesso (poco fa): la studentessa Sara, con la risposta e i pulsanti
$ora('-8 minutes');
[$cb] = $srv->apriDaUtente($sara, 'certificati', 'Come richiedo il certificato di iscrizione?', null, '10.0.0.5');

// Domande senza risposta in più, e numeri d'uso delle voci (per la scheda di Didacticus)
$m = $c->get(\App\Assistenza\Didacticus\MancateRepository::class);
$m->registra((int) $cc['id'], 'Dove trovo gli orari dei pullman per il campus?', 'altro', 'nessuna', null, date('Y-m-d H:i:s', strtotime('-5 hours')));
$m->registra((int) $cc['id'], 'Posso pagare la tassa in contanti?', 'altro', 'nessuna', null, date('Y-m-d H:i:s', strtotime('-26 hours')));
foreach ([['Come richiedo un certificato%', 34, 27, 3], ['Dove vedo a che punto%', 21, 15, 2], ['Come mi iscrivo a un evento%', 18, 14, 1], ['Posso modificare una pratica%', 12, 9, 2], ['Come accedo al portale%', 9, 6, 1], ['Marca da bollo%', 7, 3, 3]] as [$titolo, $usi, $utile, $non]) {
    $conn->query("UPDATE didacticus_voci SET usi = usi + $usi, utile = utile + $utile, non_utile = non_utile + $non WHERE titolo LIKE '" . $conn->real_escape_string($titolo) . "'");
}

$ids += ['chiusa' => (int) $cc['id'], 'risolta' => (int) $cr['id'], 'in_carico' => (int) $cs['id'], 'da_prendere' => (int) $cn['id'], 'con_didacticus' => (int) $cb['id'], 'token_da_prendere' => $cn['token'], 'token_chiusa' => $cc['token']];
file_put_contents('cache/scenario_assistenza.json', json_encode($ids));
echo "Fatto. Operatrice id $olga, Sara id {$sara['id']}.\n" . json_encode($ids) . "\n";
