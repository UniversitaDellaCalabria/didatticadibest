<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Core\Database;
use App\Core\Orologio;

/**
 * La lista d'attesa delle risorse prenotabili: chi trova lo slot occupato si mette in coda. Quando una prenotazione viene annullata o rifiutata, il primo in coda
 * per quello slot riceve un'email (ha poche ore per prenotare); se non lo fa, al giro successivo del cron l'avviso passa al successivo.
 * Lo slot non resta riservato: chi arriva prima lo prende, e la coda si chiude da sola quando chi era in attesa riesce a prenotare.
 */
final class ListaAttesaRisorse
{
    /** Ore che il primo in coda ha per prenotare, poi l'avviso passa al successivo. */
    public const ORE_PER_PRENOTARE = 24;

    /** Quante code può tenere aperte uno stesso utente per una stessa risorsa. */
    public const MASSIMO_PER_RISORSA = 5;

    public function __construct(private Database $db, private NotificheRisorse $notifiche, private Orologio $orologio)
    {
    }

    /**
     * Mette in coda per lo slot indicato. Ritorna un messaggio d'errore oppure null.
     *
     * @param array<string, mixed> $r risorsa
     * @param array<string, mixed> $u utente
     */
    public function aggiungi(array $r, array $u, string $inizio, string $fine, int $nSlot, string $motivo): ?string
    {
        $rid = (int) $r['id'];
        $uid = (int) $u['id'];
        if (strtotime($inizio) <= $this->orologio->adesso()->getTimestamp()) {
            return 'Lo slot è già passato.';
        }
        if ($this->db->valore("SELECT id FROM prenotazioni_risorse_attesa WHERE risorsa_id = ? AND utente_id = ? AND stato IN ('attesa', 'avvisata') AND inizio < ? AND fine > ?", [$rid, $uid, $fine, $inizio])) {
            return 'Sei già in lista d\'attesa per questo orario.';
        }
        if ((int) $this->db->valore("SELECT COUNT(*) FROM prenotazioni_risorse_attesa WHERE risorsa_id = ? AND utente_id = ? AND stato IN ('attesa', 'avvisata')", [$rid, $uid]) >= self::MASSIMO_PER_RISORSA) {
            return 'Hai già ' . self::MASSIMO_PER_RISORSA . ' liste d\'attesa aperte per questa risorsa: togline una prima di aggiungerne altre.';
        }
        $this->db->esegui(
            'INSERT INTO prenotazioni_risorse_attesa (risorsa_id, utente_id, nome, cognome, email, inizio, fine, n_slot, motivo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$rid, $uid, mb_substr((string) ($u['nome'] ?? ''), 0, 100), mb_substr((string) ($u['cognome'] ?? ''), 0, 100), strtolower((string) ($u['email'] ?? '')), $inizio, $fine, max(1, $nSlot), mb_substr(trim(strip_tags($motivo)), 0, 500)]
        );

        return null;
    }

    /**
     * Le attese di un utente non ancora finite, con il nome della risorsa e la posizione in coda.
     *
     * @return list<array<string, mixed>>
     */
    public function dellUtente(int $utenteId): array
    {
        $righe = $this->db->righe("SELECT a.*, r.nome AS risorsa_nome, r.pagina_id FROM prenotazioni_risorse_attesa a JOIN risorse r ON r.id = a.risorsa_id
            WHERE a.utente_id = ? AND a.stato IN ('attesa', 'avvisata') AND a.fine > NOW() ORDER BY a.inizio", [$utenteId]);
        foreach ($righe as &$a) {
            $a['posizione'] = 1 + (int) $this->db->valore("SELECT COUNT(*) FROM prenotazioni_risorse_attesa WHERE risorsa_id = ? AND stato IN ('attesa', 'avvisata') AND inizio < ? AND fine > ? AND (creata_il < ? OR (creata_il = ? AND id < ?))",
                [(int) $a['risorsa_id'], $a['fine'], $a['inizio'], $a['creata_il'], $a['creata_il'], (int) $a['id']]);
        }

        return $righe;
    }

    /** Quante persone sono in coda su una risorsa in una giornata (per mostrarlo nel calendario): [ora di inizio Y-m-d H:i:s => numero]. */
    public function incoda(int $risorsaId, string $data): array
    {
        $out = [];
        foreach ($this->db->righe("SELECT inizio FROM prenotazioni_risorse_attesa WHERE risorsa_id = ? AND stato IN ('attesa', 'avvisata') AND DATE(inizio) = ?", [$risorsaId, $data]) as $a) {
            $out[$a['inizio']] = ($out[$a['inizio']] ?? 0) + 1;
        }

        return $out;
    }

    public function annulla(int $id, int $utenteId): bool
    {
        return $this->db->esegui("UPDATE prenotazioni_risorse_attesa SET stato = 'annullata' WHERE id = ? AND utente_id = ? AND stato IN ('attesa', 'avvisata')", [$id, $utenteId]) > 0;
    }

    /**
     * Chi ha prenotato lo slot per cui era in coda: la coda si chiude.
     */
    public function soddisfatta(int $utenteId, int $risorsaId, string $inizio, string $fine): void
    {
        $this->db->esegui("UPDATE prenotazioni_risorse_attesa SET stato = 'soddisfatta' WHERE utente_id = ? AND risorsa_id = ? AND stato IN ('attesa', 'avvisata') AND inizio < ? AND fine > ?", [$utenteId, $risorsaId, $fine, $inizio]);
    }

    /**
     * Una prenotazione è stata annullata o rifiutata: il primo in coda per quell'orario riceve l'avviso. Ritorna quante email sono partite (0 o 1).
     *
     * @param array<string, mixed> $p prenotazione con risorsa_nome e pagina_id
     */
    public function liberato(array $p): int
    {
        return $this->avvisaPrimo((int) $p['risorsa_id'], (string) $p['inizio'], (string) $p['fine'], $p);
    }

    /**
     * Il giro del cron: a chi è stato avvisato da più di ORE_PER_PRENOTARE ore senza prenotare, l'attesa scade e l'avviso passa al successivo.
     * Ritorna quante email sono partite.
     */
    public function scala(): int
    {
        $n = 0;
        $limite = date('Y-m-d H:i:s', $this->orologio->adesso()->getTimestamp() - self::ORE_PER_PRENOTARE * 3600);
        foreach ($this->db->righe("SELECT id, risorsa_id, inizio, fine, n_slot FROM prenotazioni_risorse_attesa WHERE stato = 'avvisata' AND avvisata_il < ?", [$limite]) as $a) {
            $this->db->esegui("UPDATE prenotazioni_risorse_attesa SET stato = 'scaduta' WHERE id = ?", [(int) $a['id']]);
            if (strtotime((string) $a['inizio']) > $this->orologio->adesso()->getTimestamp()) {
                $n += $this->avvisaPrimo((int) $a['risorsa_id'], (string) $a['inizio'], (string) $a['fine'], null);
            }
        }

        return $n;
    }

    /**
     * @param array<string, mixed>|null $p
     */
    private function avvisaPrimo(int $risorsaId, string $inizio, string $fine, ?array $p): int
    {
        // Il primo in coda per un orario che si sovrappone allo slot liberato (e che non ha già un avviso aperto: avvisarne uno alla volta)
        if ($this->db->valore("SELECT id FROM prenotazioni_risorse_attesa WHERE risorsa_id = ? AND stato = 'avvisata' AND inizio < ? AND fine > ? LIMIT 1", [$risorsaId, $fine, $inizio])) {
            return 0;
        }
        $a = $this->db->riga("SELECT * FROM prenotazioni_risorse_attesa WHERE risorsa_id = ? AND stato = 'attesa' AND inizio < ? AND fine > ? AND inizio > NOW() ORDER BY creata_il, id LIMIT 1", [$risorsaId, $fine, $inizio]);
        if (!$a) {
            return 0;
        }
        $r = $p ?: $this->db->riga('SELECT r.id AS risorsa_id, r.nome AS risorsa_nome, r.pagina_id FROM risorse r WHERE r.id = ?', [$risorsaId]);
        if (!$r) {
            return 0;
        }
        $r['slug'] = (string) $this->db->valore('SELECT slug FROM pagine_eventi WHERE id = ?', [(int) ($r['pagina_id'] ?? 0)]);
        $r['colore_primario'] = $r['colore_primario'] ?? $this->db->valore('SELECT colore_primario FROM pagine_eventi WHERE id = ?', [(int) ($r['pagina_id'] ?? 0)]);
        $this->db->esegui("UPDATE prenotazioni_risorse_attesa SET stato = 'avvisata', avvisata_il = ? WHERE id = ?", [date('Y-m-d H:i:s', $this->orologio->adesso()->getTimestamp()), (int) $a['id']]);

        return $this->notifiche->emailSlotLibero($a, $r, self::ORE_PER_PRENOTARE) ? 1 : 0;
    }
}
