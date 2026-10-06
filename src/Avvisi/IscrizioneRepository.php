<?php

declare(strict_types=1);

namespace App\Avvisi;

use App\Core\Database;

/** Query degli avvisi per email: iscrizioni (avvisi_iscrizioni) ed eventi già annunciati (avvisi_eventi). */
final class IscrizioneRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function rigaPerEmail(string $email): ?array
    {
        return $this->db->riga('SELECT * FROM avvisi_iscrizioni WHERE email = ?', [$email]);
    }

    /** @return array<string, mixed>|null */
    public function rigaPerToken(string $token): ?array
    {
        return $this->db->riga('SELECT * FROM avvisi_iscrizioni WHERE token = ?', [$token]);
    }

    public function perToken(string $token): ?Iscrizione
    {
        $r = $this->rigaPerToken($token);

        return $r ? Iscrizione::daRiga($r) : null;
    }

    /** @param list<string> $ambiti */
    public function crea(string $email, array $ambiti, bool $scuole, string $token, ?int $utenteId, bool $confermata): void
    {
        $this->db->esegui(
            'INSERT INTO avvisi_iscrizioni (email, ambiti, scuole, token, utente_id, confermata_il) VALUES (?, ?, ?, ?, ?, ' . ($confermata ? 'NOW()' : 'NULL') . ')',
            [$email, implode(',', $ambiti), $scuole ? 1 : 0, $token, $utenteId]
        );
    }

    /**
     * Aggiorna gli argomenti; $confermaOra: conferma subito (chi è entrato con la stessa email).
     *
     * @param list<string> $ambiti
     */
    public function aggiorna(int $id, array $ambiti, bool $scuole, ?int $utenteId, bool $confermaOra): void
    {
        $this->db->esegui(
            'UPDATE avvisi_iscrizioni SET ambiti = ?, scuole = ?, utente_id = COALESCE(?, utente_id)' . ($confermaOra ? ', confermata_il = COALESCE(confermata_il, NOW())' : '') . ' WHERE id = ?',
            [implode(',', $ambiti), $scuole ? 1 : 0, $utenteId, $id]
        );
    }

    public function conferma(int $id): bool
    {
        return $this->db->esegui('UPDATE avvisi_iscrizioni SET confermata_il = COALESCE(confermata_il, NOW()) WHERE id = ?', [$id]) >= 0;
    }

    public function elimina(int $id): bool
    {
        return $this->db->esegui('DELETE FROM avvisi_iscrizioni WHERE id = ?', [$id]) > 0;
    }

    /** @return list<Iscrizione> */
    public function confermate(): array
    {
        return array_map(Iscrizione::daRiga(...), $this->db->righe('SELECT * FROM avvisi_iscrizioni WHERE confermata_il IS NOT NULL'));
    }

    public function eliminaNonConfermate(int $giorni): void
    {
        $this->db->esegui('DELETE FROM avvisi_iscrizioni WHERE confermata_il IS NULL AND creata_il < NOW() - INTERVAL ? DAY', [$giorni]);
    }

    public function segnaInvio(int $id): void
    {
        $this->db->esegui('UPDATE avvisi_iscrizioni SET ultimo_invio_il = NOW() WHERE id = ?', [$id]);
    }

    public function nessunEventoAnnunciato(): bool
    {
        return !(int) $this->db->valore('SELECT COUNT(*) FROM avvisi_eventi');
    }

    /** @return list<int> */
    public function eventiAnnunciati(): array
    {
        return array_map('intval', array_column($this->db->righe('SELECT evento_id FROM avvisi_eventi'), 'evento_id'));
    }

    public function segnaAnnunciato(int $eventoId, int $destinatari = 0): void
    {
        $this->db->esegui('INSERT IGNORE INTO avvisi_eventi (evento_id, destinatari) VALUES (?, ?)', [$eventoId, $destinatari]);
    }
}
