<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Core\Database;
use App\Eventi\Righe;

/** I contatori delle risorse (quantità prenotabili): definizione, usi delle prenotazioni e movimenti di scorta. */
final class ContatoreRisorsaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function elenco(int $risorsaId, bool $soloAttivi = true): array
    {
        return $this->db->righe('SELECT * FROM risorse_contatori WHERE risorsa_id = ?' . ($soloAttivi ? ' AND attivo = 1' : '') . ' ORDER BY ordine, id', [$risorsaId]);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM risorse_contatori WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $v nome, unita, modalita, capacita, periodo, minimo, massimo, obbligatorio, attivo */
    public function salva(int $risorsaId, int $id, array $v): int
    {
        $p = [$v['nome'], $v['unita'], $v['modalita'], (int) $v['capacita'], $v['periodo'], (int) $v['minimo'], $v['massimo'], (int) $v['obbligatorio'], (int) $v['attivo']];
        if ($id) {
            $this->db->esegui('UPDATE risorse_contatori SET nome = ?, unita = ?, modalita = ?, capacita = ?, periodo = ?, minimo = ?, massimo = ?, obbligatorio = ?, attivo = ? WHERE id = ? AND risorsa_id = ?', [...$p, $id, $risorsaId]);

            return $id;
        }

        return $this->db->inserisci('INSERT INTO risorse_contatori (nome, unita, modalita, capacita, periodo, minimo, massimo, obbligatorio, attivo, risorsa_id, ordine) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [...$p, $risorsaId, (int) $this->db->valore('SELECT COUNT(*) FROM risorse_contatori WHERE risorsa_id = ?', [$risorsaId])]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM risorse_contatori_usi WHERE contatore_id = ?', [$id]);
        $this->db->esegui('DELETE FROM risorse_contatori_movimenti WHERE contatore_id = ?', [$id]);
        $this->db->esegui('DELETE FROM risorse_contatori WHERE id = ?', [$id]);
    }

    /** Toglie i contatori di una risorsa con i loro usi e movimenti (la risorsa viene eliminata). */
    public function eliminaDellaRisorsa(int $risorsaId): void
    {
        foreach ($this->elenco($risorsaId, false) as $c) {
            $this->elimina((int) $c['id']);
        }
    }

    /** Quantità usate dalle prenotazioni confermate o da approvare con la data nel periodo (escluse eventualmente una prenotazione). */
    public function usato(int $contatoreId, ?string $dal, ?string $al, ?int $escludiPrenotazione = null): int
    {
        $sql = "SELECT COALESCE(SUM(u.quantita), 0) FROM risorse_contatori_usi u JOIN prenotazioni_risorse p ON p.id = u.prenotazione_id
                WHERE u.contatore_id = ? AND p.stato IN ('confermata', 'da_approvare')";
        $par = [$contatoreId];
        if ($dal !== null && $al !== null) {
            $sql .= ' AND u.data BETWEEN ? AND ?';
            array_push($par, $dal, $al);
        }
        if ($escludiPrenotazione) {
            $sql .= ' AND u.prenotazione_id <> ?';
            $par[] = $escludiPrenotazione;
        }

        return (int) $this->db->valore($sql, $par);
    }

    public function totaleMovimenti(int $contatoreId): int
    {
        return (int) $this->db->valore('SELECT COALESCE(SUM(quantita), 0) FROM risorse_contatori_movimenti WHERE contatore_id = ?', [$contatoreId]);
    }

    public function aggiungiMovimento(int $contatoreId, int $quantita, string $nota, string $autore): void
    {
        $this->db->esegui('INSERT INTO risorse_contatori_movimenti (contatore_id, quantita, nota, autore) VALUES (?, ?, ?, ?)', [$contatoreId, $quantita, mb_substr($nota, 0, 255), mb_substr($autore, 0, 150)]);
    }

    /** @return list<array<string, string|null>> */
    public function movimenti(int $contatoreId, int $limite = 30): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM risorse_contatori_movimenti WHERE contatore_id = ? ORDER BY id DESC LIMIT ' . (int) $limite, [$contatoreId]));
    }

    public function registraUso(int $contatoreId, int $prenotazioneId, int $quantita, string $data): void
    {
        $this->db->esegui('INSERT INTO risorse_contatori_usi (contatore_id, prenotazione_id, quantita, data) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantita = VALUES(quantita), data = VALUES(data)',
            [$contatoreId, $prenotazioneId, $quantita, $data]);
    }

    /** @return list<array<string, string|null>> le quantità di una prenotazione con il nome del contatore */
    public function usiDellaPrenotazione(int $prenotazioneId): array
    {
        return Righe::testo($this->db->righe(
            'SELECT c.id AS contatore_id, c.nome, c.unita, u.quantita FROM risorse_contatori_usi u JOIN risorse_contatori c ON c.id = u.contatore_id WHERE u.prenotazione_id = ? ORDER BY c.ordine, c.id',
            [$prenotazioneId]
        ));
    }

    /**
     * Le quantità per prenotazione delle prenotazioni indicate: id prenotazione => [«2 campioni», …] (per elenchi ed export).
     *
     * @param list<int> $prenotazioni
     * @return array<int, list<array<string, string|null>>>
     */
    public function usiDi(array $prenotazioni): array
    {
        $out = [];
        if (!$prenotazioni) {
            return $out;
        }
        foreach (Righe::testo($this->db->righe(
            'SELECT u.prenotazione_id, c.id AS contatore_id, c.nome, c.unita, u.quantita FROM risorse_contatori_usi u JOIN risorse_contatori c ON c.id = u.contatore_id
             WHERE u.prenotazione_id IN (' . implode(',', array_map('intval', $prenotazioni)) . ') ORDER BY c.ordine, c.id'
        )) as $r) {
            $out[(int) $r['prenotazione_id']][] = $r;
        }

        return $out;
    }
}
