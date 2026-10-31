<?php
// Prove di «Da fare per te» per aziende ed enti: convenzione di tesi da completare (dati, firma, risposta alle modifiche, nuovo caricamento).
// Chiamato da esegui.php. Dati di prova con id 9895xx.
sezione("Da fare per te: azienda (convenzione di tesi)");

$q("DELETE FROM convenzioni_tesi WHERE email LIKE '%9894@example.org'");
$q("INSERT INTO convenzioni_tesi (token, stato, origine, azienda, email, aggiornata_il) VALUES
    ('tokaz9894aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'invitata', 'invito', 'Azienda 9894 Spa', 'azienda9894@example.org', NOW() - INTERVAL 3 DAY),
    ('tokaz9894bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'in_carico', 'invito', 'Ente 9894', 'ente9894@example.org', NOW() - INTERVAL 1 DAY)");

$daFare = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class);
$per = fn(array $u) => array_values(array_filter($daFare->gruppi($u), fn($g) => $g['chiave'] === 'convenzioni_azienda'));

$g = $per(['email' => 'Azienda9894@example.org']);
prova(count($g) === 1 && $g[0]['n'] === 1 && $g[0]['righe'][0]['testo'] === 'Azienda 9894 Spa' && str_starts_with($g[0]['righe'][0]['link'], 'convenzione_tesi.php?t=tokaz9894aaaa') && $g[0]['righe'][0]['giorni'] === 3, "da fare: l'azienda invitata vede la convenzione da compilare con il link diretto", json_encode($g));
prova($per(['email' => 'ente9894@example.org']) === [], "da fare: quando tocca al Dipartimento l'azienda non ha niente da fare");
prova($per(['email' => 'altra9894@example.org']) === [], "da fare: un'altra persona non vede la convenzione");

$q("DELETE FROM convenzioni_tesi WHERE email LIKE '%9894@example.org'");
