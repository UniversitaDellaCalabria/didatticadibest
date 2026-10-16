<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * I responsabili delle risorse: persone dell'anagrafe di Ateneo (risorse_responsabili) e uffici interi (risorse_uffici, con le persone di uffici_persone)
 * che ricevono le richieste e, se abilitati, le approvano.
 */
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
        $this->db->esegui('DELETE FROM risorse_uffici WHERE risorsa_id = ?', [$risorsaId]);
    }

    /** @return list<string> email dei responsabili (persone e uffici) che ricevono le notifiche della risorsa */
    public function emailNotifiche(int $risorsaId): array
    {
        $e = array_map('strval', array_column($this->db->righe('SELECT email FROM risorse_responsabili WHERE risorsa_id = ? AND riceve_notifiche = 1', [$risorsaId]), 'email'));
        foreach ($this->db->righe('SELECT p.email FROM risorse_uffici x JOIN uffici_persone p ON p.ufficio_id = x.ufficio_id WHERE x.risorsa_id = ? AND x.riceve_notifiche = 1', [$risorsaId]) as $r) {
            $e[] = (string) $r['email'];
        }

        return array_values(array_unique($e));
    }

    /**
     * Gli uffici responsabili della risorsa, con nome e numero di persone.
     *
     * @return list<array<string, mixed>>
     */
    public function uffici(int $risorsaId): array
    {
        return $this->db->righe('SELECT x.*, u.nome, (SELECT COUNT(*) FROM uffici_persone p WHERE p.ufficio_id = u.id) AS n_persone FROM risorse_uffici x JOIN uffici u ON u.id = x.ufficio_id WHERE x.risorsa_id = ? ORDER BY u.nome', [$risorsaId]);
    }

    public function aggiungiUfficio(int $risorsaId, int $ufficioId, bool $notifiche, bool $approva): bool
    {
        return $this->db->esegui('INSERT IGNORE INTO risorse_uffici (risorsa_id, ufficio_id, riceve_notifiche, puo_approvare) VALUES (?, ?, ?, ?)', [$risorsaId, $ufficioId, $notifiche ? 1 : 0, $approva ? 1 : 0]) === 1;
    }

    public function impostaUfficio(int $id, bool $notifiche, bool $approva): void
    {
        $this->db->esegui('UPDATE risorse_uffici SET riceve_notifiche = ?, puo_approvare = ? WHERE id = ?', [$notifiche ? 1 : 0, $approva ? 1 : 0, $id]);
    }

    /** @return array<string, mixed>|null */
    public function ufficioPerId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM risorse_uffici WHERE id = ?', [$id]);
    }

    public function togliUfficio(int $id): void
    {
        $this->db->esegui('DELETE FROM risorse_uffici WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null la riga del responsabile con questa email per la risorsa (come persona o come membro di un ufficio responsabile: permessi al massimo dei due) */
    public function perEmail(int $risorsaId, string $email): ?array
    {
        $email = strtolower($email);
        $riga = $this->db->riga('SELECT * FROM risorse_responsabili WHERE risorsa_id = ? AND email = ?', [$risorsaId, $email]);
        foreach ($this->db->righe('SELECT x.riceve_notifiche, x.puo_approvare FROM risorse_uffici x JOIN uffici_persone p ON p.ufficio_id = x.ufficio_id WHERE x.risorsa_id = ? AND p.email = ?', [$risorsaId, $email]) as $u) {
            $riga = $riga === null
                ? ['id' => 0, 'risorsa_id' => $risorsaId, 'email' => $email, 'riceve_notifiche' => (int) $u['riceve_notifiche'], 'puo_approvare' => (int) $u['puo_approvare']]
                : array_merge($riga, ['riceve_notifiche' => max((int) $riga['riceve_notifiche'], (int) $u['riceve_notifiche']), 'puo_approvare' => max((int) $riga['puo_approvare'], (int) $u['puo_approvare'])]);
        }

        return $riga;
    }

    /**
     * Le risorse di cui la persona (per email) è responsabile, con le richieste da approvare e le prenotazioni future.
     *
     * @return list<array<string, string|null>>
     */
    public function risorseDi(string $email): array
    {
        $campi = "r.id, r.nome, r.tipo, r.luogo, r.pagina_id, r.attiva, r.diario_attivo, x.puo_approvare, x.riceve_notifiche,
                    (SELECT COUNT(*) FROM prenotazioni_risorse p WHERE p.risorsa_id = r.id AND p.stato = 'da_approvare' AND p.fine >= NOW()) AS da_approvare,
                    (SELECT COUNT(*) FROM prenotazioni_risorse p WHERE p.risorsa_id = r.id AND p.stato = 'confermata' AND p.fine >= NOW()) AS future";
        $email = strtolower($email);
        $per = [];
        foreach (Righe::testo($this->db->righe("SELECT $campi FROM risorse_responsabili x JOIN risorse r ON r.id = x.risorsa_id WHERE x.email = ?", [$email])) as $r) {
            $per[(int) $r['id']] = $r;
        }
        foreach (Righe::testo($this->db->righe("SELECT $campi FROM risorse_uffici x JOIN risorse r ON r.id = x.risorsa_id JOIN uffici_persone up ON up.ufficio_id = x.ufficio_id WHERE up.email = ?", [$email])) as $r) {
            $id = (int) $r['id'];
            if (isset($per[$id])) {
                $per[$id]['puo_approvare'] = (string) max((int) $per[$id]['puo_approvare'], (int) $r['puo_approvare']);
                $per[$id]['riceve_notifiche'] = (string) max((int) $per[$id]['riceve_notifiche'], (int) $r['riceve_notifiche']);
            } else {
                $per[$id] = $r;
            }
        }
        usort($per, static fn (array $a, array $b): int => strcmp((string) $a['nome'], (string) $b['nome']));

        return array_values($per);
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
