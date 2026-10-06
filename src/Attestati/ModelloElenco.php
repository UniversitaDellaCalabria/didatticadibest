<?php

declare(strict_types=1);

namespace App\Attestati;

use ZipArchive;

/**
 * Modello da compilare con l'elenco degli studenti (colonne Cognome e Nome): .xlsx se il server ha l'estensione zip,
 * altrimenti .csv (si apre comunque con Excel).
 */
final class ModelloElenco
{
    /**
     * Intestazioni HTTP e contenuto del file da scaricare.
     *
     * @param array<int, array{cognome: mixed, nome: mixed}> $righe nomi già inseriti
     * @return array{intestazioni: list<string>, contenuto: string}
     */
    public static function crea(array $righe, string $nomeFile): array
    {
        $righe = array_values($righe);
        $xlsx = class_exists(ZipArchive::class) ? self::xlsx($righe) : null;
        if ($xlsx !== null) {
            return [
                'intestazioni' => [
                    'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition: attachment; filename="' . $nomeFile . '.xlsx"',
                    'Content-Length: ' . strlen($xlsx),
                ],
                'contenuto' => $xlsx,
            ];
        }
        $csv = "\xEF\xBB\xBF" . "Cognome;Nome\r\n";
        foreach ($righe as $r) {
            $csv .= str_replace(';', ' ', (string) $r['cognome']) . ';' . str_replace(';', ' ', (string) $r['nome']) . "\r\n";
        }

        return [
            'intestazioni' => [
                'Content-Type: text/csv; charset=utf-8',
                'Content-Disposition: attachment; filename="' . $nomeFile . '.csv"',
            ],
            'contenuto' => $csv,
        ];
    }

    /**
     * Il file .xlsx come testo binario, null se non si riesce a crearlo.
     *
     * @param list<array{cognome: mixed, nome: mixed}> $righe
     */
    private static function xlsx(array $righe): ?string
    {
        $x = static fn ($s): string => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if (!$tmp || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            if ($tmp) {
                @unlink($tmp);
            }

            return null;
        }
        $righeXml = '<row r="1"><c r="A1" t="inlineStr" s="1"><is><t>Cognome</t></is></c><c r="B1" t="inlineStr" s="1"><is><t>Nome</t></is></c></row>';
        foreach ($righe as $i => $r) {
            $n = $i + 2;
            $righeXml .= '<row r="' . $n . '"><c r="A' . $n . '" t="inlineStr"><is><t>' . $x($r['cognome']) . '</t></is></c><c r="B' . $n . '" t="inlineStr"><is><t>' . $x($r['nome']) . '</t></is></c></row>';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Studenti" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="2" width="32" customWidth="1"/></cols><sheetData>' . $righeXml . '</sheetData></worksheet>');
        $zip->close();
        $contenuto = file_get_contents($tmp);
        @unlink($tmp);

        return $contenuto === false ? null : $contenuto;
    }
}
