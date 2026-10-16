<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Eventi\Righe;

/** Uffici del Dipartimento (tabella uffici, ambito «dipartimento»), definiti da Gestione del portale → Uffici. La lettura resta in memoria finché non si chiede di rileggerla. */
final class UfficioRepository
{
    /** @var array<int, array<string, string|null>>|null */
    private ?array $cache = null;

    public function __construct(private Database $db)
    {
    }

    /**
     * [id => riga]. «smista» = riceve le pratiche nuove e le smista (manager); «segue_corsi» = il personale indica i corsi di studio seguiti.
     *
     * @return array<int, array<string, string|null>>
     */
    public function tutti(bool $rileggi = false): array
    {
        if ($this->cache !== null && !$rileggi) {
            return $this->cache;
        }
        $this->cache = [];
        foreach (Righe::testo($this->db->righe("SELECT * FROM uffici WHERE ambito = 'dipartimento' ORDER BY ordine, nome")) as $x) {
            $this->cache[(int) $x['id']] = $x;
        }

        return $this->cache;
    }

    /** Le righe lette fino a ora non valgono più (dopo una modifica fatta da fuori). */
    public function svuota(): void
    {
        $this->cache = null;
    }

    /**
     * Id dell'ufficio da un id o da una chiave dei modelli pronti ('referente_cdl', 'carriere'…); null se non esiste.
     */
    public function idDa(mixed $x): ?int
    {
        $uff = $this->tutti();
        if (is_numeric($x) && isset($uff[(int) $x])) {
            return (int) $x;
        }
        foreach ($uff as $id => $u) {
            if ((string) $u['chiave'] !== '' && $u['chiave'] === $x) {
                return $id;
            }
        }

        return null;
    }

    /** Aggiorna l'ufficio $id oppure, con $id = 0, ne crea uno nuovo. */
    public function salva(int $id, string $nome, string $descrizione, int $smista, int $segueCorsi, int $ordine, string $tipo, ?int $consiglioId): void
    {
        if ($id) {
            $this->db->esegui('UPDATE uffici SET nome = ?, descrizione = ?, smista = ?, segue_corsi = ?, ordine = ?, tipo = ?, consiglio_id = ? WHERE id = ?', [$nome, $descrizione, $smista, $segueCorsi, $ordine, $tipo, $consiglioId, $id]);
        } else {
            $this->db->esegui("INSERT INTO uffici (nome, descrizione, ambito, smista, segue_corsi, ordine, tipo, consiglio_id) VALUES (?, ?, 'dipartimento', ?, ?, ?, ?, ?)", [$nome, $descrizione, $smista, $segueCorsi, $ordine, $tipo, $consiglioId]);
        }
        $this->cache = null;
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM uffici_persone WHERE ufficio_id = ?', [$id]);
        $this->db->esegui("DELETE FROM uffici WHERE id = ? AND ambito = 'dipartimento'", [$id]);
        $this->cache = null;
    }
}
