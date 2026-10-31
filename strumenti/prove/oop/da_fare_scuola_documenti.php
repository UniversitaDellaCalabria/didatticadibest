<?php
// Prove di «Da fare per te» per le scuole: elenco degli studenti e autorizzazione da consegnare prima di un'attività FSL di classe.
// Chiamato da esegui.php. Dati di prova con id 9893xx.
sezione("Da fare per te: scuola (elenco e autorizzazione prima dell'attività)");

foreach (["DELETE FROM prenotazioni WHERE id IN (989303, 989304)", "DELETE FROM turni WHERE id IN (989302, 989305)", "DELETE FROM progetti_dettagli WHERE evento_id = 989301",
          "DELETE FROM eventi WHERE id = 989301", "DELETE FROM pagine_eventi WHERE id = 989300"] as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (989300, 'FSL da fare 9893', 'fsl_da_fare_9893', 'fsl', 1, '#445566')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (989301, 989300, 'Attività FSL 9893', 'evento', 'Prova.')");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati) VALUES (989301, 1, 1, 0)");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, max_posti, richiede_approvazione) VALUES (989302, 989301, 'Turno futuro', '" . $giorni(20) . "', 40, 0), (989305, 989301, 'Turno passato', '" . $giorni(-5) . "', 40, 0)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, convenzione, data_prenotazione) VALUES
    (989303, 989302, 'FS-DF93A', 'confermata', 'Gian', 'Novello', 'scuola9893@example.org', 'no', NOW()),
    (989304, 989305, 'FS-DF93B', 'confermata', 'Gian', 'Novello', 'scuola9893@example.org', 'no', NOW())");

$daFare = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class);
$per = fn(array $u) => array_values(array_filter($daFare->gruppi($u), fn($g) => $g['chiave'] === 'documenti_classe'));

$g = $per(['email' => 'Scuola9893@example.org']);
prova(count($g) === 1 && $g[0]['n'] === 1 && $g[0]['righe'][0]['testo'] === 'Attività FSL 9893' && $g[0]['righe'][0]['link'] === 'elenco_studenti.php?code=FS-DF93A'
    && str_contains($g[0]['righe'][0]['dettaglio'], 'l\'elenco degli studenti e l\'autorizzazione') && str_contains($g[0]['righe'][0]['dettaglio'], ' entro il '), "da fare: la scuola vede cosa manca e la scadenza, solo per l'attività non ancora iniziata", json_encode($g));
prova($per(['email' => 'altra9893@example.org']) === [], "da fare: un'altra scuola non vede niente");
$q("UPDATE prenotazioni SET autorizzazione_file = 'x.pdf' WHERE id = 989303");
$g = $per(['email' => 'scuola9893@example.org']);
prova(count($g) === 1 && str_contains($g[0]['righe'][0]['dettaglio'], 'l\'elenco degli studenti') && !str_contains($g[0]['righe'][0]['dettaglio'], 'autorizzazione'), "da fare: con l'autorizzazione caricata resta solo l'elenco");

foreach (["DELETE FROM prenotazioni WHERE id IN (989303, 989304)", "DELETE FROM turni WHERE id IN (989302, 989305)", "DELETE FROM progetti_dettagli WHERE evento_id = 989301",
          "DELETE FROM eventi WHERE id = 989301", "DELETE FROM pagine_eventi WHERE id = 989300"] as $sql) $q($sql);
