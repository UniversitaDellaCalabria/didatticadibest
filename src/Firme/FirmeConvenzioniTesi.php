<?php

declare(strict_types=1);

namespace App\Firme;

use App\ConvenzioniTesi\ServizioConvenzioniTesi;
use App\Core\Database;

/** Convenzioni di tesi con enti e aziende arrivate alla firma del Direttore o del vice con «Direttore assente». */
final class FirmeConvenzioniTesi implements FonteFirme
{
    public function __construct(private Database $db, private ServizioConvenzioniTesi $servizio)
    {
    }

    public function tipo(): string
    {
        return 'convenzione_tesi';
    }

    public function etichetta(): string
    {
        return 'Convenzioni di tesi';
    }

    public function documenti(array $utente): array
    {
        if (!$this->servizio->puoFirmare($utente)) {
            return [];
        }
        $out = [];
        $righe = $this->db->righe(
            "SELECT *, DATEDIFF(NOW(), COALESCE(da_firmare_il, aggiornata_il, creata_il)) AS gg FROM convenzioni_tesi
             WHERE stato = 'da_firmare' AND token_direttore IS NOT NULL AND token_direttore <> '' ORDER BY da_firmare_il"
        );
        foreach ($righe as $r) {
            $out[] = new DocumentoDaFirmare(
                $this->tipo(),
                (int) $r['id'],
                'Convenzione di tesi · ' . (string) $r['azienda'],
                'ente o azienda per le tesi',
                max(0, (int) $r['gg']),
                'firma_convenzione_tesi.php?t=' . urlencode((string) $r['token_direttore']),
                $this->servizio->pdfDaFirmare($r),
                DocumentoDaFirmare::nomeSicuro($this->tipo(), (int) $r['id'], '')
            );
        }

        return $out;
    }
}
