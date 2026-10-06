<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;

/**
 * Query che legano l'anagrafe del personale al resto del portale: utenti collegati alla persona (login), gruppi automatici,
 * abilitazioni in attesa, referenti di eventi e progetti, gestori e sportelli di ricevimento.
 * Le tabelle sono di altri moduli: qui solo le letture e gli aggiornamenti che servono all'anagrafe.
 */
final class CollegamentiRepository
{
    public function __construct(private Database $db)
    {
    }

    // ---------------------------------------------------------------- utenti

    /** @return array<string, mixed>|null id, email, ruoli_secondari, persona_id */
    public function utente(int $id): ?array
    {
        return $this->db->riga('SELECT id, email, ruoli_secondari, persona_id FROM utenti WHERE id = ? LIMIT 1', [$id]);
    }

    public function collegaPersona(int $utenteId, ?string $personaId): void
    {
        $this->db->esegui('UPDATE utenti SET persona_id = ? WHERE id = ?', [$personaId, $utenteId]);
    }

    public function salvaRuoliSecondari(int $utenteId, string $ruoli): void
    {
        $this->db->esegui('UPDATE utenti SET ruoli_secondari = ? WHERE id = ?', [$ruoli, $utenteId]);
    }

    /** @return list<array<string, mixed>> utenti collegati a una persona: id, persona_id, ultimo_accesso */
    public function utentiCollegati(): array
    {
        return $this->db->righe('SELECT id, persona_id, ultimo_accesso FROM utenti WHERE persona_id IS NOT NULL');
    }

    /**
     * Utenti con persona collegata, tra quelli indicati
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>> id, nome, cognome, persona_id
     */
    public function utentiConPersona(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        return $this->db->righe('SELECT id, nome, cognome, persona_id FROM utenti WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') AND persona_id IS NOT NULL');
    }

    /** @return array<string, int> id dei gruppi (tabella ruoli) per nome */
    public function ruoliPerNome(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT id, nome FROM ruoli') as $r) {
            $out[(string) $r['nome']] = (int) $r['id'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> abilitazioni date prima del primo accesso a questa email */
    public function abilitazioniInAttesa(string $email): array
    {
        return $this->db->righe('SELECT * FROM abilitazioni_attesa WHERE email = ?', [$email]);
    }

    public function eliminaAbilitazioneInAttesa(int $id): void
    {
        $this->db->esegui('DELETE FROM abilitazioni_attesa WHERE id = ?', [$id]);
    }

    // ---------------------------------------------------------------- gestori, referenti e sportelli

    /** @return list<array<string, mixed>> campi dei gestori delle aree: gestore_utente_id, gestori_utenti_ids, permessi_gestori_json */
    public function gestoriAree(): array
    {
        return $this->db->righe('SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi');
    }

    /** @return list<array<string, mixed>> campi dei gestori degli eventi non archiviati: gestori_utenti_ids, permessi_gestori_json */
    public function gestoriEventi(): array
    {
        return $this->db->righe('SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE archiviato = 0');
    }

    /** @return list<int> utenti con un'abilitazione a un perimetro */
    public function utentiAbilitati(): array
    {
        return array_map('intval', array_column($this->db->righe('SELECT DISTINCT utente_id FROM abilitazioni_ambito'), 'utente_id'));
    }

    /** @return list<array<string, mixed>> eventi e progetti non archiviati con referenti dall'anagrafe: id, titolo, tipo, pagina_id, referenti_json */
    public function referentiInCorso(): array
    {
        return $this->db->righe("SELECT e.id, e.titolo, e.tipo, e.pagina_id, d.referenti_json FROM progetti_dettagli d JOIN eventi e ON e.id = d.evento_id WHERE e.archiviato = 0 AND d.referenti_json LIKE '%persona_id%'");
    }

    /**
     * Attività delle aree visibili che citano la persona tra i referenti (da verificare nel JSON), le concluse in fondo
     *
     * @return list<array<string, mixed>>
     */
    public function attivitaConReferente(string $personaId): array
    {
        $like = '%"persona_id":"' . addcslashes(str_replace('"', '', $personaId), '%_\\') . '"%';

        return $this->db->righe('SELECT e.id, e.titolo, e.tipo, e.archiviato, e.locandina_path, p.slug, p.titolo AS area, d.referenti_json, d.data_inizio, d.data_fine
                          FROM progetti_dettagli d JOIN eventi e ON e.id = d.evento_id JOIN pagine_eventi p ON p.id = e.pagina_id
                          WHERE d.referenti_json LIKE ? AND IFNULL(p.visibile, 1) = 1 ORDER BY e.archiviato ASC, e.id DESC', [$like]);
    }

    /** @return list<array<string, mixed>> sportelli di ricevimento attivi della persona in aree visibili: id, nome, luogo, slug */
    public function sportelliRicevimento(string $personaId): array
    {
        return $this->db->righe('SELECT r.id, r.nome, r.luogo, p.slug FROM risorse r JOIN pagine_eventi p ON p.id = r.pagina_id WHERE r.persona_id = ? AND r.attiva = 1 AND IFNULL(p.visibile, 1) = 1', [$personaId]);
    }

    /** @return list<array<string, mixed>> eventi e progetti collegati a un insegnamento? no: numero di attività non archiviate per insegnamento */
    public function attivitaPerInsegnamento(): array
    {
        return $this->db->righe('SELECT d.insegnamento_id, COUNT(*) n FROM progetti_dettagli d JOIN eventi e ON e.id = d.evento_id WHERE d.insegnamento_id IS NOT NULL AND e.archiviato = 0 GROUP BY d.insegnamento_id');
    }
}
