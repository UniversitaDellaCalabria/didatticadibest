<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Eventi\Righe;

/** Consigli dei corsi di studio (didattica_consigli) con i loro referenti e componenti (didattica_consigli_persone). */
final class ConsiglioRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Consigli in ordine di visualizzazione: [id => riga].
     *
     * @return array<int, array<string, string|null>>
     */
    public function tutti(bool $soloAttivi = false): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT * FROM didattica_consigli' . ($soloAttivi ? ' WHERE attivo = 1' : '') . ' ORDER BY ordine, nome')) as $x) {
            $out[(int) $x['id']] = $x;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM didattica_consigli WHERE id = ?', [$id]);
    }

    /**
     * Componenti (ordinati per qualifica come nel verbale) o referenti del consiglio.
     *
     * @return list<array<string, mixed>>
     */
    public function persone(int $cid, string $ruolo = 'componente'): array
    {
        $el = $this->db->righe('SELECT * FROM didattica_consigli_persone WHERE consiglio_id = ? AND ruolo = ? ORDER BY ordine, nominativo', [$cid, $ruolo]);
        $ord = array_flip(Costanti::QUALIFICHE_CONSIGLIO);
        usort($el, fn ($a, $b) => ($ord[$a['qualifica']] ?? 50) <=> ($ord[$b['qualifica']] ?? 50) ?: strcmp((string) $a['qualifica'], (string) $b['qualifica']) ?: (int) $a['ordine'] <=> (int) $b['ordine'] ?: strcmp($a['nominativo'], $b['nominativo']));

        return $el;
    }

    /** @return array<string, mixed>|null una persona di un consiglio (per id) */
    public function persona(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM didattica_consigli_persone WHERE id = ?', [$id]);
    }

    /**
     * Consiglio, email e scheda dell'anagrafe di tutti i referenti.
     *
     * @return list<array<string, string|null>>
     */
    public function referenti(): array
    {
        return Righe::testo($this->db->righe("SELECT consiglio_id, email, persona_id FROM didattica_consigli_persone WHERE ruolo = 'referente'"));
    }

    /**
     * Crea (id = 0) o aggiorna un consiglio. Ritorna l'id.
     *
     * @param array<string, string> $f nome, coordinatore, segretario, luogo, odg
     */
    public function salva(int $id, array $f, string $corsiJson, int $attivo, int $ordine): int
    {
        if ($id) {
            $this->db->esegui('UPDATE didattica_consigli SET nome=?, corsi=?, coordinatore=?, segretario=?, luogo=?, odg=?, attivo=?, ordine=? WHERE id=?', [$f['nome'], $corsiJson, $f['coordinatore'], $f['segretario'], $f['luogo'], $f['odg'], $attivo, $ordine, $id]);

            return $id;
        }

        return $this->db->inserisci('INSERT INTO didattica_consigli (nome, corsi, coordinatore, segretario, luogo, odg, attivo, ordine) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$f['nome'], $corsiJson, $f['coordinatore'], $f['segretario'], $f['luogo'], $f['odg'], $attivo, $ordine]);
    }

    /** Il posto successivo nell'elenco delle persone del consiglio. */
    public function ordineSuccessivo(int $cid): int
    {
        return (int) ($this->db->valore('SELECT COALESCE(MAX(ordine), 0) + 1 FROM didattica_consigli_persone WHERE consiglio_id = ?', [$cid]) ?? 1);
    }

    /** L'ultimo posto occupato nell'elenco dei componenti (0 se non ce ne sono). */
    public function ultimoOrdine(int $cid): int
    {
        return (int) $this->db->valore('SELECT COALESCE(MAX(ordine), 0) FROM didattica_consigli_persone WHERE consiglio_id = ?', [$cid]);
    }

    /** Aggiunge una persona al consiglio. Falso se il salvataggio non riesce. */
    public function aggiungiPersona(int $cid, string $ruolo, ?string $personaId, string $email, string $nominativo, string $qualifica, int $ordine): bool
    {
        return $this->db->esegui(
            'INSERT INTO didattica_consigli_persone (consiglio_id, ruolo, persona_id, email, nominativo, qualifica, ordine) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$cid, $ruolo, $personaId, $email, $nominativo, $qualifica, $ordine]
        ) >= 0;
    }

    public function eliminaPersona(int $id): void
    {
        $this->db->esegui('DELETE FROM didattica_consigli_persone WHERE id = ?', [$id]);
    }

    /** Gruppo del verbale e posto di un componente. */
    public function aggiornaQualifica(int $cid, int $personaId, string $qualifica, int $ordine): void
    {
        $this->db->esegui("UPDATE didattica_consigli_persone SET qualifica = ?, ordine = ? WHERE id = ? AND consiglio_id = ? AND ruolo = 'componente'", [$qualifica, $ordine, $personaId, $cid]);
    }
}
