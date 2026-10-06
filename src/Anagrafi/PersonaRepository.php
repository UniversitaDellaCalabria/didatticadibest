<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;

/** Query dell'anagrafe del personale di Ateneo (personale_ateneo) e delle modifiche alla scheda fatte dalla persona (personale_modifiche). */
final class PersonaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perId(string $id): ?array
    {
        return $this->db->riga('SELECT * FROM personale_ateneo WHERE id = ? LIMIT 1', [$id]);
    }

    /** @return array<string, mixed>|null id e gruppo della persona con questa email (prima chi è in servizio) */
    public function perEmail(string $email): ?array
    {
        return $this->db->riga('SELECT id, gruppo FROM personale_ateneo WHERE email = ? ORDER BY attivo DESC LIMIT 1', [$email]);
    }

    public function conta(bool $soloAttivi = false): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) n FROM personale_ateneo' . ($soloAttivi ? ' WHERE attivo = 1' : ''));
    }

    /** @return array<string, true> id di tutte le persone */
    public function ids(): array
    {
        return array_fill_keys(array_map('strval', array_column($this->db->righe('SELECT id FROM personale_ateneo'), 'id')), true);
    }

    /** @return array<string, array<string, mixed>> persone non più in Ateneo (id, cognome, nome, uscita_il), per id */
    public function usciti(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT id, cognome, nome, uscita_il FROM personale_ateneo WHERE attivo = 0') as $p) {
            $out[(string) $p['id']] = $p;
        }

        return $out;
    }

    /**
     * Ricerca per parole (cognome, nome, email, settore) con filtri. Prima chi è in servizio.
     *
     * @param list<string> $parole
     * @return list<array<string, mixed>>
     */
    public function cerca(array $parole, string $gruppo, string $ruolo, string $struttura, int $limite): array
    {
        $where = ['1=1'];
        $par = [];
        foreach ($parole as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $where[] = '(cognome LIKE ? OR nome LIKE ? OR email LIKE ? OR ssd LIKE ?)';
            array_push($par, $like, $like, $like, $like);
        }
        if ($gruppo !== '') {
            $where[] = 'gruppo = ?';
            $par[] = $gruppo;
        }
        if ($ruolo !== '') {
            $where[] = 'ruolo_cod = ?';
            $par[] = $ruolo;
        }
        if ($struttura !== '') {
            $where[] = 'struttura_cod = ?';
            $par[] = $struttura;
        }
        if (!$par) {
            return [];
        }

        return $this->db->righe('SELECT * FROM personale_ateneo WHERE ' . implode(' AND ', $where) . ' ORDER BY attivo DESC, cognome, nome LIMIT ' . max(1, min(50, $limite)), $par);
    }

    /**
     * Ruoli (o strutture) del personale in servizio con il numero di persone, eventualmente di un solo gruppo.
     *
     * @param 'ruolo'|'struttura' $campo
     * @return list<array<string, mixed>> ruolo_cod/ruolo (o struttura_cod/struttura) e n
     */
    public function opzioni(string $campo, ?string $gruppo = null): array
    {
        $campo = $campo === 'struttura' ? 'struttura' : 'ruolo';
        if ($gruppo === null) {
            return $this->db->righe("SELECT {$campo}_cod, $campo, COUNT(*) n FROM personale_ateneo WHERE attivo = 1 AND {$campo}_cod <> '' GROUP BY {$campo}_cod, $campo ORDER BY $campo");
        }

        return $this->db->righe("SELECT {$campo}_cod, $campo, COUNT(*) n FROM personale_ateneo WHERE gruppo = ? AND attivo = 1 GROUP BY {$campo}_cod, $campo ORDER BY $campo", [$gruppo]);
    }

    /** @return array<string, int> persone in servizio per gruppo */
    public function contaPerGruppo(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT gruppo, COUNT(*) n FROM personale_ateneo WHERE attivo = 1 GROUP BY gruppo') as $x) {
            $out[(string) $x['gruppo']] = (int) $x['n'];
        }

        return $out;
    }

    public function contaUsciti(string $gruppo): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) n FROM personale_ateneo WHERE gruppo = ? AND attivo = 0', [$gruppo]);
    }

    /**
     * Elenco di un gruppo per il pannello (al massimo 600), con filtri.
     *
     * @param list<string> $parole
     * @return list<array<string, mixed>>|null null se la lettura non riesce
     */
    public function elenco(string $gruppo, bool $usciti, string $ruolo, string $struttura, array $parole): ?array
    {
        $where = 'gruppo = ? AND attivo = ' . ($usciti ? 0 : 1);
        $par = [$gruppo];
        if ($ruolo !== '') {
            $where .= ' AND ruolo_cod = ?';
            $par[] = $ruolo;
        }
        if ($struttura !== '') {
            $where .= ' AND struttura_cod = ?';
            $par[] = $struttura;
        }
        foreach ($parole as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $where .= ' AND (cognome LIKE ? OR nome LIKE ? OR email LIKE ? OR ssd LIKE ?)';
            array_push($par, $like, $like, $like, $like);
        }
        $r = $this->db->grezza("SELECT * FROM personale_ateneo WHERE $where ORDER BY cognome, nome LIMIT 600", $par);

        return $r instanceof \mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : null;
    }

    /** Messaggio dell'ultimo errore del database (per l'avviso del pannello) */
    public function ultimoErrore(): string
    {
        return $this->db->mysqli()->error;
    }

    /** @param array<string, mixed> $p persona (id, cognome, nome, email, telefono, ufficio, ruolo_cod, ruolo, struttura_cod, struttura, gruppo, docente, ssd_cod, ssd, origine) */
    public function salvaDaSincronizzazione(array $p): void
    {
        $this->db->esegui(
            'INSERT INTO personale_ateneo (id, cognome, nome, email, telefono, ufficio, ruolo_cod, ruolo, struttura_cod, struttura, gruppo, docente, ssd_cod, ssd, origine, attivo, aggiornata_il, uscita_il)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NULL)
                              ON DUPLICATE KEY UPDATE cognome=VALUES(cognome), nome=VALUES(nome), email=VALUES(email), telefono=VALUES(telefono), ufficio=VALUES(ufficio),
                                ruolo_cod=VALUES(ruolo_cod), ruolo=VALUES(ruolo), struttura_cod=VALUES(struttura_cod), struttura=VALUES(struttura), gruppo=VALUES(gruppo),
                                docente=VALUES(docente), ssd_cod=VALUES(ssd_cod), ssd=VALUES(ssd), origine=VALUES(origine), attivo=1, aggiornata_il=NOW(), uscita_il=NULL',
            [$p['id'], $p['cognome'], $p['nome'], $p['email'], $p['telefono'], $p['ufficio'], $p['ruolo_cod'], $p['ruolo'], $p['struttura_cod'], $p['struttura'],
             $p['gruppo'], (int) $p['docente'], $p['ssd_cod'], $p['ssd'], $p['origine']]
        );
    }

    /** Chi non è stato aggiornato da $inizio non compare più nel portale; chi è uscito da più di 12 mesi si cancella. */
    public function segnaUsciti(string $inizio): void
    {
        $this->db->esegui('UPDATE personale_ateneo SET attivo = 0, uscita_il = IFNULL(uscita_il, NOW()) WHERE aggiornata_il IS NULL OR aggiornata_il < ?', [$inizio]);
        $this->db->esegui('DELETE FROM personale_ateneo WHERE attivo = 0 AND uscita_il < NOW() - INTERVAL 12 MONTH');
    }

    /** Toglie le persone arrivate da una struttura; ritorna quante */
    public function eliminaPerOrigine(string $struttura): int
    {
        return max(0, $this->db->esegui('DELETE FROM personale_ateneo WHERE origine = ?', [$struttura]));
    }

    public function salvaDettaglio(string $id, string $json): void
    {
        $this->db->esegui('UPDATE personale_ateneo SET dettaglio_json = ?, dettaglio_il = NOW() WHERE id = ?', [$json, $id]);
    }

    /** @return array<string, mixed>|null campi della scheda modificati dalla persona */
    public function modifiche(string $id): ?array
    {
        return $this->db->riga('SELECT * FROM personale_modifiche WHERE persona_id = ? LIMIT 1', [$id]);
    }

    /** @param array<string, string> $v telefono, ufficio, ricevimento, bio, sito */
    public function salvaModifiche(string $id, array $v): bool
    {
        return $this->db->esegui(
            'INSERT INTO personale_modifiche (persona_id, telefono, ufficio, ricevimento, bio, sito, aggiornata_il) VALUES (?, ?, ?, ?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE telefono = VALUES(telefono), ufficio = VALUES(ufficio), ricevimento = VALUES(ricevimento),
                                                      bio = VALUES(bio), sito = VALUES(sito), aggiornata_il = NOW()',
            [$id, $v['telefono'], $v['ufficio'], $v['ricevimento'], $v['bio'], $v['sito']]
        ) >= 0;
    }
}
