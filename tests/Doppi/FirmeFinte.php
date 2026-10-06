<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Infrastructure\Pdf\VerificaFirme;

/** Firme dei test: ogni «%%FIRMA» nel PDF vale una firma PAdES che copre tutto il file (nessuna crittografia). */
final class FirmeFinte implements VerificaFirme
{
    public function firmePades(string $pdf): array
    {
        $n = substr_count($pdf, '%%FIRMA');

        return $n ? array_fill(0, $n, ['subfilter' => 'ETSI.CAdES.detached', 'nome' => '', 'data' => '', 'valida_struttura' => true, 'copre_tutto' => true]) : [];
    }
}
