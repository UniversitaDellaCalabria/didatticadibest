<?php
// inc/organizzazione.php - Organizzazione proposta del sito pubblico, per pubblico invece che per area:
// - home: carosello, «Cosa cerchi?» (futuri studenti e scuole, studenti, eventi e seminari, area riservata), agenda con
//   gli ambiti, scadenze della modulistica, card delle aree;
// - menu: Home · Orientamento (futuri studenti, per le scuole) · Studenti (modulistica e pratiche, ricevimento, tutorato) ·
//   Eventi e seminari (agenda per ambito, archivio) · Area riservata; le altre voci già presenti (es. Link utili) restano in fondo.
// Si applica da Testata e home › Widget (admin/testata.php) con una copia di sicurezza (copie_configurazione) che si ripristina.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('home_proposta')) {
    function home_proposta(array $attuale): array {
        $w = $attuale;
        foreach (['slideshow' => 1, 'percorsi' => 1, 'mia_prenotazione' => 1, 'annunci' => (int)!empty($attuale['annunci']), 'agenda' => 1, 'scadenze' => 1,
                  'card_aree' => 1, 'ultimi_posti' => 0, 'prossimi_eventi' => 0, 'statistiche' => 0] as $k => $v) $w[$k] = $v;
        $w['ordine'] = ['slideshow', 'annunci', 'mia_prenotazione', 'percorsi', 'agenda', 'scadenze', 'card_aree', 'ultimi_posti', 'prossimi_eventi', 'statistiche'];
        $w['aree_colonne'] = 3; $w['eventi_num'] = 8;
        return $w;
    }
}

if (!function_exists('menu_proposto')) {
    // Voci proposte: [etichetta, url, [figli…]] (tre livelli come il menu del sito: voce, colonna, collegamenti)
    function menu_proposto($conn): array {
        $aree = array_values(array_filter(get_pagine_eventi_visibili($conn), fn($p) => (int)($p['visibile'] ?? 1) === 1));
        // Titolo dell'area per il menu: le parole TUTTE MAIUSCOLE diventano «Maiuscola iniziale», le sigle miste (DiBEST) restano
        $nome = function ($p) {
            $parole = preg_split('/\s+/u', trim($p['titolo']));
            $minuscole = ['di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'e', 'ed', 'il', 'lo', 'la', 'i', 'gli', 'le', 'del', 'dello', 'della', 'dei', 'degli', 'delle',
                          'al', 'allo', 'alla', 'ai', 'agli', 'alle', 'dal', 'dalla', 'dai', 'nel', 'nella', 'nei', 'sul', 'sulla'];
            foreach ($parole as $i => $w) {
                if ($w !== mb_strtoupper($w, 'UTF-8') || mb_strlen($w) < 2 && $i === 0) continue;
                $p_min = mb_strtolower($w, 'UTF-8');
                $parole[$i] = $i > 0 && in_array($p_min, $minuscole, true) ? $p_min : mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
            }
            return implode(' ', $parole);
        };
        $link = fn($p) => [$nome($p), $p['slug'] . '.php', []];
        $or = array_values(array_filter($aree, fn($p) => !in_array(tipo_area($p), ['fsl', 'calendario', 'gruppi'], true) && ambito_area($p) === 'orientamento'));
        $fsl = array_values(array_filter($aree, fn($p) => tipo_area($p) === 'fsl'));
        $cal = array_values(array_filter($aree, fn($p) => in_array(tipo_area($p), ['calendario', 'gruppi'], true)));
        $ev = array_values(array_filter($aree, fn($p) => !in_array(tipo_area($p), ['calendario'], true)));
        return [
            ['Home', 'index.php', []],
            ['Orientamento', 'orientamento.php', [
                ['Futuri studenti', 'orientamento.php', array_merge(array_map($link, $or), [['Prossimi appuntamenti', 'agenda.php?ambito=orientamento', []]])],
                ['Per le scuole', $fsl ? $fsl[0]['slug'] . '.php' : 'agenda.php?scuole=1', array_merge(array_map(fn($p) => [$nome($p) === 'Formazione Scuola Lavoro' ? 'Formazione Scuola Lavoro' : $nome($p), $p['slug'] . '.php', []], $fsl), [['Tutte le attività per le scuole', 'agenda.php?scuole=1', []]])],
            ]],
            ['Studenti', 'modulistica.php', [
                ['Modulistica e pratiche', 'modulistica.php', [['Modulistica', 'modulistica.php', []], ['Le mie pratiche', 'pratiche.php', []]]],
                ['Ricevimento e sportelli', 'ricevimento.php', array_merge([['Ricevimento dei docenti', 'ricevimento.php', []]], array_map($link, $cal))],
                ['Tutorato', 'registro_tutorato.php', [['Registro delle attività', 'registro_tutorato.php', []]]],
            ]],
            ['Eventi e seminari', 'agenda.php', [
                ['Agenda', 'agenda.php', array_merge(array_map(fn($k) => [AMBITI_EVENTO[$k]['nome'], 'agenda.php?ambito=' . $k, []], array_keys(AMBITI_EVENTO)), [['Per le scuole', 'agenda.php?scuole=1', []], ['Avvisi per email', 'avvisi.php', []]])],
                ['Archivio', '#', array_map(fn($p) => [$nome($p), $p['slug'] . '_archivio.php', []], $ev)],
            ]],
            ['Area riservata', 'area_personale.php', []],
        ];
    }
    // Voci del menu attuale che la proposta già comprende (home, aree, archivi): si tolgono, le altre restano in fondo
    function voci_menu_da_tenere($conn): array {
        $coperti = ['index.php', '/', './', 'index'];
        foreach (get_pagine_eventi_visibili($conn) as $p) array_push($coperti, $p['slug'] . '.php', $p['slug'], $p['slug'] . '_archivio.php', $p['slug'] . '_archivio');
        $norm = fn($u) => ltrim(preg_replace('#^https?://[^/]+(/[^/]+)?/#', '', trim((string)$u)), '/');
        $tutte = db_righe($conn, "SELECT * FROM menu_voci ORDER BY ordine, id");
        $figli = fn($id) => array_values(array_filter($tutte, fn($v) => (int)$v['genitore_id'] === (int)$id));
        $coperta = function (array $v) use (&$coperta, $figli, $coperti, $norm): bool {
            $u = $norm($v['url']);
            if (in_array($u, $coperti, true)) return true;
            $f = $figli($v['id']);
            return ($u === '' || $u === '#') && $f && !array_filter($f, fn($x) => !$coperta($x));
        };
        return array_values(array_filter($figli(0), fn($v) => !$coperta($v)));
    }
    function copia_configurazione($conn, string $autore): void {
        // Letta dal database, non dalla cache della configurazione (memorizzata per richiesta)
        db_esegui($conn, "INSERT INTO copie_configurazione (tipo, dati_json, autore) VALUES ('organizzazione', ?, ?)",
                  [json_encode(['widgets_home' => db_valore($conn, "SELECT widgets_home FROM configurazione_portale WHERE id = 1"), 'menu' => db_righe($conn, "SELECT * FROM menu_voci ORDER BY id")], JSON_UNESCAPED_UNICODE), $autore]);
    }
    // Applica home e menu proposti (prima una copia di sicurezza). Ritorna il numero di voci del nuovo menu.
    function applica_organizzazione_proposta($conn, string $autore, bool $home = true, bool $menu = true): int {
        copia_configurazione($conn, $autore);
        if ($home) {
            $attuale = get_widgets_home(['widgets_home' => db_valore($conn, "SELECT widgets_home FROM configurazione_portale WHERE id = 1")]);
            $w = get_widgets_home(['widgets_home' => json_encode(home_proposta($attuale))]);
            db_esegui($conn, "UPDATE configurazione_portale SET widgets_home = ? WHERE id = 1", [json_encode($w)]);
            if (function_exists('invalidate_configurazione_portale_cache')) invalidate_configurazione_portale_cache();
        }
        if (!$menu) return 0;
        // Le voci proposte già create nascoste si rifanno: non si tengono (niente doppioni)
        $gia_proposte = voci_menu_proposto($conn);
        $tieni = array_values(array_filter(voci_menu_da_tenere($conn), fn($v) => !in_array((int)$v['id'], $gia_proposte, true) || db_valore($conn, "SELECT url FROM menu_voci WHERE id = ?", [(int)$v['id']]) === 'index.php'));
        db_esegui($conn, "DELETE FROM copie_configurazione WHERE tipo = 'menu_proposto'");
        $tieni_ids = [];
        $tutte = db_righe($conn, "SELECT id, genitore_id FROM menu_voci");
        $raccogli = function (int $id) use (&$raccogli, $tutte, &$tieni_ids): void { $tieni_ids[] = $id; foreach ($tutte as $v) if ((int)$v['genitore_id'] === $id) $raccogli((int)$v['id']); };
        foreach ($tieni as $v) $raccogli((int)$v['id']);
        db_esegui($conn, "DELETE FROM menu_voci" . ($tieni_ids ? " WHERE id NOT IN (" . implode(',', array_map('intval', $tieni_ids)) . ")" : ''));
        $n = 0;
        $inserisci = function (array $voci, int $genitore) use (&$inserisci, $conn, &$n): void {
            foreach ($voci as $i => [$etichetta, $url, $figli]) {
                db_esegui($conn, "INSERT INTO menu_voci (genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id, visibile) VALUES (?, ?, ?, ?, 0, 0, 1)", [$genitore, $etichetta, $url, $i + 1]);
                $id = (int)$conn->insert_id; $n++;
                if ($figli) $inserisci($figli, $id);
            }
        };
        $proposta = menu_proposto($conn);
        $inserisci($proposta, 0);
        foreach ($tieni as $i => $v) db_esegui($conn, "UPDATE menu_voci SET ordine = ? WHERE id = ?", [count($proposta) + $i + 1, (int)$v['id']]);
        return $n;
    }
    // Voci del menu proposto già create nascoste (crea_menu_proposto_nascosto): id delle voci di primo livello, in ordine
    function voci_menu_proposto($conn): array {
        $c = db_riga($conn, "SELECT dati_json FROM copie_configurazione WHERE tipo = 'menu_proposto' ORDER BY id DESC LIMIT 1");
        $ids = $c ? array_map('intval', json_decode((string)$c['dati_json'], true) ?: []) : [];
        $esistenti = $ids ? array_map('intval', array_column(db_righe($conn, "SELECT id FROM menu_voci WHERE id IN (" . implode(',', $ids) . ")"), 'id')) : [];
        return array_values(array_filter($ids, fn($id) => in_array($id, $esistenti, true)));
    }
    // Crea le voci del menu proposto NASCOSTE (in fondo al menu attuale, che non cambia): si mostrano dopo, tutte insieme
    // (mostra_menu_proposto) o una per una da Menu del sito. La voce Home già presente si riusa. Ritorna le voci create.
    function crea_menu_proposto_nascosto($conn, string $autore = ''): int {
        if (voci_menu_proposto($conn)) return 0;
        $ordine = (int)db_valore($conn, "SELECT COALESCE(MAX(ordine), 0) FROM menu_voci WHERE genitore_id = 0");
        $n = 0; $primo_livello = [];
        $inserisci = function (array $voci, int $genitore, bool $nascoste) use (&$inserisci, $conn, &$n, &$ordine, &$primo_livello): void {
            foreach ($voci as $i => [$etichetta, $url, $figli]) {
                if ($genitore === 0 && $url === 'index.php' && ($home = db_valore($conn, "SELECT id FROM menu_voci WHERE genitore_id = 0 AND url IN ('index.php', './', '/') LIMIT 1"))) { $primo_livello[] = (int)$home; continue; }
                db_esegui($conn, "INSERT INTO menu_voci (genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id, visibile) VALUES (?, ?, ?, ?, 0, 0, ?)",
                          [$genitore, $etichetta, $url, $genitore === 0 ? ++$ordine : $i + 1, $nascoste ? 0 : 1]);
                $id = (int)$conn->insert_id; $n++;
                if ($genitore === 0) $primo_livello[] = $id;
                if ($figli) $inserisci($figli, $id, false);
            }
        };
        $inserisci(menu_proposto($conn), 0, true);
        db_esegui($conn, "INSERT INTO copie_configurazione (tipo, dati_json, autore) VALUES ('menu_proposto', ?, ?)", [json_encode($primo_livello), $autore]);
        return $n;
    }
    // Mostra le voci create nascoste e nasconde quelle del menu attuale che la proposta comprende (home, aree, archivi);
    // le altre (es. Link utili) restano visibili in fondo. Prima salva una copia (ripristina_organizzazione la rimette).
    function mostra_menu_proposto($conn, string $autore = ''): bool {
        $ids = voci_menu_proposto($conn);
        if (!$ids) return false;
        copia_configurazione($conn, $autore);
        $tieni = array_map(fn($v) => (int)$v['id'], array_filter(voci_menu_da_tenere($conn), fn($v) => !in_array((int)$v['id'], $ids, true)));
        foreach (db_righe($conn, "SELECT id FROM menu_voci WHERE genitore_id = 0") as $v)
            if (!in_array((int)$v['id'], $ids, true) && !in_array((int)$v['id'], $tieni, true)) db_esegui($conn, "UPDATE menu_voci SET visibile = 0 WHERE id = ?", [(int)$v['id']]);
        $o = 0;
        foreach (array_merge($ids, $tieni) as $id) db_esegui($conn, "UPDATE menu_voci SET ordine = ?" . (in_array($id, $ids, true) ? ", visibile = 1" : '') . " WHERE id = ?", [++$o, $id]);
        return true;
    }
    function ultima_copia_configurazione($conn): ?array {
        return db_riga($conn, "SELECT * FROM copie_configurazione WHERE tipo = 'organizzazione' ORDER BY id DESC LIMIT 1");
    }
    // Ripristina home e menu com'erano prima dell'ultima applicazione della proposta
    function ripristina_organizzazione($conn): bool {
        $c = ultima_copia_configurazione($conn);
        $d = $c ? json_decode((string)$c['dati_json'], true) : null;
        if (!is_array($d)) return false;
        db_esegui($conn, "UPDATE configurazione_portale SET widgets_home = ? WHERE id = 1", [$d['widgets_home']]);
        if (function_exists('invalidate_configurazione_portale_cache')) invalidate_configurazione_portale_cache();
        db_esegui($conn, "DELETE FROM menu_voci");
        foreach ($d['menu'] ?? [] as $v)
            db_esegui($conn, "INSERT INTO menu_voci (id, genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id, visibile) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                      [(int)$v['id'], (int)$v['genitore_id'], (string)$v['etichetta'], (string)$v['url'], (int)$v['ordine'], (int)$v['apri_nuova_scheda'], (int)$v['ruolo_visibilita_id'], (int)($v['visibile'] ?? 1)]);
        db_esegui($conn, "DELETE FROM copie_configurazione WHERE id = ?", [(int)$c['id']]);
        return true;
    }
}
