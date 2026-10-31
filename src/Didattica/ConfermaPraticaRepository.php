<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;

/** Le conferme richieste ai docenti durante l'iter di una pratica (pratiche_conferme): attestati di frequenza alle lezioni e di esame sostenuto. */
final class ConfermaPraticaRepository
{
    public function __construct(private Database $db)
    {
    }

    public function crea(int $praticaId, int $passo, string $nome, string $email, string $token, ?string $personaId = null): int
    {
        return $this->db->inserisci(
            'INSERT INTO pratiche_conferme (pratica_id, passo, nome, email, token, persona_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$praticaId, $passo, mb_substr($nome, 0, 200), strtolower($email), $token, $personaId !== '' ? $personaId : null]
        );
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $token === '' ? null : $this->db->riga('SELECT * FROM pratiche_conferme WHERE token = ?', [$token]);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM pratiche_conferme WHERE id = ?', [$id]);
    }

    /**
     * Le conferme dell'ultima richiesta della pratica (le annullate di una richiesta precedente non contano).
     *
     * @return list<array<string, mixed>>
     */
    public function dellaPratica(int $praticaId): array
    {
        return $this->db->righe("SELECT * FROM pratiche_conferme WHERE pratica_id = ? AND stato <> 'annullata' ORDER BY id", [$praticaId]);
    }

    /** @return list<array<string, mixed>> */
    public function inAttesa(int $praticaId): array
    {
        return $this->db->righe("SELECT * FROM pratiche_conferme WHERE pratica_id = ? AND stato = 'attesa' ORDER BY id", [$praticaId]);
    }

    /** Quante conferme sono state richieste (anche annullate): la pratica non si può più modificare in silenzio. */
    public function richieste(int $praticaId): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM pratiche_conferme WHERE pratica_id = ?', [$praticaId]);
    }

    public function annullaAttese(int $praticaId): void
    {
        $this->db->esegui("UPDATE pratiche_conferme SET stato = 'annullata' WHERE pratica_id = ? AND stato = 'attesa'", [$praticaId]);
    }

    /** Annulla tutte le conferme della pratica, anche già date (la pratica è stata modificata: si riparte). */
    public function annullaTutte(int $praticaId): void
    {
        $this->db->esegui("UPDATE pratiche_conferme SET stato = 'annullata' WHERE pratica_id = ? AND stato <> 'annullata'", [$praticaId]);
    }

    public function segna(int $id, string $stato, string $motivo, string $accessoJson): void
    {
        $this->db->esegui('UPDATE pratiche_conferme SET stato = ?, motivo = ?, accesso_json = ?, esito_il = NOW() WHERE id = ?', [$stato, mb_substr($motivo, 0, 500), $accessoJson, $id]);
    }

    public function segnaSollecito(int $id): void
    {
        $this->db->esegui('UPDATE pratiche_conferme SET solleciti = solleciti + 1, sollecito_il = NOW() WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> le conferme in attesa con i giorni dalla richiesta o dall'ultimo sollecito */
    public function attese(): array
    {
        return $this->db->righe(
            "SELECT c.*, p.modulo_id, DATEDIFF(NOW(), COALESCE(c.sollecito_il, c.richiesta_il)) AS giorni_ultimo FROM pratiche_conferme c JOIN pratiche p ON p.id = c.pratica_id
             WHERE c.stato = 'attesa' AND p.stato IN ('inviata', 'in_lavorazione')"
        );
    }

    /** @return list<array<string, mixed>> le conferme in attesa per un docente (email): per la sua pagina «da confermare» */
    public function inAttesaPerEmail(string $email): array
    {
        return $this->db->righe(
            "SELECT c.*, p.codice, p.nome AS studente_nome, p.cognome AS studente_cognome, m.titolo AS modulo_titolo FROM pratiche_conferme c JOIN pratiche p ON p.id = c.pratica_id
             JOIN didattica_moduli m ON m.id = p.modulo_id WHERE c.stato = 'attesa' AND p.stato IN ('inviata', 'in_lavorazione') AND c.email = ? ORDER BY c.richiesta_il",
            [strtolower($email)]
        );
    }

    /**
     * Le conferme in attesa di una persona: per l'email a cui è arrivato il link o per la scheda dell'anagrafe (se entra con un altro indirizzo).
     *
     * @return list<array<string, mixed>>
     */
    public function inAttesaDi(string $email, string $personaId): array
    {
        return $this->db->righe(
            "SELECT c.*, p.codice, p.nome AS studente_nome, p.cognome AS studente_cognome, m.titolo AS modulo_titolo, DATEDIFF(NOW(), c.richiesta_il) AS gg FROM pratiche_conferme c JOIN pratiche p ON p.id = c.pratica_id
             JOIN didattica_moduli m ON m.id = p.modulo_id WHERE c.stato = 'attesa' AND p.stato IN ('inviata', 'in_lavorazione') AND ((c.email <> '' AND c.email = ?) OR (? <> '' AND c.persona_id = ?)) ORDER BY c.richiesta_il",
            [strtolower($email), $personaId, $personaId]
        );
    }
}
