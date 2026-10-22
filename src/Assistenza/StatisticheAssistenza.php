<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Core\Database;

/**
 * I numeri dell'assistenza in un periodo (per data di apertura della chat): quante richieste, su cosa, quanto ci si mette a rispondere, quanti hanno trovato
 * utile l'assistenza e quanto lavora ogni operatore. Non servono i messaggi: restano anche dopo la conservazione, quando le chat vengono ripulite.
 */
final class StatisticheAssistenza
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     totale: int, aperte: int, chiuse: int, esterni: int, con_login: int, utili: int, non_utili: int, senza_valutazione: int, prima_risposta_min: ?float,
     *     senza_risposta: int, risolte_da_didacticus: int, passate_a_operatore: int,
     *     per_argomento: list<array{argomento: string, nome: string, n: int, chiuse: int, prima_risposta_min: ?float, utili: int, non_utili: int}>,
     *     per_operatore: list<array{nome: string, n: int, prima_risposta_min: ?float}>
     * }
     */
    public function riepilogo(string $dal, string $al): array
    {
        $da = $dal . ' 00:00:00';
        $a = $al . ' 23:59:59';
        $r = $this->db->riga(
            "SELECT COUNT(*) AS totale, SUM(stato IN ('nuova', 'assegnata', 'bot')) AS aperte, SUM(stato = 'chiusa') AS chiuse, SUM(canale = 'esterno') AS esterni, SUM(canale = 'utente') AS con_login,
                    SUM(valutazione = 1) AS utili, SUM(valutazione = 0) AS non_utili, SUM(valutazione IS NULL AND stato = 'chiusa') AS senza_valutazione,
                    AVG(TIMESTAMPDIFF(MINUTE, creato_il, prima_risposta_il)) AS prima, SUM(prima_risposta_il IS NULL AND stato <> 'bot') AS senza_risposta,
                    SUM(risolta_da_bot = 1) AS risolte_da_didacticus,
                    SUM(passata_il IS NOT NULL) AS passate
             FROM assistenza_conversazioni WHERE creato_il BETWEEN ? AND ?",
            [$da, $a]
        ) ?? [];
        $argomenti = [];
        foreach ($this->db->righe(
            "SELECT argomento, COUNT(*) AS n, SUM(stato = 'chiusa') AS chiuse, AVG(TIMESTAMPDIFF(MINUTE, creato_il, prima_risposta_il)) AS prima, SUM(valutazione = 1) AS utili, SUM(valutazione = 0) AS non_utili
             FROM assistenza_conversazioni WHERE creato_il BETWEEN ? AND ? GROUP BY argomento ORDER BY n DESC",
            [$da, $a]
        ) as $x) {
            $argomenti[] = [
                'argomento' => (string) $x['argomento'], 'nome' => Costanti::nomeArgomento((string) $x['argomento']), 'n' => (int) $x['n'], 'chiuse' => (int) $x['chiuse'],
                'prima_risposta_min' => $x['prima'] === null ? null : round((float) $x['prima'], 1), 'utili' => (int) $x['utili'], 'non_utili' => (int) $x['non_utili'],
            ];
        }
        $operatori = [];
        foreach ($this->db->righe(
            "SELECT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))), ''), NULLIF(c.operatore_nome, ''), 'Operatore') AS nome, COUNT(*) AS n,
                    AVG(TIMESTAMPDIFF(MINUTE, c.creato_il, c.prima_risposta_il)) AS prima
             FROM assistenza_conversazioni c LEFT JOIN utenti u ON u.id = c.operatore_id
             WHERE c.creato_il BETWEEN ? AND ? AND c.operatore_id IS NOT NULL GROUP BY c.operatore_id, nome ORDER BY n DESC",
            [$da, $a]
        ) as $x) {
            $operatori[] = ['nome' => (string) $x['nome'], 'n' => (int) $x['n'], 'prima_risposta_min' => $x['prima'] === null ? null : round((float) $x['prima'], 1)];
        }

        return [
            'totale' => (int) ($r['totale'] ?? 0), 'aperte' => (int) ($r['aperte'] ?? 0), 'chiuse' => (int) ($r['chiuse'] ?? 0), 'esterni' => (int) ($r['esterni'] ?? 0),
            'con_login' => (int) ($r['con_login'] ?? 0), 'utili' => (int) ($r['utili'] ?? 0), 'non_utili' => (int) ($r['non_utili'] ?? 0), 'senza_valutazione' => (int) ($r['senza_valutazione'] ?? 0),
            'prima_risposta_min' => ($r['prima'] ?? null) === null ? null : round((float) $r['prima'], 1), 'senza_risposta' => (int) ($r['senza_risposta'] ?? 0),
            'risolte_da_didacticus' => (int) ($r['risolte_da_didacticus'] ?? 0), 'passate_a_operatore' => (int) ($r['passate'] ?? 0),
            'per_argomento' => $argomenti, 'per_operatore' => $operatori,
        ];
    }

    /** Un tempo in minuti scritto per le persone: «meno di un minuto», «12 min», «2 h 10 min». */
    public static function durata(?float $minuti): string
    {
        if ($minuti === null) {
            return '—';
        }
        if ($minuti < 1) {
            return 'meno di 1 min';
        }
        $m = (int) round($minuti);

        return $m < 60 ? "$m min" : intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
    }
}
