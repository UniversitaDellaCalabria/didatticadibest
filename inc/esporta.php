<?php
// inc/esporta.php - File Excel (.xlsx) e Word (.docx) generati senza librerie esterne (serve solo ZipArchive).
// Le classi stanno in src/Infrastructure/Documenti/ (Excel, Word, Xml, Download): qui restano le funzioni di prima come facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

use App\Infrastructure\Documenti\{Download, Excel, Word, Xml};

if (!function_exists('invia_file_scaricabile')) {
    function invia_file_scaricabile(string $percorso, string $nome, string $tipo): void { Download::invia($percorso, $nome, $tipo); }
}
