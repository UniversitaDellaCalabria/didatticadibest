<?php
// inc/attestati.php - Attestati singoli e di gruppo (classi), elenco degli studenti.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// ATTESTATI: singoli (una prenotazione = un attestato) e di gruppo (progetti per le scuole:
// un attestato per ogni studente dell'elenco inserito dal docente, inviati al docente).
// Ogni attestato ha un codice verificabile su verifica_attestato.php.
// =======================================================================
if (!function_exists('regola_attestato_evento')) {
    // Come si comporta l'attestato per l'evento della prenotazione:
    // 'evento'  = evento normale (regole di sempre)
    // 'no'      = progetto senza attestati
    // 'attendi' = progetto non ancora concluso (data di fine futura)
    // 'gruppo'  = progetto per le scuole o evento con attestati per la classe: attestati per gli studenti dell'elenco
    // 'singolo' = progetto generico concluso: attestato alla persona iscritta
    function regola_attestato_evento($conn, int $evento_id): string {
        $r = $conn->query("SELECT e.tipo, d.per_scuole, d.attestati, d.data_fine FROM eventi e LEFT JOIN progetti_dettagli d ON d.evento_id = e.id WHERE e.id = $evento_id LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row) return 'evento';
        if (($row['tipo'] ?? '') !== 'progetto') return (int)($row['attestati'] ?? 0) === 1 ? 'gruppo' : 'evento';
        if ((int)($row['attestati'] ?? 0) !== 1) return 'no';
        if (!empty($row['data_fine']) && $row['data_fine'] >= date('Y-m-d')) return 'attendi';
        return (int)($row['per_scuole'] ?? 1) === 1 ? 'gruppo' : 'singolo';
    }
}

if (!function_exists('get_partecipanti_prenotazione')) {
    // Elenco degli studenti di una prenotazione (ordine di inserimento)
    function get_partecipanti_prenotazione($conn, int $pr_id): array {
        $out = [];
        $r = $conn->query("SELECT * FROM partecipanti_prenotazione WHERE prenotazione_id = $pr_id ORDER BY ordine ASC, id ASC");
        while ($r && $row = $r->fetch_assoc()) $out[] = $row;
        return $out;
    }
}

if (!function_exists('nome_partecipante')) {
    function nome_partecipante(array $p): string {
        return trim(($p['cognome'] ?? '') . ' ' . ($p['nome'] ?? ''));
    }
}

if (!function_exists('leggi_elenco_partecipanti')) {
    // Righe incollate da Excel o da un file CSV: "Cognome<TAB>Nome", "Cognome;Nome", "Cognome,Nome".
    // Una riga senza separatori è tenuta intera (es. "Rossi Mario"). L'intestazione "Cognome/Nome" è ignorata.
    // Ritorna [['cognome' => ..., 'nome' => ...], ...] senza righe vuote né doppioni.
    function leggi_elenco_partecipanti(string $testo, int $max = 500): array {
        $testo = preg_replace('/^\xEF\xBB\xBF/', '', $testo); // BOM dei CSV di Excel
        $out = []; $visti = [];
        foreach (preg_split('/\r\n|\r|\n/', $testo) as $riga) {
            $riga = trim($riga);
            if ($riga === '') continue;
            $parti = preg_split('/\t|;|,/', $riga);
            $parti = array_values(array_filter(array_map(fn($x) => trim($x, " \t\"'"), $parti), fn($x) => $x !== ''));
            if (!$parti) continue;
            $cognome = mb_substr($parti[0], 0, 100);
            $nome    = mb_substr(implode(' ', array_slice($parti, 1)), 0, 100);
            if (preg_match('/^cognome$/i', $cognome) && ($nome === '' || preg_match('/^nome$/i', $nome))) continue;
            $chiave = mb_strtolower($cognome . '|' . $nome);
            if (isset($visti[$chiave])) continue;
            $visti[$chiave] = true;
            $out[] = ['cognome' => $cognome, 'nome' => $nome];
            if (count($out) >= $max) break;
        }
        return $out;
    }
}

if (!function_exists('leggi_elenco_da_campi')) {
    // Elenco dai campi separati Cognome[] e Nome[] del modulo: righe vuote ignorate, doppioni tolti
    function leggi_elenco_da_campi(array $cognomi, array $nomi, int $max = 500): array {
        $out = []; $visti = [];
        foreach (array_values($cognomi) as $i => $c) {
            $cognome = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$c)), 0, 100);
            $nome    = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)(array_values($nomi)[$i] ?? ''))), 0, 100);
            if ($cognome === '' && $nome === '') continue;
            $chiave = mb_strtolower($cognome . '|' . $nome);
            if (isset($visti[$chiave])) continue;
            $visti[$chiave] = true;
            $out[] = ['cognome' => $cognome, 'nome' => $nome];
            if (count($out) >= $max) break;
        }
        return $out;
    }
}

if (!function_exists('testo_da_file_elenco')) {
    // Testo "Cognome;Nome" da un file caricato: CSV/TXT (anche salvato da Excel) oppure XLSX (prime due colonne
    // del primo foglio; serve l'estensione zip di PHP). Ritorna il testo oppure null con $errore valorizzato.
    function testo_da_file_elenco(array $file, ?string &$errore = null): ?string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $errore = "Caricamento del file non riuscito."; return null; }
        if ((int)$file['size'] > 2 * 1024 * 1024) { $errore = "Il file supera i 2 MB."; return null; }
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) {
            $t = (string)file_get_contents($file['tmp_name']);
            if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252'); // CSV di Excel in italiano
            return $t;
        }
        if ($ext === 'xlsx') {
            if (!class_exists('ZipArchive')) { $errore = "Su questo server non si possono leggere i file .xlsx: salva il file come CSV oppure copia e incolla i nomi."; return null; }
            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== true) { $errore = "Il file .xlsx non è leggibile."; return null; }
            $condivise = [];
            if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = @simplexml_load_string($ss);
                if ($xml) foreach ($xml->si as $si) { $condivise[] = isset($si->t) ? (string)$si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]') ?: [])); }
            }
            $foglio = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            $xml = $foglio !== false ? @simplexml_load_string($foglio) : false;
            if (!$xml) { $errore = "Nel file .xlsx non trovo il primo foglio."; return null; }
            $righe = [];
            foreach ($xml->sheetData->row as $row) {
                $celle = [];
                foreach ($row->c as $c) {
                    $col = preg_replace('/\d+/', '', (string)$c['r']);
                    if (!in_array($col, ['A', 'B'], true)) continue;
                    $v = (string)($c->v ?? '');
                    if ((string)$c['t'] === 's') $v = $condivise[(int)$v] ?? '';
                    elseif ((string)$c['t'] === 'inlineStr') $v = (string)($c->is->t ?? '');
                    $celle[$col] = str_replace(["\t", ';'], ' ', trim($v));
                }
                if ($celle) $righe[] = ($celle['A'] ?? '') . "\t" . ($celle['B'] ?? '');
            }
            return implode("\n", $righe);
        }
        $errore = "Formato non supportato: carica un file .xlsx o .csv.";
        return null;
    }
}

if (!function_exists('invia_modello_elenco')) {
    // Scarica il modello da compilare (colonne Cognome, Nome): .xlsx se il server ha l'estensione zip, altrimenti .csv
    // (si apre comunque con Excel). $righe = eventuali nomi già inseriti. Termina lo script.
    function invia_modello_elenco(array $righe = [], string $nome_file = 'elenco_studenti'): void {
        while (ob_get_level() > 0) ob_end_clean();
        $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
            $zip = new ZipArchive();
            if ($tmp && $zip->open($tmp, ZipArchive::OVERWRITE) === true) {
                $righe_xml = '<row r="1"><c r="A1" t="inlineStr" s="1"><is><t>Cognome</t></is></c><c r="B1" t="inlineStr" s="1"><is><t>Nome</t></is></c></row>';
                foreach (array_values($righe) as $i => $r) {
                    $n = $i + 2;
                    $righe_xml .= '<row r="' . $n . '"><c r="A' . $n . '" t="inlineStr"><is><t>' . $x($r['cognome']) . '</t></is></c><c r="B' . $n . '" t="inlineStr"><is><t>' . $x($r['nome']) . '</t></is></c></row>';
                }
                $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
                $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
                $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Studenti" sheetId="1" r:id="rId1"/></sheets></workbook>');
                $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
                $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
                $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="2" width="32" customWidth="1"/></cols><sheetData>' . $righe_xml . '</sheetData></worksheet>');
                $zip->close();
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $nome_file . '.xlsx"');
                header('Content-Length: ' . filesize($tmp));
                readfile($tmp);
                @unlink($tmp);
                exit;
            }
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome_file . '.csv"');
        echo "\xEF\xBB\xBF" . "Cognome;Nome\r\n";
        foreach ($righe as $r) echo str_replace(';', ' ', $r['cognome']) . ';' . str_replace(';', ' ', $r['nome']) . "\r\n";
        exit;
    }
}

if (!function_exists('salva_elenco_partecipanti')) {
    // Sostituisce l'elenco degli studenti (usata dal docente prima dell'invio degli attestati).
    function salva_elenco_partecipanti($conn, int $pr_id, array $righe): void {
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM partecipanti_prenotazione WHERE prenotazione_id = $pr_id");
            $ins = $conn->prepare("INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome, ordine) VALUES (?, ?, ?, ?)");
            foreach (array_values($righe) as $i => $r) {
                $ins->bind_param("issi", $pr_id, $r['cognome'], $r['nome'], $i);
                $ins->execute();
            }
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
}

if (!function_exists('max_partecipanti_prenotazione')) {
    // Quanti studenti può contenere l'elenco: il numero dichiarato nell'iscrizione, altrimenti il massimo del progetto
    function max_partecipanti_prenotazione(array $pren, ?array $dett): int {
        $custom = json_decode((string)($pren['dati_custom_json'] ?? ''), true) ?: [];
        $dich = (int)($custom[CAMPO_PARTECIPANTI] ?? 0);
        if ($dich > 0) return $dich;
        return limiti_partecipanti($dett, $pren)['max'] ?? 200;
    }
}

if (!function_exists('nuovo_codice_attestato')) {
    function nuovo_codice_attestato(): string {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // senza caratteri ambigui (0/O, 1/I)
        $c = '';
        for ($i = 0; $i < 10; $i++) $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        return 'AT-' . $c;
    }
}

if (!function_exists('url_verifica_attestato')) {
    function url_verifica_attestato(string $codice): string {
        return url_base_sito() . '/verifica_attestato.php?c=' . urlencode($codice);
    }
}

if (!function_exists('prenotazione_per_attestati')) {
    // Prenotazione con evento, area, portale e scheda del progetto: i dati che servono agli attestati
    function prenotazione_per_attestati($conn, int $pr_id): ?array {
        $r = $conn->query("SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine, t.nome_turno, t.evento_id, t.min_partecipanti, t.max_partecipanti,
                                  e.titolo AS evento_titolo, e.luogo AS evento_luogo, e.tipo AS evento_tipo, e.pagina_id,
                                  pe.titolo AS pagina_titolo, pe.firma_nome, pe.firma_titolo, pe.logo_attestato_path, pe.colore_primario, pe.testo_attestato,
                                  cp.logo_path, cp.nome_portale, cp.sottotitolo_portale,
                                  d.per_scuole, d.attestati, d.data_inizio, d.data_fine, d.ore_totali
                           FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                           LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
                           LEFT JOIN progetti_dettagli d ON d.evento_id = e.id
                           LEFT JOIN configurazione_portale cp ON cp.id = 1
                           WHERE pr.id = $pr_id LIMIT 1");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }
}

if (!function_exists('dati_attestato')) {
    // Campi dell'attestato a partire da una prenotazione (prenotazione_per_attestati o get_attestato).
    // Nei progetti: ore totali e periodo del progetto; negli eventi: data e durata del turno.
    function dati_attestato(array $p, string $nome_completo, string $codice, string $matricola = ''): array {
        $ore = ''; $quando = '';
        if (($p['evento_tipo'] ?? '') === 'progetto') {
            if (!empty($p['ore_totali'])) $ore = (string)(int)$p['ore_totali'];
            if (!empty($p['data_inizio']) || !empty($p['data_fine'])) $quando = periodo_progetto($p);
        } else {
            if (!empty($p['orario_inizio']) && !empty($p['orario_fine'])) {
                $ore = str_replace('.0', '', (string)round((strtotime($p['orario_fine']) - strtotime($p['orario_inizio'])) / 3600, 1));
            }
            if (!empty($p['data_turno'])) $quando = 'In data ' . date('d/m/Y', strtotime($p['data_turno']));
        }
        return [
            'nome'       => $nome_completo,
            'matricola'  => $matricola,
            'evento'     => (string)($p['evento_titolo'] ?? ''),
            'luogo'      => (string)($p['evento_luogo'] ?? ''),
            'quando'     => $quando,
            'ore'        => $ore,
            'area'       => (string)($p['pagina_titolo'] ?? ''),
            'logo'       => !empty($p['logo_attestato_path']) ? $p['logo_attestato_path'] : ($p['logo_path'] ?? ''),
            'portale'    => (string)($p['nome_portale'] ?? ''),
            'sottotitolo'=> (string)($p['sottotitolo_portale'] ?? ''),
            'firma_nome' => !empty($p['firma_nome']) ? $p['firma_nome'] : 'Mauro F. La Russa',
            'firma_titolo' => !empty($p['firma_titolo']) ? $p['firma_titolo'] : 'Il Direttore del Dipartimento',
            'codice'     => $codice,
            // Frase prima del titolo: quella dell'area (Impostazioni area → Attestati), altrimenti una predefinita
            'formula'    => trim((string)($p['testo_attestato'] ?? '')) !== '' ? trim($p['testo_attestato'])
                            : (($p['evento_tipo'] ?? '') === 'progetto' ? "ha partecipato al progetto dal titolo:" : "ha partecipato all'attività formativa/evento denominata:"),
        ];
    }
}

if (!function_exists('url_vendor')) {
    // Librerie e caratteri salvati sul server in assets/vendor (stessa struttura dei CDN): aprendo le pagine
    // il browser non si collega a servizi di terzi (privacy: niente IP a Google Fonts o ai CDN).
    // Percorso dalla radice del sito, valido sia dalle pagine pubbliche sia da /admin.
    function url_vendor(string $percorso): string {
        return rtrim((string)parse_url(url_base_sito(), PHP_URL_PATH), '/') . '/assets/vendor/' . ltrim($percorso, '/');
    }
}

if (!function_exists('script_libreria')) {
    // Librerie JavaScript salvate sul server (assets/js): QR e scanner funzionano anche se il CDN non risponde.
    // Se il file locale mancasse (es. non caricato sul server) si ripiega sul CDN, in modo sincrono
    // (document.write subito dopo lo script locale): il codice che segue trova la libreria come prima.
    function script_libreria(string $nome): string {
        $librerie = [
            'qrcode' => ['qrcode-generator-1.4.4.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js',
                         'sha384-mZT2gIty7ZDdOGkxfP6joZcYdMW1Jvj9dRlfpTmaJAKKXTqzygtB22k7FLe+KZC1', 'typeof qrcode==="function"'],
            'html5-qrcode' => ['html5-qrcode-2.3.8.min.js', 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js',
                         'sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+', 'typeof Html5Qrcode==="function"'],
        ];
        if (!isset($librerie[$nome])) return '';
        [$file, $cdn, $sri, $presente] = $librerie[$nome];
        // Percorso dalla radice del sito (es. /eventi/assets/js/...): vale sia dalle pagine pubbliche sia da /admin
        $locale = rtrim((string)parse_url(url_base_sito(), PHP_URL_PATH), '/') . '/assets/js/' . $file;
        return '<script src="' . htmlspecialchars($locale) . '" integrity="' . $sri . '"></script>'
             . '<script>' . $presente . '||document.write(\'<script src="' . $cdn . '" integrity="' . $sri . '" crossorigin="anonymous"><\/script>\');</script>';
    }
}

if (!function_exists('qr_html')) {
    // QR disegnato nella pagina (SVG, libreria qrcode-generator): nessun servizio esterno riceve il contenuto.
    // $stile imposta la larghezza (il QR è quadrato); lo script si aggiunge da solo una volta per pagina.
    function qr_html(string $dati, string $stile = 'width:150px', string $alt = 'QR code', string $classi = ''): string {
        static $script_inviato = false;
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $out = '<div class="qr-locale ' . $h($classi) . '" data-qr="' . $h($dati) . '" role="img" aria-label="' . $h($alt) . '" style="aspect-ratio:1/1;' . $h($stile) . '"></div>';
        if (!$script_inviato) {
            $script_inviato = true;
            $out .= '<style>.qr-locale svg{width:100%;height:100%;display:block}</style>'
                  . script_libreria('qrcode')
                  . '<script>(function(){function d(){document.querySelectorAll(".qr-locale[data-qr]").forEach(function(el){'
                  . 'if(el.firstChild)return;if(typeof qrcode!=="function"){el.textContent="QR non disponibile";return;}'
                  . 'var q=qrcode(0,"M");q.addData(el.dataset.qr);q.make();el.innerHTML=q.createSvgTag({cellSize:4,margin:2,scalable:true});});}'
                  . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",d);else d();})();</script>';
        }
        return $out;
    }
}

if (!function_exists('slug_file')) {
    // Testo adatto a un nome di file: minuscole, senza accenti né apostrofi, parole separate da _
    function slug_file(string $s): string {
        $s = strtr(mb_strtolower(trim($s)), ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', "'" => '', '’' => '']);
        return trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    }
}

if (!function_exists('pagina_attestati')) {
    // Documento HTML stampabile con uno o più attestati (uno per pagina A4 orizzontale).
    // Ogni attestato riporta il codice di verifica e il QR che apre verifica_attestato.php.
    function pagina_attestati(array $lista, string $titolo_doc): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        ob_start(); ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $h($titolo_doc); ?></title>
    <link href="<?php echo url_vendor('jsdelivr/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo url_vendor('cdnjs/ajax/libs/font-awesome/6.4.0/css/all.min.css'); ?>">
    <link href="<?php echo url_vendor('fonts/dancing-script.css'); ?>" rel="stylesheet">
    <style>
        body, html { margin: 0; padding: 0; background-color: #e2e8f0; font-family: 'Georgia', 'Times New Roman', serif; color: #1e293b; box-sizing: border-box; }
        @page { size: A4 landscape; margin: 0; }
        @media print {
            body { background-color: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .cert-container { box-shadow: none !important; margin: 0 !important; width: 297mm !important; height: 209mm !important; padding: 12mm !important; page-break-after: always; break-after: page; page-break-inside: avoid; }
            .cert-container:last-of-type { page-break-after: auto; break-after: auto; }
        }
        .cert-container { width: 297mm; height: 210mm; margin: 20px auto; background: #ffffff; box-shadow: 0 10px 30px rgba(0,0,0,0.15); padding: 10mm; position: relative; box-sizing: border-box; overflow: hidden; }
        .cert-border-outer { border: 4px solid #B30000; padding: 5px; height: 100%; border-radius: 4px; box-sizing: border-box; }
        .cert-border-inner { border: 2px solid #0056b3; height: 100%; padding: 20px 40px; text-align: center; position: relative; border-radius: 2px; box-sizing: border-box; display: flex; flex-direction: column; justify-content: space-between; }
        .cert-header { display: flex; justify-content: center; align-items: center; gap: 20px; }
        .cert-logo { max-height: 80px; object-fit: contain; padding: 5px; border-radius: 6px; }
        .cert-main-content { display: flex; flex-direction: column; justify-content: center; flex-grow: 1; }
        .cert-title { font-size: 3.2rem; font-weight: bold; color: #B30000; letter-spacing: 2px; margin: 0 0 10px 0; text-transform: uppercase; line-height: 1.1; }
        .cert-subtitle { font-size: 1.4rem; color: #64748b; font-style: italic; margin-bottom: 20px; }
        .cert-body { font-size: 1.3rem; line-height: 1.5; }
        .cert-name { font-size: 2.3rem; font-weight: bold; color: #1e293b; border-bottom: 1px solid #cbd5e1; display: inline-block; padding: 0 40px; margin: 10px 0; }
        .cert-event { font-size: 1.6rem; font-weight: bold; color: #0056b3; margin: 10px 0; display: block; line-height: 1.2; }
        .cert-footer { display: flex; justify-content: space-between; align-items: flex-end; padding: 0 20px; gap: 20px; }
        .cert-verifica { display: flex; align-items: flex-end; gap: 12px; text-align: left; font-size: 1.05rem; padding-bottom: 6px; }
        .cert-qr { width: 84px; height: 84px; flex-shrink: 0; font-size: .6rem; color: #94a3b8; }
        .cert-qr svg { width: 100%; height: 100%; display: block; }
        .cert-signature { width: 300px; text-align: center; font-size: 1.1rem; }
        .signature-text { font-family: 'Dancing Script', cursive; font-size: 2.6rem; color: #1e293b; line-height: 0.6; margin-bottom: 10px; transform: rotate(-3deg); white-space: nowrap; }
        .cert-stamp { position: absolute; bottom: 50%; left: 50%; transform: translate(-50%, 50%); opacity: 0.05; font-size: 15rem; color: #B30000; pointer-events: none; }
        .cert-id { position: absolute; bottom: 5px; left: 10px; font-size: 0.75rem; color: #94a3b8; font-family: monospace; }
    </style>
</head>
<body>
    <div class="text-center my-3 no-print">
        <?php $n_att = count($lista); $zip_nome = 'attestati_' . (slug_file(preg_replace('/^Attestati? - /', '', $titolo_doc)) ?: 'partecipazione'); ?>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            <button type="button" onclick="window.print()" class="btn btn-danger fw-bold px-4 py-2 shadow-sm fs-5" style="background:#B30000; border:none;"><i class="fa fa-print me-2"></i>Stampa / PDF unico<?php echo $n_att > 1 ? " ($n_att attestati)" : ''; ?></button>
            <button type="button" id="btnScaricaAtt" data-zip="<?php echo $h($zip_nome); ?>" class="btn btn-success fw-bold px-4 py-2 shadow-sm fs-5"><i class="fa <?php echo $n_att > 1 ? 'fa-file-zipper' : 'fa-file-pdf'; ?> me-2"></i><?php echo $n_att > 1 ? "Scarica ZIP ($n_att PDF separati)" : 'Scarica PDF'; ?></button>
            <button type="button" onclick="window.close()" class="btn btn-outline-secondary py-2 px-4 fw-bold fs-5">Chiudi</button>
        </div>
        <p class="text-muted mt-2 small mb-0"><i class="fa fa-info-circle me-1"></i> <strong>Stampa / PDF unico:</strong> seleziona <strong>Orizzontale</strong>, margini <strong>Nessuno</strong> e <strong>Grafica in background</strong>.<?php if ($n_att > 1): ?> <strong>Scarica ZIP:</strong> un PDF per studente (attestato_cognome_nome.pdf), comodo da inviare per email.<?php endif; ?></p>
        <div id="attBarra" class="mx-auto mt-3" style="max-width: 520px; display: none;">
            <div class="progress" style="height: 22px;" role="progressbar" aria-label="Preparazione dei PDF" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success fw-bold" style="width: 0%;">0%</div>
            </div>
        </div>
        <p id="attAvanzamento" class="fw-semibold mt-2 mb-0" aria-live="polite"></p>
    </div>
    <?php foreach ($lista as $a): $url_ver = url_verifica_attestato($a['codice']); ?>
    <div class="cert-container" data-file="<?php echo $h(($a['file'] ?? '') !== '' ? $a['file'] : 'attestato_' . slug_file($a['nome'])); ?>">
        <div class="cert-border-outer">
            <div class="cert-border-inner">
                <i class="fa fa-award cert-stamp" aria-hidden="true"></i>
                <div class="cert-header">
                    <?php if (!empty($a['logo'])): ?><img src="<?php echo $h($a['logo']); ?>" class="cert-logo" alt="Logo"><?php endif; ?>
                    <div>
                        <h4 class="fw-bold m-0" style="color: #334155;"><?php echo $h($a['portale']); ?></h4>
                        <span style="font-size: 1.1rem; color: #64748b;"><?php echo $h($a['sottotitolo']); ?></span>
                    </div>
                </div>
                <div class="cert-main-content">
                    <h1 class="cert-title">Attestato di Partecipazione</h1>
                    <div class="cert-subtitle">Si attesta che</div>
                    <div class="cert-body">
                        <span class="cert-name"><?php echo $h(mb_strtoupper($a['nome'])); ?></span><br>
                        <?php if ($a['matricola'] !== ''): ?><span style="font-size: 1.1rem; color: #64748b;">(Matricola: <?php echo $h($a['matricola']); ?>)</span><br><?php endif; ?>
                        <span class="mt-3 d-block"><?php echo $h($a['formula']); ?></span>
                        <span class="cert-event">"<?php echo $h($a['evento']); ?>"</span>
                        <span class="d-block mt-2">
                            <?php echo $a['quando'] !== '' ? $h($a['quando']) . ',' : 'Svoltasi'; ?> presso <?php echo $h($a['luogo'] ?: 'le nostre strutture'); ?><?php if ($a['ore'] !== ''): ?>
                            <strong>per un numero di ore pari a <?php echo $h($a['ore']); ?></strong><?php endif; ?>.
                        </span>
                    </div>
                </div>
                <div class="cert-footer">
                    <div class="cert-verifica">
                        <div class="cert-qr" data-qr="<?php echo $h($url_ver); ?>" role="img" aria-label="QR per verificare l'attestato"></div>
                        <div>
                            <strong>Data di rilascio:</strong> <?php echo date('d/m/Y'); ?><br>
                            <?php if ($a['area'] !== ''): ?><strong>Rif. Iniziativa:</strong> <?php echo $h($a['area']); ?><br><?php endif; ?>
                            <span style="font-size: .9rem; color: #475569;">Verifica: inquadra il QR o inserisci il codice <strong style="font-family: monospace;"><?php echo $h($a['codice']); ?></strong> su <?php echo $h(preg_replace('#^https?://#', '', url_base_sito())); ?>/verifica_attestato.php</span>
                        </div>
                    </div>
                    <div class="cert-signature">
                        <div class="signature-text"><?php echo $h($a['firma_nome']); ?></div>
                        <div class="border-top border-dark pt-1 mt-1">
                            <span class="fw-bold d-block">Prof. <?php echo $h($a['firma_nome']); ?></span>
                            <small style="color: #64748b;"><?php echo $h($a['firma_titolo']); ?></small>
                        </div>
                    </div>
                </div>
                <div class="cert-id">Codice Verifica Autenticità: <?php echo $h($a['codice']); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <!-- QR generati nella pagina: nessun servizio esterno riceve l'indirizzo di verifica -->
    <?php echo script_libreria('qrcode'); ?>
    <script>
    document.querySelectorAll('.cert-qr').forEach(function (el) {
        if (typeof qrcode !== 'function') { el.textContent = 'QR non disponibile: usa il codice'; return; }
        var q = qrcode(0, 'M'); q.addData(el.dataset.qr); q.make();
        el.innerHTML = q.createSvgTag({ cellSize: 3, margin: 0, scalable: true });
    });

    // Scarica: ogni attestato diventa un PDF A4 orizzontale (immagine ad alta risoluzione della pagina);
    // con più attestati i PDF vanno in un unico ZIP. Tutto nel browser: librerie caricate solo al clic.
    (function () {
        var btn = document.getElementById('btnScaricaAtt'), stato = document.getElementById('attAvanzamento');
        if (!btn) return;
        var barra = document.getElementById('attBarra'), pb = barra.querySelector('.progress'), pbi = barra.querySelector('.progress-bar');
        var percentuale = function (p, finito) {
            barra.style.display = '';
            pbi.style.width = p + '%'; pbi.textContent = p + '%'; pb.setAttribute('aria-valuenow', p);
            pbi.classList.toggle('progress-bar-animated', !finito);
        };
        // Impronte SRI: il browser rifiuta le librerie se il CDN le servisse modificate
        var sri = {
            'html2canvas/1.4.1/html2canvas.min.js': 'sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H',
            'jspdf/2.5.1/jspdf.umd.min.js': 'sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk',
            'jszip/3.10.1/jszip.min.js': 'sha384-+mbV2IY1Zk/X1p/nWllGySJSUN8uMs+gUAN10Or95UBH0fpj6GfKgPmgC5EXieXG'
        };
        var carica = function (src) {
            return new Promise(function (ok, ko) {
                var s = document.createElement('script'); s.src = src; s.crossOrigin = 'anonymous';
                var chiave = src.split('/ajax/libs/')[1]; if (sri[chiave]) s.integrity = sri[chiave];
                s.onload = ok; s.onerror = ko; document.head.appendChild(s);
            });
        };
        var salva = function (blob, nome) {
            var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = nome;
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 10000);
        };
        btn.addEventListener('click', async function () {
            var fogli = Array.prototype.slice.call(document.querySelectorAll('.cert-container'));
            var testo = btn.innerHTML; btn.disabled = true;
            try {
                stato.textContent = 'Preparazione in corso…'; percentuale(0);
                var lib = '<?php echo url_vendor('cdnjs/ajax/libs/'); ?>';
                await carica(lib + 'html2canvas/1.4.1/html2canvas.min.js');
                await carica(lib + 'jspdf/2.5.1/jspdf.umd.min.js');
                if (fogli.length > 1) await carica(lib + 'jszip/3.10.1/jszip.min.js');
                if (document.fonts && document.fonts.ready) await document.fonts.ready;
                var zip = fogli.length > 1 ? new JSZip() : null, usati = {};
                for (var i = 0; i < fogli.length; i++) {
                    stato.textContent = 'Creazione del PDF ' + (i + 1) + ' di ' + fogli.length + '…';
                    var canvas = await html2canvas(fogli[i], { scale: 2, backgroundColor: '#ffffff', useCORS: true, windowWidth: 1400 });
                    var pdf = new jspdf.jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4', compress: true });
                    pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, 297, 210);
                    // Nomi uguali (omonimi): attestato_rossi_mario_2.pdf
                    var base = fogli[i].dataset.file || ('attestato_' + (i + 1));
                    usati[base] = (usati[base] || 0) + 1;
                    var nome = base + (usati[base] > 1 ? '_' + usati[base] : '') + '.pdf';
                    if (zip) zip.file(nome, pdf.output('blob')); else salva(pdf.output('blob'), nome);
                    percentuale(Math.round((i + 1) / fogli.length * (zip ? 90 : 100)));
                }
                if (zip) {
                    stato.textContent = 'Creazione dello ZIP…';
                    salva(await zip.generateAsync({ type: 'blob' }, function (m) { percentuale(90 + Math.round(m.percent / 10)); }), btn.dataset.zip + '.zip');
                }
                percentuale(100, true);
                stato.textContent = 'Download completato: trovi il file nella cartella Download.';
            } catch (e) {
                console.error(e);
                barra.style.display = 'none';
                stato.textContent = 'Download non riuscito: riprova, oppure usa "Stampa / PDF unico".';
            }
            btn.disabled = false; btn.innerHTML = testo;
        });
    })();
    </script>
</body>
</html>
<?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('assegna_codici_partecipanti')) {
    // Codice di verifica per gli studenti che non l'hanno ancora (assegnato una volta, non cambia più)
    function assegna_codici_partecipanti($conn, int $pr_id): void {
        $r = $conn->query("SELECT id FROM partecipanti_prenotazione WHERE prenotazione_id = $pr_id AND (codice IS NULL OR codice = '')");
        $upd = $conn->prepare("UPDATE partecipanti_prenotazione SET codice = ? WHERE id = ?");
        while ($r && $row = $r->fetch_assoc()) {
            for ($tent = 0; $tent < 5; $tent++) {
                $c = nuovo_codice_attestato(); $id = (int)$row['id'];
                $upd->bind_param("si", $c, $id);
                if ($upd->execute()) break; // codice UNIQUE: in caso (rarissimo) di doppione si riprova
            }
        }
    }
}

if (!function_exists('invia_attestati_gruppo')) {
    // Progetti per le scuole ed eventi con attestati per la classe: genera i codici degli studenti e manda
    // a chi ha prenotato il link agli attestati. Condizioni: prenotazione confermata e presente, almeno uno
    // studente non escluso, attività conclusa (progetto: data di fine; evento: giorno del turno), oppure
    // $forza dal pulsante "Invia attestati ora" dell'admin. Ritorna true o il motivo.
    function invia_attestati_gruppo($conn, int $pr_id, bool $forza = false) {
        $p = prenotazione_per_attestati($conn, $pr_id);
        if (!$p || !attestati_di_classe($p)) return "Questa attività non prevede attestati per gli studenti.";
        $is_progetto = ($p['evento_tipo'] ?? '') === 'progetto';
        if (($p['stato'] ?? 'confermata') !== 'confermata') return "La prenotazione non è confermata.";
        if ((int)$p['presente'] !== 1) return "Segna prima la presenza della classe.";
        if (!$forza && !empty($p['attestato_inviato'])) return "Attestati già inviati.";
        if (!$forza && !attivita_conclusa_classe($p)) return $is_progetto ? "Il progetto non è ancora concluso." : "L'evento non si è ancora svolto.";
        $r_n = $conn->query("SELECT COUNT(*) AS n FROM partecipanti_prenotazione WHERE prenotazione_id = $pr_id AND escluso = 0");
        $n = $r_n ? (int)$r_n->fetch_assoc()['n'] : 0;
        if ($n === 0) return "L'elenco degli studenti è vuoto.";
        if (empty($p['email'])) return "Manca l'email del docente.";

        assegna_codici_partecipanti($conn, $pr_id);
        $link = url_base_sito() . '/attestati_gruppo.php?code=' . urlencode($p['codice_prenotazione']);
        $corpo = "<p>Gentile <strong>" . htmlspecialchars($p['nome'] . ' ' . $p['cognome']) . "</strong>,</p>"
               . "<p>grazie per aver partecipato con la tua classe " . ($is_progetto ? "al progetto" : "all'attività") . " <strong>" . htmlspecialchars($p['evento_titolo']) . "</strong>.</p>"
               . "<p>Sono pronti gli <strong>attestati di partecipazione di $n " . ($n === 1 ? 'studente' : 'studenti') . "</strong>: li trovi tutti in un'unica pagina, uno per foglio, pronti da stampare o salvare in PDF. Ogni attestato ha un codice e un QR per verificarne l'autenticità.</p>"
               . "<p style='text-align:center; margin:30px 0;'><a href='" . htmlspecialchars($link) . "' style='background-color:#198754; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:16px;'>📄 Apri gli attestati</a></p>"
               . "<p>Per aprirli accedi con le stesse credenziali usate per l'iscrizione. Li ritrovi anche nella tua <a href='" . htmlspecialchars(url_base_sito() . '/area_personale.php') . "'>Area Personale</a>.</p>";
        inviaNotificaEmail($p['email'], "Attestati degli studenti: " . $p['evento_titolo'], $corpo, $conn, colore_area_turno($conn, (int)$p['turno_id']));
        $conn->query("UPDATE prenotazioni SET attestato_inviato = 1 WHERE id = $pr_id");
        return true;
    }
}

if (!function_exists('puo_vedere_prenotazione')) {
    // L'utente loggato è il titolare della prenotazione, un amministratore o un gestore
    function puo_vedere_prenotazione(array $p): bool {
        $u_id = (int)($_SESSION['utente_id'] ?? 0);
        if ($u_id <= 0) return false;
        $ruolo = (int)($_SESSION['utente_ruolo_id'] ?? 5);
        $sec = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
        if (in_array($ruolo, [1, 2], true) || in_array('1', $sec, true) || in_array('2', $sec, true)) return true;
        return (int)($p['utente_id'] ?? 0) === $u_id
            || (!empty($_SESSION['utente_email']) && strtolower((string)$p['email']) === strtolower($_SESSION['utente_email']));
    }
}
