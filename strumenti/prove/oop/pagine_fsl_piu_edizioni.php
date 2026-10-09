<?php
// Prove via HTTP dell'opzione «più edizioni» dei progetti: interruttore nel modulo del progetto e testi della pagina pubblica.
// Possono usare $http, $jar (amministratore), $jar2 (docente) e $loc (database locale). Usa il progetto 20 (due edizioni) dei dati di esempio.
sezione("Modulo FSL: più edizioni prenotabili (pagine)");

$stato_iniziale_pe = (int)($loc->query("SELECT piu_edizioni FROM progetti_dettagli WHERE evento_id = 20")->fetch_assoc()['piu_edizioni'] ?? 0);
$loc->query("UPDATE progetti_dettagli SET piu_edizioni = 0 WHERE evento_id = 20");

$r = $http('/eventi/admin/progetti.php?p_id=2&id=20', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'name="piu_edizioni"') && !preg_match('/name="piu_edizioni"[^>]*checked/', $r['corpo']), "modulo del progetto: interruttore «più edizioni», spento per i progetti esistenti");
$r = $http('/eventi/fsl.php?progetto=20', null, $jar2);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Puoi iscriverti a una sola edizione') && !str_contains($r['corpo'], 'può prenotare più edizioni'), "pagina del progetto: «una sola edizione» se l'opzione è spenta");
$loc->query("UPDATE progetti_dettagli SET piu_edizioni = 1 WHERE evento_id = 20");
$r = $http('/eventi/fsl.php?progetto=20', null, $jar2);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'La stessa scuola può prenotare più edizioni') && !str_contains($r['corpo'], 'Puoi iscriverti a una sola edizione'), "pagina del progetto: «più edizioni» se l'opzione è accesa");
$r = $http('/eventi/admin/progetti.php?p_id=2&id=20', null, $jar);
prova(preg_match('/name="piu_edizioni"[^>]*checked/', $r['corpo']) === 1, "modulo del progetto: l'interruttore risulta acceso");

$loc->query("UPDATE progetti_dettagli SET piu_edizioni = $stato_iniziale_pe WHERE evento_id = 20");
