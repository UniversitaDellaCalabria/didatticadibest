<?php
// inc/agenda.php - Agenda unica del sito: eventi e progetti in programma in tutte le aree visibili (non i calendari a slot),
// con gli ambiti (Orientamento, Ricerca, Public engagement, Didattica), il prossimo turno e i posti.
// Usata dalla home (widget «Agenda»), da agenda.php, da orientamento.php e dal calendario agenda_ics.php.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('eventi_agenda')) {
    // $f: ambito (chiave di AMBITI_EVENTO o ''), scuole (bool: solo attività per le scuole), q (testo), limite (0 = tutti),
    //     pagine (id delle aree da considerare; vuoto = tutte quelle visibili), solo_home (bool: solo aree mostrate in home)
    // Ritorna eventi ordinati per prossima data (quelli «data da definire» in fondo) con: prossima_data, prossimo_orario,
    // ambiti, per_scuole, area (riga di pagine_eventi), url.
    function eventi_agenda($conn, array $f = []): array {
        $aree = [];
        foreach (get_pagine_eventi_visibili($conn) as $p) {
            if (tipo_area($p) === 'calendario') continue;
            if (!empty($f['solo_home']) && (int)($p['mostra_in_home'] ?? 1) !== 1) continue;
            if (!empty($f['pagine']) && !in_array((int)$p['id'], array_map('intval', $f['pagine']), true)) continue;
            $aree[(int)$p['id']] = $p;
        }
        if (!$aree) return [];
        $in = implode(',', array_keys($aree));
        $righe = db_righe($conn,
            "SELECT e.id, e.titolo, e.tipo, e.luogo, e.descrizione_breve, e.locandina_path, e.pagina_id, e.ambiti, e.relatore, e.relatore_ente, e.link_streaming,
                    MAX(IFNULL(pd.convenzione, 0)) AS fsl, MAX(IFNULL(pd.dedicata_scuole, 0)) AS dedicata, MAX(IF(e.tipo = 'progetto', IFNULL(pd.per_scuole, 0), 0)) AS progetto_scuole,
                    MIN(CONCAT(COALESCE(t.data_turno, '9999-12-31'), ' ', COALESCE(t.orario_inizio, '99:99:99'))) AS prossimo,
                    COUNT(DISTINCT t.id) AS n_turni
             FROM eventi e
             JOIN turni t ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE e.archiviato = 0 AND e.pagina_id IN ($in) AND IFNULL(e.ruolo_accesso_id, 0) = 0
               AND (t.data_turno IS NULL OR t.data_turno >= CURDATE())
             GROUP BY e.id, e.titolo, e.tipo, e.luogo, e.descrizione_breve, e.locandina_path, e.pagina_id, e.ambiti, e.relatore, e.relatore_ente, e.link_streaming
             ORDER BY prossimo, e.titolo");
        $q = mb_strtolower(trim((string)($f['q'] ?? '')));
        $out = [];
        foreach ($righe as $r) {
            $area = $aree[(int)$r['pagina_id']];
            $r['ambiti'] = ambiti_evento($r, $area);
            $r['per_scuole'] = (int)$r['fsl'] === 1 || (int)$r['dedicata'] === 1 || (int)$r['progetto_scuole'] === 1 || tipo_area($area) === 'fsl';
            if (!empty($f['ambito']) && !in_array($f['ambito'], $r['ambiti'], true)) continue;
            if (!empty($f['scuole']) && !$r['per_scuole']) continue;
            if ($q !== '' && !str_contains(mb_strtolower($r['titolo'] . ' ' . $r['luogo'] . ' ' . $area['titolo'] . ' ' . $r['relatore'] . ' ' . strip_tags((string)$r['descrizione_breve'])), $q)) continue;
            $d = substr($r['prossimo'], 0, 10); $o = substr($r['prossimo'], 11, 5);
            $r['prossima_data'] = $d === '9999-12-31' ? null : $d;
            $r['prossimo_orario'] = $o === '99:99' ? '' : $o;
            $r['area'] = $area;
            $r['url'] = $area['slug'] . '.php?' . ($r['tipo'] === 'progetto' ? 'progetto' : 'evento') . '=' . (int)$r['id'];
            $out[] = $r;
            if (!empty($f['limite']) && count($out) >= (int)$f['limite']) break;
        }
        return $out;
    }
}

if (!function_exists('conta_ambiti_agenda')) {
    // Quanti eventi in programma per ambito (per i filtri): ['orientamento' => 4, …, '' => totale]
    function conta_ambiti_agenda(array $eventi): array {
        $n = array_fill_keys(array_keys(AMBITI_EVENTO), 0) + ['' => count($eventi), 'scuole' => 0];
        foreach ($eventi as $e) { foreach ($e['ambiti'] as $a) $n[$a]++; if ($e['per_scuole']) $n['scuole']++; }
        return $n;
    }
}

if (!function_exists('ics_agenda')) {
    // Calendario .ics dei turni in programma degli eventi dati (iscrizione da Google Calendar, Outlook…)
    function ics_agenda($conn, array $eventi, string $nome): string {
        $esc = fn($s) => str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], (string)$s);
        $host = parse_url(url_base_sito(), PHP_URL_HOST) ?: 'dibest';
        $o = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//DiBEST//Agenda//IT\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:" . $esc($nome) . "\r\nX-WR-TIMEZONE:Europe/Rome\r\n";
        $ids = array_column($eventi, 'id');
        $per_ev = [];
        foreach ($eventi as $e) $per_ev[(int)$e['id']] = $e;
        if ($ids) foreach (db_righe($conn, "SELECT id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine FROM turni WHERE data_turno >= CURDATE() AND evento_id IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY data_turno, orario_inizio") as $t) {
            $e = $per_ev[(int)$t['evento_id']];
            $giorno = str_replace('-', '', $t['data_turno']);
            if ($t['orario_inizio']) {
                $ini = $giorno . 'T' . str_replace(':', '', substr($t['orario_inizio'], 0, 5)) . '00';
                $fin = $giorno . 'T' . str_replace(':', '', substr($t['orario_fine'] ?: date('H:i', strtotime($t['orario_inizio'] . ' +1 hour')), 0, 5)) . '00';
                $tempi = "DTSTART;TZID=Europe/Rome:$ini\r\nDTEND;TZID=Europe/Rome:$fin\r\n";
            } else {
                $tempi = "DTSTART;VALUE=DATE:$giorno\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime($t['data_turno'] . ' +1 day')) . "\r\n";
            }
            $o .= "BEGIN:VEVENT\r\nUID:turno-" . (int)$t['id'] . "@$host\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n" . $tempi
                . "SUMMARY:" . $esc($e['titolo'] . ($t['nome_turno'] && (int)$e['n_turni'] > 1 ? ' – ' . $t['nome_turno'] : '')) . "\r\n"
                . ($e['luogo'] ? "LOCATION:" . $esc($e['luogo']) . "\r\n" : '')
                . (trim((string)($e['relatore'] ?? '')) !== '' ? "DESCRIPTION:" . $esc('Relatore: ' . $e['relatore'] . ($e['relatore_ente'] ? ' (' . $e['relatore_ente'] . ')' : '') . ($e['link_streaming'] ? "\nDiretta: " . $e['link_streaming'] : '')) . "\r\n" : '')
                . "URL:" . $esc(url_base_sito() . '/' . $e['url']) . "\r\nCATEGORIES:" . $esc(implode(',', array_map(fn($a) => AMBITI_EVENTO[$a]['nome'], $e['ambiti']))) . "\r\nEND:VEVENT\r\n";
        }
        return $o . "END:VCALENDAR\r\n";
    }
}

if (!function_exists('html_voce_agenda')) {
    // Una riga dell'agenda: data, titolo, area e ambiti, luogo e ora, posti. $posti = riga di get_riepilogo_posti() o null.
    function html_voce_agenda(array $e, ?array $posti = null): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $mm = [1 => 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
        $gg = ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab'];
        $col = colore_valido($e['area']['colore_primario'] ?? '', '#0056B3');
        $ts = $e['prossima_data'] ? strtotime($e['prossima_data']) : null;
        $data = $ts ? '<span class="ag-g">' . $gg[(int)date('w', $ts)] . '</span><strong>' . date('j', $ts) . '</strong><span class="ag-m">' . $mm[(int)date('n', $ts)] . '</span>' : '<span class="ag-m">da<br>definire</span>';
        $posti_txt = '';
        if ($posti && $posti['capienza'] > 0) $posti_txt = $posti['liberi'] > 0 ? '<span class="text-success fw-bold">' . $h(testo_posti_liberi((int)$posti['liberi'])) . '</span>' : '<span class="text-danger fw-bold">Completo</span>';
        $altre = (int)$e['n_turni'] > 1 ? ' · ' . ((int)$e['n_turni'] === 2 ? 'un\'altra data' : ((int)$e['n_turni'] - 1) . ' altre date') : '';
        return '<a class="ag-voce" href="' . $h($e['url']) . '">'
            . '<span class="ag-data" style="--c:' . $col . ';">' . $data . '</span>'
            . '<span class="ag-testo"><span class="ag-titolo">' . $h($e['titolo']) . '</span>'
            . (trim((string)($e['relatore'] ?? '')) !== '' ? '<span class="ag-info"><i class="fa fa-chalkboard-user me-1" aria-hidden="true"></i>' . $h($e['relatore']) . (trim((string)$e['relatore_ente']) !== '' ? ' (' . $h($e['relatore_ente']) . ')' : '') . '</span>' : '')
            . '<span class="ag-info"><span class="ag-area" style="color:' . $col . ';">' . $h($e['area']['titolo']) . '</span>'
            . ($e['prossimo_orario'] ? ' · ore ' . $h($e['prossimo_orario']) : '') . ($e['luogo'] ? ' · ' . $h($e['luogo']) : '') . $altre . '</span>'
            . '<span class="ag-badge">' . html_badge_ambiti($e['ambiti']) . ($e['per_scuole'] ? ' <span class="badge rounded-pill text-bg-light border"><i class="fa fa-school me-1" aria-hidden="true"></i>Per le scuole</span>' : '') . ($posti_txt ? ' <span class="small ms-1">' . $posti_txt . '</span>' : '') . '</span></span>'
            . '<i class="fa fa-chevron-right ag-freccia" aria-hidden="true"></i></a>';
    }
    // Stile comune delle voci dell'agenda (home, agenda.php, orientamento.php)
    function css_agenda(): string {
        return '<style>
.ag-voce { display: flex; gap: 14px; align-items: center; padding: 12px 14px; border-bottom: 1px solid #e5e7eb; text-decoration: none; color: inherit; background: #fff; transition: background .15s; }
.ag-voce:hover, .ag-voce:focus { background: #f8fafc; }
.ag-voce:last-child { border-bottom: 0; }
.ag-data { width: 58px; flex-shrink: 0; text-align: center; border-radius: 10px; padding: 6px 0; background: color-mix(in srgb, var(--c) 10%, white); color: var(--c); line-height: 1.1; }
.ag-data strong { display: block; font-size: 1.45rem; }
.ag-g, .ag-m { display: block; font-size: .7rem; text-transform: uppercase; font-weight: 700; letter-spacing: .03em; }
.ag-testo { flex-grow: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.ag-titolo { font-weight: 700; color: #0f172a; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.ag-info { font-size: .82rem; color: #475569; }
.ag-area { font-weight: 700; }
.ag-badge .badge { font-size: .68rem; font-weight: 600; }
.ag-freccia { color: #94a3b8; }
.ag-filtri { display: flex; flex-wrap: wrap; gap: 6px; }
.ag-filtri a { border: 1px solid #cbd5e1; border-radius: 999px; padding: 5px 14px; font-size: .85rem; font-weight: 600; color: #334155; text-decoration: none; background: #fff; }
.ag-filtri a.attivo { background: #0f172a; color: #fff; border-color: #0f172a; }
.ag-filtri a .n { opacity: .7; font-weight: 400; margin-left: 4px; }
/* Bootstrap Italia aggiunge 48px sotto ogni .card con ::after: nelle liste non serve */
#main-content .card::after { display: none !important; }
</style>';
    }
}
