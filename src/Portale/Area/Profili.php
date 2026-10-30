<?php

declare(strict_types=1);

namespace App\Portale\Area;

/** I profili dell'area personale: chi entra vede i riquadri pensati per lui (una persona può avere più profili). */
final class Profili
{
    public const STUDENTE = 'studente';
    public const DOCENTE = 'docente';
    public const DIRETTORE = 'direttore';
    public const PERSONALE = 'personale';
    public const GESTORE = 'gestore';
    public const AMMINISTRATORE = 'amministratore';
    public const SCUOLA = 'scuola';
    public const AZIENDA = 'azienda';
    public const ESTERNO = 'esterno';

    /** Chiave => nome, nell'ordine in cui si mostrano. */
    public const NOMI = [
        self::STUDENTE => 'Studente',
        self::DOCENTE => 'Docente',
        self::DIRETTORE => 'Direttore',
        self::PERSONALE => 'Personale e uffici',
        self::GESTORE => 'Gestore di area o evento',
        self::AMMINISTRATORE => 'Amministratore',
        self::SCUOLA => 'Scuola',
        self::AZIENDA => 'Azienda',
        self::ESTERNO => 'Ospite',
    ];

    /** Icona Font Awesome di ogni profilo. */
    public const ICONE = [
        self::STUDENTE => 'fa-user-graduate',
        self::DOCENTE => 'fa-chalkboard-user',
        self::DIRETTORE => 'fa-building-columns',
        self::PERSONALE => 'fa-briefcase',
        self::GESTORE => 'fa-calendar-check',
        self::AMMINISTRATORE => 'fa-user-shield',
        self::SCUOLA => 'fa-school',
        self::AZIENDA => 'fa-building',
        self::ESTERNO => 'fa-door-open',
    ];

    /** @return list<string> le chiavi valide di un elenco «a,b,c», senza ripetizioni e nell'ordine dei profili */
    public static function da(string $csv): array
    {
        $presenti = array_flip(array_map('trim', explode(',', $csv)));

        return array_values(array_filter(array_keys(self::NOMI), static fn (string $p): bool => isset($presenti[$p])));
    }

    /** @param list<string> $profili */
    public static function inCsv(array $profili): string
    {
        return implode(',', self::da(implode(',', $profili)));
    }
}
