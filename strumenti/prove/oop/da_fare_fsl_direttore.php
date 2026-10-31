<?php
// Prove di «Da fare per te» per il Direttore: convenzioni FSL con le scuole da firmare, con il link diretto alla firma.
// Chiamato da esegui.php. Dati di prova con id 9896.
sezione("Da fare per te: convenzioni FSL da firmare (Direttore)");

$dir_prima = $conn->query("SELECT up.* FROM uffici_persone up JOIN uffici u ON u.id = up.ufficio_id WHERE u.chiave = 'direzione'")->fetch_all(MYSQLI_ASSOC);
$q("DELETE FROM uffici_persone WHERE ufficio_id IN (SELECT id FROM uffici WHERE chiave = 'direzione')");
$q("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo) SELECT id, 'direttore9896@example.org', 'Prof. Direttore Prova', 'Direttore' FROM uffici WHERE chiave = 'direzione'");
$q("DELETE FROM convenzioni_compilate WHERE id IN (989601, 989602)");
$q("INSERT INTO convenzioni_compilate (id, token, scuola_codice, stato_iter, token_direttore, da_firmare_il, email) VALUES
    (989601, 'tokcc9896aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'XX9896', 'da_firmare', 'tokdir9896bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', NOW() - INTERVAL 2 DAY, 'scuola9896@example.org'),
    (989602, 'tokcc9896cccccccccccccccccccccccccccccccc', 'XX9897', '', NULL, NULL, 'scuola9897@example.org')");

$daFare = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class);
$per = fn(array $u) => array_values(array_filter($daFare->gruppi($u), fn($g) => $g['chiave'] === 'fsl_firma'));

$g = $per(['email' => 'Direttore9896@example.org']);
prova(count($g) === 1 && $g[0]['n'] === 1 && $g[0]['righe'][0]['link'] === 'firma_convenzione.php?t=tokdir9896bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' && $g[0]['righe'][0]['giorni'] === 2, "da fare: il Direttore vede la convenzione FSL da firmare con il link diretto", json_encode($g));
prova($per(['email' => 'altro9896@example.org']) === [], "da fare: chi non firma non vede le convenzioni FSL da firmare");

$q("DELETE FROM convenzioni_compilate WHERE id IN (989601, 989602)");
$q("DELETE FROM uffici_persone WHERE ufficio_id IN (SELECT id FROM uffici WHERE chiave = 'direzione')");
foreach ($dir_prima as $r) {
    $q("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo) VALUES (" . (int) $r['ufficio_id'] . ", '" . $conn->real_escape_string((string) $r['email']) . "', '" . $conn->real_escape_string((string) $r['nominativo']) . "', '" . $conn->real_escape_string((string) $r['ruolo']) . "')");
}
