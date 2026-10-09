<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use ZipArchive;

/**
 * Allegato A in Word (.docx), uguale a quello in PDF: stesso contenuto (AllegatoAModello), stesso ordine (corso di laurea e data), stesse fasce
 * colorate e tabelle. Il documento è costruito qui, senza modello Word; non ha firme: è un allegato della convenzione già firmata.
 */
final class AllegatoAWord
{
    private const LOGO_DIPARTIMENTO = 'assets/modelli/logo_lettera_incarico.jpg';
    private const ROSSO = 'B30000';
    private const ARDESIA = '293857';
    private const SFONDO_ETICHETTE = 'F3F3F7';
    private const BORDO = 'E0E0E6';
    /** Larghezza utile della pagina in ventesimi di punto (A4 con margini di 2 cm). */
    private const LARGHEZZA = 9638;

    /** @var list<array{rid: string, nome: string, file: string, ext: string}> */
    private array $immagini = [];

    public function __construct(private Sito $sito, private AllegatoAModello $modello)
    {
    }

    /**
     * @param array<string, mixed> $scuola
     * @param list<array{p: array<string, mixed>, studenti: int, tutor: string}> $attivita
     * @return string|null percorso del file temporaneo (da cancellare dopo l'uso); null senza ZipArchive
     */
    public function genera(array $scuola, array $attivita, ?string $logo = null, string $protocollo = ''): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $this->immagini = [];
        $m = $this->modello->costruisci($scuola, $attivita, $protocollo);
        $nome = $m['nome_scuola'];

        $corpo = $this->intestazione($logo);
        if ($m['protocollo'] !== '') {
            $corpo .= $this->par($this->run('Prot. n. ' . $m['protocollo'], ['sz' => 18]), ['jc' => 'right', 'dopo' => 80]);
        }
        $corpo .= $this->fascia('ALLEGATO A – Attività di Formazione Scuola Lavoro', self::ROSSO, 28, 'center', 160);
        $corpo .= $this->par($this->testo("alla convenzione tra il Dipartimento di Biologia, Ecologia e Scienze della Terra (DiBEST) dell'Università della Calabria"
            . ($nome !== '' ? ' e **' . $nome . '**' : " e l'istituzione scolastica"), ['sz' => 21]), ['jc' => 'center', 'dopo' => 240]);
        $corpo .= $this->fascia('Istituzione scolastica', self::ARDESIA, 21, 'left', 0) . $this->coppie($m['scuola']) . $this->par('', ['dopo' => 120]);

        foreach ($m['gruppi'] as $g) {
            $corpo .= $this->fascia($g['corso'], self::ARDESIA, 23, 'left', 0, true);
            $corpo .= $this->par('', ['dopo' => 60]);
            foreach ($g['attivita'] as $a) {
                $corpo .= $this->scheda($a);
            }
        }
        if (!$m['gruppi']) {
            $corpo .= $this->par($this->run('Nessuna attività indicata.', ['i' => true]));
        }

        $sezione = '<w:sectPr><w:footerReference w:type="default" r:id="rIdPiede"/><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr>';
        $documento = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>' . $corpo . $sezione . '</w:body></w:document>';

        return $this->pacchetto($documento, 'Allegato A – Formazione Scuola Lavoro' . ($nome !== '' ? ' – ' . $nome : ''));
    }

    // ------------------------------------------------------------------ contenuto

    /** @param array{n: int, titolo: string, righe: list<array{0: string, 1: string}>} $a */
    private function scheda(array $a): string
    {
        return $this->fascia('Attività ' . $a['n'] . ' – ' . $a['titolo'], self::ROSSO, 21, 'left', 0, true) . $this->coppie($a['righe']) . $this->par('', ['dopo' => 160]);
    }

    /** Logo del Dipartimento a sinistra e, se c'è, quello della scuola a destra. */
    private function intestazione(?string $logoScuola): string
    {
        $sx = $this->immagine($this->sito->radice() . '/' . self::LOGO_DIPARTIMENTO, 210, 70);
        $dx = $logoScuola ? $this->immagine($logoScuola, 120, 55) : '';
        if ($sx === '' && $dx === '') {
            return '';
        }
        $cella = fn (string $img, string $jc): string => '<w:tc><w:tcPr><w:tcW w:w="' . (int) (self::LARGHEZZA / 2) . '" w:type="dxa"/></w:tcPr>' . $this->par($img, ['jc' => $jc, 'dopo' => 0]) . '</w:tc>';

        return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="' . (int) (self::LARGHEZZA / 2) . '"/><w:gridCol w:w="' . (int) (self::LARGHEZZA / 2) . '"/></w:tblGrid>'
            . '<w:tr>' . $cella($sx, 'left') . $cella($dx, 'right') . '</w:tr></w:tbl>' . $this->par('', ['dopo' => 120]);
    }

    // ------------------------------------------------------------------ elementi

    /** @param list<array{0: string, 1: string}> $righe */
    private function coppie(array $righe): string
    {
        $b = '<w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $lato) {
            $b .= '<w:' . $lato . ' w:val="single" w:sz="4" w:space="0" w:color="' . self::BORDO . '"/>';
        }
        $b .= '</w:tblBorders>';
        $l0 = (int) (self::LARGHEZZA * 1 / 3.6);
        $l1 = self::LARGHEZZA - $l0;
        $t = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>' . $b . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="50" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="50" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="' . $l0 . '"/><w:gridCol w:w="' . $l1 . '"/></w:tblGrid>';
        foreach ($righe as [$et, $val]) {
            $t .= '<w:tr>'
                . '<w:tc><w:tcPr><w:tcW w:w="' . $l0 . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . self::SFONDO_ETICHETTE . '"/></w:tcPr>' . $this->par($this->run($et, ['b' => true, 'sz' => 19]), ['dopo' => 0]) . '</w:tc>'
                . '<w:tc><w:tcPr><w:tcW w:w="' . $l1 . '" w:type="dxa"/></w:tcPr>' . $this->par($this->testo($val, ['sz' => 19]), ['dopo' => 0]) . '</w:tc></w:tr>';
        }

        return $t . '</w:tbl>';
    }

    /** Fascia a tutta larghezza con sfondo colorato e testo bianco in grassetto. */
    private function fascia(string $testo, string $colore, int $sz, string $jc, int $dopo, bool $tieni = false): string
    {
        $t = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="70" w:type="dxa"/><w:left w:w="120" w:type="dxa"/><w:bottom w:w="70" w:type="dxa"/><w:right w:w="120" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="' . self::LARGHEZZA . '"/></w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr><w:tc><w:tcPr><w:tcW w:w="' . self::LARGHEZZA . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $colore . '"/></w:tcPr>'
            . $this->par($this->run($testo, ['b' => true, 'sz' => $sz, 'colore' => 'FFFFFF']), ['jc' => $jc, 'dopo' => 0, 'tieni' => true]) . '</w:tc></w:tr></w:tbl>';

        return $t . ($dopo > 0 ? $this->par('', ['dopo' => $dopo]) : '');
    }

    /** @param array{jc?: string, prima?: int, dopo?: int, ind?: int, tieni?: bool} $o */
    private function par(string $contenuto, array $o = []): string
    {
        $p = '<w:pPr>' . (!empty($o['tieni']) ? '<w:keepNext/>' : '') . '<w:spacing w:before="' . (int) ($o['prima'] ?? 0) . '" w:after="' . (int) ($o['dopo'] ?? 100) . '" w:line="264" w:lineRule="auto"/>'
            . (!empty($o['ind']) ? '<w:ind w:left="' . (int) $o['ind'] . '"/>' : '') . (!empty($o['jc']) ? '<w:jc w:val="' . $o['jc'] . '"/>' : '') . '</w:pPr>';

        return '<w:p>' . $p . $contenuto . '</w:p>';
    }

    /** @param array{b?: bool, i?: bool, sz?: int, colore?: string} $o */
    private function run(string $testo, array $o = []): string
    {
        $rpr = (!empty($o['b']) ? '<w:b/>' : '') . (!empty($o['i']) ? '<w:i/>' : '') . (!empty($o['colore']) ? '<w:color w:val="' . $o['colore'] . '"/>' : '') . '<w:sz w:val="' . (int) ($o['sz'] ?? 20) . '"/>';

        return '<w:r><w:rPr>' . $rpr . '</w:rPr><w:t xml:space="preserve">' . htmlspecialchars($testo, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r>';
    }

    /**
     * Testo con **grassetto** e «\n» (a capo), come nel PDF.
     *
     * @param array{sz?: int} $o
     */
    private function testo(string $testo, array $o = []): string
    {
        $out = '';
        $grassetto = false;
        foreach (preg_split('/(\*\*|\n)/', $testo, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $pezzo) {
            if ($pezzo === '**') {
                $grassetto = !$grassetto;
            } elseif ($pezzo === "\n") {
                $out .= '<w:r><w:br/></w:r>';
            } elseif ($pezzo !== '') {
                $out .= $this->run($pezzo, $o + ['b' => $grassetto]);
            }
        }

        return $out;
    }

    /** Immagine in linea larga al massimo $larghezzaPt e alta al massimo $altezzaPt punti. Vuoto se il file non è leggibile. */
    private function immagine(string $file, float $larghezzaPt, float $altezzaPt): string
    {
        if (!is_file($file) || !($dim = @getimagesize($file)) || !in_array($dim[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return '';
        }
        $ext = $dim[2] === IMAGETYPE_PNG ? 'png' : 'jpeg';
        $n = count($this->immagini) + 1;
        $rid = 'rIdImg' . $n;
        $this->immagini[] = ['rid' => $rid, 'nome' => 'img' . $n . '.' . $ext, 'file' => $file, 'ext' => $ext];
        $scala = min($larghezzaPt / max(1, $dim[0]), $altezzaPt / max(1, $dim[1]));
        $cx = (int) round($dim[0] * $scala * 12700);
        $cy = (int) round($dim[1] * $scala * 12700);

        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $n . '" name="Immagine ' . $n . '"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="' . $n . '" name="img' . $n . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    // ------------------------------------------------------------------ pacchetto .docx

    private function pacchetto(string $documento, string $piede): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'alla') . '.docx';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $rels = '<Relationship Id="rIdStili" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdPiede" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
        foreach ($this->immagini as $img) {
            $rels .= '<Relationship Id="' . $img['rid'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $img['nome'] . '"/>';
            $zip->addFile($img['file'], 'word/media/' . $img['nome']);
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="jpeg" ContentType="image/jpeg"/><Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        $zip->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="it-IT"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="100"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>');
        $zip->addFromString('word/footer1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
            . $this->run($piede . ' – pag. ', ['sz' => 16, 'colore' => '666666'])
            . '<w:r><w:rPr><w:color w:val="666666"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:rPr><w:color w:val="666666"/><w:sz w:val="16"/></w:rPr><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
            . '<w:r><w:rPr><w:color w:val="666666"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:color w:val="666666"/><w:sz w:val="16"/></w:rPr><w:t>1</w:t></w:r>'
            . '<w:r><w:rPr><w:color w:val="666666"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>');
        $zip->addFromString('word/document.xml', $documento);

        return $zip->close() ? $tmp : null;
    }
}
