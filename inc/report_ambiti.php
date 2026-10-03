<?php
// inc/report_ambiti.php - Report per ambito (Orientamento, Ricerca, Public engagement, Didattica) per la Terza missione e
// il public engagement: per anno solare o accademico, eventi, incontri, ore, iscrizioni, presenze, partecipanti
// (per le classi il numero di studenti dichiarato dal docente), scuole raggiunte; dettaglio degli eventi e andamento per mese.
// Pagina admin/report_ambiti.php (Excel con lo stesso contenuto). Caricato da functions.php: non includerlo da solo.

if (!function_exists('periodo_report')) {
    // [dal, al] di un anno solare (2026) o accademico ('2025/2026': 1 ottobre – 30 settembre)
    function periodo_report(string $anno): array {
        if (preg_match('/^(\d{4})\/(\d{4})$/', $anno, $m) && (int)$m[2] === (int)$m[1] + 1) return [$m[1] . '-10-01', $m[2] . '-09-30'];
        $a = preg_match('/^\d{4}$/', $anno) ? (int)$anno : (int)date('Y');
        return [$a . '-01-01', $a . '-12-31'];
    }
}

if (!function_exists('dati_report_ambiti')) {
    // Ritorna ['eventi' => [riga per evento], 'ambiti' => [ambito => totali], 'totale' => totali (eventi contati una volta),
    //          'mesi' => ['2026-03' => [ambito => eventi]]]. Solo turni con data nel periodo; eventi archiviati compresi.
    function dati_report_ambiti($conn, string $dal, string $al): array {
        $aree = [];
        foreach (db_righe($conn, "SELECT * FROM pagine_eventi") as $p) $aree[(int)$p['id']] = $p;
        $turni = db_righe($conn,
            "SELECT t.id, t.evento_id, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo, e.pagina_id, e.ambiti, e.luogo, e.relatore, e.relatore_ente, e.tipo
             FROM turni t JOIN eventi e ON e.id = t.evento_id
             WHERE t.data_turno BETWEEN ? AND ? ORDER BY t.data_turno, t.orario_inizio", [$dal, $al]);
        if (!$turni) return ['eventi' => [], 'ambiti' => [], 'totale' => [], 'mesi' => []];
        $ids_t = array_map('intval', array_column($turni, 'id'));
        // Iscrizioni dei turni del periodo: confermate (e presenti), partecipanti dichiarati per le classi, scuole
        $pren = [];
        foreach (db_righe($conn, "SELECT turno_id, num_posti, presente, scuola_codice, dati_custom_json FROM prenotazioni
                                  WHERE IFNULL(stato, 'confermata') = 'confermata' AND turno_id IN (" . implode(',', $ids_t) . ")") as $p) $pren[(int)$p['turno_id']][] = $p;
        $ev = [];
        foreach ($turni as $t) {
            $id = (int)$t['evento_id'];
            if (!isset($ev[$id])) {
                $area = $aree[(int)$t['pagina_id']] ?? [];
                $ev[$id] = ['id' => $id, 'titolo' => $t['titolo'], 'area' => $area['titolo'] ?? '', 'ambiti' => ambiti_evento($t, $area), 'luogo' => (string)$t['luogo'],
                            'relatore' => trim($t['relatore'] . ($t['relatore_ente'] ? ' (' . $t['relatore_ente'] . ')' : '')), 'tipo' => $t['tipo'],
                            'prima' => $t['data_turno'], 'ultima' => $t['data_turno'], 'incontri' => 0, 'ore' => 0.0, 'iscrizioni' => 0, 'presenze' => 0, 'partecipanti' => 0, 'scuole' => []];
            }
            $e = &$ev[$id];
            $e['ultima'] = $t['data_turno']; $e['incontri']++;
            if ($t['orario_inizio'] && $t['orario_fine'] && $t['orario_fine'] > $t['orario_inizio']) $e['ore'] += (strtotime($t['orario_fine']) - strtotime($t['orario_inizio'])) / 3600;
            foreach ($pren[(int)$t['id']] ?? [] as $p) {
                $posti = max(1, (int)$p['num_posti']);
                $dich = (json_decode((string)$p['dati_custom_json'], true) ?: [])[CAMPO_PARTECIPANTI] ?? null;
                $part = is_numeric($dich) && (int)$dich > 0 ? (int)$dich : $posti;
                $e['iscrizioni'] += $posti; $e['partecipanti'] += $part;
                if ((int)$p['presente'] === 1) $e['presenze'] += $part;
                if (!empty($p['scuola_codice'])) $e['scuole'][$p['scuola_codice']] = true;
            }
            unset($e);
        }
        $vuoto = ['eventi' => 0, 'incontri' => 0, 'ore' => 0.0, 'iscrizioni' => 0, 'presenze' => 0, 'partecipanti' => 0, 'scuole' => []];
        $amb = array_fill_keys(array_keys(AMBITI_EVENTO), $vuoto); $tot = $vuoto; $mesi = [];
        $somma = function (array &$a, array $e) { $a['eventi']++; foreach (['incontri', 'ore', 'iscrizioni', 'presenze', 'partecipanti'] as $k) $a[$k] += $e[$k]; $a['scuole'] += $e['scuole']; };
        foreach ($ev as $e) {
            foreach ($e['ambiti'] as $a) $somma($amb[$a], $e);
            $somma($tot, $e);
            foreach ($e['ambiti'] as $a) { $m = substr($e['prima'], 0, 7); $mesi[$m][$a] = ($mesi[$m][$a] ?? 0) + 1; }
        }
        ksort($mesi);
        $conta = fn(array $a) => ['scuole' => count($a['scuole'])] + $a;
        return ['eventi' => array_values(array_map(fn($e) => ['scuole' => count($e['scuole'])] + $e, $ev)), 'ambiti' => array_map($conta, $amb), 'totale' => $conta($tot), 'mesi' => $mesi];
    }
}

if (!function_exists('excel_report_ambiti')) {
    function excel_report_ambiti(array $d, string $periodo): ?string {
        $num = fn($v) => rtrim(rtrim(number_format((float)$v, 1, ',', ''), '0'), ',');
        $int = ['Ambito', 'Eventi', 'Incontri', 'Ore', 'Iscrizioni', 'Partecipanti', 'Presenze', 'Scuole'];
        $righe = [];
        foreach ($d['ambiti'] as $k => $a) $righe[] = [AMBITI_EVENTO[$k]['nome'], $a['eventi'], $a['incontri'], $num($a['ore']), $a['iscrizioni'], $a['partecipanti'], $a['presenze'], $a['scuole']];
        $t = $d['totale'];
        $righe[] = ['Totale (ogni evento contato una volta)', $t['eventi'] ?? 0, $t['incontri'] ?? 0, $num($t['ore'] ?? 0), $t['iscrizioni'] ?? 0, $t['partecipanti'] ?? 0, $t['presenze'] ?? 0, $t['scuole'] ?? 0];
        $ev = array_map(fn($e) => [$e['titolo'], $e['area'], implode(', ', array_map(fn($a) => AMBITI_EVENTO[$a]['nome'], $e['ambiti'])), $e['relatore'], $e['luogo'],
                                    date('d/m/Y', strtotime($e['prima'])), date('d/m/Y', strtotime($e['ultima'])), $e['incontri'], $num($e['ore']), $e['iscrizioni'], $e['partecipanti'], $e['presenze'], $e['scuole']], $d['eventi']);
        $mesi = [];
        foreach ($d['mesi'] as $m => $x) $mesi[] = array_merge([$m], array_map(fn($k) => $x[$k] ?? 0, array_keys(AMBITI_EVENTO)));
        return xlsx_crea([
            'Riepilogo per ambito' => ['intestazioni' => $int, 'righe' => array_merge([['Periodo: ' . $periodo, '', '', '', '', '', '', '']], $righe), 'larghezze' => [40, 10, 10, 10, 12, 14, 12, 10]],
            'Eventi' => ['intestazioni' => ['Evento', 'Area', 'Ambiti', 'Relatore', 'Luogo', 'Primo incontro', 'Ultimo incontro', 'Incontri', 'Ore', 'Iscrizioni', 'Partecipanti', 'Presenze', 'Scuole'], 'righe' => $ev,
                         'larghezze' => [45, 22, 30, 30, 25, 14, 14, 10, 8, 11, 13, 11, 9]],
            'Per mese' => ['intestazioni' => array_merge(['Mese'], array_map(fn($a) => $a['nome'], array_values(AMBITI_EVENTO))), 'righe' => $mesi, 'larghezze' => [12, 16, 16, 20, 16]],
        ]);
    }
}
