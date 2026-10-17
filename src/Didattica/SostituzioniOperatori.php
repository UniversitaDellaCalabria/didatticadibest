<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Orologio;

/**
 * Sostituzioni degli operatori: chi sostituisce un operatore assente (dal… al…). Nel periodo le email sull'operatore assente (messaggi degli studenti,
 * promemoria, integrazioni) arrivano anche al sostituto, le pratiche dell'assente compaiono nel suo «da fare» e nel suo elenco «in carico a me».
 * Si cambia solo chi riceve e chi vede: la pratica resta assegnata all'assente (nessun passaggio da annullare al suo ritorno).
 */
final class SostituzioniOperatori
{
    public const MAX_GIORNI = 366;

    public function __construct(private Database $db, private Orologio $orologio, private ServizioUffici $ufficio, private SostituzioniInCorso $inCorso)
    {
    }

    private function oggi(): string
    {
        return $this->orologio->adesso()->format('Y-m-d');
    }

    /**
     * Le sostituzioni in corso oggi.
     *
     * @return list<array<string, mixed>>
     */
    public function inCorso(): array
    {
        return $this->inCorso->tutte();
    }

    /** L'operatore che oggi sostituisce quello indicato (o null). @return array<string, mixed>|null */
    public function sostitutoDi(int $opId): ?array
    {
        foreach ($this->inCorso() as $s) {
            if ((int) $s['op_id'] === $opId) {
                $o = $this->ufficio->operatore((int) $s['sostituto_op_id']);
                if ($o) {
                    return $o + ['_fino_al' => (string) $s['al']];
                }
            }
        }

        return null;
    }

    /** Gli operatori che oggi sono sostituiti da questo. @return list<int> id degli assenti */
    public function sostituisce(int $opId): array
    {
        return $this->inCorso->sostituisce($opId);
    }

    /**
     * Gli indirizzi a cui scrivere per un operatore: lui e, se è assente, il suo sostituto.
     *
     * @param array<string, mixed> $operatore
     * @return list<string>
     */
    public function destinatari(array $operatore): array
    {
        $e = [(string) $operatore['email']];
        $sost = isset($operatore['id']) ? $this->sostitutoDi((int) $operatore['id']) : null;
        if ($sost && (string) $sost['email'] !== '') {
            $e[] = (string) $sost['email'];
        }

        return array_values(array_unique(array_filter($e, static fn (string $x): bool => $x !== '')));
    }

    /** Tutte le sostituzioni non ancora finite, con i nomi. @return list<array<string, mixed>> */
    public function elenco(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT * FROM operatori_sostituzioni WHERE al >= ? ORDER BY dal, id', [$this->oggi()]) as $s) {
            $a = $this->ufficio->operatore((int) $s['op_id']);
            $b = $this->ufficio->operatore((int) $s['sostituto_op_id']);
            $s['assente'] = $a ? (string) $a['nominativo'] : '(operatore tolto)';
            $s['sostituto'] = $b ? (string) $b['nominativo'] : '(operatore tolto)';
            $s['in_corso'] = $s['dal'] <= $this->oggi();
            $out[] = $s;
        }

        return $out;
    }

    /** Registra una sostituzione. Ritorna il messaggio d'errore, o null se è andata bene. */
    public function aggiungi(int $opId, int $sostitutoId, string $dal, string $al, string $motivo, int $utenteId): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $dal);
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $al);
        if (!$d || !$a || $d->format('Y-m-d') !== $dal || $a->format('Y-m-d') !== $al) {
            return 'Indica le date di inizio e fine.';
        }
        if ($a < $d) {
            return 'La data di fine è prima di quella di inizio.';
        }
        if ($a->diff($d)->days > self::MAX_GIORNI) {
            return 'Una sostituzione può durare al massimo un anno.';
        }
        if ($al < $this->oggi()) {
            return 'Il periodo è già finito.';
        }
        if ($opId === $sostitutoId) {
            return 'Una persona non può sostituire sé stessa.';
        }
        if (!$this->ufficio->operatore($opId) || !$this->ufficio->operatore($sostitutoId)) {
            return 'Scegli due persone degli uffici.';
        }
        if ($this->db->valore('SELECT COUNT(*) FROM operatori_sostituzioni WHERE op_id = ? AND dal <= ? AND al >= ?', [$opId, $al, $dal])) {
            return 'Per quel periodo c\'è già una sostituzione: eliminala prima.';
        }
        $this->db->inserisci('INSERT INTO operatori_sostituzioni (op_id, sostituto_op_id, dal, al, motivo, creata_da) VALUES (?, ?, ?, ?, ?, ?)',
            [$opId, $sostitutoId, $dal, $al, mb_substr(trim($motivo), 0, 200), $utenteId ?: null]);

        return null;
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM operatori_sostituzioni WHERE id = ?', [$id]);
    }
}
