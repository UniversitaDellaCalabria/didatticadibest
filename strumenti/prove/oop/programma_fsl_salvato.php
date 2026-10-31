<?php
// Prove del programma FSL salvato per persona (ProgrammaFsl + ProgrammiSalvatiRepository): salvataggio, unione col programma fatto da anonimo, cambio di persona.
// Chiamato da esegui.php: stesse variabili ($conn, $q) e funzioni (prova, sezione). Dati di prova con utenti 98950x.
sezione("Programma FSL salvato per persona");

use App\Auth\Sessione;
use App\Fsl\ProgrammaFsl;
use App\Fsl\ProgrammiSalvatiRepository;

$sess_ps = static function (array $iniziale = []): Sessione {
    return new class($iniziale) implements Sessione {
        public function __construct(public array $d) {}
        public function leggi(string $chiave): mixed { return $this->d[$chiave] ?? null; }
        public function scrivi(string $chiave, mixed $valore): void { $this->d[$chiave] = $valore; }
        public function togli(string $chiave): void { unset($this->d[$chiave]); }
        public function rigenera(): void {}
    };
};
$q("DELETE FROM fsl_programmi WHERE utente_id IN (989501, 989502)");
$rep_ps = \App\Core\App::per($conn)->get(ProgrammiSalvatiRepository::class);

// Senza accesso: solo sessione, niente nel database
$anon = new ProgrammaFsl($sess_ps(), $rep_ps);
$anon->aggiungi(10, 101, ['classe' => '4A']);
prova($anon->conta() === 1 && (int) $conn->query("SELECT COUNT(*) FROM fsl_programmi WHERE utente_id IN (989501, 989502)")->fetch_row()[0] === 0, "programma: senza accesso resta nella sessione e non si salva");

// Con accesso: ogni modifica si salva
$s1 = $sess_ps(['utente_id' => 989501]);
$p1 = new ProgrammaFsl($s1, $rep_ps);
$p1->aggiungi(10, 101, ['classe' => '4A']);
$p1->aggiungi(20, 201, []);
prova(array_keys($rep_ps->dellUtente(989501)) === [101, 201] && $rep_ps->dellUtente(989501)[101]['custom'] === ['classe' => '4A'], "programma: con l'accesso le attività scelte si salvano (con le risposte al modulo)");

// Un altro computer/sessione: il programma salvato torna
$p1b = new ProgrammaFsl($sess_ps(['utente_id' => 989501]), $rep_ps);
prova($p1b->conta() === 2 && $p1b->contieneTurno(101) && $p1b->turnoDi(20) === 201, "programma: in una sessione nuova (altro computer) il programma salvato c'è ancora");

// Programma fatto da anonimo + accesso: si uniscono
$sess_u = $sess_ps();
$pu = new ProgrammaFsl($sess_u, $rep_ps);
$pu->aggiungi(30, 301, []);
$pu->aggiungi(10, 101, ['classe' => 'nuova']);
$sess_u->scrivi('utente_id', 989501);
prova($pu->conta() === 3 && $pu->contieneTurno(301) && $pu->contieneTurno(201), "programma: all'accesso quello fatto da anonimo si somma a quello salvato");
prova(array_keys($rep_ps->dellUtente(989501)) === [101, 201, 301] && $rep_ps->dellUtente(989501)[101]['custom'] === ['classe' => 'nuova'], "programma: dopo l'unione anche il database lo sa (e vale la scelta più recente)");

// Togliere e svuotare si riflette nel database
$pu->togli(201);
prova(array_keys($rep_ps->dellUtente(989501)) === [101, 301], "programma: togliere un'edizione la toglie anche dal database");
$pu->svuota();
prova($rep_ps->dellUtente(989501) === [] && $pu->conta() === 0, "programma: svuotare lo svuota anche nel database");

// Un'altra persona nella stessa sessione non eredita il programma della prima
$rep_ps->salva(989502, [401 => ['evento_id' => 40, 'turno_id' => 401, 'custom' => []]]);
$sess_x = $sess_ps();
$px = new ProgrammaFsl($sess_x, $rep_ps);
$px->aggiungi(50, 501, []);
$sess_x->scrivi('utente_id', 989501);
$px->conta();
$sess_x->scrivi('utente_id', 989502);
prova(array_keys($px->voci()) === [401], "programma: se in sessione entra un'altra persona vede il suo programma, non quello della prima");

// Senza il database (test unitari) tutto funziona come prima
$q("DELETE FROM fsl_programmi WHERE utente_id = 989501");
$senza = new ProgrammaFsl($sess_ps(['utente_id' => 989501]));
$senza->aggiungi(10, 101, []);
prova($senza->conta() === 1 && $rep_ps->dellUtente(989501) === [], "programma: senza il salvataggio configurato resta solo nella sessione");

$q("DELETE FROM fsl_programmi WHERE utente_id IN (989501, 989502)");

prova(str_contains((string) file_get_contents(__DIR__ . '/../../../programma_fsl.php'), 'Accedi per non perderlo'), "pagina del programma: senza accesso c'è l'invito ad accedere per non perderlo");
