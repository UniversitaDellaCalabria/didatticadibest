<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;

/** Le firme richieste durante l'iter di una pratica (pratiche_firme): di una persona indicata nel modulo o del Direttore. */
final class FirmaPraticaRepository
{
    public function __construct(private Database $db)
    {
    }

    public function crea(int $praticaId, int $passo, string $tipo, string $nome, string $email, string $token, string $fileOrigine, ?string $personaId = null): int
    {
        return $this->db->inserisci(
            'INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token, file_origine, persona_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$praticaId, $passo, $tipo, $nome, strtolower($email), $token, $fileOrigine, $personaId !== '' ? $personaId : null]
        );
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM pratiche_firme WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $token === '' ? null : $this->db->riga('SELECT * FROM pratiche_firme WHERE token = ?', [$token]);
    }

    /** @return array<string, mixed>|null la richiesta in attesa di firma della pratica */
    public function inAttesa(int $praticaId): ?array
    {
        return $this->db->riga("SELECT * FROM pratiche_firme WHERE pratica_id = ? AND stato = 'attesa' ORDER BY id DESC LIMIT 1", [$praticaId]);
    }

    /** @return list<array<string, mixed>> tutte le richieste della pratica, dalla più recente */
    public function dellaPratica(int $praticaId): array
    {
        return $this->db->righe('SELECT * FROM pratiche_firme WHERE pratica_id = ? ORDER BY id DESC', [$praticaId]);
    }

    /** Annulla le richieste ancora in attesa (la pratica riparte da un altro passo o viene mandata di nuovo alla firma). */
    public function annullaAttese(int $praticaId): void
    {
        $this->db->esegui("UPDATE pratiche_firme SET stato = 'annullata' WHERE pratica_id = ? AND stato = 'attesa'", [$praticaId]);
    }

    public function segnaFirmata(int $id, string $fileFirmato, string $da): void
    {
        $this->db->esegui("UPDATE pratiche_firme SET stato = 'firmata', file_firmato = ?, firmato_da = ?, firmata_il = NOW() WHERE id = ?", [$fileFirmato, mb_substr($da, 0, 200), $id]);
    }

    public function segnaRifiutata(int $id, string $motivo): void
    {
        $this->db->esegui("UPDATE pratiche_firme SET stato = 'rifiutata', motivo = ?, firmata_il = NOW() WHERE id = ?", [mb_substr($motivo, 0, 500), $id]);
    }

    public function segnaSollecito(int $id): void
    {
        $this->db->esegui('UPDATE pratiche_firme SET solleciti = solleciti + 1, sollecito_il = NOW() WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> le richieste in attesa con i dati per decidere i solleciti (modulo e giorni dalla richiesta) */
    public function attese(): array
    {
        return $this->db->righe(
            "SELECT f.*, p.modulo_id, DATEDIFF(NOW(), COALESCE(f.sollecito_il, f.richiesta_il)) AS giorni_ultimo FROM pratiche_firme f JOIN pratiche p ON p.id = f.pratica_id
             WHERE f.stato = 'attesa' AND p.stato IN ('inviata', 'in_lavorazione')"
        );
    }

    /** @return list<array<string, mixed>> ultimo documento PDF caricato dall'ufficio nella pratica (non estratti del verbale né documenti già firmati) */
    public function ultimoPdfUfficio(int $praticaId): ?array
    {
        return $this->db->riga(
            "SELECT allegato, nome_allegato FROM pratiche_eventi WHERE pratica_id = ? AND autore = 'ufficio' AND allegato LIKE '%.pdf'
               AND nome_allegato NOT LIKE 'Estratto\\_verbale\\_%' AND nome_allegato NOT LIKE 'Firmato\\_%' ORDER BY id DESC LIMIT 1",
            [$praticaId]
        );
    }

    /** @return array<string, mixed>|null il docente (attivo) con questo «Cognome Nome»; null se non c'è o se ce n'è più d'uno */
    public function docentePerNome(string $nome): ?array
    {
        $r = $this->db->righe("SELECT id, cognome, nome, email FROM personale_ateneo WHERE docente = 1 AND attivo = 1 AND TRIM(CONCAT(cognome, ' ', nome)) = ?", [trim($nome)]);

        return count($r) === 1 ? $r[0] : null;
    }
}
