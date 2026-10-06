<?php

declare(strict_types=1);

namespace App\Avvisi;

/** Iscrizione agli avvisi per email dei nuovi eventi (tabella avvisi_iscrizioni). */
final class Iscrizione
{
    /** @param list<string> $ambiti */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly array $ambiti,
        public readonly bool $scuole,
        public readonly string $token,
        public readonly ?int $utenteId,
        public readonly ?string $confermataIl,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function daRiga(array $r): self
    {
        return new self(
            (int) $r['id'],
            (string) $r['email'],
            array_values(array_filter(explode(',', (string) $r['ambiti']))),
            (bool) (int) $r['scuole'],
            (string) $r['token'],
            isset($r['utente_id']) ? (int) $r['utente_id'] : null,
            isset($r['confermata_il']) ? (string) $r['confermata_il'] : null,
        );
    }

    public function confermata(): bool
    {
        return $this->confermataIl !== null;
    }
}
