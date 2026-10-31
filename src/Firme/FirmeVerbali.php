<?php

declare(strict_types=1);

namespace App\Firme;

use App\Core\Database;
use App\Didattica\VerbaleFirmato;

/** Verbali dei consigli inviati alla firma, quando tocca al segretario o al coordinatore che sei. */
final class FirmeVerbali implements FonteFirme
{
    public function __construct(private Database $db, private VerbaleFirmato $verbali)
    {
    }

    public function tipo(): string
    {
        return 'verbale';
    }

    public function etichetta(): string
    {
        return 'Verbali';
    }

    public function documenti(array $utente): array
    {
        $out = [];
        $righe = $this->db->righe(
            "SELECT s.*, DATEDIFF(NOW(), COALESCE(s.verbale_inviato_il, s.data, s.creata_il)) AS gg FROM didattica_sedute s
             WHERE s.verbale_stato IN ('segretario', 'coordinatore') AND s.verbale_token IS NOT NULL AND s.verbale_token <> '' ORDER BY s.data"
        );
        foreach ($righe as $s) {
            if (!$this->verbali->firmatario($s, $utente)) {
                continue;
            }
            $quando = $s['data'] ? (int) strtotime((string) $s['data']) : 0;
            $out[] = new DocumentoDaFirmare(
                $this->tipo(),
                (int) $s['id'],
                'Verbale · ' . (string) $s['organo'],
                'seduta' . ($quando ? ' del ' . date('d/m/Y', $quando) : '') . ' · firma come ' . $s['verbale_stato'],
                max(0, (int) $s['gg']),
                'firma_verbale.php?t=' . urlencode((string) $s['verbale_token']),
                $this->verbali->percorso($s),
                DocumentoDaFirmare::nomeSicuro($this->tipo(), (int) $s['id'], $quando ? date('Ymd', $quando) : '')
            );
        }

        return $out;
    }
}
