<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/** Registro degli accessi al portale (tabella log_accessi): scritto al login SSO, letto da admin/log_accessi.php. */
final class LogAccessiRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Spostata da registra_accesso_sso() di inc/base.php: IP e browser già tagliati dal chiamante. */
    public function registra(int $utenteId, mixed $email, mixed $nome, mixed $cognome, string $ip, string $userAgent, string $tipo = 'sso'): void
    {
        $this->db->esegui(
            'INSERT INTO log_accessi (utente_id, email, nome, cognome, ip, user_agent, tipo) VALUES (?,?,?,?,?,?,?)',
            [$utenteId, self::testo($email), self::testo($nome), self::testo($cognome), $ip, $userAgent, $tipo]
        );
    }

    /** Accessi che corrispondono ai filtri (testo cercato in email, nome, cognome, IP; date AAAA-MM-GG già validate). */
    public function conta(string $cerca, string $dal, string $al): int
    {
        [$cond, $par] = self::condizione($cerca, $dal, $al);

        return (int) $this->db->valore("SELECT COUNT(*) as c FROM log_accessi $cond", $par);
    }

    /** @return list<array<string, mixed>> una pagina di accessi, dal più recente */
    public function pagina(string $cerca, string $dal, string $al, int $perPagina, int $offset): array
    {
        [$cond, $par] = self::condizione($cerca, $dal, $al);

        return $this->db->righe("SELECT * FROM log_accessi $cond ORDER BY created_at DESC LIMIT $perPagina OFFSET $offset", $par);
    }

    /** @return array{0: string, 1: list<string>} */
    private static function condizione(string $cerca, string $dal, string $al): array
    {
        $cond = 'WHERE 1';
        $par = [];
        if (!empty($cerca)) {
            $cond .= ' AND (email LIKE ? OR nome LIKE ? OR cognome LIKE ? OR ip LIKE ?)';
            $like = '%' . $cerca . '%';
            array_push($par, $like, $like, $like, $like);
        }
        if (!empty($dal)) {
            $cond .= ' AND DATE(created_at) >= ?';
            $par[] = $dal;
        }
        if (!empty($al)) {
            $cond .= ' AND DATE(created_at) <= ?';
            $par[] = $al;
        }

        return [$cond, $par];
    }

    // I valori arrivano dalla riga dell'utente: null resta null (come con bind_param)
    private static function testo(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
