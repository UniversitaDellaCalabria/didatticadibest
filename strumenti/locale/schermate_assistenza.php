<?php
// =========================================================================
// Schermate dell'assistenza e di Didacticus per le mappe e le guide: PNG in documentazione/schermate/ e JPEG in documentazione/mappa/img/ (nome map_*).
//   Prima: bash strumenti/locale/avvia.sh, php strumenti/locale/scenario_assistenza.php (scrive cache/scenario_assistenza.json)
//   Poi:   php -d extension=gd strumenti/locale/schermate_assistenza.php [prefisso]
// Usa Chrome in modalità headless (una schermata per volta: l'accesso di prova è dentro lo stesso indirizzo) e GD per ritagliare.
// =========================================================================
$radice = realpath(__DIR__ . '/../..');
$S = json_decode((string) file_get_contents("$radice/cache/scenario_assistenza.json"), true);
if (!$S) {
    exit("Prima lancia strumenti/locale/scenario_assistenza.php\n");
}
$CHROME = getenv('CHROME') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
$BASE = 'http://127.0.0.1:8080';
$PNG = "$radice/documentazione/schermate";
$JPG = "$radice/documentazione/mappa/img";
$prefisso = $argv[1] ?? '';
$pub = fn(string $n, int $u, string $url, int $alt = 800, bool $testata = false) => ['n' => $n, 'u' => $u, 'url' => "/eventi/$url", 'y' => $testata ? 0 : 175, 'alt' => $alt];
$pan = fn(string $n, int $u, string $url, int $alt = 760) => ['n' => $n, 'u' => $u, 'url' => "/eventi/$url", 'y' => 0, 'alt' => $alt];
$ev = rawurlencode('ho un telefono android, posso avere il sito come applicazione');

$elenco = [
    $pub('map_pub_assistenza_form.png', 0, 'assistenza.php', 1100),
    $pub('map_pub_assistenza_esterno.png', 0, 'assistenza.php?t=' . $S['token_da_prendere'], 900),
    $pub('map_pub_assistenza_didacticus.png', $S['studentessa'], 'assistenza.php', 1000),
    $pan('map_adm_assistenza.png', $S['operatrice'], "admin/assistenza.php?vista=mie&c={$S['in_carico']}", 900),
    $pan('map_adm_assistenza_coda.png', $S['operatrice'], "admin/assistenza.php?vista=da_prendere&c={$S['da_prendere']}", 900),
    $pan('map_adm_assistenza_statistiche.png', $S['operatrice'], 'admin/assistenza.php?scheda=statistiche', 900),
    $pan('map_adm_didacticus_voci.png', $S['operatrice'], 'admin/didacticus.php', 900),
    $pan('map_adm_didacticus_voce.png', $S['operatrice'], 'admin/didacticus.php?scheda=voci&voce=7', 1400),
    $pan('map_adm_didacticus_mancate.png', $S['operatrice'], 'admin/didacticus.php?scheda=mancate', 800),
    $pan('map_adm_didacticus_prova.png', $S['operatrice'], "admin/didacticus.php?scheda=prova&q=$ev", 1250),
    $pan('map_adm_didacticus_impostazioni.png', $S['operatrice'], 'admin/didacticus.php?scheda=impostazioni', 1000),
    $pan('map_adm_didacticus_pagine.png', $S['operatrice'], 'admin/didacticus.php?scheda=pagine', 700),
    // Il pulsante di Didacticus con la nuvoletta, in basso a destra (la finestra è alta quanto basta; 'x' e 'larg' ritagliano l'angolo)
    ['n' => 'map_pub_didacticus_widget.png', 'u' => $S['studentessa'], 'url' => '/eventi/agenda.php', 'y' => 340, 'alt' => 170, 'h' => 560, 'x' => 800, 'larg' => 480],
];

$profilo = sys_get_temp_dir() . '/schermate_assistenza_' . getmypid();
foreach ($elenco as $v) {
    if ($prefisso !== '' && !str_starts_with($v['n'], $prefisso)) continue;
    $tmp = sys_get_temp_dir() . '/sa_' . getmypid() . '.png';
    @unlink($tmp);
    $h = $v['h'] ?? $v['y'] + $v['alt'] + 130; // oltre il ritaglio: il banner dei cookie sta in fondo alla finestra
    $url = $v['u'] ? "$BASE/__accesso?u={$v['u']}&vai=" . urlencode($v['url']) : $BASE . $v['url']; // utente 0 = senza accesso
    $cmd = sprintf('"%s" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=1 --lang=it-IT --user-data-dir=%s --window-size=1280,%d --virtual-time-budget=8000 --screenshot=%s %s 2>&1',
        $CHROME, "\"$profilo\"", $h, "\"$tmp\"", "\"$url\"");
    exec($cmd);
    if (!is_file($tmp)) { echo "MANCA {$v['n']}\n"; continue; }
    $im = imagecreatefrompng($tmp);
    $w = imagesx($im); $hh = imagesy($im);
    $out = imagecrop($im, ['x' => min($v['x'] ?? 0, $w - 1), 'y' => min($v['y'], $hh - 1), 'width' => min($v['larg'] ?? $w, $w - ($v['x'] ?? 0)), 'height' => max(1, min($v['alt'], $hh - $v['y']))]);
    imagepng($out, "$PNG/{$v['n']}");
    imagejpeg($out, "$JPG/" . substr($v['n'], 0, -4) . '.jpg', 84);
    echo "ok {$v['n']}\n";
}
