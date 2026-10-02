<?php
// inc/didattica.php - Modulo Didattica: Ufficio didattico, modulistica (documenti da scaricare e moduli online),
// pratiche degli studenti, sedute del Consiglio con verbale in Word ed esportazione in Excel.
// Un modulo online, compilato da chi ha fatto l'accesso, apre una pratica con codice, stato, storico e messaggi con
// l'ufficio; tutto si segue dall'Area personale (pratiche.php) e dal pannello (admin/didattica.php).
// I campi possono essere guidati dalle anagrafi (corsi di studio, insegnamenti, docenti), essere tabelle a righe
// (es. esami sostenuti) e alcuni li compila solo l'ufficio durante l'istruttoria (es. convalide).
// Gli allegati delle pratiche stanno in uploads/pratiche/ (bloccata al web) e si scaricano solo da allegato_pratica.php.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('STATI_PRATICA')) define('STATI_PRATICA', [
    'inviata'        => ['Inviata', '#0056B3', 'fa-paper-plane'],
    'in_lavorazione' => ['In lavorazione', '#7c3aed', 'fa-gears'],
    'integrazione'   => ['Integrazione richiesta', '#b45309', 'fa-circle-exclamation'],
    'accolta'        => ['Accolta', '#15803d', 'fa-circle-check'],
    'respinta'       => ['Respinta', '#b91c1c', 'fa-circle-xmark'],
    'chiusa'         => ['Chiusa', '#475569', 'fa-box-archive'],
]);
if (!defined('TIPI_CAMPO_PRATICA')) define('TIPI_CAMPO_PRATICA', [
    'text' => 'Testo breve', 'textarea' => 'Testo lungo', 'email' => 'Email', 'tel' => 'Telefono', 'number' => 'Numero', 'date' => 'Data',
    'select' => 'Tendina', 'radio' => 'Scelta singola', 'checkbox' => 'Casella (sì/no)', 'file' => 'Allegato (PDF o immagine)',
    'corso_studio' => 'Corso di studio (anagrafe)', 'insegnamento' => 'Insegnamento (anagrafe)', 'docente' => 'Docente (anagrafe)',
    'anno_accademico' => 'Anno accademico', 'tabella' => 'Tabella a righe (es. esami)',
]);
if (!defined('DESTINATARI_MODULO')) define('DESTINATARI_MODULO', [
    'tutti' => 'Chiunque abbia fatto l\'accesso', 'studenti' => 'Studenti', 'docenti' => 'Docenti', 'personale' => 'Docenti e personale di Ateneo',
]);
if (!defined('COMPITI_UFFICIO')) define('COMPITI_UFFICIO', [
    'pratiche' => 'Pratiche e modulistica', 'sedute' => 'Sedute e verbali', 'ricevimento' => 'Ricevimento studenti', 'bandi' => 'Bandi',
]);
if (!defined('DIR_PRATICHE')) define('DIR_PRATICHE', 'uploads/pratiche/');
if (!defined('DIR_MODULISTICA')) define('DIR_MODULISTICA', 'uploads/modulistica/');
if (!defined('LOGO_VERBALE')) define('LOGO_VERBALE', 'assets/modelli/logo_verbale_dibest.jpg');

// ==============================================================================
// UFFICIO DIDATTICO
// ==============================================================================

if (!function_exists('uffici_didattica')) {
    // Uffici dell'Ufficio didattico (didattica_uffici), definiti dal pannello: [id => riga]. 'smista' = riceve le pratiche
    // nuove e le smista (manager); 'segue_corsi' = il personale indica i corsi di studio seguiti (referenti dei corsi).
    function uffici_didattica($conn, bool $rileggi = false): array {
        static $cache = null;
        if ($cache !== null && !$rileggi) return $cache;
        $cache = [];
        $r = @$conn->query("SELECT * FROM didattica_uffici ORDER BY ordine, nome");
        while ($r && $x = $r->fetch_assoc()) $cache[(int)$x['id']] = $x;
        return $cache;
    }
    // Id dell'ufficio da un id o da una chiave dei modelli pronti ('referente_cdl', 'carriere'…); null se non esiste
    function ufficio_didattica_id($conn, $x): ?int {
        $uff = uffici_didattica($conn);
        if (is_numeric($x) && isset($uff[(int)$x])) return (int)$x;
        foreach ($uff as $id => $u) if ((string)$u['chiave'] !== '' && $u['chiave'] === $x) return $id;
        return null;
    }
}

if (!function_exists('operatori_ufficio')) {
    // Operatori dell'Ufficio didattico (facoltativo: solo chi ha quel compito)
    function operatori_ufficio($conn, ?string $compito = null): array {
        $r = @$conn->query("SELECT * FROM ufficio_didattica ORDER BY nominativo");
        $out = [];
        while ($r && $x = $r->fetch_assoc()) {
            $x['_compiti'] = array_filter(explode(',', (string)$x['compiti']));
            if ($compito === null || in_array($compito, $x['_compiti'], true)) $out[] = $x;
        }
        return $out;
    }
}

if (!function_exists('utente_operatore_ufficio')) {
    // L'utente è un operatore dell'Ufficio didattico (riconosciuto dall'email o dalla scheda dell'anagrafe)
    function utente_operatore_ufficio($conn, ?array $u, ?string $compito = null): bool {
        if (!$u || empty($u['id'])) return false;
        $email = strtolower(trim((string)($u['email'] ?? ''))); $pid = (string)($u['persona_id'] ?? '');
        foreach (operatori_ufficio($conn, $compito) as $o) {
            if (($email !== '' && strtolower($o['email']) === $email) || ($pid !== '' && (string)$o['persona_id'] === $pid)) return true;
        }
        return false;
    }
}

if (!function_exists('utente_gestisce_didattica')) {
    // Amministratori, abilitati al modulo Didattica e operatori dell'Ufficio didattico
    function utente_gestisce_didattica($conn, ?array $u): bool {
        if (!$u || empty($u['id'])) return false;
        $sec = explode(',', (string)($u['ruoli_secondari'] ?? ''));
        return (int)($u['ruolo_id'] ?? 5) === 1 || in_array('1', $sec, true) || ha_modulo($conn, (int)$u['id'], 'didattica') || utente_operatore_ufficio($conn, $u);
    }
}

if (!function_exists('aggiungi_operatore_ufficio')) {
    // Aggiunge (o aggiorna) un operatore scelto dall'anagrafe di Ateneo. Ritorna un messaggio di errore o null.
    // $ufficio_id: ufficio dell'Ufficio didattico (didattica_uffici); $corsi: corsi di studio seguiti (uffici che seguono i corsi)
    function aggiungi_operatore_ufficio($conn, string $persona_id, string $ruolo, array $compiti, int $ufficio_id = 0, array $corsi = []): ?string {
        $p = function_exists('persona_ateneo') ? persona_ateneo($conn, $persona_id) : null;
        if (!$p) return "Persona non trovata nell'anagrafe di Ateneo.";
        $email = strtolower(trim((string)$p['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return "La persona scelta non ha un'email nell'anagrafe.";
        $nom = trim($p['cognome'] . ' ' . $p['nome']);
        $comp = implode(',', array_values(array_intersect(array_keys(COMPITI_UFFICIO), $compiti)));
        $ruolo = mb_substr(trim($ruolo), 0, 100);
        $uid_uff = ufficio_didattica_id($conn, $ufficio_id);
        $corsi_j = $corsi ? json_encode(array_values(array_unique(array_filter(array_map('trim', $corsi)))), JSON_UNESCAPED_UNICODE) : null;
        $st = $conn->prepare("INSERT INTO ufficio_didattica (persona_id, email, nominativo, ruolo, compiti, ufficio_id, corsi) VALUES (?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE persona_id = VALUES(persona_id), nominativo = VALUES(nominativo), ruolo = VALUES(ruolo), compiti = VALUES(compiti), ufficio_id = VALUES(ufficio_id), corsi = VALUES(corsi)");
        $st->bind_param("sssssis", $persona_id, $email, $nom, $ruolo, $comp, $uid_uff, $corsi_j);
        return $st->execute() ? null : "Salvataggio non riuscito.";
    }
}

if (!function_exists('operatore_ufficio')) {
    // Riga dell'operatore per id, oppure (con $u) l'operatore corrispondente all'utente
    function operatore_ufficio($conn, int $id = 0, ?array $u = null): ?array {
        foreach (operatori_ufficio($conn) as $o) {
            if ($id && (int)$o['id'] === $id) return $o;
            if ($u && ((strtolower($o['email']) === strtolower(trim((string)($u['email'] ?? ''))) && $o['email'] !== '') || ((string)($u['persona_id'] ?? '') !== '' && (string)$o['persona_id'] === (string)$u['persona_id']))) return $o;
        }
        return null;
    }
    function etichetta_operatore(?array $o): string {
        return $o ? $o['nominativo'] . (($n = nome_ufficio_operatore($GLOBALS['conn'] ?? null, $o)) !== '' ? ' · ' . $n : '') : '';
    }
    function nome_ufficio_operatore($conn, ?array $o): string {
        return ($conn && $o && !empty($o['ufficio_id'])) ? (string)(uffici_didattica($conn)[(int)$o['ufficio_id']]['nome'] ?? '') : '';
    }
    function nome_autore_ufficio($conn, ?array $u): string {
        $o = $u ? operatore_ufficio($conn, 0, $u) : null;
        return $o ? etichetta_operatore($o) : trim(($u['nome'] ?? '') . ' ' . ($u['cognome'] ?? '')) . ' · Ufficio didattico';
    }
}

if (!function_exists('email_ufficio_didattica')) {
    // Email di chi riceve gli avvisi: quelle del modulo, altrimenti gli operatori con quel compito, altrimenti gli amministratori
    function email_ufficio_didattica($conn, string $email_modulo = '', string $compito = 'pratiche'): array {
        $e = array_filter(array_map('trim', preg_split('/[,;\s]+/', $email_modulo)), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL));
        if (!$e) $e = array_column(operatori_ufficio($conn, $compito), 'email');
        if (!$e && function_exists('email_amministratori')) $e = email_amministratori($conn);
        return array_values(array_unique($e));
    }
}

if (!function_exists('sportelli_utente')) {
    // Sportelli di ricevimento che l'utente gestisce: il proprio (docente, dall'anagrafe) e quelli dell'Ufficio didattico se ne è operatore
    function sportelli_utente($conn, ?array $u): array {
        if (!$u || empty($u['id'])) return [];
        $pid = (string)($u['persona_id'] ?? '');
        $uff = utente_operatore_ufficio($conn, $u, 'ricevimento') || utente_operatore_ufficio($conn, $u, null) && !operatori_ufficio($conn, 'ricevimento');
        $where = [];
        if ($pid !== '') $where[] = "r.persona_id = '" . $conn->real_escape_string($pid) . "'";
        if ($uff) $where[] = "r.ufficio = 'didattica'";
        if (!$where) return [];
        $r = @$conn->query("SELECT r.*, p.titolo AS area_titolo, p.slug AS area_slug FROM risorse r JOIN pagine_eventi p ON p.id = r.pagina_id WHERE " . implode(' OR ', $where) . " ORDER BY r.ufficio DESC, r.nome");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('sportelli_ufficio_didattica')) {
    function sportelli_ufficio_didattica($conn, bool $solo_attivi = false): array {
        $r = @$conn->query("SELECT r.*, p.titolo AS area_titolo, p.slug AS area_slug FROM risorse r JOIN pagine_eventi p ON p.id = r.pagina_id WHERE r.ufficio = 'didattica'" . ($solo_attivi ? " AND r.attiva = 1" : '') . " ORDER BY r.nome");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('crea_sportello_ufficio')) {
    // Sportello di ricevimento dell'Ufficio didattico in un'area di Prenotazioni e risorse. Ritorna l'id.
    function crea_sportello_ufficio($conn, int $pagina_id, string $nome, string $luogo): int {
        $email = implode(', ', email_ufficio_didattica($conn, '', 'ricevimento'));
        $nome = mb_substr(trim($nome) ?: 'Ufficio didattico – ricevimento studenti', 0, 150); $luogo = mb_substr(trim($luogo), 0, 255);
        $st = $conn->prepare("INSERT INTO risorse (pagina_id, nome, tipo, luogo, referente, email_notifiche, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, chiede_motivo, attiva, ufficio)
                              VALUES (?, ?, 'sportello', ?, 'Ufficio didattico', ?, 15, 1, 12, 30, 'tutti', 0, 1, 1, 'didattica')");
        $st->bind_param("isss", $pagina_id, $nome, $luogo, $email);
        return $st->execute() ? (int)$conn->insert_id : 0;
    }
}

// ==============================================================================
// MODULI E CAMPI
// ==============================================================================

if (!function_exists('campi_modulo')) {
    // Campi del modulo online, normalizzati: [['nome', 'etichetta', 'tipo', 'opzioni' => [...], 'obbligatorio', 'aiuto', 'ufficio'], ...]
    // 'ufficio' = lo compila l'ufficio nell'istruttoria (non compare allo studente). Per 'tabella' le opzioni sono le colonne.
    function campi_modulo(?string $json): array {
        $out = [];
        foreach (json_decode((string)$json, true) ?: [] as $i => $c) {
            $tipo = isset(TIPI_CAMPO_PRATICA[$c['tipo'] ?? '']) ? $c['tipo'] : 'text';
            $et = mb_substr(trim((string)($c['etichetta'] ?? '')), 0, 200);
            if ($et === '') continue;
            $out[] = ['nome' => 'c' . ($i + 1), 'etichetta' => $et, 'tipo' => $tipo, 'obbligatorio' => !empty($c['obbligatorio']),
                      'opzioni' => array_values(array_filter(array_map('trim', is_array($c['opzioni'] ?? null) ? $c['opzioni'] : preg_split('/[,;\n]/', (string)($c['opzioni'] ?? ''))), 'strlen')),
                      'aiuto' => mb_substr(trim((string)($c['aiuto'] ?? '')), 0, 300), 'ufficio' => !empty($c['ufficio'])];
        }
        return $out;
    }
}

if (!function_exists('campi_studente')) {
    function campi_studente(array $campi): array { return array_values(array_filter($campi, fn($c) => !$c['ufficio'])); }
    function campi_ufficio(array $campi): array { return array_values(array_filter($campi, fn($c) => $c['ufficio'] && $c['tipo'] !== 'file')); }
}

if (!function_exists('modulo_didattica')) {
    function modulo_didattica($conn, int $id): ?array {
        $r = @$conn->query("SELECT * FROM didattica_moduli WHERE id = $id");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }
}

if (!function_exists('utente_destinatario_modulo')) {
    // Chi può compilare il modulo online (stesse regole delle risorse: gruppi dell'anagrafe e matricola)
    function utente_destinatario_modulo($conn, array $m, ?array $u): bool {
        if (!$u || empty($u['id'])) return false;
        if (($m['destinatari'] ?? 'tutti') === 'tutti' || utente_gestisce_didattica($conn, $u)) return true;
        return puo_prenotare_risorsa($conn, ['accesso' => $m['destinatari'], 'pagina_id' => 0], $u);
    }
}

if (!function_exists('anni_accademici_scelta')) {
    // "2025/2026" ecc.: dall'anno precedente a quello successivo all'anno in corso
    function anni_accademici_scelta(): array {
        $a = anno_accademico_corrente(); $out = [];
        for ($x = $a + 1; $x >= $a - 3; $x--) $out[] = $x . '/' . ($x + 1);
        return $out;
    }
}

if (!function_exists('scelte_anagrafe_didattica')) {
    // Valori proposti dai campi guidati: corsi di studio (per tipo), insegnamenti e docenti
    function scelte_anagrafe_didattica($conn, string $tipo): array {
        static $cache = [];
        if (isset($cache[$tipo])) return $cache[$tipo];
        $out = [];
        if ($tipo === 'corso_studio' && function_exists('corsi_studio_visibili')) {
            foreach (corsi_studio_visibili($conn) as $gruppo => $corsi) foreach ($corsi as $c) $out[$gruppo][] = nome_scheda_corso($c);
        } elseif ($tipo === 'insegnamento' && function_exists('insegnamenti_per_corso')) {
            foreach (insegnamenti_per_corso($conn) as $corso => $ins) foreach ($ins as $i) $out[] = $i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : '') . ' – ' . $corso;
            $out = array_values(array_unique($out));
        } elseif ($tipo === 'docente') {
            $r = @$conn->query("SELECT cognome, nome FROM personale_ateneo WHERE docente = 1 AND attivo = 1 ORDER BY cognome, nome");
            while ($r && $x = $r->fetch_assoc()) $out[] = trim($x['cognome'] . ' ' . $x['nome']);
            $out = array_values(array_unique($out));
        }
        return $cache[$tipo] = $out;
    }
}

if (!function_exists('salva_allegato_pratica')) {
    // Allegato di una pratica (PDF o immagine, max 10 MB): percorso relativo o null
    function salva_allegato_pratica(array $file): ?string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 10 * 1024 * 1024) return null;
        $dir = RADICE_SITO . '/' . DIR_PRATICHE;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "# Allegati delle pratiche: si scaricano solo da allegato_pratica.php\nRequire all denied\n");
        $fn = secure_upload($file, $dir, ['pdf', 'jpg', 'jpeg', 'png', 'p7m'], ['application/pdf', 'image/jpeg', 'image/png', 'application/pkcs7-mime', 'application/x-pkcs7-mime', 'application/octet-stream']);
        return $fn ? DIR_PRATICHE . $fn : null;
    }
}

if (!function_exists('leggi_risposte_modulo')) {
    // Risposte dal POST (campo_<nome>) e allegati ($_FILES campo_<nome>). Ritorna [risposte, errori].
    // Le risposte: [['etichetta' => …, 'tipo' => …, 'valore' => …, 'file' => percorso|null, 'nome_file' => …, 'righe' => [[…]], 'colonne' => […]], ...]
    // $conn serve per controllare i corsi di studio proposti dall'anagrafe.
    function leggi_risposte_modulo(array $campi, $conn = null): array {
        $risposte = []; $errori = [];
        foreach ($campi as $c) {
            $k = 'campo_' . $c['nome'];
            $r = ['etichetta' => $c['etichetta'], 'tipo' => $c['tipo'], 'valore' => '', 'file' => null, 'nome_file' => null];
            if ($c['tipo'] === 'file') {
                $f = $_FILES[$k] ?? null;
                if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $p = salva_allegato_pratica($f);
                    if ($p) { $r['file'] = $p; $r['nome_file'] = mb_substr(basename((string)$f['name']), 0, 200); $r['valore'] = $r['nome_file']; }
                    else $errori[] = $c['etichetta'] . ": allegato non valido (PDF, JPG o PNG fino a 10 MB)";
                }
            } elseif ($c['tipo'] === 'checkbox') {
                $r['valore'] = !empty($_POST[$k]) ? 'Sì' : '';
            } elseif ($c['tipo'] === 'tabella') {
                // Colonne in array paralleli: campo_cX[0][], campo_cX[1][], …; si tengono le righe non vuote (max 60)
                $cols = $c['opzioni'] ?: ['Descrizione']; $righe = [];
                $dati = is_array($_POST[$k] ?? null) ? $_POST[$k] : [];
                $n = max(0, ...array_map(fn($j) => is_array($dati[$j] ?? null) ? count($dati[$j]) : 0, array_keys($cols)));
                for ($i = 0; $i < min($n, 60); $i++) {
                    $riga = [];
                    foreach (array_keys($cols) as $j) $riga[] = mb_substr(trim((string)($dati[$j][$i] ?? '')), 0, 300);
                    if (implode('', $riga) !== '') $righe[] = $riga;
                }
                $r['colonne'] = $cols; $r['righe'] = $righe;
                $r['valore'] = implode("\n", array_map(fn($x) => implode(' | ', $x), $righe));
            } else {
                $v = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $c['tipo'] === 'textarea' ? 5000 : 500);
                if ($v !== '' && $c['tipo'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errori[] = $c['etichetta'] . ": email non valida";
                if ($v !== '' && in_array($c['tipo'], ['select', 'radio'], true) && !in_array($v, $c['opzioni'], true)) $v = '';
                if ($v !== '' && $c['tipo'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $v = '';
                if ($v !== '' && $c['tipo'] === 'anno_accademico' && !preg_match('/^\d{4}\/\d{4}$/', $v)) $v = '';
                if ($v !== '' && $c['tipo'] === 'corso_studio' && $conn && ($corsi = array_merge([], ...array_values(scelte_anagrafe_didattica($conn, 'corso_studio')))) && !in_array($v, $corsi, true)) $v = '';
                $r['valore'] = $v;
            }
            if ($c['obbligatorio'] && $r['valore'] === '') $errori[] = $c['etichetta'] . ": campo obbligatorio";
            $risposte[] = $r;
        }
        return [$risposte, $errori];
    }
}

if (!function_exists('html_datalist_didattica')) {
    // Elenchi proposti (datalist) per i campi guidati: da stampare una volta sola nella pagina del modulo
    function html_datalist_didattica($conn, array $campi): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $serve = ['insegnamento' => false, 'docente' => false];
        foreach ($campi as $c) {
            if (isset($serve[$c['tipo']])) $serve[$c['tipo']] = true;
            if ($c['tipo'] === 'tabella') foreach ($c['opzioni'] as $col) {
                if (preg_match('/insegnament|esam/i', $col)) $serve['insegnamento'] = true;
                if (preg_match('/docente|relatore|tutor/i', $col)) $serve['docente'] = true;
            }
        }
        $out = '';
        foreach ($serve as $tipo => $si) {
            if (!$si) continue;
            $out .= '<datalist id="dl_' . $tipo . '">';
            foreach (scelte_anagrafe_didattica($conn, $tipo) as $v) $out .= '<option value="' . $h($v) . '">';
            $out .= '</datalist>';
        }
        return $out;
    }
}

if (!function_exists('html_campo_pratica')) {
    // Campo del modulo online (pagina pubblica modulo.php e istruttoria dell'ufficio). $valore: testo o righe (tabella).
    function html_campo_pratica(array $c, $valore = '', $conn = null): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $id = 'mc_' . $c['nome']; $name = 'campo_' . $c['nome']; $req = $c['obbligatorio'] ? ' required' : '';
        $stella = $c['obbligatorio'] ? ' <span class="text-danger">*</span>' : '';
        $et = '<label class="form-label fw-bold" for="' . $id . '">' . $h($c['etichetta']) . $stella . '</label>';
        $aiuto = $c['aiuto'] !== '' ? '<div class="form-text">' . $h($c['aiuto']) . '</div>' : '';
        $testo = is_array($valore) ? '' : (string)$valore;
        switch ($c['tipo']) {
            case 'textarea': return '<div class="mb-3">' . $et . '<textarea class="form-control" id="' . $id . '" name="' . $name . '" rows="4"' . $req . '>' . $h($testo) . '</textarea>' . $aiuto . '</div>';
            case 'select':
                $o = '<option value="">--</option>';
                foreach ($c['opzioni'] as $op) $o .= '<option' . ($op === $testo ? ' selected' : '') . '>' . $h($op) . '</option>';
                return '<div class="mb-3">' . $et . '<select class="form-select" id="' . $id . '" name="' . $name . '"' . $req . '>' . $o . '</select>' . $aiuto . '</div>';
            case 'corso_studio':
                $gruppi = $conn ? scelte_anagrafe_didattica($conn, 'corso_studio') : [];
                if (!$gruppi) return '<div class="mb-3">' . $et . '<input type="text" class="form-control" id="' . $id . '" name="' . $name . '" value="' . $h($testo) . '"' . $req . '>' . $aiuto . '</div>';
                $o = '<option value="">-- scegli il corso --</option>';
                foreach ($gruppi as $g => $corsi) {
                    $o .= '<optgroup label="' . $h($g) . '">';
                    foreach ($corsi as $n) $o .= '<option' . ($n === $testo ? ' selected' : '') . '>' . $h($n) . '</option>';
                    $o .= '</optgroup>';
                }
                return '<div class="mb-3">' . $et . '<select class="form-select" id="' . $id . '" name="' . $name . '"' . $req . '>' . $o . '</select>' . $aiuto . '</div>';
            case 'anno_accademico':
                $o = '<option value="">--</option>';
                $sel = $testo !== '' ? $testo : anno_accademico_corrente() . '/' . (anno_accademico_corrente() + 1);
                foreach (anni_accademici_scelta() as $a) $o .= '<option' . ($a === $sel ? ' selected' : '') . '>' . $a . '</option>';
                return '<div class="mb-3">' . $et . '<select class="form-select" id="' . $id . '" name="' . $name . '"' . $req . ' style="max-width:220px;">' . $o . '</select>' . $aiuto . '</div>';
            case 'insegnamento':
            case 'docente':
                $ph = $c['tipo'] === 'docente' ? 'Scrivi il cognome e scegli dall\'elenco' : 'Scrivi il nome e scegli dall\'elenco';
                return '<div class="mb-3">' . $et . '<input type="text" class="form-control" id="' . $id . '" name="' . $name . '" value="' . $h($testo) . '" list="dl_' . $c['tipo'] . '" autocomplete="off" placeholder="' . $ph . '"' . $req . '>' . $aiuto . '</div>';
            case 'tabella':
                $cols = $c['opzioni'] ?: ['Descrizione'];
                $righe = is_array($valore) ? $valore : [];
                $righe = array_merge($righe, array_fill(0, max(0, 3 - count($righe)), []));
                $th = ''; foreach ($cols as $col) $th .= '<th scope="col" class="small">' . $h($col) . '</th>';
                $tr = '';
                foreach ($righe as $riga) {
                    $tr .= '<tr>';
                    foreach ($cols as $j => $col) {
                        $lista = preg_match('/insegnament|esam/i', $col) ? ' list="dl_insegnamento"' : (preg_match('/docente|relatore|tutor/i', $col) ? ' list="dl_docente"' : '');
                        $tr .= '<td><input type="text" class="form-control form-control-sm" name="' . $name . '[' . $j . '][]" value="' . $h($riga[$j] ?? '') . '" aria-label="' . $h($col) . '"' . $lista . ' autocomplete="off"></td>';
                    }
                    $tr .= '<td><button type="button" class="btn btn-sm btn-link text-danger p-0 tab-togli" aria-label="Togli la riga"><i class="fa fa-times" aria-hidden="true"></i></button></td></tr>';
                }
                return '<fieldset class="mb-3"><legend class="form-label fw-bold fs-6 mb-1">' . $h($c['etichetta']) . $stella . '</legend>' . $aiuto
                     . '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-1 tab-righe"><thead class="table-light"><tr>' . $th . '<th style="width:28px;"><span class="visually-hidden">Azioni</span></th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
                     . '<button type="button" class="btn btn-sm btn-outline-secondary tab-aggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi riga</button></fieldset>';
            case 'radio':
                $o = '';
                foreach ($c['opzioni'] as $i => $op) $o .= '<div class="form-check"><input class="form-check-input" type="radio" name="' . $name . '" id="' . $id . '_' . $i . '" value="' . $h($op) . '"' . ($op === $testo ? ' checked' : '') . ($i === 0 ? $req : '') . '><label class="form-check-label" for="' . $id . '_' . $i . '">' . $h($op) . '</label></div>';
                return '<fieldset class="mb-3"><legend class="form-label fw-bold fs-6">' . $h($c['etichetta']) . $stella . '</legend>' . $o . $aiuto . '</fieldset>';
            case 'checkbox': return '<div class="mb-3 form-check"><input class="form-check-input" type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . ($testo !== '' ? ' checked' : '') . $req . '><label class="form-check-label fw-bold" for="' . $id . '">' . $h($c['etichetta']) . $stella . '</label>' . $aiuto . '</div>';
            case 'file': return '<div class="mb-3">' . $et . '<input type="file" class="form-control" id="' . $id . '" name="' . $name . '" accept=".pdf,.jpg,.jpeg,.png,.p7m"' . $req . '><div class="form-text">PDF, JPG o PNG, fino a 10 MB.' . ($c['aiuto'] !== '' ? ' ' . $h($c['aiuto']) : '') . '</div></div>';
            default:
                $tipo = in_array($c['tipo'], ['email', 'tel', 'number', 'date'], true) ? $c['tipo'] : 'text';
                return '<div class="mb-3">' . $et . '<input type="' . $tipo . '" class="form-control" id="' . $id . '" name="' . $name . '" value="' . $h($testo) . '"' . $req . '>' . $aiuto . '</div>';
        }
    }
}

if (!function_exists('js_tabelle_pratica')) {
    // Righe in più / in meno nei campi "tabella" (una volta per pagina)
    function js_tabelle_pratica(): string {
        return "<script>document.addEventListener('click',function(e){var a=e.target.closest('.tab-aggiungi');if(a){var tb=a.closest('fieldset').querySelector('tbody'),r=tb.lastElementChild.cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value='';});tb.appendChild(r);r.querySelector('input').focus();return;}"
             . "var t=e.target.closest('.tab-togli');if(t){var tr=t.closest('tr'),tb2=tr.parentNode;if(tb2.children.length>1)tr.remove();else tr.querySelectorAll('input').forEach(function(i){i.value='';});}});</script>";
    }
}

if (!function_exists('valori_post_campo')) {
    // Valore da rimettere nel campo dopo un errore (le tabelle tornano come righe)
    function valori_post_campo(array $c) {
        $v = $_POST['campo_' . $c['nome']] ?? '';
        if ($c['tipo'] !== 'tabella') return is_array($v) ? '' : (string)$v;
        $righe = [];
        foreach ((is_array($v) ? $v : []) as $j => $col) foreach ((array)$col as $i => $x) $righe[$i][$j] = (string)$x;
        return $righe;
    }
}

if (!function_exists('html_risposta_pratica')) {
    // Valore di una risposta per le pagine (le tabelle diventano una tabella; il link all'allegato lo aggiunge chi chiama)
    function html_risposta_pratica(array $r): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        if (($r['tipo'] ?? '') === 'tabella') {
            if (empty($r['righe'])) return '—';
            $o = '<div class="table-responsive"><table class="table table-sm table-bordered small mb-0"><thead class="table-light"><tr>';
            foreach ($r['colonne'] ?? [] as $c) $o .= '<th>' . $h($c) . '</th>';
            $o .= '</tr></thead><tbody>';
            foreach ($r['righe'] as $riga) { $o .= '<tr>'; foreach ($riga as $x) $o .= '<td>' . $h($x) . '</td>'; $o .= '</tr>'; }
            return $o . '</tbody></table></div>';
        }
        return nl2br($h(($r['valore'] ?? '') !== '' ? $r['valore'] : '—'));
    }
}

// ==============================================================================
// PRATICHE
// ==============================================================================

if (!function_exists('pratica')) {
    // Pratica con il titolo del modulo (per id)
    function pratica($conn, int $id): ?array {
        $r = @$conn->query("SELECT p.*, m.titolo AS modulo_titolo, m.email_ufficio, m.categoria FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE p.id = $id");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }
}

if (!function_exists('email_pratica')) {
    // Email allo studente (stato, messaggio dell'ufficio) o all'ufficio (nuova pratica, messaggio dello studente)
    function email_pratica($conn, array $p, string $tipo, string $testo = ''): void {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $link_s = url_base_sito() . '/pratiche.php?id=' . (int)$p['id'];
        $link_u = url_base_sito() . '/admin/didattica.php?tab=pratiche&id=' . (int)$p['id'];
        $intest = "<p><strong>" . $h($p['modulo_titolo']) . "</strong> · pratica <strong>" . $h($p['codice']) . "</strong></p>";
        $bottone = fn($u, $t) => "<p style='margin-top:18px;'><a href='" . $h($u) . "' style='background:#047857;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>" . $t . "</a></p>";
        $ufficio = email_ufficio_didattica($conn, (string)($p['email_ufficio'] ?? ''), 'pratiche');
        // Nuove pratiche: ai manager (che smistano) se ci sono; messaggi dello studente: a chi ha la pratica in carico
        $smistano = array_keys(array_filter(uffici_didattica($conn), fn($u) => (int)$u['smista'] === 1));
        $manager = array_column(array_filter(operatori_ufficio($conn), fn($o) => in_array((int)$o['ufficio_id'], $smistano, true)), 'email');
        $in_carico = !empty($p['assegnata_a']) ? operatore_ufficio($conn, (int)$p['assegnata_a']) : null;
        if ($tipo === 'nuova' && $manager && trim((string)($p['email_ufficio'] ?? '')) === '') $ufficio = $manager;
        if ($tipo === 'msg_studente' && $in_carico) $ufficio = [$in_carico['email']];
        switch ($tipo) {
            case 'nuova':
                if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) inviaNotificaEmail($p['email'], "Pratica ricevuta: " . $p['modulo_titolo'], "<p>Gentile " . $h($p['nome']) . ",</p><p>abbiamo ricevuto la tua richiesta.</p>" . $intest . "<p>Puoi seguirne lo stato e scrivere all'ufficio dalla tua Area personale.</p>" . $bottone($link_s, 'Vedi la pratica'), $conn, '#047857');
                foreach ($ufficio as $e) inviaNotificaEmail($e, "Nuova pratica: " . $p['modulo_titolo'] . " – " . trim($p['cognome'] . ' ' . $p['nome']), "<p>È arrivata una nuova pratica.</p>" . $intest . "<p>" . $h(trim($p['nome'] . ' ' . $p['cognome'])) . " · " . $h($p['email']) . ($p['matricola'] !== '' ? " · matricola " . $h($p['matricola']) : '') . "</p>" . $bottone($link_u, 'Apri nel pannello'), $conn, '#047857');
                break;
            case 'stato':
                $st = STATI_PRATICA[$p['stato']][0] ?? $p['stato'];
                if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) inviaNotificaEmail($p['email'], "Pratica " . $p['codice'] . ": " . $st, "<p>Gentile " . $h($p['nome']) . ",</p><p>la tua pratica è ora: <strong>" . $h($st) . "</strong>.</p>" . $intest . ($testo !== '' ? "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($testo)) . "</p>" : '') . $bottone($link_s, 'Vedi la pratica'), $conn, '#047857');
                break;
            case 'msg_ufficio':
                if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) inviaNotificaEmail($p['email'], "Nuovo messaggio sulla pratica " . $p['codice'], "<p>Gentile " . $h($p['nome']) . ", l'ufficio ti ha scritto:</p>" . $intest . "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($testo)) . "</p>" . $bottone($link_s, 'Rispondi'), $conn, '#047857');
                break;
            case 'msg_studente':
                foreach ($ufficio as $e) inviaNotificaEmail($e, "Messaggio sulla pratica " . $p['codice'] . " – " . trim($p['cognome'] . ' ' . $p['nome']), "<p>Nuovo messaggio dello studente:</p>" . $intest . "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($testo)) . "</p>" . $bottone($link_u, 'Apri nel pannello'), $conn, '#047857');
                break;
        }
    }
}

if (!function_exists('crea_pratica')) {
    // Nuova pratica dal modulo compilato. Ritorna l'id (0 se non riesce).
    function crea_pratica($conn, array $m, array $u, array $risposte): int {
        $codice = 'PR-' . strtoupper(bin2hex(random_bytes(4)));
        $json = json_encode($risposte, JSON_UNESCAPED_UNICODE);
        $matr = (string)(($u['matricola_studente'] ?? '') ?: ($u['matricola_dipendente'] ?? ''));
        $st = $conn->prepare("INSERT INTO pratiche (modulo_id, utente_id, codice, nome, cognome, email, matricola, risposte_json, stato, aggiornata_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'inviata', NOW())");
        $mid = (int)$m['id']; $uid = (int)$u['id']; $email = (string)($u['email'] ?? '');
        $st->bind_param("iissssss", $mid, $uid, $codice, $u['nome'], $u['cognome'], $email, $matr, $json);
        if (!$st->execute()) return 0;
        $id = (int)$conn->insert_id;
        evento_pratica($conn, $id, 'stato', 'studente', $uid, 'inviata', 'Pratica inviata');
        if ($p = pratica($conn, $id)) email_pratica($conn, $p, 'nuova');
        return $id;
    }
}

if (!function_exists('evento_pratica')) {
    // Riga dello storico: cambio di stato, messaggio, attività, passaggio dell'iter, autodichiarazione (con eventuale allegato).
    // $interno = nota tra i referenti, non visibile allo studente; $autore_nome = chi scrive (es. "Rossi Mario · Referente del corso")
    function evento_pratica($conn, int $pratica_id, string $tipo, string $autore, int $uid, ?string $stato, string $testo, ?string $allegato = null, ?string $nome_all = null, bool $interno = false, string $autore_nome = ''): void {
        $st = $conn->prepare("INSERT INTO pratiche_eventi (pratica_id, tipo, autore, utente_id, stato, testo, allegato, nome_allegato, interno, autore_nome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $uid_b = $uid > 0 ? $uid : null; $int = $interno ? 1 : 0; $an = mb_substr($autore_nome, 0, 200);
        $st->bind_param("ississssis", $pratica_id, $tipo, $autore, $uid_b, $stato, $testo, $allegato, $nome_all, $int, $an); $st->execute();
        $conn->query("UPDATE pratiche SET aggiornata_il = NOW() WHERE id = $pratica_id");
    }
}

if (!function_exists('cambia_stato_pratica')) {
    // $richiesta (solo per 'integrazione'): ['tipo' => 'documenti'|'autodichiarazione', 'testo' => …] da mostrare allo studente
    function cambia_stato_pratica($conn, int $id, string $stato, string $nota, int $uid, string $autore_nome = '', ?array $richiesta = null): bool {
        if (!isset(STATI_PRATICA[$stato])) return false;
        $p = pratica($conn, $id);
        if (!$p) return false;
        $rj = null;
        if ($stato === 'integrazione') {
            $tipo_r = ($richiesta['tipo'] ?? '') === 'autodichiarazione' ? 'autodichiarazione' : 'documenti';
            $rj = json_encode(['tipo' => $tipo_r, 'testo' => mb_substr(trim((string)($richiesta['testo'] ?? '')) ?: $nota, 0, 3000), 'da' => $autore_nome, 'il' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
        }
        $st = $conn->prepare("UPDATE pratiche SET stato = ?, richiesta_json = ?, aggiornata_il = NOW() WHERE id = ?");
        $st->bind_param("ssi", $stato, $rj, $id); $st->execute();
        $testo_ev = $nota;
        if ($rj) { $r = json_decode($rj, true); $testo_ev = ($r['tipo'] === 'autodichiarazione' ? "Richiesta un'autodichiarazione: " : "Richiesti documenti: ") . $r['testo']; }
        evento_pratica($conn, $id, 'stato', 'ufficio', $uid, $stato, $testo_ev, null, null, false, $autore_nome);
        $p['stato'] = $stato;
        email_pratica($conn, $p, 'stato', $testo_ev);
        return true;
    }
}

if (!function_exists('messaggio_pratica')) {
    // Messaggio o attività (es. verbale caricato) dello studente o dell'ufficio, con allegato facoltativo ($file = elemento di $_FILES).
    // $interno: nota tra i referenti (lo studente non la vede e non riceve email). Lo studente che risponde a un'integrazione
    // di documenti la chiude e la pratica torna in lavorazione.
    function messaggio_pratica($conn, int $id, string $autore, int $uid, string $testo, ?array $file = null, bool $interno = false, string $tipo = 'messaggio', string $autore_nome = ''): ?string {
        $p = pratica($conn, $id);
        if (!$p) return "Pratica non trovata.";
        $testo = mb_substr(trim($testo), 0, 5000);
        $all = null; $nome_all = null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $all = salva_allegato_pratica($file);
            if (!$all) return "Allegato non valido (PDF, JPG o PNG fino a 10 MB).";
            $nome_all = mb_substr(basename((string)$file['name']), 0, 200);
        }
        if ($testo === '' && !$all) return "Scrivi il messaggio o allega un file.";
        $interno = $interno && $autore === 'ufficio';
        evento_pratica($conn, $id, $tipo === 'attivita' ? 'attivita' : 'messaggio', $autore, $uid, null, $testo, $all, $nome_all, $interno, $autore_nome);
        if ($autore === 'studente' && $p['stato'] === 'integrazione') {
            $rq = json_decode((string)($p['richiesta_json'] ?? ''), true) ?: [];
            if (($rq['tipo'] ?? 'documenti') !== 'autodichiarazione') {
                $conn->query("UPDATE pratiche SET stato = 'in_lavorazione', richiesta_json = NULL WHERE id = $id");
                evento_pratica($conn, $id, 'stato', 'studente', $uid, 'in_lavorazione', 'Integrazione inviata');
            }
        }
        if ($interno) {
            // Nota interna: avvisa chi ha la pratica in carico (se non è chi scrive)
            $o = !empty($p['assegnata_a']) ? operatore_ufficio($conn, (int)$p['assegnata_a']) : null;
            if ($o && mb_strpos($autore_nome, $o['nominativo']) !== 0)
                inviaNotificaEmail($o['email'], "Nota sulla pratica " . $p['codice'], "<p>" . htmlspecialchars($autore_nome) . " ha scritto una nota interna sulla pratica <strong>" . htmlspecialchars($p['codice']) . "</strong> (" . htmlspecialchars($p['modulo_titolo']) . "):</p><p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br(htmlspecialchars($testo)) . "</p><p><a href='" . htmlspecialchars(url_base_sito() . '/admin/didattica.php?tab=pratiche&id=' . $id) . "'>Apri la pratica</a></p>", $conn, '#047857');
            return null;
        }
        email_pratica($conn, $p, $autore === 'ufficio' ? 'msg_ufficio' : 'msg_studente', $testo . ($nome_all ? "\n[Allegato: $nome_all]" : ''));
        return null;
    }
}

if (!function_exists('iter_modulo')) {
    // Uffici che ricevono la pratica, in ordine (dal modulo: id o chiavi dei modelli pronti); vuoto = un solo passo generico (0)
    function iter_modulo(?array $m): array {
        global $conn;
        $it = [];
        foreach (json_decode((string)($m['iter_json'] ?? ''), true) ?: [] as $x) {
            $id = ufficio_didattica_id($conn, $x);
            if ($id && !(int)(uffici_didattica($conn)[$id]['smista'] ?? 0)) $it[] = $id;
        }
        return $it ?: [0];
    }
    // Passi mostrati: 0 = ricevuta (da smistare), 1..n = uffici dell'iter, n+1 = conclusa
    function passi_pratica(?array $m): array {
        global $conn;
        $out = [0 => 'Ricevuta e da smistare'];
        foreach (iter_modulo($m) as $i => $id) $out[$i + 1] = $id ? (string)(uffici_didattica($conn)[$id]['nome'] ?? 'Ufficio didattico') : 'Ufficio didattico';
        return $out;
    }
}

if (!function_exists('operatori_suggeriti')) {
    // Operatori per il passo: prima chi è nell'ufficio del passo (e, negli uffici che seguono i corsi, chi segue il corso della pratica)
    function operatori_suggeriti($conn, array $p, ?array $m, int $passo): array {
        $uff = iter_modulo($m)[$passo - 1] ?? 0;
        $corso = '';
        foreach (json_decode((string)$p['risposte_json'], true) ?: [] as $r) if (($r['tipo'] ?? '') === 'corso_studio' && $r['valore'] !== '') { $corso = $r['valore']; break; }
        $punti = function ($o) use ($uff, $corso) {
            $s = $uff && (int)$o['ufficio_id'] === $uff ? 10 : 0;
            if ($corso !== '' && in_array($corso, json_decode((string)($o['corsi'] ?? ''), true) ?: [], true)) $s += 5;
            return $s;
        };
        $ops = operatori_ufficio($conn);
        usort($ops, fn($a, $b) => $punti($b) <=> $punti($a) ?: strcmp($a['nominativo'], $b['nominativo']));
        return array_map(fn($o) => $o + ['_consigliato' => $punti($o) >= 10], $ops);
    }
}

if (!function_exists('assegna_pratica')) {
    // Smista o passa la pratica all'operatore $op_id al passo $passo dell'iter; lo studente vede il passaggio, la nota resta interna.
    function assegna_pratica($conn, int $id, int $op_id, int $passo, string $nota, int $uid, string $autore_nome = ''): ?string {
        $p = pratica($conn, $id); $o = operatore_ufficio($conn, $op_id);
        if (!$p || !$o) return "Scegli la pratica e l'operatore.";
        $m = modulo_didattica($conn, (int)$p['modulo_id']);
        $passo = max(1, min(count(iter_modulo($m)), $passo));
        $stato = in_array($p['stato'], ['inviata'], true) ? 'in_lavorazione' : $p['stato'];
        $st = $conn->prepare("UPDATE pratiche SET assegnata_a = ?, passo = ?, stato = ?, aggiornata_il = NOW() WHERE id = ?");
        $st->bind_param("iisi", $op_id, $passo, $stato, $id); $st->execute();
        evento_pratica($conn, $id, 'passaggio', 'ufficio', $uid, $stato !== $p['stato'] ? $stato : null, 'In carico a: ' . passi_pratica($m)[$passo] . ' – ' . $o['nominativo'], null, null, false, $autore_nome);
        if (trim($nota) !== '') evento_pratica($conn, $id, 'messaggio', 'ufficio', $uid, null, mb_substr(trim($nota), 0, 3000), null, null, true, $autore_nome);
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        inviaNotificaEmail($o['email'], "Pratica assegnata: " . $p['modulo_titolo'] . " – " . trim($p['cognome'] . ' ' . $p['nome']),
            "<p>Gentile " . $h($o['nominativo']) . ",</p><p>ti è stata assegnata la pratica <strong>" . $h($p['codice']) . "</strong> (" . $h($p['modulo_titolo']) . ") come <strong>" . $h(passi_pratica($m)[$passo]) . "</strong>" . ($autore_nome !== '' ? " da " . $h($autore_nome) : '') . ".</p>"
            . (trim($nota) !== '' ? "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($nota)) . "</p>" : '')
            . "<p style='margin-top:18px;'><a href='" . $h(url_base_sito() . '/admin/didattica.php?tab=pratiche&id=' . $id) . "' style='background:#047857;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Apri la pratica</a></p>", $conn, '#047857');
        return null;
    }
}

if (!function_exists('autodichiarazione_pratica')) {
    // Lo studente rende l'autodichiarazione richiesta (DPR 445/2000): resta nello storico con data e ora, la pratica torna in lavorazione
    function autodichiarazione_pratica($conn, int $id, int $uid, string $aggiunta, bool $conferma): ?string {
        $p = pratica($conn, $id);
        $rq = $p ? (json_decode((string)($p['richiesta_json'] ?? ''), true) ?: []) : [];
        if (!$p || $p['stato'] !== 'integrazione' || ($rq['tipo'] ?? '') !== 'autodichiarazione') return "Non c'è un'autodichiarazione da rendere.";
        if (!$conferma) return "Per inviare devi spuntare la dichiarazione.";
        $testo = "Il/La sottoscritto/a " . trim($p['nome'] . ' ' . $p['cognome']) . ($p['matricola'] !== '' ? ", matricola " . $p['matricola'] : '') . ", dichiara: " . $rq['testo']
               . (trim($aggiunta) !== '' ? "\n" . mb_substr(trim($aggiunta), 0, 3000) : '')
               . "\nDichiarazione resa ai sensi degli artt. 46 e 47 del D.P.R. 445/2000, consapevole delle sanzioni penali previste dall'art. 76 per le dichiarazioni mendaci, il " . date('d/m/Y \a\l\l\e H:i') . ".";
        evento_pratica($conn, $id, 'autodich', 'studente', $uid, null, $testo);
        $conn->query("UPDATE pratiche SET stato = 'in_lavorazione', richiesta_json = NULL WHERE id = $id");
        evento_pratica($conn, $id, 'stato', 'studente', $uid, 'in_lavorazione', 'Autodichiarazione inviata');
        email_pratica($conn, $p, 'msg_studente', $testo);
        return null;
    }
}

if (!function_exists('html_iter_pratica')) {
    // Iter della pratica a passi (pannello e Area personale): fatti, attuale con chi l'ha in carico, da fare, conclusione
    function html_iter_pratica($conn, array $p, ?array $m): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $passi = passi_pratica($m); $conclusa = in_array($p['stato'], ['accolta', 'respinta', 'chiusa'], true);
        $att = $conclusa ? count($passi) : (int)$p['passo'];
        $o = !empty($p['assegnata_a']) ? operatore_ufficio($conn, (int)$p['assegnata_a']) : null;
        $passi[count($passi)] = $conclusa ? (STATI_PRATICA[$p['stato']][0] ?? 'Conclusa') : 'Conclusa';
        $out = '<ol class="list-unstyled d-flex flex-wrap gap-1 mb-0 small" aria-label="Iter della pratica">';
        foreach ($passi as $i => $nome) {
            $stile = $i < $att ? 'background:#dcfce7;color:#166534;' : ($i === $att ? 'background:#047857;color:#fff;' : 'background:#f1f5f9;color:#64748b;');
            $ico = $i < $att ? 'fa-check' : ($i === $att ? 'fa-location-dot' : 'fa-circle');
            $chi = ($i === $att && $o && !$conclusa) ? '<span class="d-block fw-normal" style="font-size:.7rem;">' . $h($o['nominativo']) . '</span>' : '';
            $out .= '<li class="px-2 py-1 rounded fw-bold" style="' . $stile . '"' . ($i === $att ? ' aria-current="step"' : '') . '><i class="fa ' . $ico . ' me-1" aria-hidden="true"></i>' . $h($nome) . $chi . '</li>';
            if ($i < count($passi) - 1) $out .= '<li class="align-self-center text-secondary" aria-hidden="true">›</li>';
        }
        return $out . '</ol>';
    }
}

if (!function_exists('badge_stato_pratica')) {
    function badge_stato_pratica(string $stato): string {
        [$n, $col, $ico] = STATI_PRATICA[$stato] ?? [$stato, '#64748b', 'fa-circle'];
        return '<span class="badge" style="background:' . $col . ';"><i class="fa ' . $ico . ' me-1" aria-hidden="true"></i>' . htmlspecialchars($n) . '</span>';
    }
}

// ==============================================================================
// SEDUTE, VERBALE (WORD) ED ESPORTAZIONE (EXCEL)
// ==============================================================================

if (!function_exists('seduta_didattica')) {
    function seduta_didattica($conn, int $id): ?array {
        $r = @$conn->query("SELECT * FROM didattica_sedute WHERE id = $id");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }
    function etichetta_seduta(array $s): string {
        return ($s['data'] ? date('d/m/Y', strtotime($s['data'])) . ' – ' : '') . mb_strimwidth((string)$s['organo'], 0, 90, '…');
    }
}

if (!function_exists('verbale_modulo')) {
    // Come compare il modulo nel verbale: titolo della sezione, stile (scheda = un paragrafo per pratica con le sue tabelle,
    // elenco = una tabella con una riga per pratica), testo per ogni pratica con i segnaposto, delibera, colonne, raggruppamento
    function verbale_modulo(array $m): array {
        $v = json_decode((string)($m['verbale_json'] ?? ''), true) ?: [];
        return [
            'sezione'   => trim((string)($v['sezione'] ?? '')) ?: (string)($m['titolo'] ?? $m['modulo_titolo'] ?? ''),
            'stile'     => ($v['stile'] ?? '') === 'elenco' ? 'elenco' : 'scheda',
            'intro'     => (string)($v['intro'] ?? ''),
            'testo'     => trim((string)($v['testo'] ?? '')) ?: '{STUDENTE}, matricola {MATRICOLA}, presenta la richiesta: {MODULO}.',
            'delibera'  => (string)($v['delibera'] ?? 'Il Consiglio approva.'),
            'chiusura'  => (string)($v['chiusura'] ?? ''),
            'colonne'   => (string)($v['colonne'] ?? ''),
            'raggruppa' => (string)($v['raggruppa'] ?? ''),
        ];
    }
}

if (!function_exists('risposte_pratica_tutte')) {
    // Risposte dello studente + campi compilati dall'ufficio, per etichetta (minuscola) => risposta
    function risposte_pratica_tutte(array $p): array {
        $out = [];
        foreach (array_merge(json_decode((string)$p['risposte_json'], true) ?: [], json_decode((string)($p['ufficio_json'] ?? ''), true) ?: []) as $r)
            $out[mb_strtolower(trim((string)$r['etichetta']))] = $r;
        return $out;
    }
}

if (!function_exists('testo_segnaposti_pratica')) {
    // {STUDENTE} (COGNOME NOME), {NOME}, {COGNOME}, {MATRICOLA}, {EMAIL}, {CODICE}, {MODULO}, {DATA}, {Etichetta di un campo}
    function testo_segnaposti_pratica(string $tpl, array $p): string {
        $ris = risposte_pratica_tutte($p);
        $fissi = ['studente' => mb_strtoupper(trim($p['cognome'] . ' ' . $p['nome'])), 'nome' => $p['nome'], 'cognome' => $p['cognome'], 'matricola' => $p['matricola'],
                  'email' => $p['email'], 'codice' => $p['codice'], 'modulo' => $p['modulo_titolo'] ?? '', 'data' => date('d/m/Y', strtotime($p['creata_il'])),
                  'protocollo' => trim(($p['protocollo'] ?? '') . (!empty($p['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($p['protocollo_data'])) : ''))];
        return preg_replace_callback('/\{([^{}\n]{1,200})\}/u', function ($m) use ($fissi, $ris) {
            $k = mb_strtolower(trim($m[1]));
            if (isset($fissi[$k])) return (string)$fissi[$k];
            if (isset($ris[$k])) {
                $v = (string)$ris[$k]['valore'];
                if (($ris[$k]['tipo'] ?? '') === 'tabella') return '';
                return ($ris[$k]['tipo'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? date('d/m/Y', strtotime($v)) : $v;
            }
            return $m[0];
        }, $tpl);
    }
}

if (!function_exists('pratiche_per_esportazione')) {
    // Pratiche complete (con modulo) per id, nell'ordine del verbale: categoria e ordine del modulo, poi cognome e nome
    function pratiche_per_esportazione($conn, array $ids): array {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $r = $conn->query("SELECT p.*, m.titolo AS modulo_titolo, m.categoria, m.ordine AS modulo_ordine, m.campi_json, m.verbale_json, s.organo AS seduta_organo, s.data AS seduta_data
                           FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id LEFT JOIN didattica_sedute s ON s.id = p.seduta_id
                           WHERE p.id IN (" . implode(',', $ids) . ") ORDER BY m.categoria, m.ordine, m.titolo, m.id, p.cognome, p.nome, p.id");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('corpo_pratiche_verbale')) {
    // Parte "Pratiche studenti" del verbale (XML di Word): una sezione per modulo
    function corpo_pratiche_verbale(array $pratiche): string {
        $per_modulo = [];
        foreach ($pratiche as $p) $per_modulo[(int)$p['modulo_id']][] = $p;
        $xml = '';
        foreach ($per_modulo as $lista) {
            $m = ['titolo' => $lista[0]['modulo_titolo'], 'verbale_json' => $lista[0]['verbale_json']];
            $v = verbale_modulo($m);
            $xml .= docx_p($v['sezione'], ['b' => true, 'u' => true, 'keep' => true, 'dopo' => 120]);
            if (trim($v['intro']) !== '') $xml .= docx_p(trim($v['intro']), ['al' => 'both']);
            if ($v['stile'] === 'elenco') {
                $campi = campi_modulo($lista[0]['campi_json']);
                $colonne = array_values(array_filter(array_map('trim', explode(',', $v['colonne']))));
                if (!$colonne) {
                    $colonne = ['COGNOME', 'NOME', 'MATRICOLA'];
                    foreach ($campi as $c) if (!in_array($c['tipo'], ['file', 'tabella'], true) && mb_strtolower($c['etichetta']) !== mb_strtolower($v['raggruppa'])) $colonne[] = mb_strtoupper($c['etichetta']);
                }
                $gruppi = [];
                foreach ($lista as $p) {
                    $ris = risposte_pratica_tutte($p);
                    $g = $v['raggruppa'] !== '' ? (string)($ris[mb_strtolower($v['raggruppa'])]['valore'] ?? '') : '';
                    $riga = [];
                    foreach ($colonne as $col) {
                        $k = mb_strtolower($col);
                        $riga[] = match ($k) { 'cognome' => mb_strtoupper($p['cognome']), 'nome' => mb_strtoupper($p['nome']), 'matricola' => $p['matricola'], 'codice' => $p['codice'],
                                               'delibera' => (string)$p['delibera'], 'protocollo' => (string)($p['protocollo'] ?? ''), default => (string)($ris[$k]['valore'] ?? '') };
                    }
                    $gruppi[$g][] = $riga;
                }
                foreach ($gruppi as $g => $righe) {
                    if ($g !== '') $xml .= docx_p($g . ':', ['b' => true, 'keep' => true, 'dopo' => 60]);
                    $xml .= docx_tabella(array_map('mb_strtoupper', $colonne), $righe, ['sz' => 18]);
                }
            } else {
                foreach ($lista as $p) {
                    $xml .= docx_p(testo_segnaposti_pratica($v['testo'], $p), ['al' => 'both', 'keep' => true]);
                    foreach (array_merge(json_decode((string)$p['risposte_json'], true) ?: [], json_decode((string)($p['ufficio_json'] ?? ''), true) ?: []) as $r) {
                        if (($r['tipo'] ?? '') !== 'tabella' || empty($r['righe'])) continue;
                        $xml .= docx_p($r['etichetta'], ['b' => true, 'sz' => 18, 'keep' => true, 'dopo' => 40]);
                        $xml .= docx_tabella($r['colonne'] ?? [], $r['righe'], ['sz' => count($r['colonne'] ?? []) > 8 ? 13 : 16]);
                    }
                    $del = trim((string)$p['delibera']) !== '' ? (string)$p['delibera'] : $v['delibera'];
                    if (trim($del) !== '') $xml .= docx_p(testo_segnaposti_pratica($del, $p), ['al' => 'both', 'dopo' => 240]);
                }
            }
            if (trim($v['chiusura']) !== '') $xml .= docx_p(trim($v['chiusura']), ['al' => 'both', 'dopo' => 240]);
        }
        return $xml;
    }
}

if (!function_exists('genera_verbale_pratiche')) {
    // Verbale in Word: con la seduta è il verbale completo (intestazione, o.d.g., presenze, punti, firme) con le pratiche
    // al punto "Pratiche studenti"; senza seduta è solo la parte delle pratiche. Ritorna il percorso di un file temporaneo.
    function genera_verbale_pratiche($conn, ?array $s, array $pratiche): ?string {
        $mesi = [1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
        $x = '';
        $corpo_pr = $pratiche ? corpo_pratiche_verbale($pratiche) : docx_p('Nessuna pratica.', ['i' => true]);
        if ($s) {
            $odg = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$s['odg']))));
            $odg = array_map(fn($t) => preg_replace('/^\d+[\.\)]\s*/', '', $t), $odg);
            $x .= docx_p((string)$s['organo'], ['b' => true, 'al' => 'center', 'dopo' => 60]);
            if ($s['anno_accademico'] !== '') $x .= docx_p('a.a. ' . $s['anno_accademico'], ['b' => true, 'al' => 'center', 'dopo' => 240]);
            $ts = $s['data'] ? strtotime($s['data']) : null;
            $quando = $ts ? 'Il giorno ' . date('d', $ts) . ' del mese di ' . $mesi[(int)date('n', $ts)] . ' ' . date('Y', $ts) : 'Il giorno ____';
            $x .= docx_p($quando . ' alle ore ' . ($s['ora_inizio'] ?: '____') . ', a seguito di convocazione si è riunito' . ($s['luogo'] !== '' ? ' presso ' . $s['luogo'] : '') . ', il ' . $s['organo'] . ', con il seguente o.d.g.:', ['al' => 'both']);
            foreach ($odg as $i => $t) $x .= docx_p(($i + 1) . ". " . $t, ['rientro' => 720, 'sporgente' => 360, 'dopo' => 0]);
            $x .= docx_p('', ['dopo' => 120]);
            foreach (preg_split('/\R/', (string)$s['presenze']) as $riga) {
                $riga = rtrim($riga);
                if (trim($riga) === '') { $x .= docx_p('', ['dopo' => 0]); continue; }
                $parti = preg_split('/\s*(\t|\|)\s*|\s{3,}/', trim($riga), 2);
                if (count($parti) === 2) $x .= docx_p($parti[0] . "\t" . mb_strtoupper($parti[1]), ['tab' => 9700, 'dopo' => 0]);
                else $x .= docx_p(trim($riga), ['b' => true, 'dopo' => 60, 'keep' => true]);
            }
            $x .= docx_p('', ['dopo' => 120]);
            if ($s['segretario'] !== '') $x .= docx_p('Risulta presente ' . $s['segretario'] . ' in qualità di segretario verbalizzante.', ['al' => 'both']);
            $x .= docx_p('Il Coordinatore, accertato di aver raggiunto il numero legale, dichiara aperta la seduta per discutere i punti all’ordine del giorno.', ['al' => 'both', 'dopo' => 240]);
            $messe = false;
            foreach ($odg as $i => $t) {
                $x .= docx_p(($i + 1) . '. ' . $t, ['b' => true, 'keep' => true, 'dopo' => 120]);
                if (!$messe && preg_match('/pratich/i', $t)) { $x .= $corpo_pr; $messe = true; }
                else $x .= docx_p('…', ['i' => true, 'dopo' => 240]);
            }
            if (!$messe) { $x .= docx_p('Pratiche studenti', ['b' => true, 'keep' => true]); $x .= $corpo_pr; }
            $x .= docx_p('Non avendo altro da discutere, la seduta si scioglie alle ore ' . ($s['ora_fine'] ?: '____') . '.', ['al' => 'both', 'dopo' => 480]);
            $x .= docx_tabella([], [["Il Segretario verbalizzante\n" . $s['segretario'], "Il Coordinatore\n" . $s['coordinatore']]], ['bordi' => false, 'sz' => 22]);
        } else {
            $x .= docx_p('Pratiche studenti', ['b' => true, 'al' => 'center', 'dopo' => 240]);
            $x .= $corpo_pr;
        }
        return docx_crea($x, ['logo' => RADICE_SITO . '/' . LOGO_VERBALE, 'font' => 'Times New Roman', 'sz' => 22]);
    }
}

if (!function_exists('genera_excel_pratiche')) {
    // Excel delle pratiche: un foglio con tutte (colonne di tutti i moduli) e un foglio per ogni modulo
    function genera_excel_pratiche($conn, array $pratiche): ?string {
        $prot = fn($p) => trim(($p['protocollo'] ?? '') . (!empty($p['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($p['protocollo_data'])) : ''));
        $fisse = ['Codice', 'Protocollo', 'Modulo','Categoria', 'Stato', 'Inviata il', 'Aggiornata il', 'Cognome', 'Nome', 'Matricola', 'Email', 'Seduta', 'Delibera'];
        $base = fn($p) => [$p['codice'], $prot($p), $p['modulo_titolo'], $p['categoria'], STATI_PRATICA[$p['stato']][0] ?? $p['stato'], date('d/m/Y H:i', strtotime($p['creata_il'])),
                           $p['aggiornata_il'] ? date('d/m/Y H:i', strtotime($p['aggiornata_il'])) : '', $p['cognome'], $p['nome'], $p['matricola'], $p['email'],
                           $p['seduta_data'] ? date('d/m/Y', strtotime($p['seduta_data'])) : '', (string)$p['delibera']];
        $etichette = function (array $lista) {
            $et = [];
            foreach ($lista as $p) foreach (risposte_pratica_tutte($p) as $k => $r) $et[$k] = $r['etichetta'];
            return $et;
        };
        $righe_di = function (array $lista, array $et) use ($base) {
            $out = [];
            foreach ($lista as $p) {
                $ris = risposte_pratica_tutte($p); $riga = $base($p);
                foreach (array_keys($et) as $k) $riga[] = (string)($ris[$k]['valore'] ?? '');
                $out[] = $riga;
            }
            return $out;
        };
        $larg = [13, 18, 30, 16,14, 16, 16, 18, 18, 12, 28, 12, 40];
        $et = $etichette($pratiche);
        $fogli = ['Tutte le pratiche' => ['intestazioni' => array_merge($fisse, array_values($et)), 'righe' => $righe_di($pratiche, $et), 'larghezze' => array_merge($larg, array_fill(0, count($et), 30))]];
        $per_modulo = [];
        foreach ($pratiche as $p) $per_modulo[(int)$p['modulo_id']][] = $p;
        if (count($per_modulo) > 1) foreach ($per_modulo as $lista) {
            $nome = rtrim(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $lista[0]['modulo_titolo']), 0, 28)); $n = $nome; $k = 2;
            while (isset($fogli[$n])) $n = $nome . ' ' . $k++;
            $e2 = $etichette($lista);
            $fogli[$n] = ['intestazioni' => array_merge($fisse, array_values($e2)), 'righe' => $righe_di($lista, $e2), 'larghezze' => array_merge($larg, array_fill(0, count($e2), 30))];
        }
        return xlsx_crea($fogli);
    }
}

if (!function_exists('periodo_modulo')) {
    // Il modulo online si compila solo nel periodo aperto_dal–aperto_al (vuoti = sempre). Ritorna [aperto, testo da mostrare].
    function periodo_modulo(array $m): array {
        $oggi = date('Y-m-d'); $d = fn($x) => date('d/m/Y', strtotime($x));
        $dal = $m['aperto_dal'] ?? null; $al = $m['aperto_al'] ?? null;
        if ($dal && $oggi < $dal) return [false, 'Si compila dal ' . $d($dal) . ($al ? ' al ' . $d($al) : '')];
        if ($al && $oggi > $al) return [false, 'Chiuso il ' . $d($al)];
        if ($al) return [true, 'Aperto fino al ' . $d($al)];
        return [true, ''];
    }
}

if (!function_exists('promemoria_pratiche_ferme')) {
    // Pratiche aperte senza movimenti da più dei giorni indicati nel modulo: email a chi le ha in carico (o a chi smista,
    // se non sono ancora assegnate). Un promemoria per ogni periodo di attesa. Ritorna il numero di email inviate.
    function promemoria_pratiche_ferme($conn): int {
        $r = @$conn->query("SELECT p.id, m.giorni_promemoria FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id
                            WHERE p.stato IN ('inviata', 'in_lavorazione') AND m.giorni_promemoria > 0
                              AND COALESCE(p.aggiornata_il, p.creata_il) < NOW() - INTERVAL m.giorni_promemoria DAY
                              AND (p.promemoria_il IS NULL OR p.promemoria_il < NOW() - INTERVAL m.giorni_promemoria DAY)");
        $n = 0; $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        while ($r && $x = $r->fetch_assoc()) {
            $p = pratica($conn, (int)$x['id']);
            if (!$p) continue;
            $o = !empty($p['assegnata_a']) ? operatore_ufficio($conn, (int)$p['assegnata_a']) : null;
            $smistano = array_keys(array_filter(uffici_didattica($conn), fn($u) => (int)$u['smista'] === 1));
            $dest = $o ? [$o['email']] : (array_column(array_filter(operatori_ufficio($conn), fn($op) => in_array((int)$op['ufficio_id'], $smistano, true)), 'email') ?: email_ufficio_didattica($conn, (string)$p['email_ufficio']));
            $giorni = (int)floor((time() - strtotime($p['aggiornata_il'] ?: $p['creata_il'])) / 86400);
            foreach ($dest as $e) {
                inviaNotificaEmail($e, "Pratica ferma da $giorni giorni: " . $p['modulo_titolo'] . " – " . trim($p['cognome'] . ' ' . $p['nome']),
                    "<p>La pratica <strong>" . $h($p['codice']) . "</strong> (" . $h($p['modulo_titolo']) . ") di " . $h(trim($p['nome'] . ' ' . $p['cognome'])) . " non ha movimenti da <strong>$giorni giorni</strong>"
                    . ($o ? " ed è in carico a te." : " e non è ancora stata smistata.") . "</p><p style='margin-top:18px;'><a href='" . $h(url_base_sito() . '/admin/didattica.php?tab=pratiche&id=' . (int)$p['id']) . "' style='background:#047857;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Apri la pratica</a></p>", $conn, '#047857');
                $n++;
            }
            $conn->query("UPDATE pratiche SET promemoria_il = NOW() WHERE id = " . (int)$p['id']);
        }
        return $n;
    }
}

if (!function_exists('statistiche_pratiche')) {
    // Statistiche delle pratiche inviate tra $dal e $al: per modulo e per corso di studio (stati ed esiti),
    // tempi medi per passo dell'iter (dal passaggio al passaggio successivo o alla conclusione) e tempo medio di chiusura.
    function statistiche_pratiche($conn, string $dal, string $al): array {
        $st = $conn->prepare("SELECT p.*, m.titolo AS modulo_titolo, m.iter_json FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE p.creata_il BETWEEN ? AND ?");
        $a = "$dal 00:00:00"; $b = "$al 23:59:59";
        $st->bind_param("ss", $a, $b); $st->execute();
        $pr = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $vuoto = ['totale' => 0, 'aperte' => 0, 'accolta' => 0, 'respinta' => 0, 'chiusa' => 0, 'giorni' => []];
        $per_mod = []; $per_corso = []; $passi = []; $chiusura = [];
        $finali = ['accolta', 'respinta', 'chiusa'];
        foreach ($pr as $p) {
            $corso = 'Senza corso di studio';
            foreach (json_decode((string)$p['risposte_json'], true) ?: [] as $r) if (($r['tipo'] ?? '') === 'corso_studio' && $r['valore'] !== '') { $corso = $r['valore']; break; }
            $conta = function (array &$tab, string $k) use ($p, $vuoto, $finali) {
                $tab[$k] ??= $vuoto;
                $tab[$k]['totale']++;
                if (in_array($p['stato'], $finali, true)) $tab[$k][$p['stato']]++; else $tab[$k]['aperte']++;
            };
            $conta($per_mod, $p['modulo_titolo']);
            $conta($per_corso, $corso);
            // Tempi: eventi della pratica in ordine
            $ev = $conn->query("SELECT tipo, stato, testo, creato_il FROM pratiche_eventi WHERE pratica_id = " . (int)$p['id'] . " ORDER BY creato_il, id")->fetch_all(MYSQLI_ASSOC);
            $inizio = strtotime($p['creata_il']); $passo_corr = 'Smistamento'; $t_corr = $inizio; $fine = null;
            foreach ($ev as $e) {
                $t = strtotime($e['creato_il']);
                if ($e['tipo'] === 'passaggio') {
                    $passi[$passo_corr][] = ($t - $t_corr) / 86400;
                    $passo_corr = preg_match('/^In carico a: (.+?) – /u', (string)$e['testo'], $mm) ? $mm[1] : 'Ufficio didattico';
                    $t_corr = $t;
                } elseif ($e['tipo'] === 'stato' && in_array($e['stato'], $finali, true) && $fine === null) {
                    $passi[$passo_corr][] = ($t - $t_corr) / 86400;
                    $fine = $t;
                }
            }
            if ($fine) { $chiusura[] = ($fine - $inizio) / 86400; $per_mod[$p['modulo_titolo']]['giorni'][] = ($fine - $inizio) / 86400; }
        }
        $media = fn(array $v) => $v ? round(array_sum($v) / count($v), 1) : null;
        foreach ($per_mod as &$x) $x['giorni'] = $media($x['giorni']); unset($x);
        foreach ($per_corso as &$x) unset($x['giorni']); unset($x);
        $tempi = [];
        foreach ($passi as $nome => $v) $tempi[$nome] = ['media' => $media($v), 'n' => count($v), 'max' => round(max($v), 1)];
        uasort($per_mod, fn($a, $b) => $b['totale'] <=> $a['totale']);
        uasort($per_corso, fn($a, $b) => $b['totale'] <=> $a['totale']);
        $tot = count($pr);
        $esiti = ['accolta' => 0, 'respinta' => 0, 'chiusa' => 0, 'aperte' => 0];
        foreach ($pr as $p) { if (in_array($p['stato'], $finali, true)) $esiti[$p['stato']]++; else $esiti['aperte']++; }
        return ['totale' => $tot, 'esiti' => $esiti, 'per_modulo' => $per_mod, 'per_corso' => $per_corso, 'tempi_passi' => $tempi, 'chiusura_media' => $media($chiusura)];
    }
}
