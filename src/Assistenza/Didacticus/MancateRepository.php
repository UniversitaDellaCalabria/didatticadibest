<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Le domande a cui Didacticus non ha saputo rispondere (tipo «nessuna») o la cui risposta non è servita (tipo «non_utile»): le legge chi gestisce la base di
 * conoscenza e le trasforma in voci con un clic, così Didacticus impara da quello che gli viene chiesto davvero.
 */
final class MancateRepository
{
    public const NESSUNA = 'nessuna';
    public const NON_UTILE = 'non_utile';
    public const DA_VEDERE = 'da_vedere';
    public const FATTA = 'fatta';
    public const IGNORATA = 'ignorata';

    public function __construct(private Database $db)
    {
    }

    public function registra(int $conversazioneId, string $domanda, string $argomento, string $tipo, ?int $voceId, string $quando): int
    {
        return $this->db->inserisci(
            'INSERT INTO didacticus_mancate (conversazione_id, domanda, argomento, tipo, voce_id, creato_il) VALUES (?, ?, ?, ?, ?, ?)',
            [$conversazioneId, mb_substr($domanda, 0, 1500), $argomento, $tipo === self::NON_UTILE ? self::NON_UTILE : self::NESSUNA, $voceId, $quando]
        );
    }

    /** @return list<array<string, string|null>> */
    public function elenco(string $stato = self::DA_VEDERE): array
    {
        return Righe::testo($this->db->righe(
            'SELECT m.*, v.titolo AS voce_titolo FROM didacticus_mancate m LEFT JOIN didacticus_voci v ON v.id = m.voce_id WHERE m.stato = ? ORDER BY m.creato_il DESC LIMIT 300',
            [$stato]
        ));
    }

    /** @return array<string, string|null>|null */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM didacticus_mancate WHERE id = ?', [$id]));
    }

    public function contaDaVedere(): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM didacticus_mancate WHERE stato = 'da_vedere'");
    }

    public function segna(int $id, string $stato): void
    {
        $this->db->esegui('UPDATE didacticus_mancate SET stato = ? WHERE id = ?', [in_array($stato, [self::FATTA, self::IGNORATA, self::DA_VEDERE], true) ? $stato : self::DA_VEDERE, $id]);
    }

    /** La prima risposta data da un operatore nella chat di quella domanda (se la chat esiste ancora): serve da bozza per la nuova voce. */
    public function rispostaOperatore(int $conversazioneId): string
    {
        return (string) $this->db->valore(
            "SELECT testo FROM assistenza_messaggi WHERE conversazione_id = ? AND da = 'operatore' AND tipo = 'messaggio' ORDER BY id LIMIT 1",
            [$conversazioneId]
        );
    }

    public function eliminaPerConversazione(int $conversazioneId): void
    {
        $this->db->esegui('DELETE FROM didacticus_mancate WHERE conversazione_id = ?', [$conversazioneId]);
    }
}
