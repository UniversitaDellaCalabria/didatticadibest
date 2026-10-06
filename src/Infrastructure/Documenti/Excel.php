<?php

declare(strict_types=1);

namespace App\Infrastructure\Documenti;

/**
 * File Excel (.xlsx) generati senza librerie esterne (serve solo ZipArchive): uno o più fogli con intestazione
 * in grassetto, filtro e riga bloccata. Spostato da inc/esporta.php (xlsx_crea resta come facciata).
 */
final class Excel
{
    /**
     * @param array<string, array{intestazioni?: array<int, mixed>, righe?: array<int, array<int|string, mixed>>, larghezze?: array<int, float|int>}> $fogli
     * @return string|null percorso di un file temporaneo (null se manca ZipArchive)
     */
    public static function crea(array $fogli): ?string
    {
    if (!class_exists(\ZipArchive::class)) return null;
    $col = function (int $n): string { $s = ''; for ($n++; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s; return $s; };
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new \ZipArchive();
    if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) return null;
    $nomi = []; $i = 0;
    foreach ($fogli as $nome => $f) {
        $i++;
        $nome = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', (string)$nome) ?: "Foglio $i", 0, 31);
        $nomi[$i] = $nome;
        $int = array_values($f['intestazioni'] ?? []); $righe = $f['righe'] ?? [];
        $ncol = max(count($int), ...array_map('count', $righe ?: [[]]));
        $cols = '';
        for ($c = 0; $c < $ncol; $c++) {
            $w = $f['larghezze'][$c] ?? min(60, max(10, mb_strlen((string)($int[$c] ?? '')) + 4));
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
        }
        $xml = '';
        $tutte = $int ? array_merge([$int], $righe) : $righe;
        foreach ($tutte as $r => $riga) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($riga) as $c => $v) {
                $stile = ($r === 0 && $int) ? 1 : (str_contains((string)$v, "\n") ? 2 : 0);
                $xml .= '<c r="' . $col($c) . ($r + 1) . '" t="inlineStr" s="' . $stile . '"><is><t xml:space="preserve">' . Xml::testo($v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $ultima = $col(max(0, $ncol - 1)) . max(1, count($tutte));
        $zip->addFromString("xl/worksheets/sheet$i.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . ($int ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '')
            . ($cols ? "<cols>$cols</cols>" : '') . "<sheetData>$xml</sheetData>"
            . ($int && $righe ? '<autoFilter ref="A1:' . $ultima . '"/>' : '') . '</worksheet>');
    }
    $wb = ''; $rel = ''; $ct = '';
    foreach ($nomi as $k => $n) {
        $wb .= '<sheet name="' . Xml::testo($n) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
        $rel .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $rel .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ct . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rel . '</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs></styleSheet>');
    $zip->close();
    return $tmp;
}
}
