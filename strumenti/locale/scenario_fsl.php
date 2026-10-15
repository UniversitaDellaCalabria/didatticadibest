<?php
// =========================================================================
// Convenzione FSL di una scuola portata, a tappe, nei vari punti dell'iter (per le schermate della guida per le scuole).
//   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/scenario_fsl.php <tappa>
// tappe: nuova (riparte da zero) · compila (la scuola compila e ha i documenti pronti) · carica (la scuola ha caricato il PDF firmato) ·
//        presa (l'ufficio ha protocollato in entrata e il Direttore ha il link) · firmata (il Direttore ha firmato) · attiva (protocollo definitivo: convenzione attiva)
// Le email non partono: si salvano in cache/email_locali/ (si vedono da /__email). Le firme digitali non si controllano (dati di prova).
// Scrive in cache/scenario_fsl.json il link della scuola. Serve la prenotazione OP-PROVA001 dei dati di esempio.
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
chdir(realpath(__DIR__ . '/../..'));
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SESSION = [];
define('AMBIENTE_LOCALE_CLI', 1);
$e = parse_ini_file('.env.locale');
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($e['DB_HOST'], $e['DB_USER'], $e['DB_PASS'], $e['DB_NAME']);
$conn->set_charset('utf8mb4');

// Le email si salvano come pagine HTML, così si possono vedere (e fotografare) da /__email
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) {
    $dir = 'cache/email_locali/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $nome = date('Ymd_His') . '_' . substr(md5($subject . $to . microtime()), 0, 6) . '_' . preg_replace('/[^A-Za-z0-9@._-]/', '_', (string) $to) . '.html';
    file_put_contents($dir . $nome, '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>' . htmlspecialchars((string) $subject) . '</title></head><body style="font-family:Segoe UI,Arial,sans-serif;max-width:640px;margin:24px auto;color:#1e293b;line-height:1.5">'
        . '<div style="border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:16px;font-size:14px;color:#475569"><b>A:</b> ' . htmlspecialchars((string) $to) . '<br><b>Oggetto:</b> ' . htmlspecialchars((string) $subject) . '</div>' . $body_html . '</body></html>');

    return true;
}
require 'functions.php';

$tappa = $argv[1] ?? '';
$c = \App\Core\App::per($conn);
$db = $c->get(\App\Core\Database::class);
$online = $c->get(\App\Fsl\ServizioConvenzioneOnline::class);
$firmata = $c->get(\App\Fsl\ServizioConvenzioneFirmata::class);
$rep = $c->get(\App\Fsl\ConvenzioneCompilataRepository::class);
$sito = $c->get(\App\Core\Sito::class);

$pdf = function (string $titolo): string {
    $testo = "BT /F1 18 Tf 70 760 Td ($titolo) Tj 0 -30 Td /F1 11 Tf (Documento di esempio generato dall'ambiente di prova) Tj ET";
    $ogg = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            "<< /Length " . strlen($testo) . " >>\nstream\n$testo\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $out = "%PDF-1.4\n";
    $pos = [];
    foreach ($ogg as $i => $o) {
        $pos[] = strlen($out);
        $out .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $x = strlen($out);
    $out .= "xref\n0 " . (count($ogg) + 1) . "\n0000000000 65535 f \n";
    foreach ($pos as $p) {
        $out .= sprintf("%010d 00000 n \n", $p);
    }

    return $out . "trailer\n<< /Size " . (count($ogg) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
};
/** Salva un PDF nella cartella protetta indicata e ritorna il percorso relativo. */
$salva = function (string $dir, string $titolo) use ($pdf, $sito): string {
    @mkdir($sito->radice() . '/' . $dir, 0755, true);
    $n = bin2hex(random_bytes(12)) . '.pdf';
    file_put_contents($sito->radice() . '/' . $dir . $n, $pdf($titolo));

    return $dir . $n;
};

$pren = $db->riga("SELECT * FROM prenotazioni WHERE codice_prenotazione = 'OP-PROVA001'");
if (!$pren) {
    exit("Manca la prenotazione OP-PROVA001 dei dati di esempio.\n");
}
if ($tappa === 'nuova') {
    $db->esegui('DELETE FROM convenzioni_compilate WHERE prenotazione_id = ?', [(int) $pren['id']]);
    $db->esegui("DELETE FROM convenzioni_scuole WHERE scuola_codice = ?", [(string) $pren['scuola_codice']]);
    $db->esegui("UPDATE impostazioni_sistema SET conv_direttore_nome = 'Mario Bianchi', conv_direttore_email = 'mario.bianchi@example.org', conv_direttore_cf = 'BNCMRA65A01D086Z' WHERE id = 1");
    array_map('unlink', glob('cache/email_locali/*.html') ?: []);
    echo "Pulito.\n";
    exit;
}

$token = $online->tokenDaCodice('OP-PROVA001');
$cc = $online->perToken((string) $token);
$id = (int) $cc['id'];
if ($tappa === 'compila') {
    $ctx = $online->contesto($cc);
    $k = 'pr' . array_key_first($ctx['prenotate']);
    $r = $online->salva($cc, $ctx, ['denominazione' => 'IIS Volta', 'codice' => 'CSTF00003C', 'comune' => 'Castrovillari (Cosenza)', 'indirizzo' => 'Via delle Scuole 12', 'cf' => '80001234567',
        'dirigente' => 'Dott.ssa Maria Rossi', 'dir_cf' => 'RSSMRA70A41D086K', 'luogo_nascita' => 'Cosenza', 'data_nascita' => '1970-01-01', 'pec' => 'csis00003c@pec.example.org', 'email' => 'segreteria@iisvolta.example.org',
        'att' => [$k => ['scelta' => '1', 'studenti' => '15', 'tutor' => 'Luca Insegnante']]], null);
    echo $r['errori'] ? 'ERRORI: ' . implode(' ', $r['errori']) . "\n" : "Compilata.\n";
} elseif ($tappa === 'carica') {
    $rep->salvaFirmata($id, $salva(\App\Fsl\Costanti::DIR_FIRMATE, 'CONVENZIONE FSL (firmata dal Dirigente)'), 'Maria Rossi (Dirigente Scolastico)', $salva(\App\Fsl\Costanti::DIR_FIRMATE, 'ALLEGATO A'));
    $cc = $rep->perId($id);
    $dati = json_decode((string) $cc['dati_json'], true);
    $n = $c->get(\App\Fsl\NotificheConvenzioni::class);
    $n->firmataRicevuta('segreteria@iisvolta.example.org', 'IIS Volta', (string) $token);
    echo "Firmata caricata.\n";
} elseif ($tappa === 'presa') {
    $es = $firmata->protocolloIngresso($id, '2154', date('Y-m-d'), 'Ufficio FSL');
    echo json_encode($es) . "\n";
} elseif ($tappa === 'firmata') {
    $rep->segnaFirmataDirettore($id, $salva(\App\Fsl\Costanti::DIR_FIRMATE, 'CONVENZIONE FSL (firmata dal Direttore)'), 'Mario Bianchi (Direttore)');
    echo "Firmata dal Direttore.\n";
} elseif ($tappa === 'attiva') {
    $es = $firmata->completa($id, '', ['protocollo' => '2201', 'protocollo_data' => date('Y-m-d'), 'data_stipula' => date('Y-m-d'), 'scadenza' => date('Y-m-d', strtotime('+1 year -1 day'))], 'Ufficio FSL');
    echo json_encode($es) . "\n";
} else {
    exit("Tappa non riconosciuta.\n");
}
file_put_contents('cache/scenario_fsl.json', json_encode(['token' => $token, 'codice' => 'OP-PROVA001', 'id' => $id], JSON_PRETTY_PRINT));
