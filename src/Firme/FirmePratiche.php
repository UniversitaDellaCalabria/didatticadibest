<?php

declare(strict_types=1);

namespace App\Firme;

use App\Core\Database;
use App\Didattica\FirmaPratica;

/** Documenti delle pratiche degli studenti (tesi, attestati, certificati) che aspettano la firma della persona collegata. */
final class FirmePratiche implements FonteFirme
{
    public function __construct(private Database $db, private FirmaPratica $firma)
    {
    }

    public function tipo(): string
    {
        return 'pratica';
    }

    public function etichetta(): string
    {
        return 'Pratiche';
    }

    public function documenti(array $utente): array
    {
        $righe = $this->db->righe(
            "SELECT f.*, p.codice, p.nome AS p_nome, p.cognome AS p_cognome, m.titolo, DATEDIFF(NOW(), f.richiesta_il) AS gg
             FROM pratiche_firme f JOIN pratiche p ON p.id = f.pratica_id JOIN didattica_moduli m ON m.id = p.modulo_id
             WHERE f.stato = 'attesa' AND p.stato IN ('inviata', 'in_lavorazione') ORDER BY f.richiesta_il"
        );
        $out = [];
        foreach ($righe as $r) {
            if (!$this->firma->puoFirmare($r, $utente)) {
                continue;
            }
            $out[] = new DocumentoDaFirmare(
                $this->tipo(),
                (int) $r['id'],
                (string) $r['titolo'] . ' · ' . trim((string) $r['p_nome'] . ' ' . (string) $r['p_cognome']),
                'pratica ' . $r['codice'] . ($r['tipo'] === 'direttore' ? ' · firma del Direttore' : ''),
                max(0, (int) $r['gg']),
                'firma_pratica.php?t=' . urlencode((string) $r['token']),
                $this->firma->pdfDaFirmare($r),
                DocumentoDaFirmare::nomeSicuro($this->tipo(), (int) $r['id'], (string) $r['codice'])
            );
        }

        return $out;
    }
}
