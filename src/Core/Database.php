<?php

declare(strict_types=1);

namespace App\Core;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use Throwable;
use WeakMap;

/**
 * Accesso al database del portale sopra la stessa connessione mysqli aperta da config.php (nessuna seconda connessione).
 *
 * Tutte le query passano da prepared statement: i valori non entrano mai nel testo SQL.
 * Tipo di ogni parametro: int e bool → i, float → d, tutto il resto (anche null) → s.
 *
 * Le funzioni procedurali db_query/db_righe/db_riga/db_valore/db_esegui (inc/base.php) sono facciate di questa classe:
 * stessi risultati di prima, compresi gli errori silenziosi (false, [], null, -1). I Repository nuovi la ricevono nel costruttore.
 */
final class Database
{
    /** @var WeakMap<mysqli, Database>|null istanze per connessione (la stessa connessione → lo stesso oggetto) */
    private static ?WeakMap $istanze = null;

    public function __construct(private mysqli $conn)
    {
    }

    /** L'oggetto Database di una connessione (usato dalle facciate procedurali che ricevono ancora $conn). */
    public static function per(mysqli $conn): self
    {
        self::$istanze ??= new WeakMap();
        if (!isset(self::$istanze[$conn])) {
            self::$istanze[$conn] = new self($conn);
        }

        return self::$istanze[$conn];
    }

    /** La connessione mysqli sottostante: solo per il codice procedurale non ancora migrato. */
    public function mysqli(): mysqli
    {
        return $this->conn;
    }

    /**
     * Esegue una query con parametri e restituisce il risultato grezzo, come la vecchia db_query():
     * mysqli_result per le SELECT, mysqli_stmt per INSERT/UPDATE/DELETE, false se la query non si prepara o non riesce.
     *
     * @param array<int|string, mixed> $parametri
     */
    public function grezza(string $sql, array $parametri = []): mysqli_result|mysqli_stmt|false
    {
        $st = $this->conn->prepare($sql);
        if (!$st) {
            return false;
        }
        if ($parametri) {
            $tipi = '';
            foreach ($parametri as $v) {
                $tipi .= is_int($v) || is_bool($v) ? 'i' : (is_float($v) ? 'd' : 's');
            }
            $valori = array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, array_values($parametri));
            $st->bind_param($tipi, ...$valori);
        }
        if (!$st->execute()) {
            return false;
        }
        $r = $st->get_result();

        return $r === false ? $st : $r;
    }

    /**
     * Tutte le righe (array associativi); [] se la query non riesce.
     *
     * @param array<int|string, mixed> $parametri
     * @return list<array<string, mixed>>
     */
    public function righe(string $sql, array $parametri = []): array
    {
        $r = $this->grezza($sql, $parametri);

        return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * La prima riga o null.
     *
     * @param array<int|string, mixed> $parametri
     * @return array<string, mixed>|null
     */
    public function riga(string $sql, array $parametri = []): ?array
    {
        $r = $this->grezza($sql, $parametri);

        return $r instanceof mysqli_result ? ($r->fetch_assoc() ?: null) : null;
    }

    /**
     * Il primo campo della prima riga o null.
     *
     * @param array<int|string, mixed> $parametri
     */
    public function valore(string $sql, array $parametri = []): mixed
    {
        $r = $this->grezza($sql, $parametri);
        $x = $r instanceof mysqli_result ? $r->fetch_row() : null;

        return $x ? $x[0] : null;
    }

    /**
     * Righe toccate da INSERT/UPDATE/DELETE; -1 se la query non riesce.
     *
     * @param array<int|string, mixed> $parametri
     */
    public function esegui(string $sql, array $parametri = []): int
    {
        $r = $this->grezza($sql, $parametri);
        if ($r === false) {
            return -1;
        }

        return $r instanceof mysqli_stmt ? (int) $r->affected_rows : (int) $this->conn->affected_rows;
    }

    /**
     * Esegue un INSERT e restituisce l'id della riga creata, letto subito dallo statement (non si perde
     * se dopo si fa un UPDATE, come succede con $conn->insert_id); 0 se la query non riesce.
     *
     * @param array<int|string, mixed> $parametri
     */
    public function inserisci(string $sql, array $parametri = []): int
    {
        $r = $this->grezza($sql, $parametri);

        return $r instanceof mysqli_stmt ? (int) $r->insert_id : 0;
    }

    /**
     * Esegue un comando SQL senza parametri (DDL: CREATE, ALTER, SHOW…, che non serve preparare).
     * Ritorna il risultato per le query che ne hanno uno, true per gli altri comandi riusciti, false se non riesce
     * (il motivo in ultimoErrore()).
     */
    public function comando(string $sql): mysqli_result|bool
    {
        return $this->conn->query($sql);
    }

    /** Messaggio dell'ultimo errore della connessione ('' se nessuno). */
    public function ultimoErrore(): string
    {
        return (string) $this->conn->error;
    }

    /**
     * Esegue $lavoro in una transazione: commit se termina, rollback (e rilancio dell'eccezione) se fallisce.
     * Dentro si possono usare le SELECT … FOR UPDATE (es. posti disponibili di un turno).
     *
     * @template T
     * @param callable(Database): T $lavoro
     * @return T
     */
    public function transazione(callable $lavoro): mixed
    {
        $this->conn->begin_transaction();
        try {
            $esito = $lavoro($this);
            $this->conn->commit();

            return $esito;
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
}
