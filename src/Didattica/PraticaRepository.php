<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Eventi\Righe;

/** Pratiche degli studenti (pratiche), il loro storico (pratiche_eventi) e gli operatori che le hanno avute in carico (pratiche_operatori). */
final class PraticaRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Pratica con il titolo del modulo (per id).
     *
     * @return array<string, mixed>|null
     */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT p.*, m.titolo AS modulo_titolo, m.email_ufficio, m.categoria FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE p.id = ?', [$id]);
    }

    /**
     * Nuova pratica «inviata». Ritorna l'id (0 se non riesce).
     */
    public function inserisci(int $moduloId, int $utenteId, string $codice, ?string $nome, ?string $cognome, string $email, string $matricola, string $risposteJson, string $ip, string $accessoJson): int
    {
        return $this->db->inserisci(
            "INSERT INTO pratiche (modulo_id, utente_id, codice, nome, cognome, email, matricola, risposte_json, stato, aggiornata_il, ip_invio, accesso_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'inviata', NOW(), ?, ?)",
            [$moduloId, $utenteId, $codice, $nome, $cognome, $email, $matricola, $risposteJson, $ip, $accessoJson]
        );
    }

    /** Riga dello storico (e la pratica risulta aggiornata adesso). */
    public function aggiungiEvento(int $praticaId, string $tipo, string $autore, ?int $utenteId, ?string $stato, string $testo, ?string $allegato, ?string $nomeAllegato, bool $interno, string $autoreNome): void
    {
        $this->db->esegui(
            'INSERT INTO pratiche_eventi (pratica_id, tipo, autore, utente_id, stato, testo, allegato, nome_allegato, interno, autore_nome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$praticaId, $tipo, $autore, $utenteId, $stato, $testo, $allegato, $nomeAllegato, $interno ? 1 : 0, $autoreNome]
        );
        $this->db->esegui('UPDATE pratiche SET aggiornata_il = NOW() WHERE id = ?', [$praticaId]);
    }

    /** Nuovo stato con l'eventuale richiesta di integrazione (JSON). */
    public function impostaStato(int $id, string $stato, ?string $richiestaJson): void
    {
        $this->db->esegui('UPDATE pratiche SET stato = ?, richiesta_json = ?, aggiornata_il = NOW() WHERE id = ?', [$stato, $richiestaJson, $id]);
    }

    /** La pratica torna in lavorazione e la richiesta di integrazione è chiusa. */
    public function riprendi(int $id): void
    {
        $this->db->esegui("UPDATE pratiche SET stato = 'in_lavorazione', richiesta_json = NULL WHERE id = ?", [$id]);
    }

    /** La pratica passa all'operatore (e all'ufficio) indicati, al passo dell'iter indicato. */
    public function assegna(int $id, int $operatoreId, int $passo, string $stato, ?int $ufficioId): void
    {
        $this->db->esegui('UPDATE pratiche SET assegnata_a = ?, passo = ?, stato = ?, ufficio_id = ?, aggiornata_il = NOW() WHERE id = ?', [$operatoreId, $passo, $stato, $ufficioId, $id]);
    }

    /** Chi ha avuto la pratica resta tra gli operatori: la continua a vedere e a integrare (se c'è già, si aggiorna il passo). */
    public function ricordaOperatore(int $praticaId, int $operatoreId, int $passo, bool $aggiornaPasso): void
    {
        if ($aggiornaPasso) {
            $this->db->esegui('INSERT INTO pratiche_operatori (pratica_id, operatore_id, passo) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE passo = VALUES(passo)', [$praticaId, $operatoreId, $passo]);
        } else {
            $this->db->esegui('INSERT IGNORE INTO pratiche_operatori (pratica_id, operatore_id, passo) VALUES (?, ?, ?)', [$praticaId, $operatoreId, $passo]);
        }
    }

    /**
     * Operatori che hanno avuto (o hanno) in carico la pratica: id.
     *
     * @return list<int>
     */
    public function operatori(int $id): array
    {
        return array_map('intval', array_column($this->db->righe('SELECT operatore_id FROM pratiche_operatori WHERE pratica_id = ? ORDER BY passo, dal', [$id]), 'operatore_id'));
    }

    /**
     * Storico della pratica; senza le note interne se $soloVisibili (lo vede lo studente).
     *
     * @return list<array<string, mixed>>
     */
    public function eventi(int $id, bool $soloVisibili): array
    {
        return $this->db->righe('SELECT * FROM pratiche_eventi WHERE pratica_id = ?' . ($soloVisibili ? ' AND interno = 0' : '') . ' ORDER BY creato_il, id', [$id]);
    }

    /** Pratiche aperte («n_aperte» del pannello). */
    public function contaAperte(): int
    {
        return (int) ($this->db->riga("SELECT COUNT(*) n FROM pratiche WHERE stato IN ('inviata', 'in_lavorazione', 'integrazione')")['n'] ?? 0);
    }

    /**
     * Le pratiche dell'utente, dalla più recente.
     *
     * @return list<array<string, mixed>>
     */
    public function dellUtente(int $utenteId): array
    {
        return $this->db->righe('SELECT p.*, m.titolo AS modulo_titolo FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE p.utente_id = ? ORDER BY p.aggiornata_il DESC', [$utenteId]);
    }

    /**
     * Pratiche aperte senza movimenti da più dei giorni indicati nel modulo e senza un promemoria recente: id e giorni del modulo.
     *
     * @return list<array<string, string|null>>
     */
    public function ferme(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT p.id, m.giorni_promemoria FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id
             WHERE p.stato IN ('inviata', 'in_lavorazione') AND m.giorni_promemoria > 0
               AND COALESCE(p.aggiornata_il, p.creata_il) < NOW() - INTERVAL m.giorni_promemoria DAY
               AND (p.promemoria_il IS NULL OR p.promemoria_il < NOW() - INTERVAL m.giorni_promemoria DAY)"
        ));
    }

    public function segnaPromemoria(int $id): void
    {
        $this->db->esegui('UPDATE pratiche SET promemoria_il = NOW() WHERE id = ?', [$id]);
    }

    /**
     * Pratiche inviate tra due date (con titolo e iter del modulo).
     *
     * @return list<array<string, mixed>>
     */
    public function inviateTra(string $dal, string $al): array
    {
        return $this->db->righe('SELECT p.*, m.titolo AS modulo_titolo, m.iter_json FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE p.creata_il BETWEEN ? AND ?', ["$dal 00:00:00", "$al 23:59:59"]);
    }

    /**
     * Eventi della pratica in ordine di tempo (per i tempi dell'iter).
     *
     * @return list<array<string, mixed>>
     */
    public function eventiPerTempi(int $id): array
    {
        return $this->db->righe('SELECT tipo, stato, testo, creato_il FROM pratiche_eventi WHERE pratica_id = ? ORDER BY creato_il, id', [$id]);
    }

    /** Istruttoria dell'ufficio: campi dell'ufficio (JSON), seduta, delibera e protocollo. */
    public function salvaIstruttoria(int $id, ?string $ufficioJson, ?int $sedutaId, string $delibera, string $protocollo, ?string $protocolloData): void
    {
        $this->db->esegui('UPDATE pratiche SET ufficio_json = ?, seduta_id = ?, delibera = ?, protocollo = ?, protocollo_data = ?, aggiornata_il = NOW() WHERE id = ?', [$ufficioJson, $sedutaId, $delibera, $protocollo, $protocolloData, $id]);
    }

    /**
     * Elenco delle pratiche del pannello (max 1000, dalla più recente) con i filtri scelti.
     * $stato: «aperte», «tutte» o uno stato; $seduta: «nessuna» o l'id; $carico: «me», «seguite», «smistare», «ufficio» o vuoto.
     *
     * @param array<string, mixed>|null $io l'operatore che guarda (per i filtri sul carico)
     * @return list<array<string, mixed>>
     */
    public function elenco(string $stato, int $modulo, string $seduta, string $carico, string $dal, string $al, string $q, ?array $io): array
    {
        $where = ['1=1'];
        $par = [];
        if ($stato === 'aperte') {
            $where[] = "p.stato IN ('inviata', 'in_lavorazione', 'integrazione')";
        } elseif ($stato !== 'tutte') {
            $where[] = 'p.stato = ?';
            $par[] = $stato;
        }
        if ($modulo) {
            $where[] = "p.modulo_id = $modulo";
        }
        if ($seduta === 'nessuna') {
            $where[] = 'p.seduta_id IS NULL';
        } elseif ((int) $seduta > 0) {
            $where[] = 'p.seduta_id = ' . (int) $seduta;
        }
        if ($carico === 'me') {
            $where[] = 'p.assegnata_a = ' . (int) ($io['id'] ?? -1);
        } elseif ($carico === 'seguite') {
            $where[] = 'p.id IN (SELECT pratica_id FROM pratiche_operatori WHERE operatore_id = ' . (int) ($io['id'] ?? -1) . ') AND (p.assegnata_a IS NULL OR p.assegnata_a <> ' . (int) ($io['id'] ?? -1) . ')';
        } elseif ($carico === 'smistare') {
            $where[] = "p.assegnata_a IS NULL AND p.ufficio_id IS NULL AND p.stato NOT IN ('accolta', 'respinta', 'chiusa')";
        } elseif ($carico === 'ufficio') {
            // Del mio ufficio: quelle che l'ufficio ha ora (anche senza una persona) e quelle assegnate a me
            $where[] = '(p.ufficio_id = ' . (int) ($io['ufficio_id'] ?? -1) . ' OR p.assegnata_a = ' . (int) ($io['id'] ?? -1) . ')';
        }
        if ($dal !== '') {
            $where[] = 'p.creata_il >= ?';
            $par[] = "$dal 00:00:00";
        }
        if ($al !== '') {
            $where[] = 'p.creata_il <= ?';
            $par[] = "$al 23:59:59";
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(p.nome LIKE ? OR p.cognome LIKE ? OR p.email LIKE ? OR p.codice LIKE ? OR p.matricola LIKE ?)';
            array_push($par, $like, $like, $like, $like, $like);
        }

        return $this->db->righe('SELECT p.*, m.titolo AS modulo_titolo, s.data AS seduta_data FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id LEFT JOIN didattica_sedute s ON s.id = p.seduta_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.aggiornata_il DESC LIMIT 1000', $par);
    }
}
