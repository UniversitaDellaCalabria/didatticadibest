<?php
// Prove della comunicazione con la scuola sulla convenzione online (App\Fsl\ServizioMessaggiConvenzione): promemoria «carica il PDF»,
// messaggi dell'ufficio, risposte della scuola. Chiamato da esegui.php (variabili $conn, $q, $EMAIL e funzioni prova, sezione). Compilazioni di prova con id 987xx.
sezione("Modulo FSL: messaggi tra ufficio e scuola sulla convenzione");

use App\Core\App as AppMsg;
use App\Fsl\MessaggiConvenzioneRepository as MsgRep;
use App\Fsl\ServizioMessaggiConvenzione as MsgServ;

foreach (["DELETE FROM convenzioni_messaggi WHERE compilazione_id BETWEEN 98771 AND 98773", "DELETE FROM convenzioni_compilate WHERE id BETWEEN 98771 AND 98773"] as $sql) $q($sql);
$dati_msg = json_encode(['scuola' => ['denominazione' => 'IIS Prova Messaggi', 'email' => 'scuola.msg@example.org']]);
$q("INSERT INTO convenzioni_compilate (id, token, email, dati_json, aggiornata_il, stato_iter) VALUES
    (98771, 'tokmsg98771', 'scuola.msg@example.org', '" . $conn->real_escape_string($dati_msg) . "', NOW(), ''),
    (98772, 'tokmsg98772', 'scuola.msg@example.org', '" . $conn->real_escape_string($dati_msg) . "', NOW(), 'caricata_scuola'),
    (98773, 'tokmsg98773', '', '" . $conn->real_escape_string(json_encode(['scuola' => ['denominazione' => 'Senza email']])) . "', NOW(), '')");
$q("UPDATE convenzioni_compilate SET file_firmato = 'x.pdf' WHERE id = 98772");
$ms = AppMsg::per($conn)->get(MsgServ::class);
$mr = AppMsg::per($conn)->get(MsgRep::class);
$cc_msg = fn(int $id) => $conn->query("SELECT * FROM convenzioni_compilate WHERE id = $id")->fetch_assoc();

prova(MsgServ::attendeIlPdf($cc_msg(98771)) && !MsgServ::attendeIlPdf($cc_msg(98772)), "il promemoria vale solo se la scuola non ha caricato il PDF");
prova(MsgServ::emailScuola($cc_msg(98771)) === 'scuola.msg@example.org' && MsgServ::emailScuola($cc_msg(98773)) === '', "email della scuola: quella del modulo, vuota se manca");

// Promemoria
$EMAIL = [];
prova($ms->ricordaPdf(98771, 'Marta Ufficio') === null && count($EMAIL) === 1 && $EMAIL[0]['a'] === 'scuola.msg@example.org' && str_contains($EMAIL[0]['oggetto'], 'Promemoria') && str_contains($EMAIL[0]['corpo'], 'tokmsg98771'), "promemoria: email alla scuola con il link personale");
$ult = $mr->della(98771);
prova(count($ult) === 1 && $ult[0]['da'] === 'ufficio' && $ult[0]['tipo'] === 'promemoria' && $ult[0]['autore'] === 'Marta Ufficio', "promemoria: registrato nella conversazione");
$EMAIL = [];
$er = $ms->ricordaPdf(98771, 'Marta Ufficio');
prova($er !== null && str_contains($er, 'già partito') && !$EMAIL, "promemoria: non si rimanda prima di 24 ore");
prova($ms->ricordaPdf(98772, 'x') !== null && $ms->ricordaPdf(98773, 'x') !== null && $ms->ricordaPdf(1, 'x') !== null, "promemoria: no se il PDF c'è già, no senza email");
$q("UPDATE convenzioni_messaggi SET creato_il = NOW() - INTERVAL 2 DAY WHERE compilazione_id = 98771");
$EMAIL = [];
prova($ms->ricordaPdf(98771, 'Marta Ufficio') === null && count($EMAIL) === 1, "promemoria: dopo 24 ore si può rimandare");
$q("UPDATE convenzioni_compilate SET stato_iter = 'rifiutata', nota_ufficio = 'Firma mancante' WHERE id = 98771");
$q("UPDATE convenzioni_messaggi SET creato_il = NOW() - INTERVAL 5 DAY WHERE compilazione_id = 98771");
$EMAIL = [];
prova(MsgServ::attendeIlPdf($cc_msg(98771)) && $ms->ricordaPdf(98771, 'Marta') === null && str_contains($EMAIL[0]['corpo'], 'Firma mancante') && str_contains($EMAIL[0]['corpo'], 'non è stata accettata'), "promemoria dopo il rifiuto: ripete il motivo");
$q("UPDATE convenzioni_compilate SET stato_iter = '', nota_ufficio = NULL WHERE id = 98771");

// Messaggio libero dell'ufficio
$EMAIL = [];
prova($ms->scriviAllaScuola(98771, "  ", 'Marta') === 'Scrivi il messaggio.' && $ms->scriviAllaScuola(98771, str_repeat('a', 1600), 'Marta') !== null, "messaggio: vuoto o troppo lungo rifiutato");
prova($ms->scriviAllaScuola(98771, "Manca la firma del <Dirigente>.\nGrazie", 'Marta') === null && count($EMAIL) === 1 && str_contains($EMAIL[0]['corpo'], '&lt;Dirigente&gt;') && str_contains($EMAIL[0]['corpo'], '<br'), "messaggio: email con il testo (protetto) e il link per rispondere");
prova($ms->scriviAllaScuola(98773, 'ciao', 'Marta') !== null, "messaggio: senza email della scuola non parte");

// Risposta della scuola e lettura
$EMAIL = [];
prova($ms->rispondi($cc_msg(98771), 'Ora carico il file giusto') === null && $EMAIL && str_contains($EMAIL[0]['oggetto'], 'Messaggio della scuola'), "risposta della scuola: avvisa chi gestisce le convenzioni");
prova(($mr->nonLetteDallUfficio()[98771] ?? 0) === 1, "risposta della scuola: da leggere per l'ufficio");
$mr->segnaLetti(98771, 'ufficio');
prova(!isset($mr->nonLetteDallUfficio()[98771]), "l'ufficio segna come letto");
$mr->segnaLetti(98771, 'scuola');
prova(array_filter($mr->della(98771), fn($m) => $m['da'] === 'ufficio' && $m['letto_il'] === null) === [], "la scuola che apre la pagina segna letti i messaggi dell'ufficio");
for ($i = 0; $i < MsgServ::MAX_RISPOSTE_AL_GIORNO; $i++) $ms->rispondi($cc_msg(98771), "prova $i");
prova($ms->rispondi($cc_msg(98771), 'ancora') !== null, "risposte della scuola: massimo al giorno");

// Promemoria a tutte
$q("DELETE FROM convenzioni_messaggi WHERE compilazione_id IN (98771, 98772, 98773)");
$EMAIL = [];
[$partiti, $saltati] = $ms->ricordaATutte('Marta');
prova($partiti >= 1 && $mr->ultimoDelTipo(98771, 'promemoria') !== null && $mr->ultimoDelTipo(98772, 'promemoria') === null, "promemoria a tutte: parte solo per chi aspetta il PDF");
[$p2] = $ms->ricordaATutte('Marta');
prova($p2 === 0, "promemoria a tutte: una seconda volta nello stesso giorno non rimanda");

foreach (["DELETE FROM convenzioni_messaggi WHERE compilazione_id BETWEEN 98771 AND 98773", "DELETE FROM convenzioni_compilate WHERE id BETWEEN 98771 AND 98773"] as $sql) $q($sql);
