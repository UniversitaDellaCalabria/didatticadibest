<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Core\Database;
use App\Eventi\Righe;

/** I responsabili delle risorse (risorse_responsabili): persone dell'anagrafe di Ateneo che ricevono le richieste e, se abilitate, le approvano. */
final class ResponsabileRisorsaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function elenco(int $risorsaId): array
    {
        return $this->db->righe('SELECT * FROM risorse_responsabili WHERE risorsa_id = ? ORDER BY nominativo, id', [$risorsaId]);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM risorse_responsabili WHERE id = ?', [$id]);
    }

    public function aggiungi(int $risorsaId, string $personaId, string $nominativo, string $email, bool $notifiche, bool $approva): bool
    {
        return $this->db->esegui(
            'INSERT IGNORE INTO risorse_responsabili (risorsa_id, persona_id, nominativo, email, riceve_notifiche, puo_approvare) VALUES (?, ?, ?, ?, ?, ?)',
            [$risorsaId, $personaId, $nominativo, strtolower($email), $notifiche ? 1 : 0, $approva ? 1 : 0]
        ) === 1;
    }

    public function imposta(int $id, bool $notifiche, bool $approva): void
    {
        $this->db->esegui('UPDATE risorse_responsabili SET riceve_notifiche = ?, puo_approvare = ? WHERE id = ?', [$notifiche ? 1 : 0, $approva ? 1 : 0, $id]);
    }

    public function togli(int $id): void
    {
        $this->db->esegui('DELETE FROM risorse_responsabili WHERE id = ?', [$id]);
    }

    /** Toglie i responsabili di una risorsa che sta per essere eliminata. */
    public function togliDellaRisorsa(int $risorsaId): void
    {
        $this->db->esegui('DELETE FROM risorse_responsabili WHERE risorsa_id = ?', [$risorsaId]);
    }

    /** @return list<string> email dei responsabili che ricevono le notifiche della risorsa */
    public function emailNotifiche(int $risorsaId): array
    {
        return array_map('strval', array_column($this->db->righe('SELECT email FROM risorse_responsabili WHERE risorsa_id = ? AND riceve_notifiche = 1', [$risorsaId]), 'email'));
    }

    /** @return array<string, mixed>|null la riga del responsabile con questa email per la risorsa */
    public function perEmail(int $risorsaId, string $email): ?array
    {
        return $this->db->riga('SELECT * FROM risorse_responsabili WHERE risorsa_id = ? AND email = ?', [$risorsaId, strtolower($email)]);
    }

    /**
     * Le risorse di cui la persona (per email) è responsabile, con le richieste da approvare e le prenotazioni future.
     *
     * @return list<array<string, string|null>>
     */
    public function risorseDi(string $email): array
    {
        return Righe::testo($this->db->righe(
            "SELECT r.id, r.nome, r.tipo, r.luogo, r.pagina_id, r.attiva, r.diario_attivo, x.puo_approvare, x.riceve_notifiche,
                    (SELECT COUNT(*) FROM prenotazioni_risorse p WHERE p.risorsa_id = r.id AND p.stato = 'da_approvare' AND p.fine >= NOW()) AS da_approvare,
                    (SELECT COUNT(*) FROM prenotazioni_risorse p WHERE p.risorsa_id = r.id AND p.stato = 'confermata' AND p.fine >= NOW()) AS future
             FROM risorse_responsabili x JOIN risorse r ON r.id = x.risorsa_id WHERE x.email = ? ORDER BY r.nome",
            [strtolower($email)]
        ));
    }

    /**
     * Prenotazioni di una risorsa da gestire: da approvare e confermate future, nell'ordine di data.
     *
     * @return list<array<string, string|null>>
     */
    public function prenotazioni(int $risorsaId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT p.*, r.nome AS risorsa_nome FROM prenotazioni_risorse p JOIN risorse r ON r.id = p.risorsa_id
             WHERE p.risorsa_id = ? AND p.stato IN ('da_approvare', 'confermata') AND p.fine >= NOW() ORDER BY (p.stato = 'da_approvare') DESC, p.inizio",
            [$risorsaId]
        ));
    }
}
