<?php
// Prove del primo accesso («Benvenuto: chi sei?» e «Sono una scuola»): ScegliScuola e riquadro per il profilo esterno.
// Chiamato da esegui.php: stesse variabili ($conn, $q) e funzioni (prova, sezione).
sezione("Primo accesso: benvenuto e Sono una scuola");

use App\Portale\Area\Profili;
use App\Portale\ScegliScuola;

$sc_ben = \App\Core\App::per($conn)->get(ScegliScuola::class);
$q("DELETE FROM utenti WHERE id = 989500");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (989500, 'PROVA95XX0000X00', 'Sara', 'Scuola', 'sara9895@prova.it', 5)");
$cod_ben = (string) ($conn->query("SELECT codice FROM scuole LIMIT 1")->fetch_row()[0] ?? '');

prova($sc_ben->imposta(0, 'ABCDEFGHIJ') !== null, "ScegliScuola: senza accesso niente");
prova($sc_ben->imposta(989500, 'ZZZZZZZZZZ') !== null && $sc_ben->imposta(989500, 'corto') !== null && $sc_ben->imposta(989500, '') !== null, "ScegliScuola: un codice inventato o vuoto è rifiutato");
prova((string) $conn->query("SELECT IFNULL(scuola_codice, '') FROM utenti WHERE id = 989500")->fetch_row()[0] === '', "ScegliScuola: dopo un rifiuto la scuola non cambia");
if ($cod_ben !== '') {
    prova($sc_ben->imposta(989500, strtolower($cod_ben)) === null && strtoupper((string) $conn->query("SELECT scuola_codice FROM utenti WHERE id = 989500")->fetch_row()[0]) === strtoupper($cod_ben), "ScegliScuola: una scuola dell'anagrafe viene impostata (anche scritta in minuscolo)");
    $prof_ben = \App\Core\App::per($conn)->get(\App\Portale\Area\ProfiliPersona::class)->di(\App\Core\App::per($conn)->get(\App\Auth\UtenteRepository::class)->perId(989500));
    prova(in_array(Profili::SCUOLA, $prof_ben, true) && !in_array(Profili::ESTERNO, $prof_ben, true), "dopo «Sono una scuola» il profilo è Scuola, non più esterno", json_encode($prof_ben));
} else {
    prova(true, "(nessuna scuola in anagrafe nell'ambiente di prova: prova saltata)");
}
$q("DELETE FROM utenti WHERE id = 989500");

$ben = \App\Portale\Area\CatalogoRiquadri::sistema()['benvenuto'] ?? null;
prova($ben !== null && $ben['profili'] === [Profili::ESTERNO] && is_file(__DIR__ . '/../../../inc/area/benvenuto.php') && is_file(__DIR__ . '/../../../sono_una_scuola.php'), "riquadro benvenuto: solo per il profilo esterno, con tessera e pagina");
