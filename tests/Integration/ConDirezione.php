<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Per i test che hanno bisogno del Direttore: la Direzione del Dipartimento è l'ufficio «direzione» (tabella uffici) con le persone di
 * uffici_persone; l'interruttore «Direttore assente» sta in impostazioni_sistema. Il codice fiscale viene dall'utente collegato (tabella utenti).
 * Si usa dentro una classe di test (DatabaseDiProva): tabelleUffici() si aggiunge a tabelle(), impostaDirettore() prepara i dati.
 */
trait ConDirezione
{
    /** Colonne dell'interruttore «Direttore assente» da mettere nella tabella impostazioni_sistema dei test. */
    protected const COLONNE_ASSENZA = "direttore_assente TINYINT(1) NOT NULL DEFAULT 0, direttore_assente_fino DATE NULL, direttore_assente_da VARCHAR(150) NOT NULL DEFAULT '', direttore_assente_il DATETIME NULL";

    /** @return array<string, string> le tabelle degli uffici (solo le colonne usate); impostazioni_sistema ha bisogno delle colonne direttore_assente* (vedi COLONNE_ASSENZA) */
    protected function tabelleUffici(): array
    {
        return [
            'uffici' => "CREATE TABLE uffici (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(150) NOT NULL DEFAULT '', descrizione VARCHAR(500) DEFAULT '', chiave VARCHAR(30) NULL, ambito VARCHAR(12) NOT NULL DEFAULT 'dipartimento', smista TINYINT(1) NOT NULL DEFAULT 0, segue_corsi TINYINT(1) NOT NULL DEFAULT 0, ordine INT NOT NULL DEFAULT 0, tipo VARCHAR(20) NOT NULL DEFAULT '', consiglio_id INT NULL, attivo TINYINT(1) NOT NULL DEFAULT 1, creato_il DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'uffici_persone' => "CREATE TABLE uffici_persone (id INT AUTO_INCREMENT PRIMARY KEY, ufficio_id INT NOT NULL, persona_id VARCHAR(80) NULL, email VARCHAR(150) NOT NULL DEFAULT '', nominativo VARCHAR(200) NOT NULL DEFAULT '', struttura VARCHAR(255) DEFAULT '', ruolo VARCHAR(100) DEFAULT '', compiti VARCHAR(100) NOT NULL DEFAULT '', corsi TEXT NULL, op_id INT NULL, creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (ufficio_id, email))",
        ];
    }

    /** Il Direttore (o il vice, con $ruolo) della Direzione: crea l'ufficio se manca. Con $cf indica anche il codice fiscale dell'utente collegato (serve alla verifica del certificato). */
    protected function impostaDirettore(string $nome, string $email, string $cf = '', string $ruolo = 'Direttore'): void
    {
        $this->db->esegui('INSERT IGNORE INTO impostazioni_sistema (id) VALUES (1)');
        $uff = (int) $this->db->valore("SELECT id FROM uffici WHERE chiave = 'direzione'");
        if ($uff === 0) {
            $uff = $this->db->inserisci("INSERT INTO uffici (nome, chiave) VALUES ('Direzione del Dipartimento', 'direzione')");
        }
        $this->db->esegui('DELETE FROM uffici_persone WHERE ufficio_id = ? AND ruolo = ?', [$uff, $ruolo]);
        $this->db->esegui('INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo) VALUES (?, ?, ?, ?)', [$uff, strtolower($email), $nome, $ruolo]);
        if ($cf !== '') {
            $this->db->esegui('UPDATE utenti SET codice_fiscale = ? WHERE LOWER(email) = ?', [strtoupper($cf), strtolower($email)]);
        }
    }

    /** Toglie il Direttore: nessuno firma. */
    protected function togliDirettore(): void
    {
        $this->db->esegui("DELETE FROM uffici_persone WHERE ufficio_id IN (SELECT id FROM uffici WHERE chiave = 'direzione')");
    }
}
