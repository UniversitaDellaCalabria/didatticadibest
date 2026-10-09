<?php
// Prove via HTTP: un evento segnato Formazione Scuola Lavoro diventa un progetto (le prenotazioni restano). Ambiente locale acceso.
// Possono usare $http, $jar (amministratore) e $loc (database locale). Dati di prova con id 989xx nell'area OpenLab (id 1).
sezione("Modulo FSL: evento che diventa progetto (pagine)");

$pulisci_cv = ["DELETE FROM prenotazioni WHERE id = 98953", "DELETE FROM turni WHERE id = 98952", "DELETE FROM progetti_dettagli WHERE evento_id = 98951", "DELETE FROM eventi WHERE id = 98951"];
foreach ($pulisci_cv as $sql) $loc->query($sql);
$loc->query("INSERT INTO eventi (id, pagina_id, titolo, tipo, luogo, descrizione_breve, archiviato, richiede_prenotazione) VALUES (98951, 1, 'Evento OpenLab FSL da trasformare', 'evento', 'Aula', 'Prova.', 0, 1)");
$loc->query("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, dedicata_scuole) VALUES (98951, 1, 1, 0, 1)");
$loc->query("INSERT INTO turni (id, evento_id, nome_turno, data_turno, max_posti) VALUES (98952, 98951, 'Turno A', '" . date('Y-m-d', strtotime('+40 days')) . "', 5)");
$loc->query("INSERT INTO prenotazioni (id, turno_id, utente_id, codice_prenotazione, stato, num_posti, nome, cognome, email, convenzione, data_prenotazione)
             VALUES (98953, 98952, 4, 'FS-CONV1', 'confermata', 1, 'Luca', 'Insegnante', 'luca.insegnante@example.org', 'no', NOW())");
$tipo_ev = fn() => $loc->query("SELECT tipo FROM eventi WHERE id = 98951")->fetch_assoc()['tipo'] ?? null;
$errore_php_cv = fn(array $r) => (bool)preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);

// Modulo dell'evento: interruttore «Formazione Scuola Lavoro» che porta al progetto, e avviso per l'evento già segnato FSL
$r = $http('/eventi/admin/eventi.php?p_id=1&id=98951', null, $jar);
prova($r['codice'] === 200 && !$errore_php_cv($r) && str_contains($r['corpo'], 'id="evFsl"') && str_contains($r['corpo'], 'progetti.php?p_id=1&amp;id=98951&amp;converti=1') && str_contains($r['corpo'], 'Trasformalo in progetto'),
    "modulo evento: interruttore Formazione Scuola Lavoro verso il progetto e avviso per l'evento già FSL");
$rn = $http('/eventi/admin/eventi.php?p_id=1&azione=nuovo', null, $jar);
prova($rn['codice'] === 200 && str_contains($rn['corpo'], 'id="evFsl"') && str_contains($rn['corpo'], 'data-vai="progetti.php?p_id=1&amp;azione=nuovo"'), "nuovo evento: l'interruttore porta al modulo di un nuovo progetto");

// Modulo del progetto aperto sull'evento
$rf = $http('/eventi/admin/progetti.php?p_id=1&id=98951&converti=1', null, $jar);
prova($rf['codice'] === 200 && !$errore_php_cv($rf) && str_contains($rf['corpo'], 'sta per diventare un progetto') && str_contains($rf['corpo'], 'name="converti"') && str_contains($rf['corpo'], 'value="Evento OpenLab FSL da trasformare"') && str_contains($rf['corpo'], 'name="ed_id[]"'),
    "modulo del progetto sull'evento: avviso, dati dell'evento e turni come edizioni");
prova($tipo_ev() === 'evento', "aprire il modulo non cambia nulla: l'evento resta un evento");
prova(!str_contains($http('/eventi/admin/progetti.php?p_id=1&id=98951', null, $jar)['corpo'], 'Modifica progetto'), "senza «converti» un evento non si apre come progetto");

// Salvataggio: l'evento diventa progetto, prenotazione e turno restano
preg_match('/name="csrf_token" value="([^"]+)"/', $rf['corpo'], $m_tk);
$r = $http('/eventi/admin/progetti.php?p_id=1', ['csrf_token' => $m_tk[1] ?? '', 'salva_progetto' => '1', 'converti' => '1', 'evento_id' => '98951', 'p_id' => '1', 'titolo' => 'Progetto OpenLab FSL', 'luogo' => 'Aula',
    'struttura' => 'Corso di laurea in Biologia', 'data_inizio' => date('Y-m-d', strtotime('+40 days')), 'data_fine' => date('Y-m-d', strtotime('+60 days')), 'ore_totali' => '20',
    'convenzione' => '1', 'per_scuole' => '1', 'ed_id' => ['98952'], 'ed_nome' => ['Turno A'], 'ed_posti' => ['1']], $jar);
$riga_cv = $loc->query("SELECT e.tipo, e.titolo, (SELECT COUNT(*) FROM prenotazioni WHERE id = 98953) AS pren, (SELECT COUNT(*) FROM turni WHERE id = 98952) AS turno, (SELECT convenzione FROM progetti_dettagli WHERE evento_id = 98951) AS conv FROM eventi e WHERE e.id = 98951")->fetch_assoc();
prova(in_array($r['codice'], [200, 302, 303], true) && ($riga_cv['tipo'] ?? '') === 'progetto' && ($riga_cv['titolo'] ?? '') === 'Progetto OpenLab FSL' && (int)$riga_cv['pren'] === 1 && (int)$riga_cv['turno'] === 1 && (int)$riga_cv['conv'] === 1,
    "salvando, l'evento diventa un progetto FSL e la prenotazione resta", json_encode($riga_cv) . ' ' . $r['codice']);
$rp = $http('/eventi/admin/progetti.php?p_id=1&id=98951', null, $jar);
prova($rp['codice'] === 200 && str_contains($rp['corpo'], 'Modifica progetto') && !str_contains($rp['corpo'], 'sta per diventare'), "ora si modifica come un normale progetto");
prova(!str_contains($http('/eventi/admin/progetti.php?p_id=1&id=98951&converti=1', null, $jar)['corpo'] ?: '', 'sta per diventare un progetto'), "un progetto non si trasforma una seconda volta");

foreach (array_merge(["DELETE FROM partecipanti_prenotazione WHERE prenotazione_id = 98953"], $pulisci_cv) as $sql) $loc->query($sql);
