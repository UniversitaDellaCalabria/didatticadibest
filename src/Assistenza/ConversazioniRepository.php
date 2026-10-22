<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Core\Database;
use App\Eventi\Righe;

/** Le conversazioni di assistenza (tabella assistenza_conversazioni). Gli orari arrivano dal servizio (orologio iniettato), mai da NOW(). */
final class ConversazioniRepository
{
    /** Elenchi della casella degli operatori. */
    public const VISTA_DA_PRENDERE = 'da_prendere';
    public const VISTA_MIE = 'mie';
    public const VISTA_TUTTE = 'tutte';
    public const VISTA_CHIUSE = 'chiuse';
    public const VISTA_BOT = 'bot';

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array{canale: string, utente_id: ?int, nome: string, email: string, argomento: string, contesto_tipo: string, contesto_id: ?int, contesto_testo: string,
     *     stato: string, ip: string} $d
     */
    public function crea(array $d, string $quando): int
    {
        return $this->db->inserisci(
            'INSERT INTO assistenza_conversazioni (token, canale, utente_id, nome, email, argomento, contesto_tipo, contesto_id, contesto_testo, stato, ip, creato_il, ultimo_il, ultimo_da)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [bin2hex(random_bytes(16)), $d['canale'], $d['utente_id'], mb_substr($d['nome'], 0, Costanti::MAX_NOME), mb_substr($d['email'], 0, 190), $d['argomento'],
                $d['contesto_tipo'], $d['contesto_id'], mb_substr($d['contesto_testo'], 0, 200), $d['stato'], mb_substr($d['ip'], 0, 45), $quando, $quando, Costanti::DA_UTENTE]
        );
    }

    /** @return array<string, string|null>|null */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM assistenza_conversazioni WHERE id = ?', [$id]));
    }

    /** @return array<string, string|null>|null */
    public function perToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        return Righe::riga($this->db->riga('SELECT * FROM assistenza_conversazioni WHERE token = ?', [$token]));
    }

    /** La conversazione non chiusa di una persona collegata (ne ha una sola alla volta). @return array<string, string|null>|null */
    public function apertaDi(int $utenteId): ?array
    {
        return Righe::riga($this->db->riga("SELECT * FROM assistenza_conversazioni WHERE utente_id = ? AND stato <> 'chiusa' ORDER BY id DESC LIMIT 1", [$utenteId]));
    }

    /**
     * Le conversazioni di una persona collegata, dalla più recente.
     *
     * @return list<array<string, string|null>>
     */
    public function diUtente(int $utenteId, int $limite = 20): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM assistenza_conversazioni WHERE utente_id = ? ORDER BY id DESC LIMIT ' . max(1, $limite), [$utenteId]));
    }

    public function assegna(int $id, ?int $operatoreId, string $operatoreNome, string $stato): void
    {
        $this->db->esegui('UPDATE assistenza_conversazioni SET operatore_id = ?, operatore_nome = ?, stato = ? WHERE id = ?', [$operatoreId, mb_substr($operatoreNome, 0, Costanti::MAX_NOME), $stato, $id]);
    }

    public function impostaStato(int $id, string $stato): void
    {
        $this->db->esegui('UPDATE assistenza_conversazioni SET stato = ? WHERE id = ?', [$stato, $id]);
    }

    /** Un messaggio nuovo: aggiorna l'ultimo movimento (e chi ha scritto). */
    public function movimento(int $id, string $da, string $quando): void
    {
        $this->db->esegui('UPDATE assistenza_conversazioni SET ultimo_il = ?, ultimo_da = ? WHERE id = ?', [$quando, $da, $id]);
    }

    /** Segna l'orario della prima risposta di un operatore (solo la prima volta). */
    public function primaRisposta(int $id, string $quando): void
    {
        $this->db->esegui('UPDATE assistenza_conversazioni SET prima_risposta_il = ? WHERE id = ? AND prima_risposta_il IS NULL', [$quando, $id]);
    }

    /** La chat passa da Didacticus a un operatore (la prima volta, anche quando è l'operatore a prenderla da sé). */
    public function segnaPassataDaBot(int $id, string $quando): void
    {
        $this->db->esegui("UPDATE assistenza_conversazioni SET passata_il = COALESCE(passata_il, ?) WHERE id = ? AND stato = 'bot'", [$quando, $id]);
    }

    /** La persona ha trovato utile la risposta di Didacticus e ha chiuso da sé. */
    public function risoltaDaBot(int $id): void
    {
        $this->db->esegui('UPDATE assistenza_conversazioni SET risolta_da_bot = 1 WHERE id = ?', [$id]);
    }

    /** Quante risposte di fila di Didacticus sono andate a vuoto. */
    public function botMancate(int $id, int $n): void
    {
        $this->db->esegui('UPDATE assistenza_conversazioni SET bot_mancate = ? WHERE id = ?', [max(0, $n), $id]);
    }

    public function chiudi(int $id, string $da, string $quando): void
    {
        $this->db->esegui("UPDATE assistenza_conversazioni SET stato = 'chiusa', chiusa_il = ?, chiusa_da = ? WHERE id = ?", [$quando, $da, $id]);
    }

    public function riapri(int $id, string $stato): void
    {
        $this->db->esegui('UPDATE assistenza_conversazioni SET stato = ?, chiusa_il = NULL, chiusa_da = \'\', valutazione = NULL WHERE id = ?', [$stato, $id]);
    }

    public function valuta(int $id, bool $utile): void
    {
        $this->db->esegui('UPDATE assistenza_conversazioni SET valutazione = ? WHERE id = ?', [$utile ? 1 : 0, $id]);
    }

    /** Ultima volta che la persona ($lato = utente) o l'operatore ($lato = operatore) aveva la chat aperta. */
    public function visto(int $id, string $lato, string $quando): void
    {
        $colonna = $lato === Costanti::DA_OPERATORE ? 'operatore_visto_il' : 'utente_visto_il';
        $this->db->esegui("UPDATE assistenza_conversazioni SET $colonna = ? WHERE id = ?", [$quando, $id]);
    }

    /** Orario dell'ultimo avviso per email mandato all'utente o agli operatori. */
    public function avvisato(int $id, string $lato, string $quando): void
    {
        $colonna = $lato === Costanti::DA_OPERATORE ? 'avviso_operatore_il' : 'avviso_utente_il';
        $this->db->esegui("UPDATE assistenza_conversazioni SET $colonna = ? WHERE id = ?", [$quando, $id]);
    }

    /** Conversazioni aperte da un indirizzo IP dalla data indicata (contro gli abusi). */
    public function nuoveDaIp(string $ip, string $dal): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM assistenza_conversazioni WHERE ip = ? AND creato_il >= ?', [$ip, $dal]);
    }

    public function nuoveDaEmail(string $email, string $dal): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM assistenza_conversazioni WHERE email = ? AND creato_il >= ?', [strtolower($email), $dal]);
    }

    public function nuoveDiUtente(int $utenteId, string $dal): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM assistenza_conversazioni WHERE utente_id = ? AND creato_il >= ?', [$utenteId, $dal]);
    }

    /**
     * I numeri delle schede della casella: da prendere, in carico all'operatore, tutte quelle aperte.
     *
     * @return array{da_prendere: int, mie: int, tutte: int, bot: int}
     */
    public function contatori(int $operatoreId): array
    {
        $r = $this->db->riga(
            "SELECT SUM(stato = 'nuova') AS da_prendere, SUM(stato = 'assegnata' AND operatore_id = ?) AS mie, SUM(stato IN ('nuova', 'assegnata')) AS tutte, SUM(stato = 'bot') AS bot FROM assistenza_conversazioni",
            [$operatoreId]
        ) ?? [];

        return ['da_prendere' => (int) ($r['da_prendere'] ?? 0), 'mie' => (int) ($r['mie'] ?? 0), 'tutte' => (int) ($r['tutte'] ?? 0), 'bot' => (int) ($r['bot'] ?? 0)];
    }

    /** Quante conversazioni ci sono per ogni stato: [stato => numero]. @return array<string, int> */
    public function contaPerStato(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT stato, COUNT(*) n FROM assistenza_conversazioni GROUP BY stato') as $r) {
            $out[(string) $r['stato']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * La casella degli operatori: per ogni conversazione anche quanti messaggi dell'utente non sono stati ancora letti. Prima quelle con messaggi da
     * leggere, poi le più recenti.
     *
     * @return list<array<string, string|null>>
     */
    public function elenco(string $vista, int $operatoreId, int $limite = 200): array
    {
        $dove = match ($vista) {
            self::VISTA_DA_PRENDERE => "c.stato = 'nuova'",
            self::VISTA_MIE => "c.stato = 'assegnata' AND c.operatore_id = " . (int) $operatoreId,
            self::VISTA_CHIUSE => "c.stato = 'chiusa'",
            self::VISTA_BOT => "c.stato = 'bot'",
            default => "c.stato IN ('nuova', 'assegnata')",
        };

        return Righe::testo($this->db->righe(
            "SELECT x.* FROM (SELECT c.*, (SELECT COUNT(*) FROM assistenza_messaggi m WHERE m.conversazione_id = c.id AND m.da = 'utente' AND m.tipo = 'messaggio' AND m.letto_il IS NULL) AS da_leggere,
             (SELECT COUNT(*) FROM assistenza_messaggi m2 WHERE m2.conversazione_id = c.id AND m2.tipo = 'messaggio') AS totale
             FROM assistenza_conversazioni c WHERE $dove) x ORDER BY (x.da_leggere > 0) DESC, x.ultimo_il DESC LIMIT " . max(1, $limite)
        ));
    }
}
