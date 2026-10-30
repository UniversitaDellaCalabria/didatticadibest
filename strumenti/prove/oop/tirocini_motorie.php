<?php
// Prove dei tirocini di Scienze motorie: posti per azienda, occupazione (anche richieste non ancora cominciate), data in cui si libera un posto,
// lettura del report del TSP (solo [0827], senza i tirocini interni). Chiamato da esegui.php. Dati di prova con codice TM9.
sezione("Tirocini di Scienze motorie: posti, occupazione e report");

use App\Tirocini\ServizioMotorie;

$q("DELETE FROM tirocini_motorie_periodi WHERE azienda_id IN (SELECT id FROM tirocini_aziende WHERE cod_tsp LIKE 'TM9%')");
$q("DELETE FROM tirocini_motorie_posti WHERE azienda_id IN (SELECT id FROM tirocini_aziende WHERE cod_tsp LIKE 'TM9%')");
$q("DELETE FROM tirocini_aziende WHERE cod_tsp LIKE 'TM9%'");
$q("INSERT INTO tirocini_aziende (cod_tsp, nome, convenzione) VALUES ('TM9001', 'Power Gym E Fitness', 1), ('TM9002', 'ASD Rugby Rende', 1), ('TM9003', 'Senza Convenzione Srl', 0)");
$tm = \App\Core\App::per($conn)->get(ServizioMotorie::class);
$id_tm = fn(string $cod): int => (int) $conn->query("SELECT id FROM tirocini_aziende WHERE cod_tsp = '$cod'")->fetch_assoc()['id'];
[$g, $r, $s] = [$id_tm('TM9001'), $id_tm('TM9002'), $id_tm('TM9003')];
$mia = fn(int $id): ?array => array_values(array_filter($tm->stato('2026-10-30'), fn($a) => $a['id'] === $id))[0] ?? null;

prova($tm->aggiungiAzienda($s) !== null && $tm->aggiungiAzienda(0) !== null && $tm->aggiungiAzienda($g, 0) !== null, "azienda: si scelgono solo le convenzionate, con posti da 1 a 50");
prova($tm->aggiungiAzienda($g) === null && $tm->aggiungiAzienda($r, 3) === null, "azienda: si aggiunge dall'elenco delle convenzionate");
prova(!in_array($g, array_column($tm->convenzionateLibere(), 'id'), true) && !in_array($s, array_column($tm->convenzionateLibere(), 'id'), true), "azienda: chi è già nell'elenco o senza convenzione non si offre di nuovo");
prova($mia($g)['posti'] === 2 && $mia($g)['stato'] === 'libera' && $mia($g)['liberi'] === 2 && $mia($g)['libera_dal'] === null, "stato: di partenza 2 posti, libera");

prova($tm->aggiungiPeriodo($g, 'M1', 'Rossi Mario', '2026-09-09', '2026-11-15') === null, "periodo: si inserisce un tirocinio in corso");
prova($mia($g)['stato'] === 'ultimo' && $mia($g)['liberi'] === 1, "stato: con un posto occupato su due resta l'ultimo posto");
prova($tm->aggiungiPeriodo($g, 'M2', 'Bianchi Anna', '2026-12-01', '2027-01-20') === null && $mia($g)['stato'] === 'satura', "stato: anche una richiesta approvata ma non ancora cominciata occupa il posto");
prova($mia($g)['libera_dal'] === '2026-11-16', "stato: satura, il posto si libera il giorno dopo la fine del primo tirocinio che finisce", (string) $mia($g)['libera_dal']);
prova($tm->aggiungiPeriodo($g, 'M3', 'Verdi Luca', '2026-10-01', '2026-10-20') === null && count($mia($g)['periodi']) === 2, "stato: un tirocinio già finito non occupa");
$st16 = array_values(array_filter($tm->stato('2026-11-16'), fn($a) => $a['id'] === $g))[0];
prova($st16['occupati'] === 1 && $st16['stato'] === 'ultimo', "stato: il 16 novembre il primo tirocinio è finito e un posto è libero", $st16['stato']);

$tm->aggiungiPeriodo($g, 'M4', 'Neri Paolo', '2026-11-01', '2026-12-10');
$sopra = $mia($g);
prova($sopra['occupati'] === 3 && $sopra['stato'] === 'satura' && $sopra['libera_dal'] === '2026-12-11', "oltre il limite: si segnala e il posto si libera quando gli occupati scendono sotto il limite", (string) $sopra['libera_dal']);
$ids = array_column($sopra['periodi'], 'id');
$tm->annullaPeriodo((int) $ids[0]);
prova($mia($g)['occupati'] === 2, "periodo annullato: il posto è subito libero");
$tm->concludiPeriodo((int) $ids[1], '2026-10-30');
$dopo = array_values(array_filter($tm->stato('2026-10-31'), fn($a) => $a['id'] === $g))[0];
prova($mia($g)['occupati'] === 2 && $dopo['occupati'] === 1 && $dopo['stato'] === 'ultimo', "periodo concluso oggi: oggi conta ancora, il posto si libera dal giorno dopo");

prova($tm->aggiungiPeriodo($g, '', '', '2026-12-10', '2026-12-01') !== null && $tm->aggiungiPeriodo($g, '', '', 'x', 'y') !== null && $tm->aggiungiPeriodo($s, '', '', '2026-01-01', '2026-02-01') !== null, "periodo: date sbagliate o azienda fuori elenco sono rifiutate");
prova($tm->impostaPosti($r, 4, 'Solo la mattina') === null && $mia($r)['posti'] === 4 && $mia($r)['note'] === 'Solo la mattina' && $tm->impostaPosti($r, 0, '') !== null, "posti: si cambia il limite per azienda");

// Pagina per gli studenti: niente nomi né matricole
$json_pubblico = json_encode(array_map(fn($a) => array_diff_key($a, ['periodi' => 1]), $tm->stato('2026-10-30')), JSON_UNESCAPED_UNICODE);
prova(!str_contains((string) $json_pubblico, 'Rossi') && !str_contains((string) $json_pubblico, 'M1'), "pagina studenti: i dati mostrati non contengono nomi né matricole");
$src_pagina = (string) file_get_contents(__DIR__ . '/../../../tirocini_motorie.php');
prova(!str_contains($src_pagina, "['studente']") && !str_contains($src_pagina, "['matricola']") && str_contains($src_pagina, 'richiedi_accesso('), "pagina studenti: richiede l'accesso e non legge nome né matricola");

// Report del TSP
$report = [
    ['corso' => '[0827] SCIENZE E TECNOLOGIE PER LE ATTIVITÀ MOTORIE E SPORTIVE', 'matricola' => '111', 'nome' => 'NICOLE', 'cognome' => 'VALLONE', 'azienda' => 'Power Gym e Fitness', 'data inizio tirocinio' => '09/09/2026', 'data fine tirocinio' => '29/09/2026'],
    ['corso' => '[0827] SCIENZE E TECNOLOGIE PER LE ATTIVITÀ MOTORIE E SPORTIVE', 'matricola' => '112', 'nome' => 'ANNA', 'cognome' => 'ROSSI', 'azienda' => 'Università della Calabria', 'data inizio tirocinio' => '01/10/2026', 'data fine tirocinio' => '30/10/2026'],
    ['corso' => '[0827] SCIENZE E TECNOLOGIE PER LE ATTIVITÀ MOTORIE E SPORTIVE', 'matricola' => '113', 'nome' => 'LUCA', 'cognome' => 'NERI', 'azienda' => 'Azienda Sconosciuta', 'data inizio tirocinio' => '01/10/2026', 'data fine tirocinio' => '31/10/2026'],
    ['corso' => '[0400] INFORMATICA', 'matricola' => '114', 'nome' => 'X', 'cognome' => 'Y', 'azienda' => 'Power Gym e Fitness', 'data inizio tirocinio' => '01/10/2026', 'data fine tirocinio' => '31/10/2026'],
];
$lett = $tm->leggiReport($report);
prova(count($lett['righe']) === 2 && $lett['saltate'] === 1 && $lett['interni'] === 1, "report: restano solo Scienze motorie [0827], senza i tirocini interni all'Ateneo", json_encode([count($lett['righe']), $lett['saltate'], $lett['interni']]));
prova($lett['righe'][0]['azienda_id'] === $g && $lett['righe'][0]['inizio'] === '2026-09-09' && $lett['righe'][0]['fine'] === '2026-09-29' && $lett['righe'][1]['azienda_id'] === 0, "report: l'azienda si ritrova dal nome anche scritto in modo un po' diverso, quella sconosciuta resta da scegliere");
$lett['righe'][1]['azienda_id'] = $r;
$ap = $tm->applicaReport($lett['righe']);
prova($ap['nuovi'] === 2 && $ap['doppioni'] === 0, "report: le righe confermate entrano come tirocini", json_encode($ap));
$ap2 = $tm->applicaReport($lett['righe']);
prova($ap2['nuovi'] === 0 && $ap2['doppioni'] === 2, "report: caricarlo due volte non duplica niente", json_encode($ap2));
$lett['righe'][0]['azienda_id'] = 0;
prova($tm->applicaReport([$lett['righe'][0]])['saltati'] === 1, "report: «salta» non inserisce niente");
prova(ServizioMotorie::data('09/09/2026') === '2026-09-09' && ServizioMotorie::data('31/02/2026') === null && ServizioMotorie::data('') === null, "date: gg/mm/aaaa si legge, una data impossibile no");

$tm->togliAzienda($g);
prova($mia($g) === null && (int) $conn->query("SELECT COUNT(*) c FROM tirocini_motorie_periodi WHERE azienda_id = $g")->fetch_assoc()['c'] === 0, "togliere un'azienda toglie anche i suoi periodi");

$q("DELETE FROM tirocini_motorie_periodi WHERE azienda_id IN (SELECT id FROM tirocini_aziende WHERE cod_tsp LIKE 'TM9%')");
$q("DELETE FROM tirocini_motorie_posti WHERE azienda_id IN (SELECT id FROM tirocini_aziende WHERE cod_tsp LIKE 'TM9%')");
$q("DELETE FROM tirocini_aziende WHERE cod_tsp LIKE 'TM9%'");
