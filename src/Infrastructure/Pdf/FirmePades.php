<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

/**
 * Firme digitali presenti in un PDF. PAdES = firma dentro il PDF (dizionario /Sig con /ByteRange); un file .p7m (CAdES) non è
 * un PDF e non ne ha. Spostata da firme_pades_pdf() di inc/pdf.php (che resta come facciata).
 */
final class FirmePades implements VerificaFirme
{
    /**
     * @return list<array<string, mixed>> una voce per firma: subfilter ('ETSI.CAdES.detached', 'adbe.pkcs7.detached'…), nome, data,
     *                                    fine, valida_struttura e copre_tutto (l'ultima firma copre il file fino alla fine)
     */
    public function firmePades(string $pdf): array
    {
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            return [];
        }
        $out = [];
        if (!preg_match_all('#/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $pdf, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($mm as $m) {
            $pos = $m[0][1];
            // Il dizionario della firma è intorno al /ByteRange: si cercano /SubFilter, /Name e /M lì vicino
            $da = max(0, $pos - 4000);
            $dict = substr($pdf, $da, 8000);
            $sub = preg_match('#/SubFilter\s*/([A-Za-z0-9.\-]+)#', $dict, $x) ? $x[1] : '';
            $nome = preg_match('#/Name\s*\(([^)]{0,200})\)#', $dict, $x) ? $x[1] : '';
            $data = preg_match('#/M\s*\(D:(\d{14})#', $dict, $x) ? $x[1] : '';
            [$a, $b, $c, $d] = [(int) $m[1][0], (int) $m[2][0], (int) $m[3][0], (int) $m[4][0]];
            $out[] = ['subfilter' => $sub, 'nome' => $nome, 'data' => $data, 'fine' => $c + $d, 'valida_struttura' => $a === 0 && $c > $b && $c + $d <= strlen($pdf)];
        }
        $lung = strlen(rtrim($pdf));
        foreach ($out as &$f) {
            $f['copre_tutto'] = $f['fine'] >= $lung - 2;
        }
        unset($f);

        return $out;
    }
}
