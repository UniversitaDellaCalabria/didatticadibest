<?php
// Prove della tessera «Tesi e laurea» dello studente (TesiStudente): cosa mostrare (regole pure) e lettura dal database.
// Chiamato da esegui.php: stesse variabili ($conn, $q) e funzioni (prova, sezione).
sezione("Tesi e laurea: tessera dello studente (TesiStudente)");

use App\Didattica\TesiStudente;

$ts_az = static fn (string $esito, array $x = []): array => $x + ['esito' => $esito, 'azienda' => 'Azienda X', 'valida_al' => '2028-05-01', 'protocollo' => '1', 'stato_in_corso' => 'Compilata'];
$ts_p = static fn (string $stato): array => ['id' => 7, 'codice' => 'P-7', 'stato' => $stato];

// Senza richiesta: si parte dal relatore e dal modulo, se pubblicato
$r = TesiStudente::riepilogo(['pratica' => null, 'azienda' => null, 'modulo' => 12]);
prova($r['azioni'][0] === ['Fai la richiesta di tesi', 'modulo.php?id=12', false, true] && $r['azioni'][1][1] === 'disponibilita_tesi.php' && $r['azioni'][2][1] === 'percorso_tesi.php' && $r['novita'] === 0, "TesiStudente: senza richiesta, «Fai la richiesta» (se il modulo è pubblicato), «Cerca un relatore» e i passi");
$r = TesiStudente::riepilogo(['pratica' => null, 'azienda' => null, 'modulo' => null]);
prova(array_column($r['azioni'], 1) === ['disponibilita_tesi.php', 'percorso_tesi.php'], "TesiStudente: modulo non pubblicato, niente «Fai la richiesta»");

// Con la richiesta: stato, azienda, pulsante giusto
$r = TesiStudente::riepilogo(['pratica' => $ts_p('in_lavorazione'), 'azienda' => null, 'modulo' => 12]);
prova(str_contains($r['testo'], 'P-7') && str_contains($r['testo'], 'in lavorazione') && $r['azioni'][0] === ['Apri la richiesta', 'pratiche.php?id=7', false, false] && $r['novita'] === 0, "TesiStudente: richiesta in lavorazione, si apre senza evidenza");
$r = TesiStudente::riepilogo(['pratica' => $ts_p('bozza'), 'azienda' => null, 'modulo' => 12]);
prova($r['azioni'][0][0] === 'Completa la richiesta' && $r['azioni'][0][3] === true && $r['novita'] === 1, "TesiStudente: bozza, «Completa la richiesta» in evidenza e un da fare");
$r = TesiStudente::riepilogo(['pratica' => $ts_p('integrazione'), 'azienda' => null, 'modulo' => 12]);
prova(str_starts_with($r['azioni'][0][0], 'Rispondi') && $r['novita'] === 1, "TesiStudente: integrazione richiesta, un da fare");
$r = TesiStudente::riepilogo(['pratica' => $ts_p('accolta'), 'azienda' => $ts_az('valida'), 'modulo' => 12]);
prova(str_contains($r['testo'], 'Azienda convenzionata fino al 01/05/2028') && count($r['azioni']) === 3, "TesiStudente: azienda convenzionata, la scadenza nel testo; al massimo tre pulsanti");
$r = TesiStudente::riepilogo(['pratica' => $ts_p('inviata'), 'azienda' => $ts_az('da_chiedere'), 'modulo' => 12]);
prova(str_contains($r['testo'], 'non è ancora convenzionata') && $r['azioni'][1] === ['Convenzione per l\'azienda', 'richiedi_convenzione_tesi.php', false, false], "TesiStudente: azienda da convenzionare, il pulsante porta alla richiesta");
$r = TesiStudente::riepilogo(['pratica' => $ts_p('inviata'), 'azienda' => $ts_az('scaduta'), 'modulo' => null]);
prova(str_contains($r['testo'], 'scaduta') && $r['azioni'][1][1] === 'richiedi_convenzione_tesi.php', "TesiStudente: convenzione scaduta, va rinnovata");
$r = TesiStudente::riepilogo(['pratica' => $ts_p('inviata'), 'azienda' => $ts_az('in_corso'), 'modulo' => null]);
prova(str_contains($r['testo'], 'in corso (Compilata)'), "TesiStudente: convenzione in corso, lo stato nel testo");

// Lettura dal database: una pratica del modulo «tesi» dello studente, mai quella di un altro
$q("DELETE FROM pratiche WHERE modulo_id = 98981");
$q("DELETE FROM didattica_moduli WHERE id = 98981");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, chiave, attivo, aggiornato_il) VALUES (98981, 'Richiesta tesi (prova)', 'Prova', 'online', 'tesi', 1, NOW())");
$q("INSERT INTO pratiche (id, modulo_id, utente_id, codice, stato, risposte_json) VALUES (98981, 98981, 98981, 'P-98981-1', 'inviata', '[]'), (98982, 98981, 98982, 'P-98981-2', 'bozza', '[]')");
$ts_srv = \App\Core\App::per($conn)->get(TesiStudente::class);
$ts_d = $ts_srv->perUtente(['id' => 98981]);
prova($ts_d['pratica'] !== null && (int) $ts_d['pratica']['id'] === 98981 && $ts_d['pratica']['stato'] === 'inviata' && !isset($ts_d['pratica']['risposte_json']) && $ts_d['azienda'] === null && is_int($ts_d['modulo']), "TesiStudente::perUtente(): la pratica di tesi dello studente (senza le risposte) e il modulo pubblicato");
prova((int) $ts_srv->perUtente(['id' => 98982])['pratica']['id'] === 98982 && $ts_srv->perUtente(['id' => 98983])['pratica'] === null && $ts_srv->perUtente(null)['pratica'] === null, "TesiStudente::perUtente(): ognuno vede solo la propria; chi non ha richieste o non ha fatto l'accesso, nessuna");
$q("DELETE FROM pratiche WHERE modulo_id = 98981");
$q("DELETE FROM didattica_moduli WHERE id = 98981");

$ts_riq = \App\Portale\Area\CatalogoRiquadri::sistema()['tesi_laurea'] ?? null;
prova($ts_riq !== null && $ts_riq['profili'] === [\App\Portale\Area\Profili::STUDENTE, \App\Portale\Area\Profili::AMMINISTRATORE] && is_file(__DIR__ . '/../../../inc/area/tesi_laurea.php'), "riquadro tesi_laurea: nel catalogo per Studente e Amministratore, disegnato da inc/area/tesi_laurea.php");
