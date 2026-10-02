<?php
// inc/esporta.php - File Excel (.xlsx) e Word (.docx) generati senza librerie esterne (serve solo ZipArchive).
// xlsx_crea(): uno o più fogli con intestazione in grassetto, filtro e riga bloccata.
// docx_*: paragrafi, tabelle e documento con logo nell'intestazione (usati dal verbale delle pratiche).
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('xml_testo')) {
    // Testo sicuro dentro un file XML di Office (toglie i caratteri di controllo non ammessi)
    function xml_testo($s): string {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

if (!function_exists('xlsx_crea')) {
    // $fogli = ['Nome foglio' => ['intestazioni' => [...], 'righe' => [[...], ...], 'larghezze' => [...]]]
    // Ritorna il percorso di un file temporaneo (null se manca ZipArchive)
    function xlsx_crea(array $fogli): ?string {
        if (!class_exists('ZipArchive')) return null;
        $col = function (int $n): string { $s = ''; for ($n++; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s; return $s; };
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return null;
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
                    $xml .= '<c r="' . $col($c) . ($r + 1) . '" t="inlineStr" s="' . $stile . '"><is><t xml:space="preserve">' . xml_testo($v) . '</t></is></c>';
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
            $wb .= '<sheet name="' . xml_testo($n) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
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

if (!function_exists('docx_runs')) {
    // Testo in "run" di Word: **grassetto** e a capo (\n) sono riconosciuti
    function docx_runs(string $testo, array $o = []): string {
        $rpr_base = (!empty($o['sz']) ? '<w:sz w:val="' . (int)$o['sz'] . '"/><w:szCs w:val="' . (int)$o['sz'] . '"/>' : '') . (!empty($o['u']) ? '<w:u w:val="single"/>' : '') . (!empty($o['i']) ? '<w:i/>' : '');
        $out = ''; $grassetto = !empty($o['b']);
        foreach (preg_split('/(\*\*)/', $testo, -1, PREG_SPLIT_DELIM_CAPTURE) as $pezzo) {
            if ($pezzo === '**') { $grassetto = !$grassetto; continue; }
            if ($pezzo === '') continue;
            $rpr = ($grassetto ? '<w:b/><w:bCs/>' : '') . $rpr_base;
            foreach (explode("\n", $pezzo) as $j => $riga) {
                $out .= '<w:r>' . ($rpr ? "<w:rPr>$rpr</w:rPr>" : '') . ($j > 0 ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . xml_testo($riga) . '</w:t></w:r>';
            }
        }
        return $out;
    }
}

if (!function_exists('docx_p')) {
    // Paragrafo. $o: b, i, u, sz (mezzi punti), al (left|center|right|both), dopo (spazio dopo in twip), rientro, tab (posizione di un tabulatore a destra)
    function docx_p(string $testo, array $o = []): string {
        $ppr = '';
        if (!empty($o['tab'])) $ppr .= '<w:tabs><w:tab w:val="right" w:pos="' . (int)$o['tab'] . '"/></w:tabs>';
        $ppr .= '<w:spacing w:after="' . (int)($o['dopo'] ?? 120) . '" w:line="' . (int)($o['interlinea'] ?? 264) . '" w:lineRule="auto"/>';
        if (!empty($o['rientro'])) $ppr .= '<w:ind w:left="' . (int)$o['rientro'] . '" w:hanging="' . (int)($o['sporgente'] ?? 0) . '"/>';
        if (!empty($o['al'])) $ppr .= '<w:jc w:val="' . $o['al'] . '"/>';
        if (!empty($o['keep'])) $ppr .= '<w:keepNext/>';
        $runs = !empty($o['tab']) ? implode('<w:r><w:tab/></w:r>', array_map(fn($t) => docx_runs($t, $o), explode("\t", $testo))) : docx_runs($testo, $o);
        return "<w:p><w:pPr>$ppr</w:pPr>$runs</w:p>";
    }
}

if (!function_exists('docx_tabella')) {
    // Tabella con bordi; $intest = prima riga in grassetto e grigia (vuoto = nessuna). $o: sz, larghezze (twip), bordi (bool), intest_ripeti
    function docx_tabella(array $intest, array $righe, array $o = []): string {
        $sz = (int)($o['sz'] ?? 18); $bordi = $o['bordi'] ?? true;
        $ncol = max(count($intest), ...array_map('count', $righe ?: [[]]));
        if ($ncol === 0) return '';
        $tot = 9740; // larghezza utile della pagina A4 con margini di 1,9 cm
        // Larghezze proporzionali al testo più lungo di ogni colonna (le parole lunghe come "Insegnamento" hanno più spazio)
        if (empty($o['larghezze'])) {
            $pesi = [];
            for ($c = 0; $c < $ncol; $c++) {
                // intestazione: conta la parola più lunga (va a capo); celle: tutto il testo, fino a 40 caratteri
                $max = max(5, ...array_map('mb_strlen', preg_split('/\s+/',(string)(array_values($intest)[$c] ?? '')) ?: ['']));
                foreach ($righe as $r) $max = max($max, min(40, mb_strlen((string)(array_values($r)[$c] ?? ''))));
                $pesi[$c] = $max + 2;
            }
            $larg = array_map(fn($p) => (int)floor($tot * $p / array_sum($pesi)), $pesi);
        } else $larg = $o['larghezze'];
        $b = $bordi ? 'single' : 'nil';
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($larg) . '" w:type="dxa"/><w:tblBorders>'
             . "<w:top w:val=\"$b\" w:sz=\"4\" w:space=\"0\" w:color=\"808080\"/><w:left w:val=\"$b\" w:sz=\"4\" w:space=\"0\" w:color=\"808080\"/><w:bottom w:val=\"$b\" w:sz=\"4\" w:space=\"0\" w:color=\"808080\"/>"
             . "<w:right w:val=\"$b\" w:sz=\"4\" w:space=\"0\" w:color=\"808080\"/><w:insideH w:val=\"$b\" w:sz=\"4\" w:space=\"0\" w:color=\"808080\"/><w:insideV w:val=\"$b\" w:sz=\"4\" w:space=\"0\" w:color=\"808080\"/>"
             . '</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($larg as $w) $xml .= '<w:gridCol w:w="' . (int)$w . '"/>';
        $xml .= '</w:tblGrid>';
        $riga_xml = function (array $celle, bool $testa) use ($larg, $sz, $ncol) {
            $x = '<w:tr>' . ($testa ? '<w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>' : '<w:trPr><w:cantSplit/></w:trPr>');
            for ($c = 0; $c < $ncol; $c++) {
                $x .= '<w:tc><w:tcPr><w:tcW w:w="' . (int)($larg[$c] ?? 1000) . '" w:type="dxa"/>' . ($testa ? '<w:shd w:val="clear" w:color="auto" w:fill="E7E6E6"/>' : '') . '<w:vAlign w:val="center"/></w:tcPr>'
                    . docx_p((string)($celle[$c] ?? ''), ['sz' => $sz, 'b' => $testa, 'dopo' => 0, 'interlinea' => 240, 'al' => $testa ? 'center' : 'left']) . '</w:tc>';
            }
            return $x . '</w:tr>';
        };
        if ($intest) $xml .= $riga_xml(array_values($intest), true);
        foreach ($righe as $r) $xml .= $riga_xml(array_values($r), false);
        return $xml . '</w:tbl>' . docx_p('', ['dopo' => 60]);
    }
}

if (!function_exists('docx_crea')) {
    // Documento Word A4 con il corpo dato (paragrafi e tabelle). $o: font, sz, logo (percorso jpg/png per l'intestazione),
    // piede (testo a piè di pagina). Ritorna il percorso di un file temporaneo o null.
    function docx_crea(string $corpo, array $o = []): ?string {
        if (!class_exists('ZipArchive')) return null;
        $font = xml_testo($o['font'] ?? 'Times New Roman'); $sz = (int)($o['sz'] ?? 22);
        $logo = (!empty($o['logo']) && is_file($o['logo'])) ? $o['logo'] : null;
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return null;
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
        $ext = $logo ? strtolower(pathinfo($logo, PATHINFO_EXTENSION)) : '';
        $ct_img = $ext === 'png' ? '<Default Extension="png" ContentType="image/png"/>' : '<Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="jpeg" ContentType="image/jpeg"/>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . $ct_img
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdSt" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdH1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
            . '<Relationship Id="rIdF1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . $ns . '><w:docDefaults><w:rPrDefault><w:rPr>'
            . "<w:rFonts w:ascii=\"$font\" w:hAnsi=\"$font\" w:eastAsia=\"$font\" w:cs=\"$font\"/><w:sz w:val=\"$sz\"/><w:szCs w:val=\"$sz\"/><w:lang w:val=\"it-IT\"/>"
            . '</w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>');
        // Intestazione con il logo (centrato) e piè di pagina con il numero di pagina
        $hdr = '';
        if ($logo) {
            [$wpx, $hpx] = @getimagesize($logo) ?: [750, 230];
            $cx = 2520000; $cy = (int)round($cx * $hpx / max(1, $wpx)); // larghezza 7 cm
            $zip->addFile($logo, 'word/media/logo.' . $ext);
            $zip->addFromString('word/_rels/header1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.' . $ext . '"/></Relationships>');
            $hdr = '<w:p><w:pPr><w:jc w:val="left"/><w:spacing w:after="0"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="1" name="Logo"/>'
                . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="logo"/><pic:cNvPicPr/></pic:nvPicPr>'
                . '<pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
                . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
        }
        $zip->addFromString('word/header1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . $ns . '>' . ($hdr ?: '<w:p/>') . '</w:hdr>');
        $piede = !empty($o['piede']) ? docx_runs((string)$o['piede'], ['sz' => 16]) . '<w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:t xml:space="preserve"> – </w:t></w:r>' : '';
        $zip->addFromString('word/footer1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '><w:p><w:pPr><w:jc w:val="right"/></w:pPr>' . $piede
            . '<w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:t xml:space="preserve">pag. </w:t></w:r><w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
            . '<w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:t>1</w:t></w:r><w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $corpo
            . '<w:sectPr><w:headerReference w:type="default" r:id="rIdH1"/><w:footerReference w:type="default" r:id="rIdF1"/><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="' . ($logo ? 2100 : 1440) . '" w:right="1080" w:bottom="1134" w:left="1080" w:header="425" w:footer="340" w:gutter="0"/></w:sectPr></w:body></w:document>');
        $zip->close();
        return $tmp;
    }
}

if (!function_exists('invia_file_scaricabile')) {
    // Manda al browser un file temporaneo come download e lo cancella
    function invia_file_scaricabile(string $percorso, string $nome, string $tipo): void {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $tipo);
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\- ]+/u', '_', $nome) . '"');
        header('Content-Length: ' . filesize($percorso));
        header('X-Content-Type-Options: nosniff');
        readfile($percorso);
        @unlink($percorso);
        exit;
    }
}
