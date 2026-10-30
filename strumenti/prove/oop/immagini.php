<?php
// Prove di App\Infrastructure\Storage\Immagini (foto alleggerite per la home) e delle regole di compressione/cache del .htaccess. Chiamato da esegui.php.
sezione("Immagini: foto alleggerite per la home");

use App\Infrastructure\Storage\Immagini;

$im = new Immagini();
$dir = sys_get_temp_dir() . '/prova_immagini_' . getmypid();
@mkdir($dir);
if (!$im->disponibile()) {
    prova(true, "immagini: estensione GD non presente in questo ambiente, prove saltate");
} else {
    // Foto grande e «rumorosa» (pesa parecchio): 3200×2000
    $g = imagecreatetruecolor(3200, 2000);
    for ($i = 0; $i < 4000; $i++) {
        imagefilledrectangle($g, random_int(0, 3200), random_int(0, 2000), random_int(0, 3200), random_int(0, 2000), imagecolorallocate($g, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }
    imagejpeg($g, "$dir/grande.jpg", 98);
    imagedestroy($g);
    $prima = filesize("$dir/grande.jpg");
    prova($im->misure("$dir/grande.jpg") === [3200, 2000] && $im->misure("$dir/non_esiste.jpg") === null, "immagini: le misure si leggono, un file che non c'è dà null");
    $risp = $im->riduci("$dir/grande.jpg");
    $m = $im->misure("$dir/grande.jpg");
    prova($risp > 0 && $m !== null && max($m) === 1600 && $m[0] / $m[1] > 1.59 && $m[0] / $m[1] < 1.61 && filesize("$dir/grande.jpg") < $prima, "immagini: una foto grande si riduce a 1600 px di lato mantenendo le proporzioni e pesa meno", json_encode([$prima, filesize("$dir/grande.jpg"), $m]));
    prova(filesize("$dir/grande.jpg") > Immagini::PESO_OK || $im->riduci("$dir/grande.jpg") === 0, "immagini: una foto già ridotta e leggera non si ricomprime a ogni passaggio");

    // Piccola e leggera: non si tocca
    $p = imagecreatetruecolor(800, 400);
    imagefill($p, 0, 0, imagecolorallocate($p, 10, 120, 80));
    imagejpeg($p, "$dir/piccola.jpg", 80);
    imagedestroy($p);
    $h1 = md5_file("$dir/piccola.jpg");
    prova($im->riduci("$dir/piccola.jpg") === 0 && md5_file("$dir/piccola.jpg") === $h1, "immagini: una foto già piccola e leggera non si tocca");

    // PNG trasparente grande
    $png = imagecreatetruecolor(2400, 1200);
    imagesavealpha($png, true);
    imagealphablending($png, false);
    imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
    for ($i = 0; $i < 600; $i++) {
        imagefilledellipse($png, random_int(0, 2400), random_int(0, 1200), 200, 200, imagecolorallocatealpha($png, random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(0, 100)));
    }
    imagepng($png, "$dir/logo.png", 0);
    imagedestroy($png);
    $mp = null;
    $im->riduci("$dir/logo.png");
    $mp = $im->misure("$dir/logo.png");
    prova($mp !== null && max($mp) <= 1600, "immagini: anche un PNG grande si riduce", json_encode($mp));

    // File che non è un'immagine
    file_put_contents("$dir/falso.jpg", "non sono una foto");
    prova($im->riduci("$dir/falso.jpg") === 0 && file_get_contents("$dir/falso.jpg") === "non sono una foto", "immagini: un file che non è un'immagine resta com'è");

    // Cartella
    $r = $im->riduciCartella($dir);
    prova($r['viste'] === 4, "immagini: la cartella si controlla tutta", json_encode($r));
    foreach (glob("$dir/*") ?: [] as $f) {
        @unlink($f);
    }
}
@rmdir($dir);

// Il .htaccess: compressione e cache a lungo solo dove il nome cambia con il contenuto
$ht = (string) file_get_contents(__DIR__ . '/../../../.htaccess');
prova(str_contains($ht, 'mod_deflate') && str_contains($ht, 'text/html') && str_contains($ht, 'application/javascript'), ".htaccess: si comprimono HTML, CSS e JavaScript");
prova(str_contains($ht, 'E=LUNGA:1') && preg_match('#\^\(assets/vendor\|uploads/slide_home\)/#', $ht) === 1 && str_contains($ht, 'immutable'), ".htaccess: la cache lunga vale solo per assets/vendor e le slide (nomi che cambiano con il file)");
prova(substr_count($ht, 'IfModule') >= 6, ".htaccess: ogni regola nuova è dentro un controllo del modulo (se manca il modulo il sito funziona lo stesso)");
// Home: la prima slide si carica subito, le altre solo quando servono
$home = (string) file_get_contents(__DIR__ . '/../../../index.php');
prova(str_contains($home, "'fetchpriority=\"high\"'") && str_contains($home, 'loading="lazy"') && str_contains($home, 'misure('), "home: prima slide con priorità, le altre in caricamento differito e con le misure");
