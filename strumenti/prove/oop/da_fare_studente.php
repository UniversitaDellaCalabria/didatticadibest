<?php
// Prove di «Da fare per te» per lo studente: pratica con integrazione richiesta e posto liberato da confermare.
// Chiamato da esegui.php. Dati di prova con id 9897.
sezione("Da fare per te: studente (integrazione, posto liberato)");

foreach (["DELETE FROM pratiche WHERE id IN (989701, 989702)", "DELETE FROM prenotazioni WHERE id IN (989701, 989702)", "DELETE FROM turni WHERE id = 989700", "DELETE FROM eventi WHERE id = 989700", "DELETE FROM didattica_moduli WHERE id = 989700"] as $sql) $q($sql);
$q("INSERT INTO didattica_moduli (id, titolo, chiave, attivo) VALUES (989700, 'Modulo da fare 9897', 'prova_df97', 1)");
$q("INSERT INTO pratiche (id, modulo_id, codice, nome, cognome, email, stato) VALUES (989701, 989700, 'DF-1', 'Anna', 'Rossi', 'stud9897@example.org', 'integrazione'), (989702, 989700, 'DF-2', 'Anna', 'Rossi', 'stud9897@example.org', 'inviata')");

$daFare = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class);
$per = fn(array $u, string $chiave) => array_values(array_filter($daFare->gruppi($u), fn($g) => $g['chiave'] === $chiave));

$g = $per(['email' => 'STUD9897@example.org'], 'pratiche_integrazione');
prova(count($g) === 1 && $g[0]['n'] === 1 && $g[0]['righe'][0]['link'] === 'pratiche.php?id=989701', "da fare: lo studente vede la pratica con integrazione richiesta, non quelle inviate", json_encode($g));
prova($per(['email' => 'altro9897@example.org'], 'pratiche_integrazione') === [], "da fare: un'altra persona non vede la pratica da integrare");

$q("INSERT INTO eventi (id, titolo, archiviato) VALUES (989700, 'Evento da fare 9897', 0)");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno) VALUES (989700, 989700, 'Turno', CURDATE() + INTERVAL 10 DAY)");
$q("INSERT INTO prenotazioni (id, turno_id, nome, cognome, email, stato, scadenza_conferma, num_posti) VALUES (989701, 989700, 'Anna', 'Rossi', 'stud9897@example.org', 'richiesta_conferma', NOW() + INTERVAL 1 DAY, 1)");
$g = $per(['email' => 'stud9897@example.org'], 'posto_liberato');
prova(count($g) === 1 && $g[0]['n'] === 1 && $g[0]['righe'][0]['link'] === 'miei_eventi.php?conferma_posto=989701' && str_contains($g[0]['righe'][0]['dettaglio'], 'conferma entro il'), "da fare: il posto liberato compare con la scadenza e il link per confermare", json_encode($g));
$q("UPDATE prenotazioni SET scadenza_conferma = NOW() - INTERVAL 1 HOUR WHERE id = 989701");
prova($per(['email' => 'stud9897@example.org'], 'posto_liberato') === [], "da fare: scaduta l'offerta il posto non compare più");

foreach (["DELETE FROM pratiche WHERE id IN (989701, 989702)", "DELETE FROM prenotazioni WHERE id = 989701", "DELETE FROM turni WHERE id = 989700", "DELETE FROM eventi WHERE id = 989700", "DELETE FROM didattica_moduli WHERE id = 989700"] as $sql) $q($sql);
