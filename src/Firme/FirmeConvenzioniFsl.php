<?php

declare(strict_types=1);

namespace App\Firme;

use App\Core\Database;
use App\Fsl\ServizioFirmaDirettore;

/** Convenzioni con le scuole (Formazione Scuola Lavoro) arrivate alla firma del Direttore o del vice con «Direttore assente». */
final class FirmeConvenzioniFsl implements FonteFirme
{
    public function __construct(private Database $db, private ServizioFirmaDirettore $firma)
    {
    }

    public function tipo(): string
    {
        return 'convenzione_fsl';
    }

    public function etichetta(): string
    {
        return 'Convenzioni FSL';
    }

    public function documenti(array $utente): array
    {
        if (!$this->firma->puoFirmare($utente)) {
            return [];
        }
        $out = [];
        $righe = $this->db->righe(
            "SELECT c.*, COALESCE(s.denominazione, c.scuola_codice) AS scuola, DATEDIFF(NOW(), COALESCE(c.aggiornata_il, c.creata_il)) AS gg
             FROM convenzioni_compilate c LEFT JOIN scuole s ON s.codice = c.scuola_codice
             WHERE c.stato_iter = 'da_firmare' AND c.token_direttore IS NOT NULL AND c.token_direttore <> '' ORDER BY COALESCE(c.aggiornata_il, c.creata_il)"
        );
        foreach ($righe as $r) {
            $out[] = new DocumentoDaFirmare(
                $this->tipo(),
                (int) $r['id'],
                'Convenzione · ' . (string) $r['scuola'],
                'Formazione Scuola Lavoro',
                max(0, (int) $r['gg']),
                'firma_convenzione.php?t=' . urlencode((string) $r['token_direttore']),
                $this->firma->pdfDaFirmare($r),
                DocumentoDaFirmare::nomeSicuro($this->tipo(), (int) $r['id'], (string) $r['scuola_codice']),
                fn (string $pdf): ?string => $this->firma->caricaFirmato($r, $pdf)
            );
        }

        return $out;
    }
}
