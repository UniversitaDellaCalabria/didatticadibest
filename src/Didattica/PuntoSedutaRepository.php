<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;

/** Punti dell'ordine del giorno di una seduta (didattica_sedute_punti) con il testo scritto per il verbale. */
final class PuntoSedutaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function elenco(int $sedutaId): array
    {
        return $this->db->righe('SELECT * FROM didattica_sedute_punti WHERE seduta_id = ? ORDER BY ordine, id', [$sedutaId]);
    }

    public function inserisci(int $sedutaId, int $ordine, string $titolo, string $tipo, string $testo = ''): int
    {
        return $this->db->inserisci('INSERT INTO didattica_sedute_punti (seduta_id, ordine, titolo, tipo, testo) VALUES (?, ?, ?, ?, ?)', [$sedutaId, $ordine, $titolo, $tipo, $testo]);
    }

    public function aggiornaTitolo(int $id, int $ordine, string $titolo): void
    {
        $this->db->esegui('UPDATE didattica_sedute_punti SET ordine = ?, titolo = ? WHERE id = ?', [$ordine, $titolo, $id]);
    }

    public function salvaTestoETipo(int $id, string $testo, string $tipo): void
    {
        $this->db->esegui('UPDATE didattica_sedute_punti SET testo = ?, tipo = ? WHERE id = ?', [$testo, $tipo, $id]);
    }

    /** Toglie il punto; le pratiche assegnate tornano «senza punto» (vanno al primo punto delle pratiche). */
    public function elimina(int $id): void
    {
        $this->db->esegui('UPDATE pratiche SET punto_id = NULL WHERE punto_id = ?', [$id]);
        $this->db->esegui('DELETE FROM didattica_sedute_punti WHERE id = ?', [$id]);
    }

    public function eliminaDellaSeduta(int $sedutaId): void
    {
        $this->db->esegui('UPDATE pratiche SET punto_id = NULL WHERE seduta_id = ?', [$sedutaId]);
        $this->db->esegui('DELETE FROM didattica_sedute_punti WHERE seduta_id = ?', [$sedutaId]);
    }

    public function assegnaPratica(int $praticaId, ?int $puntoId): void
    {
        $this->db->esegui('UPDATE pratiche SET punto_id = ? WHERE id = ?', [$puntoId ?: null, $praticaId]);
    }
}
