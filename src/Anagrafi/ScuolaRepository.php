<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;

/** Query dell'anagrafe delle scuole (tabella scuole, open data del Ministero) e dei suoi collegamenti con iscrizioni e profili. */
final class ScuolaRepository
{
    /** Colonne della tabella scuole caricate dal file del Ministero, nell'ordine dell'importazione */
    public const COLONNE_IMPORTAZIONE = ['codice', 'denominazione', 'istituto_codice', 'istituto_denominazione', 'tipo', 'comune', 'provincia', 'regione',
                                         'indirizzo', 'cap', 'email', 'pec', 'anno_scolastico'];

    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perCodice(string $codice): ?array
    {
        return $this->db->riga('SELECT * FROM scuole WHERE codice = ? LIMIT 1', [$codice]);
    }

    /**
     * Ricerca con filtri sul luogo e parole (tutte devono comparire): prima il codice esatto, poi la Calabria, poi per nome.
     *
     * @param array<string, string> $luogo ['regione' => …, 'provincia' => …, 'comune' => …] in maiuscolo, già puliti
     * @param list<string> $parole
     * @return list<array<string, mixed>>
     */
    public function cerca(array $luogo, array $parole, string $codiceEsatto, int $limite): array
    {
        $where = [];
        $par = [];
        foreach ($luogo as $campo => $v) {
            $where[] = "$campo = ?";
            $par[] = $v;
        }
        foreach ($parole as $p) {
            $where[] = '(denominazione LIKE ? OR comune LIKE ? OR istituto_denominazione LIKE ? OR codice LIKE ?)';
            $like = '%' . addcslashes($p, '%_\\') . '%';
            array_push($par, $like, $like, $like, $like);
        }
        $par[] = $codiceEsatto;

        return $this->db->righe(
            'SELECT codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, statale
                FROM scuole WHERE ' . implode(' AND ', $where) . "
                ORDER BY (codice = ?) DESC, (regione = 'CALABRIA') DESC, denominazione ASC LIMIT " . max(1, min(200, $limite)),
            $par
        );
    }

    /**
     * Periodi delle convenzioni con il Dipartimento per scuola: codice => [[data_stipula, scadenza], …]
     *
     * @param list<string> $codici
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public function convenzioni(array $codici): array
    {
        if (!$codici) {
            return [];
        }
        $conv = [];
        $righe = $this->db->righe('SELECT scuola_codice, data_stipula, scadenza FROM convenzioni_scuole WHERE scuola_codice IN (' . implode(',', array_fill(0, count($codici), '?')) . ')', $codici);
        foreach ($righe as $x) {
            $conv[(string) $x['scuola_codice']][] = [(string) $x['data_stipula'], (string) $x['scadenza']];
        }

        return $conv;
    }

    /**
     * Valori distinti di un campo del luogo (regione, provincia, comune) con il numero di scuole
     *
     * @param array<string, string> $filtri campo => valore (es. ['regione' => 'CALABRIA'])
     * @return list<array<string, mixed>> v (valore) e n (numero di scuole)
     */
    public function luoghi(string $campo, array $filtri): array
    {
        if (!in_array($campo, ['regione', 'provincia', 'comune'], true)) {
            return [];
        }
        $where = ["$campo IS NOT NULL", "$campo <> ''"];
        foreach (array_keys($filtri) as $f) {
            $where[] = "$f = ?";
        }

        return $this->db->righe("SELECT $campo AS v, COUNT(*) AS n FROM scuole WHERE " . implode(' AND ', $where) . " GROUP BY $campo ORDER BY $campo", array_values($filtri));
    }

    // ---------------------------------------------------------------- pannello "Anagrafe scuole"

    /**
     * Inserisce o aggiorna le scuole del file del Ministero, a blocchi di 2000 per transazione.
     *
     * @param list<list<string|null>> $righe valori nell'ordine di COLONNE_IMPORTAZIONE
     * @return array{0: int, 1: int, 2: int, 3: int} [inserite, aggiornate, invariate, non salvate]
     */
    public function importa(array $righe, int $statale): array
    {
        $cols = self::COLONNE_IMPORTAZIONE;
        $sql = 'INSERT INTO scuole (' . implode(', ', $cols) . ', statale, aggiornata_il) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, NOW())
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => "$c = VALUES($c)", array_diff($cols, ['codice']))) . ', statale = VALUES(statale), aggiornata_il = NOW()';
        $conta = [0, 0, 0, 0];
        foreach (array_chunk($righe, 2000) as $blocco) {
            $this->db->transazione(function (Database $db) use ($blocco, $sql, $statale, &$conta): void {
                foreach ($blocco as $val) {
                    $n = $db->esegui($sql, [...$val, $statale]);
                    $conta[match (true) {
                        $n === 1 => 0, $n === 2 => 1, $n < 0 => 3, default => 2
                    }]++;
                }
            });
        }

        return $conta;
    }

    /** @return list<array<string, mixed>> scuole per regione: regione, n */
    public function perRegione(): array
    {
        return $this->db->righe('SELECT regione, COUNT(*) n FROM scuole GROUP BY regione');
    }

    /** @return array<string, mixed> tot, statali, paritarie, calabria, agg, anno */
    public function stato(): array
    {
        return $this->db->riga("SELECT COUNT(*) AS tot, SUM(statale = 1) AS statali, SUM(statale = 0) AS paritarie, SUM(regione = 'CALABRIA') AS calabria,
                              MAX(aggiornata_il) AS agg, MAX(anno_scolastico) AS anno FROM scuole") ?? [];
    }

    /**
     * Toglie le scuole fuori dalla Calabria, tranne quelle già scelte in un'iscrizione o in un profilo.
     *
     * @return array{0: int, 1: bool} [quante tolte, ce n'erano di già scelte]
     */
    public function tieniSoloCalabria(): array
    {
        $usate = [];
        foreach (['prenotazioni', 'utenti'] as $tab) {
            foreach ($this->db->righe("SELECT DISTINCT scuola_codice FROM $tab WHERE scuola_codice IS NOT NULL") as $x) {
                $usate[] = (string) $x['scuola_codice'];
            }
        }
        $usate = array_values(array_unique($usate));

        $tolte = max(0, $this->db->esegui(
            "DELETE FROM scuole WHERE regione <> 'CALABRIA'" . ($usate ? ' AND codice NOT IN (' . implode(',', array_fill(0, count($usate), '?')) . ')' : ''),
            $usate
        ));

        return [$tolte, (bool) $usate];
    }

    /** @return list<array<string, mixed>> iscrizioni attive con dati personalizzati (id, scuola_codice, dati_custom_json) */
    public function iscrizioniConDati(): array
    {
        return $this->db->righe("SELECT id, scuola_codice, dati_custom_json FROM prenotazioni WHERE dati_custom_json IS NOT NULL AND dati_custom_json <> ''
                       AND IFNULL(stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')");
    }

    /** @param list<int> $ids iscrizioni da collegare alla scuola; ritorna le righe aggiornate */
    public function abbinaIscrizioni(array $ids, string $codice): int
    {
        if (!$ids) {
            return 0;
        }

        return max(0, $this->db->esegui('UPDATE prenotazioni SET scuola_codice = ? WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')', [$codice]));
    }

    public function iscrizioniConCodice(): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) AS n FROM prenotazioni WHERE scuola_codice IS NOT NULL');
    }

    /** @return list<array<string, mixed>> iscrizioni attive collegate a una scuola, dalla più recente (con il titolo dell'attività) */
    public function iscrizioniCollegate(): array
    {
        return $this->db->righe("SELECT pr.scuola_codice, pr.nome, pr.cognome, LOWER(pr.email) AS email, pr.data_prenotazione, e.titolo
                       FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                       WHERE pr.scuola_codice IS NOT NULL AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')
                       ORDER BY pr.data_prenotazione DESC");
    }

    /** @return list<array<string, mixed>> utenti che hanno indicato la scuola nel profilo */
    public function profiliCollegati(): array
    {
        return $this->db->righe('SELECT scuola_codice, nome, cognome, LOWER(email) AS email FROM utenti WHERE scuola_codice IS NOT NULL');
    }
}
