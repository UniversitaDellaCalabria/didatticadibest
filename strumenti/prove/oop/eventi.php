<?php
// Prove del modulo Eventi (App\Eventi): le funzioni di inc/ come facciate (stessi risultati di prima).
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione). I dati di prova usano id 98xx.
sezione("Modulo Eventi: facciate di inc/");

// getPostiOccupati() sta in config.php, che queste prove non caricano: stessa query
if (!function_exists('getPostiOccupati')) {
    function getPostiOccupati($conn, $turno_id, $for_update = false) {
        $r = $conn->query("SELECT COALESCE(SUM(num_posti), 0) as totale FROM prenotazioni WHERE turno_id = " . (int)$turno_id . " AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')");
        return (int)$r->fetch_assoc()['totale'];
    }
}

// Dati: un evento con due turni e un'iscrizione in un'area visibile (non calendario)
$area = (int)$conn->query("SELECT id FROM pagine_eventi WHERE visibile = 1 AND IFNULL(tipo_area, '') <> 'calendario' ORDER BY id LIMIT 1")->fetch_assoc()['id'];
$q("DELETE FROM eventi WHERE id IN (9801, 9802)");
$q("INSERT INTO eventi (id, pagina_id, titolo, luogo, tipo, ambiti, relatore, relatore_ente, ruolo_accesso_id, archiviato) VALUES (9801, $area, 'Prova Eventi OOP; con, virgole', 'Aula 98', 'evento', 'ricerca', 'Prof. Prova', 'Unical', 0, 0)");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti) VALUES (98011, 9801, 'Turno uno', '2999-03-01', '10:00:00', '12:00:00', 5), (98012, 9801, NULL, '2999-03-02', NULL, NULL, 5)");
$q("INSERT INTO campi_form (evento_id, pagina_id, nome_campo, etichetta, tipo_campo) VALUES (9801, $area, 'campo_prova_98', 'Campo di prova', 'text')");
$q("INSERT INTO progetti_dettagli (evento_id, struttura, referenti_json, info_extra_json) VALUES (9801, 'DiBEST', '[{\"nome\":\"Ref\"}]', '[{\"etichetta\":\"a\",\"valore\":\"b\"}]')");

// Agenda
$ag = eventi_agenda($conn);
$mio = array_values(array_filter($ag, fn($e) => (int)$e['id'] === 9801))[0] ?? null;
prova($mio && $mio['prossima_data'] === '2999-03-01' && $mio['prossimo_orario'] === '10:00' && $mio['ambiti'] === ['ricerca'] && (int)$mio['n_turni'] === 2 && str_ends_with($mio['url'], '.php?evento=9801'), "eventi_agenda(): prossimo turno, ambiti, url");
prova(array_column(eventi_agenda($conn, ['ambito' => 'ricerca', 'q' => 'virgole']), 'id') !== [] && eventi_agenda($conn, ['q' => 'zzz-non-esiste-98']) === [] && count(eventi_agenda($conn, ['limite' => 1])) <= 1, "eventi_agenda(): filtri per ambito, testo e limite");
$n = conta_ambiti_agenda($ag);
prova($n[''] === count($ag) && $n['ricerca'] >= 1 && isset($n['scuole']), "conta_ambiti_agenda()");
$ics = ics_agenda($conn, [$mio], 'Prova');
prova(str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_contains($ics, 'SUMMARY:Prova Eventi OOP\; con\, virgole – Turno uno') && str_contains($ics, "DTSTART;TZID=Europe/Rome:29990301T100000\r\nDTEND;TZID=Europe/Rome:29990301T120000") && str_contains($ics, "DTSTART;VALUE=DATE:29990302") && str_contains($ics, 'DESCRIPTION:Relatore: Prof. Prova (Unical)'), "ics_agenda(): turni con e senza orario, testo con caratteri speciali");
$voce = html_voce_agenda($mio, ['capienza' => 10, 'occupati' => 4, 'liberi' => 6]);
prova(str_contains($voce, 'class="ag-voce"') && str_contains($voce, 'Prova Eventi OOP; con, virgole') && str_contains($voce, '6 posti liberi') && str_contains($voce, 'un\'altra data') && str_contains(css_agenda(), '.ag-voce'), "html_voce_agenda() e css_agenda()");

// Seminari
prova(e_seminario(['relatore' => 'x']) && !e_seminario(['relatore' => ' ', 'abstract' => '']), "e_seminario()");
salva_seminario_evento($conn, 9801, ['relatore' => ' Dott. Test ', 'relatore_ente' => 'Ente', 'abstract' => '<b>Abs</b>', 'link_streaming' => 'https://x.it/live', 'link_registrazione' => 'ftp://no'], []);
$r = $conn->query("SELECT relatore, abstract, link_streaming, link_registrazione FROM eventi WHERE id = 9801")->fetch_assoc();
prova($r['relatore'] === 'Dott. Test' && $r['abstract'] === 'Abs' && $r['link_streaming'] === 'https://x.it/live' && $r['link_registrazione'] === '', "salva_seminario_evento()");
$sem = html_seminario($r + ['relatore_ente' => 'Ente', 'slide_pdf' => ''], false);
prova(str_contains($sem, 'Dott. Test') && str_contains($sem, 'Segui in diretta') && !str_contains(html_seminario($r + ['relatore_ente' => '', 'slide_pdf' => ''], true), 'Segui in diretta'), "html_seminario()");

// Report
[$dal, $al] = periodo_report('2999');
$rep = dati_report_ambiti($conn, $dal, $al);
$evr = array_values(array_filter($rep['eventi'], fn($e) => $e['id'] === 9801))[0] ?? null;
prova(periodo_report('2025/2026') === ['2025-10-01', '2026-09-30'] && $evr && $evr['incontri'] === 2 && $evr['ore'] === 2.0 && $evr['ambiti'] === ['ricerca'] && $rep['ambiti']['ricerca']['eventi'] >= 1 && isset($rep['mesi']['2999-03']), "periodo_report() e dati_report_ambiti()");
$xl = excel_report_ambiti($rep, '2999');
prova(is_string($xl) && is_file($xl) && str_starts_with((string)file_get_contents($xl), 'PK'), "excel_report_ambiti(): file Excel");
@unlink($xl);

// Turni e progetti
prova(orario_turno(['orario_inizio' => '10:00:00', 'orario_fine' => '12:00:00']) === '10:00–12:00' && etichetta_turno(['nome_turno' => 'G', 'data_turno' => '2999-03-01']) === 'G · 01/03/2999' && !turno_concluso(['data_turno' => '2999-03-01']) && turno_concluso(['data_turno' => '2000-01-01']), "orario_turno(), etichetta_turno(), turno_concluso()");
prova(str_starts_with(getGoogleCalendarUrl('A', '2999-03-01', '10:00', '12:00', 'Aula', 'x'), 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=A&dates=29990301T100000/29990301T120000'), "getGoogleCalendarUrl()");
prova((finestra_prenotazione([['data_turno' => '2999-01-01', 'data_chiusura' => date('Y-m-d H:i:s', strtotime('+1 day'))]])['icona'] ?? '') === 'fa-hourglass-half' && finestra_prenotazione([]) === null, "finestra_prenotazione()");
prova(periodo_progetto(['data_inizio' => '2026-10-13', 'data_fine' => '2026-12-18']) === 'Dal 13/10/2026 al 18/12/2026' && periodo_progetto(null) === 'Date da definire' && stato_progetto(['data_fine' => '2000-01-01'], null, 0)['codice'] === 'concluso', "periodo_progetto() e stato_progetto()");
$dp = get_dettagli_progetti($conn, [9801]);
prova(($dp[9801]['referenti'][0]['nome'] ?? '') === 'Ref' && ($dp[9801]['info_extra'][0]['valore'] ?? '') === 'b' && get_dettagli_progetti($conn, []) === [], "get_dettagli_progetti(): referenti e info decodificati");
$slug_area = $conn->query("SELECT slug, titolo FROM pagine_eventi WHERE id = $area")->fetch_assoc();
$dest = destinazione_progetto($conn, ['destinazione' => $slug_area['slug'] . '.php']);
prova($dest && $dest['url'] === $slug_area['slug'] . '.php' && $dest['esterno'] === false && destinazione_progetto($conn, ['destinazione' => 'https://www.unical.it/x'])['nome'] === 'unical.it' && destinazione_progetto($conn, ['destinazione' => 'non_esiste_98']) === null && destinazione_progetto($conn, null) === null, "destinazione_progetto()");
prova(str_contains(html_pulsante_destinazione(['url' => 'https://x.it', 'nome' => 'x.it', 'esterno' => true], 'color:red'), 'target="_blank"') && !str_contains(html_pulsante_destinazione($dest, ''), 'target='), "html_pulsante_destinazione()");
$q("UPDATE turni SET max_posti = 1 WHERE id = 98011");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, num_posti, nome, cognome, email, matricola) VALUES (9801, 98011, 'PROVA98A', 'confermata', 1, 'Scuola', 'Uno', 'uno98@prova.it', ''), (9802, 98011, 'PROVA98B', 'in_attesa', 1, 'Attesa', 'Due', 'due98@prova.it', '')");
$turni_p = [];
$rt = $conn->query("SELECT * FROM turni WHERE evento_id = 9801 ORDER BY id");
while ($t = $rt->fetch_assoc()) $turni_p[] = $t;
$ied = info_edizioni_progetto($conn, ['per_scuole' => 1], $turni_p, [98012 => 'confermata']);
prova(count($ied['edizioni']) === 2 && $ied['edizioni'][0]['attesa'] === 1 && ($ied['edizioni'][0]['assegnata']['nome'] ?? '') === 'Scuola' && $ied['edizioni'][0]['etichetta'] === 'Turno uno' && $ied['mio_turno'] === 98012 && $ied['edizioni'][1]['etichetta'] === 'Edizione 2', "info_edizioni_progetto(): coda, scuola assegnata, iscrizione dell'utente");

// Referenti, corso, insegnamento
$_POST = ['ref_nome' => ['Mario Prova', ''], 'ref_email' => ['MARIO98@x.it', ''], 'ref_ruolo' => ['Referente', ''], 'ref_notifiche' => ['1', '0'], 'ref_link' => ['www.x.it', ''], 'ref_tel' => ['', '']];
$ref = leggi_referenti_post($scartate);
prova(count($ref) === 1 && $ref[0]['email'] === 'mario98@x.it' && $ref[0]['link'] === 'https://www.x.it' && $scartate === [], "leggi_referenti_post()");
salva_referenti_evento($conn, 9801, $ref);
prova(json_decode($conn->query("SELECT referenti_json FROM progetti_dettagli WHERE evento_id = 9801")->fetch_assoc()['referenti_json'], true)[0]['nome'] === 'Mario Prova', "salva_referenti_evento()");
$_POST = ['struttura' => 'Struttura 98', 'corso_codice' => ''];
salva_corso_evento($conn, 9801);
prova($conn->query("SELECT struttura FROM progetti_dettagli WHERE evento_id = 9801")->fetch_assoc()['struttura'] === 'Struttura 98', "salva_corso_evento()");
$_POST = ['insegnamento_id' => '0'];
salva_insegnamento_evento($conn, 9801);
prova($conn->query("SELECT insegnamento_id FROM progetti_dettagli WHERE evento_id = 9801")->fetch_assoc()['insegnamento_id'] === null, "salva_insegnamento_evento(): nessun insegnamento");
$_POST = [];

// Copia ed eliminazione
$cop = duplica_evento($conn, 9801, true);
$nuovo = (int)$cop['evento'];
prova($cop['turni'] === 2 && $cop['sondaggi'] === 0 && $conn->query("SELECT titolo FROM eventi WHERE id = $nuovo")->fetch_assoc()['titolo'] === 'Prova Eventi OOP; con, virgole (copia)' && (int)$conn->query("SELECT COUNT(*) AS n FROM prenotazioni p JOIN turni t ON p.turno_id = t.id WHERE t.evento_id = $nuovo")->fetch_assoc()['n'] === 0 && (int)$conn->query("SELECT COUNT(*) AS n FROM campi_form WHERE evento_id = $nuovo")->fetch_assoc()['n'] === 1, "duplica_evento(): turni e campi del form, senza iscritti");
$nt = duplica_turno($conn, 98011, 9801, true);
prova($conn->query("SELECT nome_turno FROM turni WHERE id = $nt")->fetch_assoc()['nome_turno'] === 'Turno uno (copia)', "duplica_turno()");
elimina_turno($conn, $nt);
prova(!$conn->query("SELECT 1 FROM turni WHERE id = $nt")->num_rows, "elimina_turno()");
prova(elimina_evento($conn, $nuovo) === true && !$conn->query("SELECT 1 FROM eventi WHERE id = $nuovo")->num_rows && !$conn->query("SELECT 1 FROM turni WHERE evento_id = $nuovo")->num_rows && !$conn->query("SELECT 1 FROM campi_form WHERE evento_id = $nuovo")->num_rows, "elimina_evento(): evento e dipendenze");
prova(in_array('nome_turno', colonne_copiabili($conn, 'turni'), true) && !in_array('id', colonne_copiabili($conn, 'turni'), true) && !in_array('nome_turno', colonne_copiabili($conn, 'turni', ['nome_turno']), true), "colonne_copiabili()");

// Funzioni di dati.php
prova(get_pagina_by_slug($conn, $slug_area['slug'])['id'] == $area && get_pagina_by_slug($conn, 'non-esiste-98') === null && in_array($area, array_map('intval', array_column(get_pagine_eventi_visibili($conn), 'id')), true), "get_pagina_by_slug() e get_pagine_eventi_visibili()");
prova(in_array(9801, array_map('intval', array_column(cerca_eventi($conn, 'virgole'), 'id')), true), "cerca_eventi()");
$q("UPDATE eventi SET archiviato = 1 WHERE id = 9801");
prova(in_array(9801, array_map('intval', array_column(get_eventi_archivio($conn, $area), 'id')), true), "get_eventi_archivio()");
$q("UPDATE eventi SET archiviato = 0 WHERE id = 9801");
$ct = array_values(array_filter(get_eventi_con_turni_admin($conn, $area, 0, ''), fn($e) => (int)$e['id'] === 9801))[0] ?? null;
prova($ct && count($ct['turni']) === 2 && $ct['turni'][0]['id'] === '98011' && is_array(get_sottocategorie($conn, $area)), "get_eventi_con_turni_admin() e get_sottocategorie()");
$kpi = get_kpi_statistiche_v2($conn, $area, '');
prova($kpi['confermate'] >= 1 && $kpi['attesa'] >= 1 && $kpi['capienza'] >= 1 && get_kpi_statistiche($conn, $area, '')['confermate'] === $kpi['confermate'], "get_kpi_statistiche() e get_kpi_statistiche_v2()");
$st = array_values(array_filter(get_stats_turni_ext($conn, $area, ''), fn($t) => $t['turno_id'] === '98011'))[0] ?? null;
prova($st && $st['confermati'] === '1' && $st['attesa'] === '1' && count(get_stats_turni($conn, $area, '')) === count(get_stats_turni_ext($conn, $area, '')), "get_stats_turni() e get_stats_turni_ext()");
$gr = get_dati_grafico_eventi($conn, $area, '');
prova(in_array('"Prova Eventi OOP; con,..."', $gr['nomi'], true), "get_dati_grafico_eventi(): nomi tra virgolette, troncati a 22 caratteri");
prova(is_array(get_trend_iscrizioni($conn, $area, '', 30)), "get_trend_iscrizioni()");

// Pulizia
$q("DELETE FROM prenotazioni WHERE id IN (9801, 9802)");
elimina_evento($conn, 9801);
prova(!$conn->query("SELECT 1 FROM eventi WHERE id = 9801")->num_rows, "pulizia dei dati di prova del modulo Eventi");
