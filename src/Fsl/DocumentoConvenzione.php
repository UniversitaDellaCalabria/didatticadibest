<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use ZipArchive;

/** Documenti Word della convenzione (Convenzione e Allegato A) compilati sui modelli del Dipartimento (modelli_documenti/). */
final class DocumentoConvenzione
{
    public function __construct(private Sito $sito)
    {
    }

    /**
     * Documento (.docx) dal modello del Dipartimento ('convenzione' o 'allegato'): $scuola = segnaposto della scuola e del Dirigente,
     * $attivita = una riga per attività (TITOLO, DESCRIZIONE, STUDENTI, PERIODO, DURATA, TUTOR_DIBEST, TUTOR_SCUOLA): il blocco
     * dell'Allegato A ("Titolo corso" … riga tratteggiata) si ripete per ogni attività. $logo = immagine della scuola in testa.
     * I campi compilati perdono l'evidenziazione gialla; quelli vuoti restano evidenziati con il testo originale.
     * $senzaAllegato = nella Convenzione si toglie la pagina dell'Allegato A (la scuola lo riceve a parte, in PDF, con la scheda completa di ogni attività).
     * Ritorna il percorso del file temporaneo (da cancellare dopo l'uso) o null se il modello manca.
     *
     * @param array<string, mixed> $scuola
     * @param list<array<string, mixed>> $attivita
     */
    public function genera(string $doc, array $scuola, array $attivita, ?string $logo = null, string $protocollo = '', bool $senzaAllegato = false): ?string
    {
        $modello = $this->sito->radice() . '/modelli_documenti/' . ($doc === 'allegato' ? 'allegato_a' : 'convenzione') . '_precompilabile.docx';
        if (!is_file($modello) || !class_exists('ZipArchive')) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'conv') . '.docx';
        if (!@copy($modello, $tmp)) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            return null;
        }
        $xml = (string)$zip->getFromName('word/document.xml');
        $sostituisci = function (string $xml, array $valori) {
            foreach (Costanti::SEGNAPOSTI as $k => $orig) {
                if (!array_key_exists($k, $valori)) {
                    continue;
                }
                $xml = (string) preg_replace_callback('#<w:r\b(?:(?!</w:r>).)*?\{\{' . $k . '\}\}(?:(?!</w:r>).)*?</w:r>#s', function ($m) use ($k, $orig, $valori) {
                    $v = trim((string)($valori[$k] ?? ''));
                    $run = $m[0];
                    if ($v === '') {
                        return str_replace('{{' . $k . '}}', htmlspecialchars($orig, ENT_XML1, 'UTF-8'), $run);
                    }
                    $run = (string) preg_replace('#<w:highlight [^>]*/>#', '', $run);
                    $run = str_replace('w:val="FF0000"', 'w:val="000000"', $run);
                    return str_replace('{{' . $k . '}}', htmlspecialchars($v, ENT_XML1, 'UTF-8'), $run);
                }, $xml);
            }
            return $xml;
        };
        if ($senzaAllegato && $doc !== 'allegato') {
            $xml = $this->senzaAllegato($xml);
        }
        // Blocco dell'Allegato A ripetuto per ogni attività
        if (!($senzaAllegato && $doc !== 'allegato') && preg_match_all('#<w:p\b(?:(?!<w:p\b).)*?</w:p>#s', $xml, $mm, PREG_OFFSET_CAPTURE)) {
            $ini = $fin = null;
            foreach ($mm[0] as [$p, $pos]) {
                if ($ini === null && str_contains($p, '{{TITOLO}}')) {
                    $ini = $pos;
                } elseif ($ini !== null && preg_match('/-{10,}/', strip_tags($p))) {
                    $fin = $pos + strlen($p);
                    break;
                }
            }
            if ($ini !== null && $fin !== null) {
                $blocco = substr($xml, $ini, $fin - $ini);
                $nuovi = '';
                $vuoti = array_fill_keys(['TITOLO', 'DESCRIZIONE', 'STUDENTI', 'PERIODO', 'DURATA', 'TUTOR_DIBEST', 'TUTOR_SCUOLA'], '');
                foreach ($attivita ?: [[]] as $a) {
                    $b = $sostituisci($blocco, $a + $vuoti);
                    // Attività compilata: le etichette ("Titolo corso", "Periodo"…) non restano evidenziate; restano gialli solo i campi vuoti
                    if (trim((string)($a['TITOLO'] ?? '')) !== '') {
                        $originali = array_values(Costanti::SEGNAPOSTI);
                        $b = (string) preg_replace_callback('#<w:pPr>.*?</w:pPr>#s', fn ($m) => (string) preg_replace('#<w:highlight [^>]*/>#', '', $m[0]), $b);
                        $b = (string) preg_replace_callback('#<w:r\b(?:(?!</w:r>).)*?</w:r>#s', function ($m) use ($originali) {
                            if (!str_contains($m[0], '<w:highlight')) {
                                return $m[0];
                            }
                            preg_match_all('#<w:t(?: [^>]*)?>(.*?)</w:t>#s', $m[0], $tt);
                            $t = trim(html_entity_decode(implode('', $tt[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            foreach ($originali as $o) {
                                if ($t !== '' && str_contains($t, $o)) {
                                    return $m[0];
                                }
                            }
                            return (string) preg_replace('#<w:highlight [^>]*/>#', '', $m[0]);
                        }, $b);
                    }
                    $nuovi .= $b;
                }
                $xml = substr($xml, 0, $ini) . $nuovi . substr($xml, $fin);
            }
        }
        $xml = $sostituisci($xml, $scuola + array_fill_keys(array_keys(Costanti::SEGNAPOSTI), ''));
        // Protocollo (assegnato dal Dipartimento): in alto a destra, prima del testo
        if (trim($protocollo) !== '') {
            $xml = (string) preg_replace('#<w:body>#', '<w:body><w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:rPr><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">' . htmlspecialchars('Prot. n. ' . trim($protocollo), ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>', $xml, 1);
        }
        // Firma compilata: "Il Dirigente Scolastico dell'Istituto" non resta evidenziato
        if (trim((string)($scuola['ISTITUTO_FIRMA'] ?? '')) !== '') {
            $xml = (string) preg_replace_callback('#<w:r\b(?:(?!</w:r>).)*?</w:r>#s', fn ($m) => preg_match('#<w:t(?: [^>]*)?>[^<]*(Il Dirigente Scolastico|dell.Istituto)[^<]*</w:t>#u', $m[0]) ? (string) preg_replace('#<w:highlight [^>]*/>#', '', $m[0]) : $m[0], $xml);
        }
        // Logo della scuola nell'intestazione, al posto della scritta "Logo/intestazione Istituzione Scolastica" (alto 1,5 cm)
        if ($logo && is_file($logo) && ($dim = @getimagesize($logo))) {
            $ext = $dim[2] === IMAGETYPE_PNG ? 'png' : 'jpeg';
            $zip->addFile($logo, 'word/media/logo_scuola.' . $ext);
            $ct = (string)$zip->getFromName('[Content_Types].xml');
            if (!preg_match('/Extension="' . $ext . '"/i', $ct)) {
                $zip->addFromString('[Content_Types].xml', str_replace('</Types>', '<Default Extension="' . $ext . '" ContentType="image/' . $ext . '"/></Types>', $ct));
            }
            $cy = 540000;
            $cx = (int)round($cy * $dim[0] / max(1, $dim[1]));
            if ($cx > 2340000) {
                $cy = (int)round($cy * 2340000 / $cx);
                $cx = 2340000;
            }
            $disegno = '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="9001" name="Logo della scuola"/>'
                 . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="logo_scuola"/><pic:cNvPicPr/></pic:nvPicPr>'
                 . '<pic:blipFill><a:blip r:embed="rIdLogoScuola" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
            $nel_header = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nome = (string)$zip->getNameIndex($i);
                if (!preg_match('#^word/header\d+\.xml$#', $nome)) {
                    continue;
                }
                $hx = (string)$zip->getFromName($nome);
                if (!str_contains($hx, 'Logo/intestazione Istituzione Scolastica')) {
                    continue;
                }
                $hx = (string) preg_replace('#(<w:r\b(?:(?!</w:r>).)*?)<w:t>Logo/intestazione Istituzione Scolastica</w:t>(</w:r>)#s', '$1' . $disegno . '$2', $hx, 1);
                $zip->addFromString($nome, $hx);
                $rn = 'word/_rels/' . basename($nome) . '.rels';
                $rels = (string)$zip->getFromName($rn) ?: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
                $zip->addFromString($rn, str_replace('</Relationships>', '<Relationship Id="rIdLogoScuola" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo_scuola.' . $ext . '"/></Relationships>', $rels));
                $nel_header = true;
            }
            if (!$nel_header) {
                // Modello senza la scritta nell'intestazione: logo in testa al documento
                $rels = (string)$zip->getFromName('word/_rels/document.xml.rels');
                $zip->addFromString('word/_rels/document.xml.rels', str_replace('</Relationships>', '<Relationship Id="rIdLogoScuola" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo_scuola.' . $ext . '"/></Relationships>', $rels));
                $xml = (string) preg_replace('#<w:body>#', '<w:body><w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r>' . $disegno . '</w:r></w:p>', $xml, 1);
            }
        }
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        return $tmp;
    }

    /** Toglie dalla Convenzione la pagina dell'Allegato A (dal titolo «Allegato_A» alla fine) e i paragrafi vuoti che la precedono. */
    private function senzaAllegato(string $xml): string
    {
        if (!preg_match_all('#<w:p\b(?:(?!<w:p\b).)*?</w:p>#s', $xml, $mm, PREG_OFFSET_CAPTURE)) {
            return $xml;
        }
        foreach ($mm[0] as [$p, $pos]) {
            if (trim(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_XML1, 'UTF-8')) !== 'Allegato_A') {
                continue;
            }
            $fine = strrpos($xml, '<w:sectPr');
            if ($fine === false || $fine < $pos) {
                return $xml;
            }
            $prima = substr($xml, 0, $pos);
            // paragrafi vuoti (anche con interruzione di pagina) subito prima del titolo; i cambi di sezione restano
            while (preg_match('#<w:p\b(?:(?!<w:p\b).)*?</w:p>\s*$#s', $prima, $u) && !str_contains($u[0], '<w:sectPr') && !preg_match('#<w:t[ >]#', $u[0])) {
                $prima = substr($prima, 0, -strlen($u[0]));
            }

            return $prima . substr($xml, $fine);
        }

        return $xml;
    }
}
