<?php
// Prove di «I miei studenti» del docente (StudentiDelDocente): regole pure e lettura dalle pratiche con la firma del relatore.
// Chiamato da esegui.php: stesse variabili ($conn, $q) e funzioni (prova, sezione).
sezione("Docenti: I miei studenti");

use App\Didattica\StudentiDelDocente;

$sd = static fn (string $stato, string $firma): array => ['stato' => $stato, 'firma' => $firma];

$r = StudentiDelDocente::riepilogo([]);
prova($r['totale'] === 0 && $r['testo'] === '', "StudentiDelDocente: senza richieste la tessera non c'è");
$r = StudentiDelDocente::riepilogo([$sd('inviata', 'attesa'), $sd('inviata', 'attesa'), $sd('in_lavorazione', 'firmata'), $sd('integrazione', 'rifiutata'), $sd('accolta', 'firmata')]);
prova($r['totale'] === 5 && $r['attesa'] === 2 && $r['in_corso'] === 2 && $r['concluse'] === 1 && str_contains($r['testo'], '2 aspettano la tua firma') && str_contains($r['testo'], '2 in corso') && str_contains($r['testo'], '1 conclusa'), "StudentiDelDocente: numeri per gruppo (da firmare, in corso, concluse)");
$r = StudentiDelDocente::riepilogo([$sd('inviata', 'attesa')]);
prova($r['testo'] === '1 richiesta di uno studente: 1 aspetta la tua firma.', "StudentiDelDocente: singolare");

$srv_sd = \App\Core\App::per($conn)->get(StudentiDelDocente::class);
prova($srv_sd->perUtente(null) === [] && $srv_sd->perUtente(['id' => 0]) === [] && $srv_sd->perUtente(['id' => 5, 'email' => '', 'persona_id' => '']) === [], "StudentiDelDocente::perUtente(): senza accesso o senza identità niente");

// Dati: una pratica inviata con richiesta di firma per il relatore, una bozza e una di un altro relatore
$q("DELETE FROM pratiche_firme WHERE pratica_id IN (989901, 989902, 989903)");
$q("DELETE FROM pratiche WHERE id IN (989901, 989902, 989903)");
$q("INSERT INTO didattica_moduli (id, titolo, chiave, attivo) VALUES (989900, 'Richiesta tesi prova', 'prova_ms', 1) ON DUPLICATE KEY UPDATE titolo = VALUES(titolo)");
foreach ([[989901, 'MS-1', 'Anna', 'Rossi', 'inviata'], [989902, 'MS-2', 'Bruno', 'Verdi', 'bozza'], [989903, 'MS-3', 'Carla', 'Neri', 'accolta']] as [$id, $cod, $n, $c, $st]) {
    $q("INSERT INTO pratiche (id, modulo_id, codice, nome, cognome, stato) VALUES ($id, 989900, '$cod', '$n', '$c', '$st')");
}
$q("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token, stato) VALUES (989901, 1, 'persona', 'Prof Prova', 'relatore.9899@esempio.invalid', 'tokms01', 'attesa')");
$q("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token, stato) VALUES (989902, 1, 'persona', 'Prof Prova', 'relatore.9899@esempio.invalid', 'tokms02', 'attesa')");
$q("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token, stato) VALUES (989903, 1, 'persona', 'Prof Altro', 'altro.9899@esempio.invalid', 'tokms03', 'firmata')");
$q("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token, stato, persona_id) VALUES (989903, 2, 'persona', 'Prof Prova', 'altra.mail@esempio.invalid', 'tokms04', 'firmata', 'PERS-9899')");
$mio = ["id" => 989901, "email" => "Relatore.9899@esempio.invalid", "persona_id" => "", "codice_fiscale" => ""];
$l = $srv_sd->perUtente($mio);
prova(count($l) === 1 && $l[0]['codice'] === 'MS-1' && $l[0]['studente'] === 'Anna Rossi' && $l[0]['firma'] === 'attesa', "StudentiDelDocente::perUtente(): per email vede le sue richieste inviate, non le bozze né quelle di altri");
$l = $srv_sd->perUtente(['id' => 989902, 'email' => 'cambiata@esempio.invalid', 'persona_id' => 'PERS-9899']);
prova(count($l) === 1 && $l[0]['codice'] === 'MS-3' && $l[0]['stato'] === 'accolta', "StudentiDelDocente::perUtente(): per scheda dell'anagrafe anche con un'altra email");

$q("DELETE FROM pratiche_firme WHERE pratica_id IN (989901, 989902, 989903)");
$q("DELETE FROM pratiche WHERE id IN (989901, 989902, 989903)");
$q("DELETE FROM didattica_moduli WHERE id = 989900");

$ms_riq = \App\Portale\Area\CatalogoRiquadri::sistema()['miei_studenti'] ?? null;
prova($ms_riq !== null && $ms_riq['profili'] === [\App\Portale\Area\Profili::DOCENTE, \App\Portale\Area\Profili::AMMINISTRATORE] && is_file(__DIR__ . '/../../../inc/area/miei_studenti.php') && is_file(__DIR__ . '/../../../miei_studenti.php'), "riquadro miei_studenti: nel catalogo per Docente e Amministratore, con tessera e pagina");
