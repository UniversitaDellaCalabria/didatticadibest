<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;
use App\Eventi\Righe;

/** Registro delle convenzioni con le scuole (tabella convenzioni_scuole). */
final class ConvenzioneRepository
{
    /** File che si possono collegare a una convenzione (colonne di convenzioni_scuole). */
    private const COLONNE_FILE = ['file_convenzione', 'file_allegato'];

    public function __construct(private Database $db)
    {
    }

    /** Codice meccanografico in maiuscolo (10 lettere/cifre) oppure null se non è un codice. */
    public static function codiceValido(?string $codice): ?string
    {
        $codice = strtoupper(trim((string) $codice));

        return preg_match('/^[A-Z0-9]{10}$/', $codice) ? $codice : null;
    }

    /**
     * La convenzione della scuola valida per tutto il periodo $dal-$al (la senza scadenza e la più lontana per prime).
     *
     * @return array<string, mixed>|null
     */
    public function valida(string $codice, string $dal, string $al): ?array
    {
        return $this->db->riga(
            'SELECT * FROM convenzioni_scuole WHERE scuola_codice = ? AND (data_stipula IS NULL OR data_stipula <= ?) AND (scadenza IS NULL OR scadenza >= ?)
             ORDER BY (scadenza IS NULL) DESC, scadenza DESC LIMIT 1',
            [$codice, $dal, $al]
        );
    }

    /**
     * Tutte le convenzioni della scuola, dalla più recente ([] se il codice non è valido).
     *
     * @return list<array<string, mixed>>
     */
    public function dellaScuola(?string $codice): array
    {
        $codice = self::codiceValido($codice);
        if ($codice === null) {
            return [];
        }

        return $this->db->righe('SELECT * FROM convenzioni_scuole WHERE scuola_codice = ? ORDER BY (scadenza IS NULL) DESC, scadenza DESC, id DESC', [$codice]);
    }

    /**
     * Una convenzione per id (valori come testo, come li dava $conn->query()).
     *
     * @return array<string, string|null>|null
     */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_scuole WHERE id = ?', [$id]));
    }

    /**
     * Scuola e file collegato di una convenzione, per scaricarlo dal pannello. $colonna: file_convenzione | file_allegato.
     *
     * @return array<string, string|null>|null
     */
    public function fileDi(int $id, string $colonna): ?array
    {
        if (!in_array($colonna, self::COLONNE_FILE, true)) {
            return null;
        }

        return Righe::riga($this->db->riga("SELECT scuola_codice, $colonna AS file FROM convenzioni_scuole WHERE id = ?", [$id]));
    }

    /** Registra una convenzione e ne restituisce l'id (0 se non riesce). */
    public function inserisci(string $codice, ?string $dal, ?string $al, string $protocollo, string $note, ?string $docentiJson, string $autore): int
    {
        return $this->db->inserisci(
            'INSERT INTO convenzioni_scuole (scuola_codice, data_stipula, scadenza, protocollo, note, docenti_json, registrata_da) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$codice, $dal, $al, $protocollo, $note, $docentiJson, $autore]
        );
    }

    /** Modifica una convenzione (e riarma l'avviso di scadenza); false se la query non riesce. */
    public function aggiorna(int $id, string $codice, ?string $dal, ?string $al, string $protocollo, string $note, ?string $docentiJson): bool
    {
        return $this->db->esegui(
            'UPDATE convenzioni_scuole SET scuola_codice = ?, data_stipula = ?, scadenza = ?, protocollo = ?, note = ?, docenti_json = ?, avviso_scadenza_inviato = 0 WHERE id = ?',
            [$codice, $dal, $al, $protocollo, $note, $docentiJson, $id]
        ) >= 0;
    }

    /** Collega il file firmato (percorso già salvato). $colonna: file_convenzione | file_allegato. */
    public function impostaFile(int $id, string $colonna, string $percorso): void
    {
        if (in_array($colonna, self::COLONNE_FILE, true)) {
            $this->db->esegui("UPDATE convenzioni_scuole SET $colonna = ? WHERE id = ?", [$percorso, $id]);
        }
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM convenzioni_scuole WHERE id = ?', [$id]);
    }
}
