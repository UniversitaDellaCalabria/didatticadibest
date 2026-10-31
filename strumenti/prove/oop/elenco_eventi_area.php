<?php
// Prove del raggruppamento degli eventi di un'area per i vari disegni della pagina (ElencoEventiArea): senza database né pagina.
// Chiamato da esegui.php: stesse funzioni (prova, sezione).
sezione("Area: raggruppamento di eventi e turni per i disegni della pagina");

use App\Iscrizioni\ElencoEventiArea as EEA;

$ev = fn (int $id, string $titolo, string $sottocat, int $evid = 0, int $alto = 0) => ['id' => $id, 'titolo' => $titolo, 'luogo' => 'Aula &amp; 1', 'descrizione' => 'd', 'locandina_path' => '', 'richiede_prenotazione' => 1,
    'ruolo_accesso_id' => 0, 'nome_sottocategoria' => $sottocat, 'is_evidenza' => $evid, 'tipo' => 'evento', 'affiancata_in_alto' => $alto];
$eventi_eea = [$ev(1, 'Uno', 'Biologia', 1), $ev(2, 'Due', 'Biologia'), $ev(3, 'Tre', '', 0, 1), $ev(4, 'Quattro &amp; co', 'Geologia')];
$turni_eea = [
    1 => [['id' => 11, 'data_turno' => '2026-11-10', 'orario_inizio' => '10:00', 'orario_fine' => '12:00', 'nome_turno' => 'Mattina']],
    2 => [['id' => 21, 'data_turno' => '2026-11-05', 'orario_inizio' => '', 'orario_fine' => '', 'nome_turno' => ''], ['id' => 22, 'data_turno' => '', 'nome_turno' => 'Libero']],
    3 => [],
    4 => [['id' => 41, 'data_turno' => '2026-11-05', 'orario_inizio' => '09:00', 'orario_fine' => '', 'nome_turno' => '']],
];
$r_eea = EEA::costruisci($eventi_eea, fn (int $id) => $turni_eea[$id] ?? [], '#0056B3');

prova(array_keys($r_eea['eventi_by_id']) === [1, 2, 3, 4] && count($r_eea['eventi_by_id'][2]['turni']) === 2, "per id: ogni evento con i suoi turni");
prova(count($r_eea['all_turni_flat']) === 4 && $r_eea['all_turni_flat'][0]['evento_titolo'] === 'Uno' && $r_eea['all_turni_flat'][0]['categoria'] === 'Biologia' && $r_eea['all_turni_flat'][0]['evento_tipo'] === 'evento', "turni piatti: un elemento per turno, con i dati dell'evento");
prova(count($r_eea['json_events_calendar']) === 3 && $r_eea['json_events_calendar'][0]['start'] === '2026-11-10T10:00' && $r_eea['json_events_calendar'][0]['end'] === '2026-11-10T12:00' && $r_eea['json_events_calendar'][0]['title'] === 'Uno – Mattina' && $r_eea['json_events_calendar'][1]['allDay'] === true && $r_eea['json_events_calendar'][2]['extendedProps']['luogo'] === 'Aula & 1', "calendario: solo i turni con data, orari e giornata intera", json_encode($r_eea['json_events_calendar'][1]));
prova($r_eea['evento_evidenza']['id'] === 1 && !in_array(1, array_column($r_eea['eventi_macro'], 'id'), true) && in_array(1, array_column($r_eea['eventi_per_categoria']['Biologia'], 'id'), true), "in evidenza: il primo, fuori dall'elenco normale ma nella sua categoria");
prova(array_keys($r_eea['sezioni_superiori']) === ['Altre Attività'] && array_column($r_eea['eventi_macro'], 'id') === [2, 4], "sezione affiancata in alto separata dal resto");
prova(array_keys($r_eea['eventi_per_data']) === ['2026-11-05', EEA::SENZA_DATA] && count($r_eea['eventi_per_data']['2026-11-05']) === 2 && count($r_eea['eventi_per_data'][EEA::SENZA_DATA]) === 2, "per giorno: ordinati, e i turni senza data (o senza turni) in fondo");
prova(array_keys($r_eea['eventi_per_categoria']) === ['Biologia', 'Altri gruppi', 'Geologia'], "per categoria: «Altri gruppi» se non ce l'ha", json_encode(array_keys($r_eea['eventi_per_categoria'])));
$vuoto_eea = EEA::costruisci([], fn () => [], '#000');
prova($vuoto_eea['evento_evidenza'] === null && $vuoto_eea['eventi_by_id'] === [] && $vuoto_eea['eventi_per_data'] === [], "area senza eventi: tutto vuoto");
prova(str_contains(file_get_contents(dirname(__DIR__, 3) . '/master_template.php'), 'ElencoEventiArea::costruisci'), "master_template.php usa ElencoEventiArea");
