<?php
// =========================================================================
// Scenario di dimostrazione delle convenzioni di tesi: una convenzione in ogni punto dell'iter (per le schermate e per provare il pannello).
//   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/scenario_convenzioni_tesi.php
// Cancella le convenzioni di tesi esistenti. Le email non partono (si salvano in cache/email_locali/). Le firme PAdES si simulano.
// Scrive in cache/scenario_convenzioni_tesi.json i link delle aziende e del Direttore.
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
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) {
    $dir = 'cache/email_locali/';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . date('Ymd_His') . '_' . substr(md5($subject . $to . microtime()), 0, 6) . '_' . preg_replace('/[^A-Za-z0-9@._-]/', '_', (string) $to) . '.html',
        '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>' . htmlspecialchars((string) $subject) . '</title></head><body><p><b>A:</b> ' . htmlspecialchars((string) $to) . '<br><b>Oggetto:</b> ' . htmlspecialchars((string) $subject) . '</p>' . $body_html . '</body></html>');

    return true;
}
require 'functions.php';

$c = \App\Core\App::per($conn);
$svc = $c->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
$rep = $c->get(\App\ConvenzioniTesi\ConvenzioneTesiRepository::class);
$sito = $c->get(\App\Core\Sito::class);
$S = \App\ConvenzioniTesi\Costanti::class;
$conn->query('DELETE FROM convenzioni_tesi');
$conn->query("UPDATE impostazioni_sistema SET conv_direttore_nome = 'Mario Bianchi', conv_direttore_email = 'mario.bianchi@example.org' WHERE id = 1");
array_map('unlink', glob('cache/email_locali/*.html') ?: []);

$salva = function (string $dir, string $contenuto) use ($sito): string {
    @mkdir($sito->radice() . '/' . $dir, 0755, true);
    $n = bin2hex(random_bytes(12)) . '.pdf';
    file_put_contents($sito->radice() . '/' . $dir . $n, $contenuto);

    return $dir . $n;
};
$base = ['forma_giuridica' => 'S.r.l.', 'piva' => '01234567890', 'cf' => '01234567890', 'provincia' => 'CS', 'rappr_titolo' => 'Dott.ssa', 'rappr_qualifica' => 'Amministratore Unico', 'consenso_elenco' => '1'];
$aziende = [
    'modifiche' => ['BioLab Calabria S.r.l.', 'info@biolab.example.it', 'Rende', 'Via Pietro Bucci 12', 'Biotecnologie', 'Maria Rossi'],
    'caricata' => ['Geo Studio S.n.c.', 'info@geostudio.example.it', 'Cosenza', 'Via Roma 8', 'Geologia', 'Paolo Neri'],
    'dirfirma' => ['Ambiente Sud S.r.l.', 'ambiente@example.it', 'Castrovillari', 'Corso Garibaldi 21', 'Ambiente e territorio', 'Giulia Verdi'],
    'firmata' => ['Zoo Lab S.r.l.', 'zoolab@example.it', 'Lamezia Terme', 'Via dei Mille 3', 'Zoologia', 'Luca Bianchi'],
    'attesa' => ['Orto Botanico Sila', 'sila@example.it', 'Camigliatello Silano', 'Località Fossiata', 'Botanica', 'Anna Greco'],
];
$tok = [];
$ids = [];
foreach ($aziende as $k => [$nome, $mail, $comune, $via, $settore, $rappr]) {
    [, $id] = $svc->avvia($mail, $nome, $S::ORIGINE_INVITO, 'Anna Protocollo');
    $ids[$k] = $id;
    if ($k === 'attesa') {
        continue;
    }
    $post = $base + ['ragione_sociale' => $nome, 'comune' => $comune, 'indirizzo' => $via, 'settore' => $settore, 'rappr_nome' => $rappr, 'pec' => 'pec@' . explode('@', $mail)[1], 'sito' => 'www.' . explode('@', $mail)[1]];
    $art = [];
    if ($k === 'modifiche') {
        foreach ($svc->articoliStandard() as $n => $a) {
            $art[$n] = $a['testo'];
        }
        $art[8] = 'Il soggetto ospitante può offrire borse di studio ai tesisti, dandone comunicazione scritta al Dipartimento.';
        $art[11] = 'L\'imposta di bollo è a carico del soggetto ospitante.';
    }
    $svc->salvaDati($rep->perId($id), $post + ($art ? ['art' => $art, 'nota' => 'Vorremmo poter offrire una borsa di studio ai migliori tesisti.'] : []));
    $cc = $rep->perId($id);
    $pdf = $svc->pdf($cc) ?? "%PDF-1.7\nconvenzione\n%%EOF";
    if (in_array($k, ['caricata', 'dirfirma', 'firmata'], true)) {
        $rep->salvaFirmata($id, $salva($S::DIR_FIRMATE, $pdf . "\n%% firmato\n"), $rappr . ' (' . $post['rappr_qualifica'] . ')');
    }
    if (in_array($k, ['dirfirma', 'firmata'], true)) {
        $svc->protocolloIngresso($id, $k === 'dirfirma' ? '4411' : '4398', date('Y-m-d'), 'Anna Protocollo');
    }
    if ($k === 'firmata') {
        $rep->segnaFirmataDirettore($id, $salva($S::DIR_DEFINITIVE, $pdf . "\n%% firmato dal Direttore\n"), 'Mario Bianchi (Direttore)');
    }
    $tok[$k] = (string) $rep->perId($id)['token'];
}
$tok['attesa'] = (string) $rep->perId($ids['attesa'])['token'];
$tok['dirfirma_direttore'] = (string) $rep->perId($ids['dirfirma'])['token_direttore'];
// Due già stipulate, una con articolo modificato e concordato, una vecchia inserita a mano
[$err, $a1] = $svc->archivia(['ragione_sociale' => 'Mare Nostrum S.r.l.', 'comune' => 'Cetraro', 'provincia' => 'CS', 'settore' => 'Biologia marina', 'sito' => 'marenostrum.example.it', 'protocollo' => '55/2024',
    'protocollo_data' => '2024-03-01', 'valida_dal' => '2024-03-01', 'valida_al' => '2029-02-28', 'consenso_elenco' => '1', 'email' => 'info@marenostrum.example.it'], "%PDF-1.7\nvecchia\n%%EOF", 'Anna Protocollo');
$svc->archivia(['ragione_sociale' => 'Agrisud Società Cooperativa', 'comune' => 'Corigliano-Rossano', 'provincia' => 'CS', 'settore' => 'Agroalimentare', 'protocollo' => '12/2021', 'protocollo_data' => '2021-02-10',
    'valida_dal' => '2021-02-10', 'valida_al' => '2026-12-09', 'consenso_elenco' => '1', 'email' => 'info@agrisud.example.it'], '', 'Anna Protocollo');
$svc->archivia(['ragione_sociale' => 'Laboratorio Analisi Pollino', 'comune' => 'Castrovillari', 'provincia' => 'CS', 'settore' => 'Analisi chimiche', 'sito' => 'labpollino.example.it', 'protocollo' => '230/2025',
    'protocollo_data' => '2025-05-02', 'valida_dal' => '2025-05-02', 'valida_al' => '2030-05-01', 'consenso_elenco' => '1', 'email' => 'lab@pollino.example.it'], '', 'Anna Protocollo');
file_put_contents('cache/scenario_convenzioni_tesi.json', json_encode($tok + ['ids' => $ids], JSON_PRETTY_PRINT));
echo "Scenario creato: " . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($ids), $ids)) . "\n";
