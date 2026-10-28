<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * Numeri per «La mia scrivania» (admin/inizio.php) e per il menu: cose che aspettano qualcuno nel pannello.
 * Ogni metodo conta soltanto; chi chiama decide quali contare in base ai permessi dell'utente.
 * Se una query non riesce (tabella mancante in installazioni vecchie) il risultato è zero, come per la dashboard.
 */
final class CoseDaFare
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Pratiche aperte senza ufficio né persona a cui spetta («da smistare») e giorni della più vecchia.
     *
     * @return array{n: int, giorni: int}
     */
    public function praticheDaSmistare(): array
    {
        $r = $this->db->riga("SELECT COUNT(*) AS n, DATEDIFF(NOW(), MIN(creata_il)) AS giorni FROM pratiche
            WHERE assegnata_a IS NULL AND ufficio_id IS NULL AND stato NOT IN ('bozza', 'accolta', 'respinta', 'chiusa')");

        return ['n' => (int) ($r['n'] ?? 0), 'giorni' => max(0, (int) ($r['giorni'] ?? 0))];
    }

    /** Pratiche aperte che si trovano in un ufficio di tipo «protocollo» (da registrare). */
    public function praticheDaProtocollare(): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM pratiche p JOIN uffici u ON u.id = p.ufficio_id
            WHERE u.tipo = 'protocollo' AND p.stato NOT IN ('bozza', 'integrazione', 'accolta', 'respinta', 'chiusa')");
    }

    /**
     * Verbali inviati alla firma e non ancora firmati da tutti.
     *
     * @param list<int>|null $consigli null = di tutti i consigli; altrimenti solo quelli indicati (referenti)
     */
    public function verbaliInFirma(?array $consigli = null): int
    {
        $filtro = $consigli !== null ? ' AND consiglio_id IN (' . implode(',', array_map('intval', $consigli ?: [0])) . ')' : '';

        return (int) $this->db->valore("SELECT COUNT(*) FROM didattica_sedute WHERE verbale_stato IN ('segretario', 'coordinatore')$filtro");
    }

    /** Pratiche di tutorato con la fine attività firmata dal docente (o rimandate dall'ufficio pagamenti): da validare e passare ai pagamenti. */
    public function tutoratoDaValidare(): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_incarichi WHERE stato = 'protocollata' AND iter = 'portale' AND fine_stato IN ('firmata', 'protocollata') AND pagamento_stato IN ('', 'rimandata')");
    }

    /** Convenzioni firmate caricate dalle scuole e non ancora protocollate: da prendere in carico o da far firmare e registrare (FSL). */
    public function convenzioniDaPrendereInCarico(): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM convenzioni_compilate WHERE stato_iter IN ('caricata_scuola', 'in_carico', 'firmata_direttore')");
    }

    /**
     * Prenotazioni con messaggi dell'utente non ancora letti, per area.
     *
     * @param list<int> $areeIds
     * @return array<int, int> id area => numero (solo le aree con almeno un messaggio)
     */
    public function messaggiNonLetti(array $areeIds): array
    {
        if (!$areeIds) {
            return [];
        }
        $righe = $this->db->righe("SELECT e.pagina_id AS area, COUNT(DISTINCT m.prenotazione_id) AS n
            FROM messaggi_prenotazioni m JOIN prenotazioni p ON m.prenotazione_id = p.id JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
            WHERE m.letto = 0 AND m.mittente_tipo = 'utente' AND e.pagina_id IN (" . implode(',', array_map('intval', $areeIds)) . ') GROUP BY e.pagina_id');

        return $this->perArea($righe);
    }

    /**
     * Iscrizioni agli eventi che aspettano l'approvazione, per area.
     *
     * @param list<int> $areeIds
     * @return array<int, int>
     */
    public function iscrizioniDaApprovare(array $areeIds): array
    {
        if (!$areeIds) {
            return [];
        }
        $righe = $this->db->righe("SELECT e.pagina_id AS area, COUNT(*) AS n FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
            WHERE e.archiviato = 0 AND p.stato = 'da_approvare' AND e.pagina_id IN (" . implode(',', array_map('intval', $areeIds)) . ') GROUP BY e.pagina_id');

        return $this->perArea($righe);
    }

    /**
     * Prenotazioni di aule e laboratori da approvare (ancora non finite), per area.
     *
     * @param list<int> $areeIds
     * @return array<int, int>
     */
    public function prenotazioniRisorseDaApprovare(array $areeIds): array
    {
        if (!$areeIds) {
            return [];
        }
        $righe = $this->db->righe("SELECT r.pagina_id AS area, COUNT(*) AS n FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id
            WHERE pr.stato = 'da_approvare' AND pr.fine >= NOW() AND r.pagina_id IN (" . implode(',', array_map('intval', $areeIds)) . ') GROUP BY r.pagina_id');

        return $this->perArea($righe);
    }

    /**
     * @param list<array<string, mixed>> $righe
     * @return array<int, int>
     */
    private function perArea(array $righe): array
    {
        $out = [];
        foreach ($righe as $r) {
            if ((int) $r['n'] > 0) {
                $out[(int) $r['area']] = (int) $r['n'];
            }
        }

        return $out;
    }
}
