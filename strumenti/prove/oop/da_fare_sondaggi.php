<?php
// Prove di «Da fare per te» per i sondaggi di gradimento degli eventi a cui si è partecipato.
// Chiamato da esegui.php. Dati di prova con id 9892xx.
sezione("Da fare per te: sondaggi da compilare");

$pulisci = ["DELETE FROM prenotazioni WHERE id IN (989203, 989204)", "DELETE FROM sondaggi WHERE evento_id = 989201", "DELETE FROM turni WHERE id = 989202",
            "DELETE FROM eventi WHERE id = 989201", "DELETE FROM pagine_eventi WHERE id = 989200"];
foreach ($pulisci as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (989200, 'Sondaggi 9892', 'sondaggi_9892', 'eventi', 1, '#445566')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (989201, 989200, 'Evento sondaggio 9892', 'evento', 'Prova.')");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, max_posti, richiede_approvazione) VALUES (989202, 989201, 'Turno', '" . $giorni(-4) . "', 40, 0)");
$q("INSERT INTO sondaggi (evento_id, titolo, attivo) VALUES (989201, 'Gradimento 9892', 1)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, presente, sondaggio_completato, token_sondaggio, num_posti) VALUES
    (989203, 989202, 'SO-9892A', 'confermata', 'Eva', 'Prova', 'stud9892@example.org', 1, 0, 'toksond9892', 1),
    (989204, 989202, 'SO-9892B', 'confermata', 'Ugo', 'Prova', 'altro9892@example.org', 0, 0, NULL, 1)");

$daFare = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class);
$per = fn(array $u) => array_values(array_filter($daFare->gruppi($u), fn($g) => $g['chiave'] === 'sondaggi'));

$g = $per(['email' => 'Stud9892@example.org']);
prova(count($g) === 1 && $g[0]['n'] === 1 && $g[0]['righe'][0]['link'] === 'sondaggio.php?token=toksond9892' && $g[0]['righe'][0]['giorni'] === 4, "da fare: chi ha partecipato vede il sondaggio con il link diretto", json_encode($g));
prova($per(['email' => 'altro9892@example.org']) === [], "da fare: chi non era presente non vede il sondaggio");
$q("UPDATE prenotazioni SET sondaggio_completato = 1 WHERE id = 989203");
prova($per(['email' => 'stud9892@example.org']) === [], "da fare: compilato il sondaggio non compare più");

foreach ($pulisci as $sql) $q($sql);
