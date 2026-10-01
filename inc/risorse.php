<?php
// inc/risorse.php - Calendari e risorse: aule, laboratori e sportelli (appuntamenti con gli uffici) prenotabili a slot.
// Ogni risorsa appartiene a un'area di tipo "calendario" e ha orari settimanali (risorse_orari), chiusure
// (risorse_chiusure, anche per tutta l'area) e prenotazioni (prenotazioni_risorse) con controllo delle sovrapposizioni.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('TIPI_RISORSA')) define('TIPI_RISORSA', [
    'aula' => ['Aula', 'fa-chalkboard'], 'laboratorio' => ['Laboratorio', 'fa-flask'],
    'sportello' => ['Sportello / appuntamento', 'fa-user-clock'], 'altro' => ['Altra risorsa', 'fa-cube'],
]);
if (!defined('ACCESSI_RISORSA')) define('ACCESSI_RISORSA', [
    'tutti' => 'Chiunque abbia fatto l\'accesso', 'studenti' => 'Studenti', 'docenti' => 'Docenti',
    'personale' => 'Docenti e personale di Ateneo',
]);
if (!defined('GIORNI_SETTIMANA')) define('GIORNI_SETTIMANA', [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica']);

if (!function_exists('risorsa')) {
    function risorsa($conn, int $id): ?array {
        $r = @$conn->query("SELECT r.*, p.titolo AS area_titolo, p.slug AS area_slug, p.colore_primario FROM risorse r JOIN pagine_eventi p ON p.id = r.pagina_id WHERE r.id = $id");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }
}

if (!function_exists('orari_risorsa')) {
    // Fasce orarie della risorsa per giorno della settimana: [1 => [['09:00:00','13:00:00'], ...], ...]
    function orari_risorsa($conn, int $risorsa_id): array {
        static $cache = [];
        if (!isset($cache[$risorsa_id])) {
            $out = [];
            $r = @$conn->query("SELECT giorno, dalle, alle FROM risorse_orari WHERE risorsa_id = $risorsa_id ORDER BY giorno, dalle");
            while ($r && $x = $r->fetch_assoc()) $out[(int)$x['giorno']][] = [$x['dalle'], $x['alle']];
            $cache[$risorsa_id] = $out;
        }
        return $cache[$risorsa_id];
    }
}

if (!function_exists('chiusura_risorsa')) {
    // Motivo della chiusura della risorsa (o di tutta l'area) nel giorno, null se aperta
    function chiusura_risorsa($conn, array $r, string $data): ?string {
        $st = $conn->prepare("SELECT motivo FROM risorse_chiusure WHERE (risorsa_id = ? OR (risorsa_id IS NULL AND pagina_id = ?)) AND ? BETWEEN dal AND al LIMIT 1");
        if (!$st) return null;
        $rid = (int)$r['id']; $pid = (int)$r['pagina_id'];
        $st->bind_param("iis", $rid, $pid, $data); $st->execute();
        $x = $st->get_result()->fetch_assoc();
        return $x ? ($x['motivo'] !== '' ? $x['motivo'] : 'Chiuso') : null;
    }
}

if (!function_exists('slot_risorsa')) {
    // Slot di un giorno: [['inizio' => 'Y-m-d H:i:s', 'fine' => ..., 'stato' => libero|occupato|passato|lontano|chiuso, 'fascia' => n], ...]
    // passato = prima dell'anticipo minimo; lontano = oltre i giorni prenotabili; fascia = indice della fascia oraria
    // (gli slot consecutivi si possono unire solo nella stessa fascia).
    function slot_risorsa($conn, array $r, string $data, ?int $escludi_pren = null): array {
        $giorno = (int)date('N', strtotime($data));
        $fasce = orari_risorsa($conn, (int)$r['id'])[$giorno] ?? [];
        if (!$fasce) return [];
        $chiuso = chiusura_risorsa($conn, $r, $data);
        $durata = max(5, (int)$r['durata_slot']);
        $occ = [];
        $st = $conn->prepare("SELECT inizio, fine FROM prenotazioni_risorse WHERE risorsa_id = ? AND stato IN ('confermata', 'da_approvare')
                              AND inizio < ? AND fine > ?" . ($escludi_pren ? " AND id <> " . (int)$escludi_pren : ''));
        $rid = (int)$r['id']; $fine_g = "$data 23:59:59"; $ini_g = "$data 00:00:00";
        $st->bind_param("iss", $rid, $fine_g, $ini_g); $st->execute();
        $res = $st->get_result();
        while ($res && $x = $res->fetch_assoc()) $occ[] = [strtotime($x['inizio']), strtotime($x['fine'])];
        $minimo = time() + max(0, (int)$r['anticipo_ore']) * 3600;
        $massimo = strtotime(date('Y-m-d', strtotime('+' . max(1, (int)$r['max_giorni']) . ' days')) . ' 23:59:59');
        $out = [];
        foreach ($fasce as $n => [$dalle, $alle]) {
            $t = strtotime("$data $dalle"); $fine_f = strtotime("$data $alle");
            while ($t + $durata * 60 <= $fine_f) {
                $f = $t + $durata * 60;
                $stato = 'libero';
                if ($chiuso !== null) $stato = 'chiuso';
                elseif ($t < $minimo) $stato = 'passato';
                elseif ($t > $massimo) $stato = 'lontano';
                else foreach ($occ as [$a, $b]) if ($t < $b && $f > $a) { $stato = 'occupato'; break; }
                $out[] = ['inizio' => date('Y-m-d H:i:s', $t), 'fine' => date('Y-m-d H:i:s', $f), 'stato' => $stato, 'fascia' => $n];
                $t = $f;
            }
        }
        return $out;
    }
}

if (!function_exists('gruppi_utente_nomi')) {
    // Nomi dei gruppi dell'utente (ruolo principale e secondari), in minuscolo
    function gruppi_utente_nomi($conn, array $u): array {
        $ids = array_filter(array_map('intval', array_merge([(int)($u['ruolo_id'] ?? 5)], explode(',', (string)($u['ruoli_secondari'] ?? '')))));
        if (!$ids) return [];
        $out = [];
        $r = $conn->query("SELECT nome FROM ruoli WHERE id IN (" . implode(',', $ids) . ")");
        while ($r && $x = $r->fetch_assoc()) $out[] = mb_strtolower($x['nome']);
        return $out;
    }
}

if (!function_exists('puo_prenotare_risorsa')) {
    // Chi può prenotare: amministratori e gestori dell'area sempre; poi in base a risorse.accesso
    function puo_prenotare_risorsa($conn, array $r, ?array $u): bool {
        if (!$u || empty($u['id'])) return false;
        $sec = explode(',', (string)($u['ruoli_secondari'] ?? ''));
        if ((int)($u['ruolo_id'] ?? 5) === 1 || in_array('1', $sec, true)) return true;
        $pag = $conn->query("SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id = " . (int)$r['pagina_id'])->fetch_assoc();
        if ($pag && in_array((int)$u['id'], ids_gestori_da_campi($pag['gestore_utente_id'] ?? 0, $pag['gestori_utenti_ids'] ?? '', $pag['permessi_gestori_json'] ?? ''), true)) return true;
        $gruppi = gruppi_utente_nomi($conn, $u);
        $docente = in_array(mb_strtolower(GRUPPI_PERSONALE['docenti']), $gruppi, true);
        $personale = $docente || (int)($u['ruolo_id'] ?? 5) === 4 || in_array('4', $sec, true)
                     || in_array(mb_strtolower(GRUPPI_PERSONALE['pta']), $gruppi, true) || in_array(mb_strtolower(GRUPPI_PERSONALE['altro']), $gruppi, true);
        $studente = (int)($u['ruolo_id'] ?? 5) === 3 || in_array('3', $sec, true) || !empty($u['matricola_studente']);
        return match ((string)$r['accesso']) {
            'studenti' => $studente,
            'docenti' => $docente,
            'personale' => $personale,
            default => true,
        };
    }
}

if (!function_exists('prenota_risorsa')) {
    // Prenota $n_slot slot consecutivi da $inizio (Y-m-d H:i:s) e, se la risorsa lo consente, ogni settimana fino a $ripeti_fino.
    // La prima occorrenza deve riuscire; le successive in conflitto vengono saltate. Ritorna
    // ['codici' => [...], 'saltate' => ['Y-m-d' => motivo], 'errore' => null|string, 'stato' => confermata|da_approvare, 'serie' => ?].
    function prenota_risorsa($conn, array $r, array $u, string $inizio, int $n_slot, string $motivo = '', ?string $ripeti_fino = null): array {
        $esito = ['codici' => [], 'saltate' => [], 'errore' => null, 'stato' => (int)$r['approvazione'] === 1 ? 'da_approvare' : 'confermata', 'serie' => null];
        if (!(int)$r['attiva']) { $esito['errore'] = "La risorsa non è prenotabile."; return $esito; }
        if (!puo_prenotare_risorsa($conn, $r, $u)) { $esito['errore'] = "Non sei abilitato a prenotare questa risorsa."; return $esito; }
        $n_slot = max(1, min(max(1, (int)$r['max_slot']), $n_slot));
        $t0 = strtotime($inizio);
        if (!$t0) { $esito['errore'] = "Orario non valido."; return $esito; }
        $date = [date('Y-m-d', $t0)];
        if ($ripeti_fino && (int)$r['ripetizione'] === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ripeti_fino)) {
            for ($k = 1; $k <= 25; $k++) {
                $d = date('Y-m-d', strtotime("+$k week", $t0));
                if ($d > $ripeti_fino) break;
                $date[] = $d;
            }
            if (count($date) > 1) $esito['serie'] = strtoupper(bin2hex(random_bytes(4)));
        }
        $ora = date('H:i:s', $t0);
        $motivo = mb_substr(trim(strip_tags($motivo)), 0, 500);
        $lock = 'dibest_risorsa_' . (int)$r['id'];
        $st_l = $conn->prepare("SELECT GET_LOCK(?, 10)"); $st_l->bind_param("s", $lock); $st_l->execute();
        if ((int)($st_l->get_result()->fetch_row()[0] ?? 0) !== 1) { $esito['errore'] = "Il calendario è occupato: riprova tra un istante."; return $esito; }
        try {
            $ins = $conn->prepare("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, serie, codice) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($date as $i => $d) {
                $slot = slot_risorsa($conn, $r, $d);
                $idx = null;
                foreach ($slot as $k => $s) if ($s['inizio'] === "$d $ora") { $idx = $k; break; }
                $motivo_no = null;
                if ($idx === null) $motivo_no = "orario non disponibile";
                else {
                    for ($j = 0; $j < $n_slot; $j++) {
                        $s = $slot[$idx + $j] ?? null;
                        if (!$s || $s['fascia'] !== $slot[$idx]['fascia']) { $motivo_no = "durata oltre l'orario di apertura"; break; }
                        if ($s['stato'] !== 'libero') { $motivo_no = ['occupato' => 'già prenotato', 'chiuso' => 'chiuso', 'passato' => 'troppo vicino', 'lontano' => 'troppo lontano'][$s['stato']] ?? 'non disponibile'; break; }
                    }
                }
                if ($motivo_no !== null) {
                    if ($i === 0) { $esito['errore'] = "Lo slot scelto non è più disponibile (" . $motivo_no . "): scegline un altro."; return $esito; }
                    $esito['saltate'][$d] = $motivo_no; continue;
                }
                $ini = $slot[$idx]['inizio']; $fin = $slot[$idx + $n_slot - 1]['fine'];
                $codice = 'RS-' . strtoupper(bin2hex(random_bytes(4)));
                $rid = (int)$r['id']; $uid = (int)$u['id'];
                $nome = (string)($u['nome'] ?? ''); $cognome = (string)($u['cognome'] ?? ''); $email = strtolower((string)($u['email'] ?? ''));
                $ins->bind_param("iisssssssss", $rid, $uid, $nome, $cognome, $email, $ini, $fin, $motivo, $esito['stato'], $esito['serie'], $codice);
                if ($ins->execute()) $esito['codici'][] = $codice;
            }
        } finally {
            $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lock) . "')");
        }
        if (!$esito['codici']) $esito['errore'] = "Nessuna prenotazione registrata.";
        return $esito;
    }
}

if (!function_exists('prenotazione_risorsa')) {
    // Prenotazione con risorsa e area (per id o per codice)
    function prenotazione_risorsa($conn, $chiave): ?array {
        $campo = is_int($chiave) ? 'pr.id' : 'pr.codice';
        $st = $conn->prepare("SELECT pr.*, r.nome AS risorsa_nome, r.tipo AS risorsa_tipo, r.luogo, r.pagina_id, r.email_notifiche, r.approvazione, p.titolo AS area_titolo, p.colore_primario
                              FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id JOIN pagine_eventi p ON p.id = r.pagina_id WHERE $campo = ? LIMIT 1");
        if (!$st) return null;
        if (is_int($chiave)) $st->bind_param("i", $chiave); else $st->bind_param("s", $chiave);
        $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }
}

if (!function_exists('quando_risorsa')) {
    // "Martedì 10/11/2026, 09:00–11:00"
    function quando_risorsa(array $p): string {
        $i = strtotime($p['inizio']); $f = strtotime($p['fine']);
        return GIORNI_SETTIMANA[(int)date('N', $i)] . ' ' . date('d/m/Y', $i) . ', ' . date('H:i', $i) . '–' . date('H:i', $f);
    }
}

if (!function_exists('email_gestori_risorsa')) {
    // Chi riceve le notifiche: indirizzi della risorsa, altrimenti i gestori dell'area con le notifiche attive
    function email_gestori_risorsa($conn, array $p): array {
        $out = array_filter(array_map('trim', explode(',', (string)($p['email_notifiche'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($out) return array_values(array_unique(array_map('strtolower', $out)));
        $pag = $conn->query("SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id = " . (int)$p['pagina_id'])->fetch_assoc();
        $ids = $pag ? ids_gestori_da_campi($pag['gestore_utente_id'] ?? 0, $pag['gestori_utenti_ids'] ?? '', $pag['permessi_gestori_json'] ?? '') : [];
        if ($ids) {
            $r = $conn->query("SELECT DISTINCT LOWER(email) e FROM utenti WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") AND email <> ''");
            while ($r && $x = $r->fetch_assoc()) if (filter_var($x['e'], FILTER_VALIDATE_EMAIL)) $out[] = $x['e'];
        }
        return $out ?: email_amministratori($conn);
    }
}

if (!function_exists('email_prenotazione_risorsa')) {
    // Email a chi ha prenotato. $tipo: registrata | approvata | rifiutata | annullata_gestore | promemoria
    // $codici: più prenotazioni della stessa serie in un'unica email (solo "registrata")
    function email_prenotazione_risorsa($conn, array $p, string $tipo, array $altre = [], array $saltate = []): bool {
        if (empty($p['email'])) return false;
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $base = url_base_sito();
        $titoli = ['registrata' => $p['stato'] === 'da_approvare' ? 'Richiesta ricevuta, in attesa di approvazione' : 'Prenotazione confermata',
                   'approvata' => 'Prenotazione approvata', 'rifiutata' => 'Prenotazione non approvata', 'annullata_gestore' => 'Prenotazione annullata',
                   'promemoria' => 'Promemoria: prenotazione di domani'];
        $quando = array_merge([$p], $altre);
        $righe = '';
        foreach ($quando as $q) $righe .= "<li>" . $h(quando_risorsa($q)) . " · codice <strong>" . $h($q['codice']) . "</strong></li>";
        $corpo = "<p>Gentile <strong>" . $h(trim($p['nome'] . ' ' . $p['cognome'])) . "</strong>,</p>"
               . "<p><strong>" . $h($titoli[$tipo] ?? '') . "</strong>: " . $h($p['risorsa_nome']) . ($p['luogo'] !== '' ? " (" . $h($p['luogo']) . ")" : '') . ".</p><ul>$righe</ul>"
               . ($p['motivo'] !== '' ? "<p>Motivo: " . $h($p['motivo']) . "</p>" : '')
               . ($saltate ? "<p>Non prenotate perché non disponibili: " . $h(implode(', ', array_map(fn($d, $m) => date('d/m/Y', strtotime($d)) . " ($m)", array_keys($saltate), $saltate))) . ".</p>" : '')
               . ($tipo === 'registrata' && $p['stato'] === 'da_approvare' ? "<p>Riceverai un'email quando la richiesta sarà approvata.</p>" : '')
               . (in_array($tipo, ['registrata', 'approvata', 'promemoria'], true)
                    ? "<p><a href='" . $h("$base/risorsa_ics.php?code=" . urlencode($p['codice'])) . "' style='background:#334155;color:#fff;padding:8px 14px;text-decoration:none;border-radius:4px;font-weight:bold;'>📥 Aggiungi al calendario (.ics)</a></p>" : '')
               . "<p>Le tue prenotazioni sono nella tua <a href='" . $h("$base/area_personale.php") . "'>Area personale</a>, dove puoi anche annullarle.</p>";
        return (bool)inviaNotificaEmail($p['email'], ($titoli[$tipo] ?? 'Prenotazione') . ": " . $p['risorsa_nome'], $corpo, $conn, colore_valido($p['colore_primario'] ?? ''));
    }
}

if (!function_exists('notifica_gestori_risorsa')) {
    // Email ai gestori: nuova prenotazione (o richiesta da approvare) oppure annullamento da parte dell'utente
    function notifica_gestori_risorsa($conn, array $p, string $tipo, int $n = 1): void {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $link = url_base_sito() . '/admin/prenotazioni_risorse.php?p_id=' . (int)$p['pagina_id'] . ($p['stato'] === 'da_approvare' ? '&stato=da_approvare' : '');
        $cosa = $tipo === 'annullata' ? 'ha annullato la prenotazione' : ($p['stato'] === 'da_approvare' ? 'chiede di prenotare' : 'ha prenotato');
        $corpo = "<p><strong>" . $h(trim($p['nome'] . ' ' . $p['cognome'])) . "</strong> (" . $h($p['email']) . ") $cosa <strong>" . $h($p['risorsa_nome']) . "</strong>:"
               . " " . $h(quando_risorsa($p)) . ($n > 1 ? " e altre " . ($n - 1) . " date (ogni settimana)" : '') . ".</p>"
               . ($p['motivo'] !== '' ? "<p>Motivo: " . $h($p['motivo']) . "</p>" : '')
               . "<p><a href='" . $h($link) . "'>" . ($p['stato'] === 'da_approvare' && $tipo !== 'annullata' ? 'Approva o rifiuta' : 'Apri le prenotazioni') . "</a></p>";
        $ogg = ($tipo === 'annullata' ? "Annullata: " : ($p['stato'] === 'da_approvare' ? "Da approvare: " : "Nuova prenotazione: ")) . $p['risorsa_nome'] . " – " . date('d/m H:i', strtotime($p['inizio']));
        foreach (email_gestori_risorsa($conn, $p) as $em) inviaNotificaEmail($em, $ogg, $corpo, $conn, colore_valido($p['colore_primario'] ?? ''));
    }
}

if (!function_exists('cambia_stato_prenotazione_risorsa')) {
    // Approva / rifiuta / annulla (gestore) oppure annulla (utente). Ritorna true se lo stato è cambiato.
    function cambia_stato_prenotazione_risorsa($conn, int $id, string $nuovo, bool $da_gestore = true): bool {
        $p = prenotazione_risorsa($conn, $id);
        if (!$p) return false;
        $da = ['confermata' => ['da_approvare'], 'rifiutata' => ['da_approvare'], 'annullata' => ['confermata', 'da_approvare']][$nuovo] ?? [];
        if (!in_array($p['stato'], $da, true)) return false;
        $st = $conn->prepare("UPDATE prenotazioni_risorse SET stato = ? WHERE id = ? AND stato = ?");
        $st->bind_param("sis", $nuovo, $id, $p['stato']); $st->execute();
        if ($st->affected_rows !== 1) return false;
        $p['stato'] = $nuovo;
        if ($da_gestore) email_prenotazione_risorsa($conn, $p, $nuovo === 'confermata' ? 'approvata' : ($nuovo === 'rifiutata' ? 'rifiutata' : 'annullata_gestore'));
        else notifica_gestori_risorsa($conn, $p, 'annullata');
        return true;
    }
}

if (!function_exists('ics_prenotazione_risorsa')) {
    // File .ics della prenotazione
    function ics_prenotazione_risorsa(array $p): string {
        $e = fn($s) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], (string)$s);
        $utc = fn($t) => gmdate('Ymd\THis\Z', strtotime($t));
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Didattica DiBEST//IT\r\nCALSCALE:GREGORIAN\r\nBEGIN:VEVENT\r\n"
             . "UID:" . $e($p['codice']) . "@didattica-dibest\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
             . "DTSTART:" . $utc($p['inizio']) . "\r\nDTEND:" . $utc($p['fine']) . "\r\n"
             . "SUMMARY:" . $e($p['risorsa_nome']) . "\r\n" . ($p['luogo'] !== '' ? "LOCATION:" . $e($p['luogo']) . "\r\n" : '')
             . "DESCRIPTION:" . $e("Prenotazione " . $p['codice'] . ($p['motivo'] !== '' ? " – " . $p['motivo'] : '')) . "\r\n"
             . "STATUS:" . ($p['stato'] === 'confermata' ? 'CONFIRMED' : 'TENTATIVE') . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
