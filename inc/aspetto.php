<?php
// inc/aspetto.php - Colori delle aree, etichette di turni e orari, impaginazione delle email.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// COLORE DELL'AREA (card, badge, email)
// =======================================================================
if (!function_exists('colore_valido')) {
    // Colore #RRGGBB sicuro da stampare negli attributi style; altrimenti il default.
    function colore_valido($hex, string $default = '#B30000'): string {
        $hex = trim((string)$hex);
        if (preg_match('/^#[0-9a-f]{6}$/i', $hex)) return strtoupper($hex);
        if (preg_match('/^#[0-9a-f]{3}$/i', $hex)) return strtoupper('#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3]);
        return $default;
    }
}

if (!function_exists('colore_testo_su')) {
    // Colore del testo leggibile su uno sfondo (regola di contrasto WCAG):
    // bianco o grigio quasi nero, quello con il contrasto più alto.
    function colore_testo_su($hex_sfondo): string {
        $h = ltrim(colore_valido($hex_sfondo), '#');
        $lin = function (int $c): float { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
        $L = 0.2126 * $lin(hexdec(substr($h, 0, 2))) + 0.7152 * $lin(hexdec(substr($h, 2, 2))) + 0.0722 * $lin(hexdec(substr($h, 4, 2)));
        $contrasto_bianco = 1.05 / ($L + 0.05);
        $contrasto_scuro  = ($L + 0.05) / (0.0216 + 0.05); // 0.0216 = luminanza di #1F2937
        return $contrasto_bianco >= $contrasto_scuro ? '#FFFFFF' : '#1F2937';
    }
}

if (!function_exists('colore_area_turno')) {
    // Colore primario dell'area a cui appartiene un turno (memorizzato per la richiesta).
    function colore_area_turno($conn, $turno_id): string {
        static $cache = [];
        $turno_id = (int)$turno_id;
        if (!isset($cache[$turno_id])) {
            $res = $conn->query("SELECT pe.colore_primario FROM turni t JOIN eventi e ON t.evento_id = e.id
                                 JOIN pagine_eventi pe ON e.pagina_id = pe.id WHERE t.id = $turno_id LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
            $cache[$turno_id] = colore_valido($row['colore_primario'] ?? '');
        }
        return $cache[$turno_id];
    }
}

if (!function_exists('impagina_email')) {
    // Impaginazione comune delle email: intestazione con il colore dell'area, corpo, piè di pagina.
    // I pulsanti col rosso istituzionale nel corpo prendono il colore dell'area.
    // Se il corpo è già un documento HTML completo (template personalizzato) resta com'è.
    function impagina_email(string $corpo, string $titolo, ?string $colore = null): string {
        if (stripos($corpo, '<html') !== false || stripos($corpo, '<body') !== false) return $corpo;
        $col   = colore_valido($colore ?? '');
        $testo = colore_testo_su($col);
        if ($colore !== null) {
            // Pulsanti "sfondo rosso + testo bianco": sfondo dell'area e testo a contrasto
            $corpo = preg_replace('/background(-color)?\s*:\s*#B[38]0000\s*;\s*color\s*:\s*(#fff(fff)?|white)/i',
                                  'background$1:' . $col . '; color:' . $testo, $corpo);
            $corpo = str_ireplace(['#B30000', '#B80000'], $col, $corpo);
        }
        return '<div style="background:#f3f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
             . '<tr><td style="background:' . $col . ';color:' . $testo . ';padding:16px 24px;font-size:18px;font-weight:bold;">' . htmlspecialchars($titolo) . '</td></tr>'
             . '<tr><td style="padding:24px;color:#1f2937;font-size:15px;line-height:1.6;">' . $corpo . '</td></tr>'
             . '<tr><td style="padding:12px 24px;background:#f9fafb;color:#6b7280;font-size:12px;">Messaggio automatico: non rispondere a questa email. Gestisci le tue prenotazioni dall\'Area Personale del portale.</td></tr>'
             . '</table></div>';
    }
}

if (!function_exists('invia_email_attestato_se_concluso')) {
    function invia_email_attestato_se_concluso($conn, $pr_id) {
        $stmt = $conn->prepare(
            "SELECT p.id, p.turno_id, p.nome, p.cognome, p.email, p.codice_prenotazione,
                    p.attestato_inviato, p.presente,
                    t.data_turno, t.orario_fine, e.titolo
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE p.id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $pr_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || $row['presente'] != 1) return false;
        if (!empty($row['attestato_inviato'])) return false;
        if (empty($row['email'])) return false;

        // Progetti: niente attestato se non previsto o se il progetto non è finito; per le scuole
        // partono gli attestati degli studenti (al docente), non quello personale
        $r_ev = $conn->query("SELECT t.evento_id FROM turni t WHERE t.id = " . (int)$row['turno_id']);
        $regola = regola_attestato_evento($conn, $r_ev ? (int)($r_ev->fetch_assoc()['evento_id'] ?? 0) : 0);
        if ($regola === 'no' || $regola === 'attendi') return false;
        if ($regola === 'gruppo') return invia_attestati_gruppo($conn, (int)$pr_id) === true;

        // Turni senza data: l'attestato parte alla registrazione della presenza
        if (!empty($row['data_turno']) && !turno_concluso($row)) return false;

        $domain = url_base_sito();

        $link_attestato = $domain . "/stampa_attestato.php?code=" . urlencode($row['codice_prenotazione']);
        $link_area = $domain . "/area_personale.php";
        $oggetto = "Il tuo Attestato è pronto: " . $row['titolo'];
        $corpo = "<p>Gentile <strong>" . htmlspecialchars($row['nome']) . " " . htmlspecialchars($row['cognome']) . "</strong>,</p>"
               . "<p>Grazie per aver partecipato all'evento <strong>" . htmlspecialchars($row['titolo']) . "</strong>" . (!empty($row['data_turno']) ? " del " . date('d/m/Y', strtotime($row['data_turno'])) : "") . ".</p>"
               . "<p>Il tuo <strong>Attestato di Partecipazione</strong> è disponibile per il download.</p>"
               . "<p style='text-align:center; margin:30px 0;'>"
               . "<a href='" . $link_attestato . "' style='background-color:#198754; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:16px;'>📄 Scarica il tuo Attestato</a>"
               . "</p>"
               . "<p>In alternativa puoi recuperarlo dalla tua <a href='" . $link_area . "'>Area Personale</a>.</p>"
               . "<p>Cordiali saluti,<br>Il team Eventi DiBEST</p>";

        inviaNotificaEmail($row['email'], $oggetto, $corpo, $conn, colore_area_turno($conn, $row['turno_id']));
        $id_safe = (int)$pr_id;
        $conn->query("UPDATE prenotazioni SET attestato_inviato = 1 WHERE id = $id_safe");
        return true;
    }
}

// 3. HELPER DATE E CALENDARI (Ora Ripristinati!)
if (!function_exists('formattaDataItaliano')) {
    function formattaDataItaliano($data_str) {
        if (empty($data_str) || $data_str == '0000-00-00' || $data_str == '9999-12-31') return 'Date da definire';
        $giorni = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
        $mesi   = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
        $timestamp = strtotime($data_str);
        return $giorni[date('w', $timestamp)] . ' <span class="text-danger fw-bold">' . date('j', $timestamp) . ' ' . ($mesi[date('n', $timestamp)] ?? '') . ' ' . date('Y', $timestamp) . '</span>';
    }
}

// Turni: nome, data e orari sono tutti facoltativi (almeno nome o data)
if (!function_exists('orario_turno')) {
    function orario_turno(array $t): string {
        if (empty($t['orario_inizio'])) return '';
        return substr($t['orario_inizio'], 0, 5) . (!empty($t['orario_fine']) ? '–' . substr($t['orario_fine'], 0, 5) : '');
    }
}

if (!function_exists('etichetta_turno')) {
    // Testo semplice (da passare a htmlspecialchars): "Gruppo 1 · 22/09/2026 · 09:30–11:00"
    function etichetta_turno(array $t): string {
        $parti = [];
        if (!empty($t['nome_turno'])) $parti[] = $t['nome_turno'];
        if (!empty($t['data_turno'])) $parti[] = date('d/m/Y', strtotime($t['data_turno']));
        $ora = orario_turno($t);
        if ($ora !== '') $parti[] = $ora;
        return $parti ? implode(' · ', $parti) : 'Turno';
    }
}

if (!function_exists('turno_concluso')) {
    // Un turno senza data non scade mai.
    function turno_concluso(array $t): bool {
        if (empty($t['data_turno'])) return false;
        $fine = $t['data_turno'] . ' ' . (!empty($t['orario_fine']) ? substr($t['orario_fine'], 0, 8) : '23:59:59');
        return date('Y-m-d H:i:s') > $fine;
    }
}

if (!function_exists('pulisci_descrizione_breve')) {
    // HTML della descrizione breve: solo grassetto, corsivo, sottolineato e a capo, senza attributi.
    // Oltre 300 caratteri visibili si perde la formattazione e il testo viene troncato.
    function pulisci_descrizione_breve(string $html): string {
        $html = preg_replace('#</p>\s*<p[^>]*>#i', '<br>', $html);
        $html = strip_tags($html, '<strong><b><em><i><u><br>');
        $html = preg_replace('#<(/?)(strong|b|em|i|u|br)\b[^>]*>#i', '<$1$2>', $html);
        $html = trim(preg_replace('#^(?:\s|&nbsp;|<br>)+|(?:\s|&nbsp;|<br>)+$#iu', '', $html));
        $testo = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        if ($testo === '') return '';
        if (mb_strlen($testo) > 300) return htmlspecialchars(mb_substr($testo, 0, 300));
        return $html;
    }
}

if (!function_exists('testo_card_evento')) {
    // HTML delle card: la descrizione breve; se manca, l'inizio della descrizione completa senza formattazione
    function testo_card_evento(array $ev, int $max = 220): string {
        $breve = pulisci_descrizione_breve((string)($ev['descrizione_breve'] ?? ''));
        if ($breve !== '') return $breve;
        $testo = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string)($ev['descrizione'] ?? ''))), ENT_QUOTES, 'UTF-8')));
        return htmlspecialchars(mb_strimwidth($testo, 0, $max, '…'));
    }
}

if (!function_exists('finestra_prenotazione')) {
    // Scadenza/apertura delle prenotazioni di un turno, o di un insieme di turni (card dell'evento):
    // tra i turni non conclusi, se qualcuno è prenotabile ora -> la chiusura più vicina ("Prenota entro…"),
    // altrimenti l'apertura più vicina ("Prenotazioni dal…"), altrimenti "Prenotazioni chiuse".
    // Ritorna ['testo', 'icona', 'bg', 'fg'] oppure null se non c'è nulla da dire (nessuna data impostata).
    function finestra_prenotazione(array $turni): ?array {
        $ora = date('Y-m-d H:i:s');
        $aperti = 0; $chiusura = null; $apertura = null; $chiusi = 0; $attivi = 0;
        foreach ($turni as $t) {
            if (turno_concluso($t)) continue;
            $attivi++;
            if (!empty($t['data_apertura']) && $ora < $t['data_apertura']) { if ($apertura === null || $t['data_apertura'] < $apertura) $apertura = $t['data_apertura']; continue; }
            if (!empty($t['data_chiusura']) && $ora > $t['data_chiusura']) { $chiusi++; continue; }
            $aperti++;
            if (!empty($t['data_chiusura']) && ($chiusura === null || $t['data_chiusura'] < $chiusura)) $chiusura = $t['data_chiusura'];
        }
        $fmt = fn($d) => date('d/m/Y', strtotime($d)) . ' alle ' . date('H:i', strtotime($d));
        if ($aperti > 0 && $chiusura !== null) {
            $urgente = strtotime($chiusura) - time() < 48 * 3600;
            return ['testo' => 'Prenota entro il ' . $fmt($chiusura), 'icona' => 'fa-hourglass-half', 'bg' => $urgente ? '#FEE2E2' : '#FEF3C7', 'fg' => $urgente ? '#991B1B' : '#92400E'];
        }
        if ($aperti > 0) return null;
        if ($apertura !== null) return ['testo' => 'Prenotazioni dal ' . $fmt($apertura), 'icona' => 'fa-door-open', 'bg' => '#DBEAFE', 'fg' => '#1E40AF'];
        if ($attivi > 0 && $chiusi === $attivi) return ['testo' => 'Prenotazioni chiuse', 'icona' => 'fa-lock', 'bg' => '#F1F5F9', 'fg' => '#334155'];
        return null;
    }
}

if (!function_exists('getGoogleCalendarUrl')) {
    function getGoogleCalendarUrl($title, $data_turno, $ora_inizio, $ora_fine, $location, $details) {
        $st = date('Ymd\THis', strtotime($data_turno . ' ' . $ora_inizio));
        $et = date('Ymd\THis', strtotime($data_turno . ' ' . $ora_fine));
        return "https://calendar.google.com/calendar/render?action=TEMPLATE&text=" . urlencode($title) . "&dates=" . $st . "/" . $et . "&details=" . urlencode($details) . "&location=" . urlencode($location);
    }
}

// getPostiOccupati() è definita in config.php (unica versione, con regola degli stati e FOR UPDATE)
