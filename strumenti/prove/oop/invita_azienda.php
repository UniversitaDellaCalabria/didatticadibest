<?php
// Prove di «Invita l'azienda» dalla pratica di tesi (InvitoAziendaDaPratica): controlli, collegamento alla pratica, avanzamento e avviso a convenzione stipulata.
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione). Dati di prova con id 9896xx.
sezione("Tesi: lo studente invita l'azienda dalla pratica");

use App\ConvenzioniTesi\AziendaDellaPratica;
use App\ConvenzioniTesi\Costanti;
use App\ConvenzioniTesi\InvitoAziendaDaPratica;

$pulisci_ia = ["DELETE FROM pratiche WHERE id IN (989601, 989602)", "DELETE FROM convenzioni_tesi WHERE email LIKE '%9896@esempio.invalid'", "DELETE FROM didattica_moduli WHERE id = 989600", "DELETE FROM utenti WHERE id IN (989600, 989603)"];
foreach ($pulisci_ia as $sql) $q($sql);
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (989600, 'PROVA96AA0000A00', 'Sara', 'Tesista', 'sara9896@prova.it', 3), (989603, 'PROVA96BB0000B00', 'Altro', 'Utente', 'altro9896@prova.it', 3)");
$q("INSERT INTO didattica_moduli (id, titolo, chiave, attivo) VALUES (989600, 'Tesi prova 9896', 'prova_ia_9896', 1)");
$risp_ia = json_encode([
    ['etichetta' => 'Azienda o ente', 'tipo' => 'azienda_tesi', 'valore' => Costanti::ALTRA_AZIENDA],
    ['etichetta' => 'Nome dell\'azienda o ente (non convenzionata)', 'tipo' => 'text', 'valore' => 'Bio Nuova 9896 Srl'],
]);
$q("INSERT INTO pratiche (id, modulo_id, utente_id, codice, nome, cognome, email, risposte_json, stato) VALUES (989601, 989600, 989600, 'IA-1', 'Sara', 'Tesista', 'sara9896@prova.it', '" . $conn->real_escape_string($risp_ia) . "', 'inviata'),
    (989602, 989600, 989600, 'IA-2', 'Sara', 'Tesista', 'sara9896@prova.it', '" . $conn->real_escape_string($risp_ia) . "', 'bozza')");

$srv_ia = \App\Core\App::per($conn)->get(InvitoAziendaDaPratica::class);
$az_ia = \App\Core\App::per($conn)->get(AziendaDellaPratica::class);
$sara = ['id' => 989600, 'nome' => 'Sara', 'cognome' => 'Tesista', 'email' => 'sara9896@prova.it'];
$ris_ia = json_decode($risp_ia, true);

$f_ia = $az_ia->riquadro($ris_ia, false, 989601);
prova(str_contains($f_ia, 'Azienda non ancora convenzionata') && str_contains($f_ia, 'action="invita_azienda.php"') && str_contains($f_ia, 'name="pratica" value="989601"') && str_contains($f_ia, 'value="Bio Nuova 9896 Srl"'), "riquadro dello studente: azienda da convenzionare, c'è il modulo «Invita l'azienda» col nome già scritto");
prova(!str_contains($az_ia->riquadro($ris_ia, true, 989601), 'invita_azienda.php') && !str_contains($az_ia->riquadro($ris_ia, false), 'invita_azienda.php'), "riquadro: senza lo studente (ufficio, o senza pratica) niente modulo");

prova($srv_ia->invita([], 989601, 'X', 'a@b.it') !== null && $srv_ia->invita(['id' => 989603], 989601, 'X', 'azienda9896@esempio.invalid') !== null, "invito: senza accesso o su una pratica di un altro studente, rifiutato");
prova($srv_ia->invita($sara, 989602, 'X', 'azienda9896@esempio.invalid') !== null, "invito: una bozza non si può usare (si invia prima la richiesta)");
prova($srv_ia->invita($sara, 989601, '', 'azienda9896@esempio.invalid') !== null && $srv_ia->invita($sara, 989601, 'Bio Nuova', 'non-una-email') !== null, "invito: servono il nome e un'email valida");
prova((int) $conn->query("SELECT COUNT(*) FROM convenzioni_tesi WHERE email LIKE '%9896@esempio.invalid'")->fetch_row()[0] === 0, "invito: dopo i rifiuti non è stata creata nessuna convenzione");

$EMAIL = [];
prova($srv_ia->invita($sara, 989601, 'Bio Nuova 9896 Srl', 'Azienda9896@esempio.invalid') === null, "invito: partito");
$cv_ia = $conn->query("SELECT * FROM convenzioni_tesi WHERE email = 'azienda9896@esempio.invalid'")->fetch_assoc();
prova($cv_ia && $cv_ia['origine'] === 'studente' && $cv_ia['stato'] === Costanti::INVITATA && $cv_ia['invitata_da'] === 'Sara Tesista', "invito: convenzione creata con origine «studente» e il nome dello studente", json_encode($cv_ia));
prova(count($EMAIL) === 1 && $EMAIL[0]['a'] === 'azienda9896@esempio.invalid', "invito: l'azienda riceve il link per compilare la convenzione", json_encode(array_column($EMAIL, 'a')));
prova((int) $conn->query("SELECT convenzione_tesi_id FROM pratiche WHERE id = 989601")->fetch_row()[0] === (int) $cv_ia['id'] && (int) ($srv_ia->collegata(989601)['id'] ?? 0) === (int) $cv_ia['id'], "invito: la convenzione resta collegata alla pratica");
prova(str_contains((string) $srv_ia->invita($sara, 989601, 'Altra', 'altra9896@esempio.invalid'), 'già invitato'), "invito: una sola convenzione aperta per pratica");

$f_ia = $az_ia->riquadro($ris_ia, false, 989601);
prova(str_contains($f_ia, 'Convenzione in corso') && !str_contains($f_ia, 'invita_azienda.php'), "riquadro dello studente dopo l'invito: l'avanzamento, non più il modulo", strip_tags($f_ia));
prova($az_ia->stato(Costanti::ALTRA_AZIENDA, 'Bio Nuova 9896 Srl', 989601)['esito'] === 'in_corso' && $az_ia->stato(Costanti::ALTRA_AZIENDA, 'Altro nome ancora', 989601)['esito'] === 'in_corso', "stato: vale il collegamento, non la ricerca per nome");

// Convenzione stipulata → avviso allo studente
$EMAIL = [];
$q("UPDATE convenzioni_tesi SET stato = 'firmata_direttore' WHERE id = " . (int) $cv_ia['id']);
$svc_ct = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
$er_ia = $svc_ct->completa((int) $cv_ia['id'], ['protocollo' => 'P-9896', 'valida_dal' => '2026-11-01', 'valida_al' => '2031-10-31']);
$a_ia = array_column($EMAIL, 'a');
prova($er_ia === null && in_array('azienda9896@esempio.invalid', $a_ia, true) && in_array('sara9896@prova.it', $a_ia, true), "stipulata: l'avviso arriva all'azienda e allo studente che l'ha invitata", json_encode([$er_ia, $a_ia]));
$f_ia = $az_ia->riquadro($ris_ia, false, 989601);
prova(str_contains($f_ia, 'Azienda convenzionata') && str_contains($f_ia, 'P-9896'), "stipulata: il riquadro dello studente dice che l'azienda è convenzionata");

foreach ($pulisci_ia as $sql) $q($sql);
