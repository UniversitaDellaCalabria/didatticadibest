<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/** Utenti del portale (tabella utenti). */
final class UtenteRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null riga completa (come $user_info del middleware) */
    public function perId(int $id): ?array
    {
        return $id > 0 ? $this->db->riga('SELECT * FROM utenti WHERE id = ?', [$id]) : null;
    }

    /** @return array<string, array<string, mixed>> ruolo, struttura, gruppo e stato in Ateneo delle persone dell'anagrafe, per id (letto a parte: le due tabelle possono avere collation diverse) */
    public function personaleAteneoPerId(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT id, ruolo, struttura, gruppo, attivo FROM personale_ateneo') as $x) {
            $out[(string) $x['id']] = $x;
        }

        return $out;
    }

    /** @return array<string, mixed>|null riga completa con il nome del ruolo principale (ruolo_nome) */
    public function perCodiceFiscaleConRuolo(string $cf): ?array
    {
        return $this->db->riga('SELECT u.*, r.nome as ruolo_nome FROM utenti u LEFT JOIN ruoli r ON u.ruolo_id = r.id WHERE u.codice_fiscale = ? LIMIT 1', [$cf]);
    }

    /** @return array<string, mixed>|null riga completa con il nome del ruolo principale (ruolo_nome) */
    public function perIdConRuolo(int $id): ?array
    {
        return $this->db->riga('SELECT u.*, r.nome as ruolo_nome FROM utenti u LEFT JOIN ruoli r ON u.ruolo_id = r.id WHERE u.id = ? LIMIT 1', [$id]);
    }

    /** @return list<array<string, mixed>> tutti gli utenti, in ordine di cognome e nome */
    public function tutti(): array
    {
        return $this->db->righe('SELECT * FROM utenti ORDER BY cognome ASC, nome ASC');
    }

    /** Email recuperata dall'IdP per chi non l'aveva nel database. */
    public function impostaEmail(int $id, string $email): void
    {
        $this->db->esegui('UPDATE utenti SET email = ? WHERE id = ?', [$email, $id]);
    }

    /**
     * Accesso SSO di un utente già registrato: data dell'accesso, email dell'IdP (se non personalizzata dall'utente)
     * e nome/cognome se erano rimasti vuoti.
     */
    public function aggiornaAccessoSso(int $id, string $email, string $nome, string $cognome): void
    {
        $this->db->esegui(
            "UPDATE utenti SET ultimo_accesso = NOW(), email = IF(? != '' AND email_personalizzata = 0, ?, email), nome = IF(nome = 'Utente' AND ? != 'Utente', ?, nome), cognome = IF(cognome = '' AND ? != '', ?, cognome) WHERE id = ?",
            [$email, $email, $nome, $nome, $cognome, $cognome, $id]
        );
    }

    /** Nuovo utente dal primo accesso SSO; ritorna l'id creato (0 se non riesce). */
    public function creaDaSso(string $cf, string $nome, string $cognome, string $email, string $matricola, string $matricolaStudente, string $matricolaDipendente, int $ruoloId): int
    {
        return $this->db->inserisci(
            'INSERT INTO utenti (codice_fiscale, nome, cognome, email, matricola, matricola_studente, matricola_dipendente, ruolo_id, ultimo_accesso) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$cf, $nome, $cognome, $email, $matricola, $matricolaStudente, $matricolaDipendente, $ruoloId]
        );
    }

    /** Un altro utente (diverso da $escludiId) usa già questa email (confronto senza maiuscole sul database). */
    public function emailUsataDaAltri(string $email, int $escludiId): bool
    {
        return $this->db->riga('SELECT id FROM utenti WHERE LOWER(email) = ? AND id != ? LIMIT 1', [$email, $escludiId]) !== null;
    }

    /** Email scelta dall'utente: marcata come personalizzata (l'accesso SSO non la sovrascrive più). Ritorna false se la query non riesce. */
    public function impostaEmailPersonalizzata(int $id, string $email): bool
    {
        return $this->db->esegui('UPDATE utenti SET email = ?, email_personalizzata = 1 WHERE id = ?', [$email, $id]) >= 0;
    }

    /** @return list<string> email (grezze) degli amministratori globali: ruolo principale o secondario 1 */
    public function emailAmministratori(): array
    {
        $righe = $this->db->righe("SELECT DISTINCT email FROM utenti WHERE (ruolo_id = 1 OR FIND_IN_SET('1', ruoli_secondari) > 0) AND email IS NOT NULL AND email <> ''");

        return array_map(static fn (array $r): string => (string) $r['email'], $righe);
    }

    /**
     * @param list<int> $ids
     * @return list<string> email distinte e non vuote degli utenti indicati
     */
    public function emailDi(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $righe = $this->db->righe(
            'SELECT DISTINCT email FROM utenti WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ") AND email IS NOT NULL AND email != ''",
            array_map('intval', $ids)
        );

        return array_map(static fn (array $r): string => (string) $r['email'], $righe);
    }

    /**
     * Modifica dal pannello Utenti: ruolo principale e gruppi secondari sempre; nome e cognome se $nome non è null;
     * email (marcata come personalizzata) se $email non è null.
     */
    public function aggiornaDaAdmin(int $id, int $ruoloId, string $ruoliSecondari, ?string $nome, string $cognome, ?string $email): void
    {
        $set = ['ruolo_id = ?', 'ruoli_secondari = ?'];
        $par = [$ruoloId, $ruoliSecondari];
        if ($nome !== null) {
            $set[] = 'nome = ?';
            $set[] = 'cognome = ?';
            $par[] = $nome;
            $par[] = $cognome;
        }
        if ($email !== null) {
            $set[] = 'email = ?';
            $set[] = 'email_personalizzata = 1';
            $par[] = $email;
        }
        $par[] = $id;
        $this->db->esegui('UPDATE utenti SET ' . implode(', ', $set) . ' WHERE id = ?', $par);
    }

    /** Elimina l'utente e le sue prenotazioni (prima le prenotazioni, come prima). */
    public function eliminaConPrenotazioni(int $id): void
    {
        $this->db->esegui('DELETE FROM prenotazioni WHERE utente_id = ?', [$id]);
        $this->db->esegui('DELETE FROM utenti WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null l'utente con questa email (minuscola) o collegato a questa persona dell'anagrafe, il più recente */
    public function perEmailOPersona(string $email, string $personaId): ?array
    {
        return $this->db->riga("SELECT id FROM utenti WHERE LOWER(email) = ? OR (? <> '' AND persona_id = ?) ORDER BY ultimo_accesso DESC LIMIT 1", [$email, $personaId, $personaId]);
    }

    /** @return string|null email dell'utente (null se non esiste) */
    public function emailDiUtente(int $id): ?string
    {
        $r = $this->db->riga('SELECT email FROM utenti WHERE id = ? LIMIT 1', [$id]);

        return $r ? ($r['email'] === null ? null : (string) $r['email']) : null;
    }
}
