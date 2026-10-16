<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;
use App\Eventi\Righe;

/** Messaggi tra l'ufficio e la scuola sulla convenzione online (tabella convenzioni_messaggi). */
final class MessaggiConvenzioneRepository
{
    public const UFFICIO = 'ufficio';
    public const SCUOLA = 'scuola';
    public const TIPO_PROMEMORIA = 'promemoria';
    public const TIPO_MESSAGGIO = 'messaggio';

    public function __construct(private Database $db)
    {
    }

    public function aggiungi(int $compilazioneId, string $da, string $tipo, string $testo, string $autore): int
    {
        return $this->db->inserisci(
            'INSERT INTO convenzioni_messaggi (compilazione_id, da, tipo, testo, autore) VALUES (?, ?, ?, ?, ?)',
            [$compilazioneId, $da, $tipo, mb_substr($testo, 0, 2000), mb_substr($autore, 0, 150)]
        );
    }

    /**
     * La conversazione, dal più vecchio al più recente.
     *
     * @return list<array<string, string|null>>
     */
    public function della(int $compilazioneId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_messaggi WHERE compilazione_id = ? ORDER BY creato_il, id', [$compilazioneId]));
    }

    /** Data dell'ultimo messaggio del tipo indicato mandato dall'ufficio (null se non ce ne sono). */
    public function ultimoDelTipo(int $compilazioneId, string $tipo): ?string
    {
        $v = $this->db->valore('SELECT MAX(creato_il) FROM convenzioni_messaggi WHERE compilazione_id = ? AND da = ? AND tipo = ?', [$compilazioneId, self::UFFICIO, $tipo]);

        return $v === null || $v === '' ? null : (string) $v;
    }

    /** Quanti messaggi mandati dalla scuola nelle ultime 24 ore (limite contro l'abuso della pagina pubblica). */
    public function dellaScuolaOggi(int $compilazioneId): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM convenzioni_messaggi WHERE compilazione_id = ? AND da = 'scuola' AND creato_il >= NOW() - INTERVAL 1 DAY", [$compilazioneId]);
    }

    /** Segna come letti i messaggi dell'altra parte: $lettore è chi apre la conversazione (ufficio o scuola). */
    public function segnaLetti(int $compilazioneId, string $lettore): void
    {
        $mittente = $lettore === self::UFFICIO ? self::SCUOLA : self::UFFICIO;
        $this->db->esegui('UPDATE convenzioni_messaggi SET letto_il = NOW() WHERE compilazione_id = ? AND da = ? AND letto_il IS NULL', [$compilazioneId, $mittente]);
    }

    /**
     * Risposte della scuola ancora da leggere, per compilazione: [compilazione_id => numero].
     *
     * @return array<int, int>
     */
    public function nonLetteDallUfficio(): array
    {
        $out = [];
        foreach ($this->db->righe("SELECT compilazione_id, COUNT(*) n FROM convenzioni_messaggi WHERE da = 'scuola' AND letto_il IS NULL GROUP BY compilazione_id") as $r) {
            $out[(int) $r['compilazione_id']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Ultimo messaggio per compilazione: [compilazione_id => riga], per le etichette dell'elenco.
     *
     * @return array<int, array<string, string|null>>
     */
    public function ultimiPerCompilazione(): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT m.* FROM convenzioni_messaggi m JOIN (SELECT compilazione_id, MAX(id) mid FROM convenzioni_messaggi GROUP BY compilazione_id) x ON x.mid = m.id')) as $r) {
            $out[(int) $r['compilazione_id']] = $r;
        }

        return $out;
    }
}
