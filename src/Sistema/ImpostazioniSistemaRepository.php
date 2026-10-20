<?php

declare(strict_types=1);

namespace App\Sistema;

use App\Core\Database;

/** Configurazione SMTP e modelli delle email di sistema (tabella impostazioni_sistema, riga 1). */
final class ImpostazioniSistemaRepository
{
    /** Campi di testo salvati dal pannello Sistema, nell'ordine dell'UPDATE */
    public const CAMPI_TESTO = [
        'email_conferma_oggetto', 'email_conferma_corpo', 'email_canc_utente_oggetto', 'email_canc_utente_corpo',
        'email_canc_admin_oggetto', 'email_canc_admin_corpo', 'email_reminder_oggetto', 'email_reminder_corpo',
        'email_attestato_oggetto', 'email_attestato_corpo', 'email_sondaggio_oggetto', 'email_sondaggio_corpo',
    ];

    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null la riga 1 (null se manca) */
    public function riga(): ?array
    {
        return $this->db->riga('SELECT * FROM impostazioni_sistema WHERE id = 1');
    }

    /**
     * Il Direttore che firma le convenzioni FSL: nome, email (a cui arriva il link di firma) e codice fiscale (controllo del certificato).
     *
     * @return array{nome: string, email: string, cf: string}
     */
    public function direttoreConvenzioni(): array
    {
        $r = $this->riga() ?: [];

        return ['nome' => (string) ($r['conv_direttore_nome'] ?? ''), 'email' => (string) ($r['conv_direttore_email'] ?? ''), 'cf' => (string) ($r['conv_direttore_cf'] ?? '')];
    }

    public function salvaDirettoreConvenzioni(string $nome, string $email, string $cf): void
    {
        $this->db->esegui('UPDATE impostazioni_sistema SET conv_direttore_nome = ?, conv_direttore_email = ?, conv_direttore_cf = ? WHERE id = 1', [$nome, $email, $cf]);
    }
    /**
     * Salva server SMTP e, se indicati, i vecchi campi dei testi delle email (CAMPI_TESTO: ora i testi stanno in email_modelli, la pagina Sistema non li
     * manda più e le colonne restano come sono). Se manca una colonna l'aggiornamento non avviene (come prima: nessun errore a schermo).
     *
     * @param array<string, string> $modelli valori di CAMPI_TESTO (solo quelli da cambiare)
     */
    public function salva(string $host, int $porta, string $utente, string $password, string $sicurezza, string $mittenteEmail, string $mittenteNome, array $modelli = []): void
    {
        $valori = [$host, $porta, $utente, $password, $sicurezza, $mittenteEmail, $mittenteNome];
        $campi = [];
        foreach (self::CAMPI_TESTO as $c) {
            if (array_key_exists($c, $modelli)) {
                $campi[] = "$c=?";
                $valori[] = (string) $modelli[$c];
            }
        }
        $this->db->esegui('UPDATE impostazioni_sistema SET
        smtp_host=?, smtp_port=?, smtp_username=?, smtp_password=?, smtp_secure=?, smtp_from_email=?, smtp_from_name=?'
        . ($campi ? ', ' . implode(', ', $campi) : '') . '
        WHERE id = 1', $valori);
    }
}
