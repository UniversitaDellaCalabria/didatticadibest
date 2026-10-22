<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Core\Database;
use App\Eventi\Righe;

/** I messaggi delle conversazioni di assistenza (tabella assistenza_messaggi). */
final class MessaggiAssistenzaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed>|null $extra link, allegati e suggerimenti di Didacticus (JSON) */
    public function aggiungi(int $conversazioneId, string $da, string $tipo, string $testo, string $autore, string $quando, ?array $extra = null): int
    {
        return $this->db->inserisci(
            'INSERT INTO assistenza_messaggi (conversazione_id, da, tipo, testo, autore, creato_il, extra) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$conversazioneId, $da, $tipo, mb_substr($testo, 0, 4000), mb_substr($autore, 0, Costanti::MAX_NOME), $quando, $extra ? (string) json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]
        );
    }

    /**
     * I messaggi dal più vecchio, solo quelli con id maggiore di $dopoId (per chiedere solo i nuovi). Le note interne le vedono solo gli operatori.
     *
     * @return list<array<string, string|null>>
     */
    public function della(int $conversazioneId, int $dopoId = 0, bool $conNote = false): array
    {
        return Righe::testo($this->db->righe(
            'SELECT * FROM assistenza_messaggi WHERE conversazione_id = ? AND id > ?' . ($conNote ? '' : " AND tipo = 'messaggio'") . ' ORDER BY id',
            [$conversazioneId, $dopoId]
        ));
    }

    /**
     * Segna come letti i messaggi dell'altra parte: se legge l'operatore quelli della persona, se legge la persona quelli di operatori, Didacticus e sistema.
     */
    public function segnaLetti(int $conversazioneId, string $lettore, string $quando): void
    {
        $this->db->esegui(
            'UPDATE assistenza_messaggi SET letto_il = ? WHERE conversazione_id = ? AND letto_il IS NULL AND tipo = \'messaggio\' AND da '
            . ($lettore === Costanti::DA_OPERATORE ? "= 'utente'" : "<> 'utente'"),
            [$quando, $conversazioneId]
        );
    }

    /** Messaggi dell'altra parte ancora da leggere, per chi legge ($lettore = operatore o utente). */
    public function nonLetti(int $conversazioneId, string $lettore): int
    {
        return (int) $this->db->valore(
            "SELECT COUNT(*) FROM assistenza_messaggi WHERE conversazione_id = ? AND letto_il IS NULL AND tipo = 'messaggio' AND da " . ($lettore === Costanti::DA_OPERATORE ? "= 'utente'" : "<> 'utente'"),
            [$conversazioneId]
        );
    }

    /** Quante conversazioni aperte hanno messaggi della persona da leggere (il numero del pulsante «Messaggi»). */
    public function conversazioniDaLeggere(): int
    {
        return (int) $this->db->valore(
            "SELECT COUNT(DISTINCT m.conversazione_id) FROM assistenza_messaggi m JOIN assistenza_conversazioni c ON c.id = m.conversazione_id
             WHERE m.da = 'utente' AND m.tipo = 'messaggio' AND m.letto_il IS NULL AND c.stato IN ('nuova', 'assegnata')"
        );
    }

    /** Messaggi di un certo mittente dalla data indicata (limite contro l'abuso). */
    public function inviatiDa(int $conversazioneId, string $da, string $dal): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM assistenza_messaggi WHERE conversazione_id = ? AND da = ? AND tipo = \'messaggio\' AND creato_il >= ?', [$conversazioneId, $da, $dal]);
    }

    /** @return array<string, string|null>|null */
    public function ultimo(int $conversazioneId): ?array
    {
        return Righe::riga($this->db->riga("SELECT * FROM assistenza_messaggi WHERE conversazione_id = ? AND tipo = 'messaggio' ORDER BY id DESC LIMIT 1", [$conversazioneId]));
    }
}
