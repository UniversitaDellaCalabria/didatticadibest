<?php
// inc/avvisi.php - Avvisi per email dei nuovi eventi per ambito (avvisi.php): ci si iscrive scegliendo gli ambiti
// (e/o le attività per le scuole), si conferma dal link ricevuto (chi è entrato con la stessa email è confermato subito),
// ci si cancella con il link in fondo a ogni email. Il cron (admin/cron_reminders.php) manda a ogni iscritto un solo
// riepilogo con i nuovi eventi dei suoi ambiti; ogni evento si annuncia una volta (tabella avvisi_eventi).
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('iscrivi_avvisi')) {
    // Ritorna [messaggio, errore]. $utente: utente collegato (se l'email è la sua, l'iscrizione è confermata subito)
    function iscrivi_avvisi($conn, string $email, array $ambiti, bool $scuole, ?array $utente = null): array {
        $email = strtolower(trim($email));
        $ambiti = array_values(array_intersect(array_keys(AMBITI_EVENTO), array_map('strval', $ambiti)));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, "Scrivi un indirizzo email valido."];
        if (!$ambiti && !$scuole) return [null, "Scegli almeno un argomento."];
        $sua = $utente && strtolower(trim((string)($utente['email'] ?? ''))) === $email;
        $x = db_riga($conn, "SELECT * FROM avvisi_iscrizioni WHERE email = ?", [$email]);
        $tok = $x['token'] ?? bin2hex(random_bytes(20));
        if ($x) db_esegui($conn, "UPDATE avvisi_iscrizioni SET ambiti = ?, scuole = ?, utente_id = COALESCE(?, utente_id)" . ($sua ? ", confermata_il = COALESCE(confermata_il, NOW())" : '') . " WHERE id = ?",
                          [implode(',', $ambiti), $scuole ? 1 : 0, $sua ? (int)$utente['id'] : null, (int)$x['id']]);
        else db_esegui($conn, "INSERT INTO avvisi_iscrizioni (email, ambiti, scuole, token, utente_id, confermata_il) VALUES (?, ?, ?, ?, ?, " . ($sua ? 'NOW()' : 'NULL') . ")",
                       [$email, implode(',', $ambiti), $scuole ? 1 : 0, $tok, $sua ? (int)$utente['id'] : null]);
        if ($sua || !empty($x['confermata_il'])) return ["Preferenze salvate: riceverai un'email quando escono nuovi eventi dei tuoi argomenti.", null];
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $link = url_base_sito() . '/avvisi.php?conferma=' . $tok;
        inviaNotificaEmail($email, "Conferma l'iscrizione agli avvisi degli eventi",
            "<p>Hai chiesto di ricevere un'email quando il Dipartimento pubblica nuovi eventi di: <strong>" . $h(testo_argomenti_avvisi(implode(',', $ambiti), $scuole)) . "</strong>.</p>"
            . "<p style='margin-top:18px;'><a href='" . $h($link) . "' style='background:#0056B3;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Conferma l'iscrizione</a></p>"
            . "<p style='font-size:12px;color:#64748b;'>Se non sei stato tu, ignora questa email: senza conferma non riceverai nulla e i dati si cancellano dopo 30 giorni.</p>", $conn, '#0056B3');
        return ["Ti abbiamo mandato un'email: clicca sul link per confermare l'iscrizione.", null];
    }
    function testo_argomenti_avvisi(string $ambiti, bool $scuole): string {
        $n = array_map(fn($k) => AMBITI_EVENTO[$k]['nome'], array_values(array_intersect(array_keys(AMBITI_EVENTO), explode(',', $ambiti))));
        if ($scuole) $n[] = 'attività per le scuole';
        return implode(', ', $n);
    }
    function iscrizione_avvisi_per_token($conn, string $tok): ?array {
        return preg_match('/^[a-f0-9]{40}$/', $tok) ? db_riga($conn, "SELECT * FROM avvisi_iscrizioni WHERE token = ?", [$tok]) : null;
    }
    function conferma_avvisi($conn, string $tok): bool {
        return ($x = iscrizione_avvisi_per_token($conn, $tok)) && db_esegui($conn, "UPDATE avvisi_iscrizioni SET confermata_il = COALESCE(confermata_il, NOW()) WHERE id = ?", [(int)$x['id']]) >= 0;
    }
    function cancella_avvisi($conn, string $tok): bool {
        return ($x = iscrizione_avvisi_per_token($conn, $tok)) && db_esegui($conn, "DELETE FROM avvisi_iscrizioni WHERE id = ?", [(int)$x['id']]) > 0;
    }
    // Iscritti confermati per argomento (pannello): ['orientamento' => n, …, 'scuole' => n, '' => totale]
    function conta_iscritti_avvisi($conn): array {
        $n = array_fill_keys(array_keys(AMBITI_EVENTO), 0) + ['scuole' => 0, '' => 0];
        foreach (db_righe($conn, "SELECT ambiti, scuole FROM avvisi_iscrizioni WHERE confermata_il IS NOT NULL") as $x) {
            $n['']++; if ((int)$x['scuole']) $n['scuole']++;
            foreach (explode(',', $x['ambiti']) as $a) if (isset($n[$a]) && $a !== '') $n[$a]++;
        }
        return $n;
    }
}

if (!function_exists('invia_avvisi_eventi')) {
    // Cron: nuovi eventi in programma (aree visibili, pubblici) non ancora annunciati → un riepilogo per iscritto con quelli
    // dei suoi argomenti. Al primo avvio segna come annunciati gli eventi già esistenti senza inviare nulla.
    // Toglie anche le iscrizioni mai confermate dopo 30 giorni. Ritorna le email inviate.
    function invia_avvisi_eventi($conn): int {
        db_esegui($conn, "DELETE FROM avvisi_iscrizioni WHERE confermata_il IS NULL AND creata_il < NOW() - INTERVAL 30 DAY");
        $primo = !(int)db_valore($conn, "SELECT COUNT(*) FROM avvisi_eventi");
        $gia = array_map('intval', array_column(db_righe($conn, "SELECT evento_id FROM avvisi_eventi"), 'evento_id'));
        $nuovi = array_values(array_filter(eventi_agenda($conn), fn($e) => !in_array((int)$e['id'], $gia, true)));
        if ($primo) {
            // Primo avvio: gli eventi già pubblicati non sono «nuovi»; serve anche una riga se non ce ne sono
            foreach ($nuovi as $e) db_esegui($conn, "INSERT IGNORE INTO avvisi_eventi (evento_id) VALUES (?)", [(int)$e['id']]);
            db_esegui($conn, "INSERT IGNORE INTO avvisi_eventi (evento_id, destinatari) VALUES (0, 0)");
            return 0;
        }
        if (!$nuovi) return 0;
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $mm = [1 => 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
        $per_ev = []; $n = 0;
        foreach (db_righe($conn, "SELECT * FROM avvisi_iscrizioni WHERE confermata_il IS NOT NULL") as $isc) {
            $amb = array_filter(explode(',', $isc['ambiti']));
            $suoi = array_values(array_filter($nuovi, fn($e) => array_intersect($amb, $e['ambiti']) || ((int)$isc['scuole'] && $e['per_scuole'])));
            if (!$suoi) continue;
            $righe = '';
            foreach ($suoi as $e) {
                $quando = $e['prossima_data'] ? (int)date('j', strtotime($e['prossima_data'])) . ' ' . $mm[(int)date('n', strtotime($e['prossima_data']))] . ' ' . date('Y', strtotime($e['prossima_data'])) . ($e['prossimo_orario'] ? ', ore ' . $e['prossimo_orario'] : '') : 'data da definire';
                $righe .= "<tr><td style='padding:8px 0;border-bottom:1px solid #e5e7eb;'><a href='" . $h(url_base_sito() . '/' . $e['url']) . "' style='font-weight:bold;color:#0f172a;'>" . $h($e['titolo']) . "</a>"
                        . (!empty($e['relatore']) ? "<br><span style='color:#475569;'>" . $h($e['relatore']) . "</span>" : '')
                        . "<br><span style='color:#64748b;font-size:13px;'>" . $h($quando) . ($e['luogo'] ? ' · ' . $h($e['luogo']) : '') . ' · ' . $h(implode(', ', array_map(fn($a) => AMBITI_EVENTO[$a]['nome'], $e['ambiti']))) . "</span></td></tr>";
                $per_ev[(int)$e['id']] = ($per_ev[(int)$e['id']] ?? 0) + 1;
            }
            $gestisci = url_base_sito() . '/avvisi.php?t=' . $isc['token'];
            inviaNotificaEmail($isc['email'], count($suoi) === 1 ? "Nuovo evento: " . $suoi[0]['titolo'] : count($suoi) . " nuovi eventi del Dipartimento",
                "<p>Nuovi appuntamenti su: <strong>" . $h(testo_argomenti_avvisi($isc['ambiti'], (bool)$isc['scuole'])) . "</strong>.</p><table style='width:100%;border-collapse:collapse;font-size:14px;'>$righe</table>"
                . "<p style='margin-top:16px;'><a href='" . $h(url_base_sito() . '/agenda.php') . "'>Tutta l'agenda</a></p>"
                . "<p style='font-size:12px;color:#64748b;margin-top:18px;'>Ricevi questa email perché ti sei iscritto agli avvisi. <a href='" . $h($gestisci) . "'>Cambia argomenti o cancellati</a>.</p>", $conn, '#0056B3');
            db_esegui($conn, "UPDATE avvisi_iscrizioni SET ultimo_invio_il = NOW() WHERE id = ?", [(int)$isc['id']]);
            $n++;
        }
        foreach ($nuovi as $e) db_esegui($conn, "INSERT IGNORE INTO avvisi_eventi (evento_id, destinatari) VALUES (?, ?)", [(int)$e['id'], $per_ev[(int)$e['id']] ?? 0]);
        return $n;
    }
}
