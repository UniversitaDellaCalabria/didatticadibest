<?php
// Prove di «Le mie approvazioni» del docente (ApprovazioniDocente): cosa mostrare (regole pure) e raccolta dalle fonti.
// Chiamato da esegui.php: stesse variabili ($conn) e funzioni (prova, sezione).
sezione("Docenti: Le mie approvazioni");

use App\Portale\ApprovazioniDocente;

$ad = static fn (int $f, int $c, int $r, array $t = []): array => ['firme' => $f, 'firme_per_tipo' => $t, 'conferme' => $c, 'registri' => $r];

$r = ApprovazioniDocente::riepilogo($ad(0, 0, 0));
prova($r['totale'] === 0 && $r['testo'] === '' && $r['azioni'] === [], "ApprovazioniDocente: senza niente da approvare la tessera non c'è");

$r = ApprovazioniDocente::riepilogo($ad(3, 2, 1, ['Pratiche' => 2, 'Verbali' => 1]));
prova($r['totale'] === 6 && str_contains($r['testo'], '3 documenti da firmare (2 pratiche, 1 verbali)') && str_contains($r['testo'], '2 conferme di attestati') && str_contains($r['testo'], '1 registro di tutorato da controllare'), "ApprovazioniDocente: numeri separati per gruppo, con il dettaglio delle firme per tipo");
prova(array_column($r['azioni'], 0) === ['Firma ora (3)', 'Conferma (2)', 'Registri (1)'] && array_column($r['azioni'], 1) === ['firme.php', 'mie_conferme.php', 'registro_tutorato.php'], "ApprovazioniDocente: un pulsante per gruppo, verso la pagina giusta");
prova(array_column($r['azioni'], 3) === [true, false, false], "ApprovazioniDocente: in evidenza solo il primo gruppo");

$r = ApprovazioniDocente::riepilogo($ad(0, 1, 0));
prova($r['testo'] === '1 conferma di attestato' && $r['azioni'] === [['Conferma (1)', 'mie_conferme.php', false, true]], "ApprovazioniDocente: una sola conferma, singolare e in evidenza");
$r = ApprovazioniDocente::riepilogo($ad(0, 0, 4));
prova($r['azioni'][0][3] === true && str_contains($r['testo'], '4 registri'), "ApprovazioniDocente: solo registri, il pulsante è in evidenza");

$srv_ad = \App\Core\App::per($conn)->get(ApprovazioniDocente::class);
prova($srv_ad->perUtente(null) === $ad(0, 0, 0) && $srv_ad->perUtente(['id' => 0]) === $ad(0, 0, 0), "ApprovazioniDocente::perUtente(): senza accesso tutto a zero");
$z = $srv_ad->perUtente(['id' => 989801, 'email' => 'nessuno.9898@esempio.invalid', 'persona_id' => '', 'codice_fiscale' => '']);
prova($z === $ad(0, 0, 0), "ApprovazioniDocente::perUtente(): il container la costruisce e chi non ha niente da approvare ha zero");

$ad_riq = \App\Portale\Area\CatalogoRiquadri::sistema()['approvazioni_docente'] ?? null;
prova($ad_riq !== null && $ad_riq['profili'] === [\App\Portale\Area\Profili::DOCENTE, \App\Portale\Area\Profili::AMMINISTRATORE] && is_file(__DIR__ . '/../../../inc/area/approvazioni_docente.php'), "riquadro approvazioni_docente: nel catalogo per Docente e Amministratore, disegnato da inc/area/approvazioni_docente.php");
