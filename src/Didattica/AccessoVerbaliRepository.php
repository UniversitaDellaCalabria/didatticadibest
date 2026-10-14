<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Eventi\Righe;

/** Gli accessi alla pagina pubblica dei verbali (verbali_accessi) e il registro di chi è entrato (verbali_accessi_log). */
final class AccessoVerbaliRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function elenco(): array
    {
        return $this->db->righe('SELECT * FROM verbali_accessi ORDER BY attivo DESC, etichetta, id');
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM verbali_accessi WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> gli accessi attivi e non scaduti */
    public function validi(string $oggi): array
    {
        return $this->db->righe('SELECT * FROM verbali_accessi WHERE attivo = 1 AND (scade_il IS NULL OR scade_il >= ?)', [$oggi]);
    }

    public function crea(string $etichetta, string $hash, string $ambitiJson, ?string $scade, string $creatoDa): int
    {
        return $this->db->inserisci('INSERT INTO verbali_accessi (etichetta, password_hash, ambiti_json, scade_il, creato_da) VALUES (?, ?, ?, ?, ?)', [$etichetta, $hash, $ambitiJson, $scade, $creatoDa]);
    }

    public function aggiorna(int $id, string $etichetta, string $ambitiJson, ?string $scade): void
    {
        $this->db->esegui('UPDATE verbali_accessi SET etichetta = ?, ambiti_json = ?, scade_il = ? WHERE id = ?', [$etichetta, $ambitiJson, $scade, $id]);
    }

    public function nuovaPassword(int $id, string $hash): void
    {
        $this->db->esegui('UPDATE verbali_accessi SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    public function imposta(int $id, bool $attivo): void
    {
        $this->db->esegui('UPDATE verbali_accessi SET attivo = ? WHERE id = ?', [$attivo ? 1 : 0, $id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM verbali_accessi WHERE id = ?', [$id]);
    }

    public function segnaAccesso(int $id): void
    {
        $this->db->esegui('UPDATE verbali_accessi SET ultimo_accesso_il = NOW(), n_accessi = n_accessi + 1 WHERE id = ?', [$id]);
    }

    public function registra(?int $accessoId, string $azione, ?int $sedutaId, string $ip): void
    {
        $this->db->esegui('INSERT INTO verbali_accessi_log (accesso_id, azione, seduta_id, ip) VALUES (?, ?, ?, ?)', [$accessoId, $azione, $sedutaId, $ip]);
    }

    /** Toglie dal registro le righe più vecchie dei mesi indicati. */
    public function pulisci(int $mesi): void
    {
        $this->db->esegui('DELETE FROM verbali_accessi_log WHERE creato_il < DATE_SUB(NOW(), INTERVAL ? MONTH)', [max(1, $mesi)]);
    }

    /** @return list<array<string, string|null>> le ultime righe del registro con l'etichetta dell'accesso e la data della seduta scaricata */
    public function registro(int $limite = 100): array
    {
        return Righe::testo($this->db->righe(
            'SELECT l.creato_il, l.azione, l.ip, l.seduta_id, a.etichetta, s.data AS seduta_data, s.organo AS seduta_organo
             FROM verbali_accessi_log l LEFT JOIN verbali_accessi a ON a.id = l.accesso_id LEFT JOIN didattica_sedute s ON s.id = l.seduta_id
             ORDER BY l.id DESC LIMIT ' . (int) $limite
        ));
    }
}
