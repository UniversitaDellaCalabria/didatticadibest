<?php

declare(strict_types=1);

namespace App\Assistenza\Push;

use App\Core\Database;
use App\Eventi\Righe;

/** I recapiti push dei browser (tabella push_sottoscrizioni). */
final class SottoscrizioniRepository
{
    public const OPERATORE = 'operatore';
    public const UTENTE = 'utente';
    public const PERSONA = 'persona';   // chi attiva gli avvisi sul dispositivo per i compiti urgenti di «Da fare per te» (dal profilo)
    /** Dopo tanti invii falliti di fila una sottoscrizione si elimina. */
    public const MAX_ERRORI = 5;

    public function __construct(private Database $db)
    {
    }

    public static function impronta(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Salva o aggiorna il recapito di un browser (lo stesso indirizzo passa da una persona all'altra se cambia chi usa il browser).
     *
     * @param array{endpoint: string, p256dh: string, auth: string} $s
     */
    public function salva(array $s, string $ruolo, ?int $utenteId, ?int $conversazioneId, string $agente, string $quando): void
    {
        $this->db->esegui(
            'INSERT INTO push_sottoscrizioni (endpoint_hash, endpoint, p256dh, auth, ruolo, utente_id, conversazione_id, agente, creato_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth), ruolo = VALUES(ruolo), utente_id = VALUES(utente_id),
             conversazione_id = VALUES(conversazione_id), agente = VALUES(agente), errori = 0',
            [self::impronta($s['endpoint']), $s['endpoint'], $s['p256dh'], $s['auth'], $ruolo, $utenteId, $conversazioneId, mb_substr($agente, 0, 200), $quando]
        );
    }

    /** @return array<string, string|null>|null */
    public function perEndpoint(string $endpoint): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM push_sottoscrizioni WHERE endpoint_hash = ?', [self::impronta($endpoint)]));
    }

    public function eliminaPerEndpoint(string $endpoint): void
    {
        $this->db->esegui('DELETE FROM push_sottoscrizioni WHERE endpoint_hash = ?', [self::impronta($endpoint)]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM push_sottoscrizioni WHERE id = ?', [$id]);
    }

    /** @return list<array<string, string|null>> */
    public function operatori(): array
    {
        return Righe::testo($this->db->righe("SELECT * FROM push_sottoscrizioni WHERE ruolo = 'operatore'"));
    }

    /** @return list<array<string, string|null>> i dispositivi iscritti agli avvisi dei compiti (una riga per dispositivo) */
    public function persone(): array
    {
        return Righe::testo($this->db->righe("SELECT * FROM push_sottoscrizioni WHERE ruolo = 'persona' AND utente_id IS NOT NULL"));
    }

    /** @return list<array<string, string|null>> */
    public function perUtente(int $utenteId, string $ruolo): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM push_sottoscrizioni WHERE ruolo = ? AND utente_id = ?', [$ruolo, $utenteId]));
    }

    /** I browser di chi ha chiesto aiuto in questa conversazione (con il login o col link personale). @return list<array<string, string|null>> */
    public function perConversazione(int $conversazioneId, ?int $utenteId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT * FROM push_sottoscrizioni WHERE ruolo = 'utente' AND (conversazione_id = ?" . ($utenteId ? ' OR utente_id = ' . (int) $utenteId : '') . ')',
            [$conversazioneId]
        ));
    }

    /** Preferenze di un operatore (valgono per tutti i suoi browser): silenzia fino a quando, solo le chat in carico a lui. */
    public function preferenze(int $utenteId, ?string $silenziaFino, bool $soloMie): void
    {
        $this->db->esegui("UPDATE push_sottoscrizioni SET silenzia_fino = ?, solo_mie = ? WHERE ruolo = 'operatore' AND utente_id = ?", [$silenziaFino, $soloMie ? 1 : 0, $utenteId]);
    }

    /** Esito di un invio: se non è andato bene si conta l'errore e dopo troppi errori di fila il recapito si elimina. */
    public function esito(int $id, bool $ok, string $quando): void
    {
        if ($ok) {
            $this->db->esegui('UPDATE push_sottoscrizioni SET ultimo_invio_il = ?, errori = 0 WHERE id = ?', [$quando, $id]);

            return;
        }
        $this->db->esegui('UPDATE push_sottoscrizioni SET errori = errori + 1 WHERE id = ?', [$id]);
        $this->db->esegui('DELETE FROM push_sottoscrizioni WHERE id = ? AND errori >= ?', [$id, self::MAX_ERRORI]);
    }
}
