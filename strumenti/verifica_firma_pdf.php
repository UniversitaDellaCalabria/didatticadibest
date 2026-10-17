<?php
// =========================================================================
// Controlla un PDF firmato con le stesse regole del portale (convenzioni di tesi, convenzioni FSL, lettere, pratiche): serve per provare una firma vera
// PRIMA di usarla, senza passare dal portale. Si lancia da riga di comando, su qualunque computer con PHP.
//
//   php strumenti/verifica_firma_pdf.php firmato.pdf
//        Dice se nel file c'è una firma PAdES, di che tipo, di chi, se copre tutto il file (cioè se è stato salvato di nuovo dopo la firma) e se è integra.
//        È quello che controlla il portale quando l'AZIENDA (o la scuola) carica la convenzione firmata.
//   php strumenti/verifica_firma_pdf.php firmato_dal_direttore.pdf firmato_dall_azienda.pdf [CODICEFISCALE]
//        Controlla anche che il primo sia il secondo con UNA firma in più, senza altre modifiche (come per la firma del Direttore), e che il certificato
//        sia di chi ha il codice fiscale indicato (facoltativo).
// Non modifica nulla e non invia nulla. Esce con 0 se il portale accetterebbe il file, con 1 se no.
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once dirname(__DIR__) . '/src/autoload.php';

use App\Infrastructure\Pdf\FirmePades;
use App\Tutorato\VerificaPdfFirmato;

$argv = $_SERVER['argv'];
if (count($argv) < 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Uso: php strumenti/verifica_firma_pdf.php firmato.pdf [precedente.pdf [CODICEFISCALE]]\n");
    exit(2);
}
$pdf = (string) file_get_contents($argv[1]);
$f = new FirmePades();
$firme = $f->firmePades($pdf);
$ok = true;
$riga = static function (bool $bene, string $testo) use (&$ok): void {
    if (!$bene) {
        $ok = false;
    }
    echo ($bene ? "  [OK] " : "  [NO] ") . $testo . "\n";
};

echo "File: " . basename($argv[1]) . ' (' . number_format(strlen($pdf)) . " byte)\n";
$riga(strncmp($pdf, '%PDF-', 5) === 0, 'è un PDF (un file .p7m / CAdES non è accettato)');
$riga($firme !== [], 'contiene una firma digitale PAdES: ' . count($firme) . ' firma/e');
foreach ($firme as $i => $x) {
    echo '       firma ' . ($i + 1) . ': formato ' . ($x['subfilter'] ?: 'sconosciuto') . ($x['nome'] !== '' ? ', nome «' . $x['nome'] . '»' : '') . ($x['data'] !== '' ? ', data ' . $x['data'] : '') . "\n";
    $riga(!empty($x['valida_struttura']), '       la struttura della firma è integra');
    $riga(in_array($x['subfilter'], ['ETSI.CAdES.detached', 'adbe.pkcs7.detached'], true), '       formato PAdES riconosciuto (' . ($x['subfilter'] ?: '?') . ')');
}
if ($firme) {
    $riga(!empty($firme[array_key_last($firme)]['copre_tutto']), "l'ultima firma copre tutto il file (se NO: il PDF è stato modificato o salvato di nuovo dopo la firma)");
    $v = new VerificaPdfFirmato($f);
    $info = $v->analizza($pdf);
    echo '  Certificato: ' . ($info['nome'] !== '' ? $info['nome'] : '(nome non leggibile)') . ($info['cf'] !== '' ? ', codice fiscale ' . $info['cf'] : ', codice fiscale non presente nel certificato') . ($info['emittente'] !== '' ? ', emesso da ' . $info['emittente'] : '') . "\n";
    $riga($info['integra'] !== false, 'integrità crittografica: ' . ($info['integra'] === null ? 'non verificabile da qui (la catena dei certificati la verifica chi protocolla con gli strumenti di Ateneo)' : 'il documento non è stato alterato'));
    if (isset($argv[2]) && is_file($argv[2])) {
        $prima = (string) file_get_contents($argv[2]);
        [$err] = $v->verifica($prima, $pdf, $argv[3] ?? '');
        $riga($err === null, 'è il file «' . basename($argv[2]) . '» con una firma in più' . ($err !== null ? ': ' . $err : '') . (isset($argv[3]) ? ' e il certificato è di ' . $argv[3] : ''));
    }
}
echo $ok ? "\nIl portale accetterebbe questo file.\n" : "\nIl portale NON accetterebbe questo file (vedi le righe [NO]).\n";
exit($ok ? 0 : 1);
