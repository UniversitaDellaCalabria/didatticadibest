<?php
// inc/prenotazioni.php - Modulo di prenotazione: controllo anti-robot, termini di annullamento, referenti, campi e studenti.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// ── CAPTCHA delle prenotazioni pubbliche (chi prenota senza accesso) ──────────
// Domanda semplice (una somma) generata dal server: niente servizi esterni, niente cookie di terzi.
// Una domanda per pagina (vale per tutte le finestre di prenotazione della pagina), risposta in sessione,
// valida una sola volta; si tengono le ultime 10 pagine aperte (più schede del browser).
if (!function_exists('captcha_prenotazione')) {
    function captcha_prenotazione(): array {
        static $corrente = null;
        if ($corrente !== null) return $corrente;
        $a = random_int(2, 9); $b = random_int(1, 9);
        $id = bin2hex(random_bytes(8));
        $lista = $_SESSION['captcha_pren'] ?? [];
        $lista[$id] = ['r' => $a + $b, 't' => time()];
        $_SESSION['captcha_pren'] = array_slice($lista, -10, null, true);
        return $corrente = ['id' => $id, 'domanda' => "Quanto fa $a + $b?"];
    }
}

if (!function_exists('captcha_verifica')) {
    // Ritorna null se la risposta è giusta, altrimenti il messaggio da mostrare.
    // Rifiuta anche i moduli inviati in meno di 3 secondi (tipico dei programmi automatici).
    function captcha_verifica(string $id, string $risposta): ?string {
        $c = $_SESSION['captcha_pren'][$id] ?? null;
        if ($c !== null) unset($_SESSION['captcha_pren'][$id]);
        if ($c === null || time() - (int)$c['t'] > 7200) return "La domanda di controllo è scaduta: ricarica la pagina e riprova.";
        if (time() - (int)$c['t'] < 3) return "Modulo inviato troppo in fretta: attendi qualche secondo e riprova.";
        if (!preg_match('/^\s*\d+\s*$/', $risposta) || (int)$risposta !== (int)$c['r']) return "La risposta alla domanda di controllo non è corretta: riprova.";
        return null;
    }
}

if (!function_exists('annullamento_scaduto')) {
    // Il turno ha una scadenza per annullare/cambiare turno ed è passata
    function annullamento_scaduto(?array $turno): bool {
        return !empty($turno['annullabile_fino']) && date('Y-m-d H:i:s') > $turno['annullabile_fino'];
    }
}

if (!function_exists('leggi_referenti_post')) {
    // Referenti dal form (ref_ruolo[], ref_nome[], ref_email[], ref_tel[], ref_link[], ref_notifiche[]) di progetti ed eventi:
    // righe con almeno nome o email, email/telefono/link non validi scartati (le email scartate finiscono in $email_scartate).
    function leggi_referenti_post(?array &$email_scartate = null): array {
        $referenti = []; $email_scartate = [];
        foreach ((array)($_POST['ref_nome'] ?? []) as $i => $nome_r) {
            $r = [
                'ruolo'    => mb_substr(trim((string)($_POST['ref_ruolo'][$i] ?? '')), 0, 60),
                'nome'     => mb_substr(trim((string)$nome_r), 0, 120),
                'email'    => mb_substr(strtolower(trim((string)($_POST['ref_email'][$i] ?? ''))), 0, 150),
                'telefono' => mb_substr(trim((string)($_POST['ref_tel'][$i] ?? '')), 0, 40),
                'link'     => mb_substr(trim((string)($_POST['ref_link'][$i] ?? '')), 0, 300),
                // Riceve il riepilogo di ogni iscrizione e disdetta (come i gestori)
                'notifiche' => (($_POST['ref_notifiche'][$i] ?? '0') === '1') ? 1 : 0,
            ];
            // Persona scelta dall'anagrafe di Ateneo: il nome porta alla sua pagina nel portale eventi
            $pid = trim((string)($_POST['ref_persona'][$i] ?? ''));
            if ($pid !== '' && isset($GLOBALS['conn']) && ($pers = persona_ateneo($GLOBALS['conn'], $pid))) {
                $r['persona_id'] = $pers['id'];
                if (empty($pers['dettaglio_il'])) dettaglio_persona($GLOBALS['conn'], $pers); // foto e scheda pronte per le pagine pubbliche
            }
            if ($r['email'] !== '' && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { $email_scartate[] = $r['email']; $r['email'] = ''; }
            if ($r['telefono'] !== '' && !preg_match('/^[0-9 +().\/-]{5,40}$/', $r['telefono'])) $r['telefono'] = '';
            // Pagina personale: solo indirizzi http(s), es. https://www.unical.it/... ("www.…" senza schema diventa https://)
            if ($r['link'] !== '' && !preg_match('#^https?://#i', $r['link'])) $r['link'] = 'https://' . $r['link'];
            if ($r['link'] !== '' && !filter_var($r['link'], FILTER_VALIDATE_URL)) $r['link'] = '';
            if ($r['email'] === '') $r['notifiche'] = 0;
            if ($r['nome'] === '' && $r['email'] === '') continue;
            $referenti[] = $r;
            if (count($referenti) >= 10) break;
        }
        return $referenti;
    }
}

if (!function_exists('salva_referenti_evento')) {
    // Referenti di un evento normale: stessi dati dei progetti, salvati nella scheda (progetti_dettagli.referenti_json)
    function salva_referenti_evento($conn, int $ev_id, array $referenti): void {
        $json = $referenti ? json_encode($referenti, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $conn->prepare("INSERT INTO progetti_dettagli (evento_id, referenti_json, updated_at) VALUES (?, ?, NOW())
                                ON DUPLICATE KEY UPDATE referenti_json = VALUES(referenti_json), updated_at = NOW()");
        $stmt->bind_param("is", $ev_id, $json);
        $stmt->execute();
    }
}

if (!function_exists('salva_corso_evento')) {
    // Corso di laurea / struttura di un evento normale (campi struttura e corso_codice del modulo), nella scheda progetti_dettagli
    function salva_corso_evento($conn, int $ev_id): void {
        if (!isset($_POST['struttura']) && !isset($_POST['corso_codice'])) return;
        $testo = mb_substr(trim((string)($_POST['struttura'] ?? '')), 0, 255);
        $corso = corso_studio($conn, (string)($_POST['corso_codice'] ?? ''));
        $cod = $corso['codice'] ?? null;
        if ($testo === '' && $cod === null && !$conn->query("SELECT 1 FROM progetti_dettagli WHERE evento_id = $ev_id")->num_rows) return;
        $stmt = $conn->prepare("INSERT INTO progetti_dettagli (evento_id, struttura, corso_codice, updated_at) VALUES (?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE struttura = VALUES(struttura), corso_codice = VALUES(corso_codice), updated_at = NOW()");
        $stmt->bind_param("iss", $ev_id, $testo, $cod);
        $stmt->execute();
    }
}

if (!function_exists('html_campi_form_admin')) {
    // Campi del Form Builder per l'evento indicato, da usare nell'admin (prenotazione manuale e modifica).
    // $valori = dati_custom_json già salvati. Condizioni "mostra se" ignorate: in admin si vede tutto, niente obbligatori.
    // Gli allegati non si caricano da qui: si mostrano i link a quelli esistenti.
    function html_campi_form_admin($conn, int $evento_id, array $valori = [], string $pref = 'cf', int $turno_id = 0): string {
        $r_ev = $conn->query("SELECT e.pagina_id, e.tipo FROM eventi e WHERE e.id = $evento_id LIMIT 1");
        $ev = $r_ev ? $r_ev->fetch_assoc() : null;
        if (!$ev) return '';
        $is_progetto = $ev['tipo'] === 'progetto';
        $dett = get_dettagli_progetti($conn, [$evento_id])[$evento_id] ?? null;
        $r_tl = $turno_id > 0 ? $conn->query("SELECT min_partecipanti, max_partecipanti FROM turni WHERE id = $turno_id AND evento_id = $evento_id") : null;
        $lim = limiti_partecipanti($dett, $r_tl ? $r_tl->fetch_assoc() : null);
        $pag = (int)$ev['pagina_id'];
        $res = $conn->query("SELECT * FROM campi_form WHERE (pagina_id = $pag AND (evento_id IS NULL OR evento_id = 0)) OR evento_id = $evento_id ORDER BY ordine ASC, id ASC");
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $out = '';
        while ($res && $cf = $res->fetch_assoc()) {
            if (!campo_form_visibile($cf, $is_progetto, $dett)) continue;
            $tipo = $cf['tipo_campo']; $nome = $cf['nome_campo']; $name = 'custom_' . $nome;
            $val = (string)($valori[$nome] ?? '');
            $id = $pref . '_' . (int)$cf['id'];
            $etichetta = $nome === CAMPO_PARTECIPANTI ? 'Numero di studenti partecipanti' : $cf['etichetta'];
            $opts = !empty($cf['opzioni_select']) ? array_map('trim', explode(',', $cf['opzioni_select'])) : [];
            if ($tipo === 'hidden') { $out .= '<input type="hidden" name="' . $h($name) . '" value="' . $h($val !== '' ? $val : ($opts[0] ?? '')) . '">'; continue; }
            if ($tipo === 'separator') { $out .= '<div class="col-12"><hr class="my-1">' . ($etichetta !== '-' ? '<div class="small fw-bold text-uppercase text-secondary">' . $h($etichetta) . '</div>' : '') . '</div>'; continue; }
            $col = in_array($tipo, ['textarea', 'checkboxes', 'radio'], true) ? 'col-12' : 'col-md-6';
            $out .= '<div class="' . $col . '"><label class="form-label small fw-bold mb-1" for="' . $id . '">' . $h($etichetta) . '</label>';
            if ($tipo === 'file') {
                if ($val !== '') {
                    $link = [];
                    foreach (array_filter(array_map('trim', explode(',', $val))) as $i => $path) $link[] = '<a href="../' . $h($path) . '" target="_blank">Allegato ' . ($i + 1) . '</a>';
                    $out .= '<div class="small p-2 border rounded bg-light">' . implode(' · ', $link) . '</div>';
                } else $out .= '<div class="small text-muted p-2 border rounded bg-light">Nessun allegato (si carica solo dal modulo pubblico)</div>';
            } elseif ($tipo === 'select' || $tipo === 'radio') {
                $out .= '<select name="' . $h($name) . '" id="' . $id . '" class="form-select form-select-sm"><option value="">--</option>';
                foreach ($opts as $o) $out .= '<option value="' . $h($o) . '"' . ($o === $val ? ' selected' : '') . '>' . $h($o) . '</option>';
                $out .= '</select>';
            } elseif ($tipo === 'checkboxes') {
                $sel = array_map('trim', explode(',', $val));
                foreach ($opts as $k => $o) $out .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="' . $h($name) . '[]" id="' . $id . '_' . $k . '" value="' . $h($o) . '"' . (in_array($o, $sel, true) ? ' checked' : '') . '><label class="form-check-label small" for="' . $id . '_' . $k . '">' . $h($o) . '</label></div>';
            } elseif ($tipo === 'checkbox') {
                $out .= '<div class="form-check"><input type="hidden" name="' . $h($name) . '" value=""><input class="form-check-input" type="checkbox" name="' . $h($name) . '" id="' . $id . '" value="Sì"' . ($val !== '' ? ' checked' : '') . '><label class="form-check-label small" for="' . $id . '">Sì</label></div>';
            } elseif ($tipo === 'textarea') {
                $out .= '<textarea name="' . $h($name) . '" id="' . $id . '" class="form-control form-control-sm" rows="2">' . $h($val) . '</textarea>';
            } elseif ($tipo === 'scuola') {
                $out .= html_campo_scuola($nome, $val, (string)($valori['__scuola_codice'] ?? ''));
            } elseif ($tipo === 'corso_studio') {
                $out .= html_campo_corso($GLOBALS['conn'], $nome, $val, '', 'form-select form-select-sm', $id);
            } else {
                $html_tipo = in_array($tipo, ['number', 'email', 'tel', 'url', 'date', 'time'], true) ? $tipo : 'text';
                $extra = '';
                if ($nome === CAMPO_PARTECIPANTI) {
                    $extra = ' min="' . $lim['min'] . '"' . ($lim['max'] ? ' max="' . $lim['max'] . '"' : '') . ' required';
                }
                if ($tipo === 'rating') { $html_tipo = 'number'; $extra = ' min="1" max="' . max(5, count($opts)) . '"'; }
                $out .= '<input type="' . $html_tipo . '" name="' . $h($name) . '" id="' . $id . '" class="form-control form-control-sm" value="' . $h($val) . '"' . $extra . '>';
            }
            $out .= '</div>';
        }
        return $out;
    }
}

if (!function_exists('limiti_partecipanti')) {
    // Minimo e massimo di partecipanti per iscrizione: quelli dell'edizione (turno) se indicati,
    // altrimenti quelli generali del progetto. max = null se non c'è un massimo.
    function limiti_partecipanti(?array $d, ?array $turno = null): array {
        $min = !empty($turno['min_partecipanti']) ? (int)$turno['min_partecipanti'] : (!empty($d['min_studenti']) ? (int)$d['min_studenti'] : 1);
        $max = !empty($turno['max_partecipanti']) ? (int)$turno['max_partecipanti'] : (!empty($d['max_studenti']) ? (int)$d['max_studenti'] : null);
        return ['min' => $min, 'max' => $max];
    }
}

if (!function_exists('testo_limiti_partecipanti')) {
    // "da 15 a 30", "almeno 15", "fino a 30" o '' se non ci sono limiti
    function testo_limiti_partecipanti(?int $min, ?int $max): string {
        if ($min > 1 && $max) return $min === $max ? (string)$min : "da $min a $max";
        if ($min > 1) return "almeno $min";
        return $max ? "fino a $max" : '';
    }
}

if (!function_exists('valida_partecipanti_progetto')) {
    // Progetti per le scuole: numero di partecipanti obbligatorio, intero, dentro i limiti del progetto.
    // Ritorna null se va bene (o se il progetto non è per le scuole), altrimenti il messaggio di errore.
    function valida_partecipanti_progetto(array $custom, ?array $d, ?array $turno = null): ?string {
        if ((int)($d['per_scuole'] ?? 1) !== 1) return null;
        $v = trim((string)($custom[CAMPO_PARTECIPANTI] ?? ''));
        if ($v === '') return "Indica il numero di studenti partecipanti.";
        if (!preg_match('/^\d+$/', $v)) return "Il numero di studenti deve essere un numero intero.";
        $n = (int)$v;
        ['min' => $lim_min, 'max' => $lim_max] = limiti_partecipanti($d, $turno);
        if ($n < $lim_min || ($lim_max !== null && $n > $lim_max)) {
            return "Il numero di studenti deve essere compreso tra $lim_min e " . ($lim_max ?? 'il massimo previsto') . ".";
        }
        return null;
    }
}
