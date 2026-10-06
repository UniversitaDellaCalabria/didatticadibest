<?php

declare(strict_types=1);

namespace App\Sistema;

use App\Core\Database;

/**
 * Letture del registro degli invii (tabella log_email, scritta da App\Infrastructure\Mail\SmtpMailer):
 * pannello Sistema, controllo del sito e riepilogo settimanale. La tabella può mancare (si crea al primo invio):
 * in quel caso le letture danno risultati vuoti, come prima.
 */
final class LogEmailRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> ultimi invii, dal più recente */
    public function ultimi(int $quanti = 100): array
    {
        return $this->db->righe('SELECT * FROM log_email ORDER BY id DESC LIMIT ' . $quanti);
    }

    /** @return array{ok: int, ko: int} invii accettati e falliti degli ultimi 7 giorni */
    public function esitiSettimana(): array
    {
        $s = $this->db->riga('SELECT SUM(esito = 1) AS ok, SUM(esito = 0) AS ko FROM log_email WHERE created_at >= NOW() - INTERVAL 7 DAY');

        return $s ? ['ok' => (int) $s['ok'], 'ko' => (int) $s['ko']] : ['ok' => 0, 'ko' => 0];
    }

    /** @return array{ko: mixed, tot: mixed}|null non partite e totale delle ultime 24 ore */
    public function esitiGiorno(): ?array
    {
        $x = $this->db->riga('SELECT SUM(esito = 0) AS ko, COUNT(*) AS tot FROM log_email WHERE created_at >= NOW() - INTERVAL 1 DAY');

        return $x ? ['ko' => $x['ko'], 'tot' => $x['tot']] : null;
    }

    /** @return array{ok: int, ko: int, mail: int}|null totali della settimana, con gli invii ripiegati su mail() */
    public function totaliSettimana(): ?array
    {
        $row = $this->db->riga("SELECT SUM(esito = 1) AS ok, SUM(esito = 0) AS ko, SUM(esito = 1 AND canale = 'mail()') AS via_mail FROM log_email WHERE created_at >= NOW() - INTERVAL 7 DAY");

        return $row ? ['ok' => (int) $row['ok'], 'ko' => (int) $row['ko'], 'mail' => (int) $row['via_mail']] : null;
    }

    /** @return list<array<string, mixed>> errori della settimana raggruppati (errore, n, ultimo, chi) */
    public function erroriSettimana(): array
    {
        return $this->db->righe("SELECT errore, COUNT(*) AS n, MAX(created_at) AS ultimo, GROUP_CONCAT(DISTINCT destinatario ORDER BY destinatario SEPARATOR ', ') AS chi
                            FROM log_email WHERE esito = 0 AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY errore ORDER BY n DESC LIMIT 15");
    }
}
