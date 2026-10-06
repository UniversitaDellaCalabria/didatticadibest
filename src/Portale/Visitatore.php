<?php

declare(strict_types=1);

namespace App\Portale;

/** Chi sta guardando il sito pubblico: serve per le voci di menu riservate a un ruolo o a chi è entrato. */
final class Visitatore
{
    public const RUOLO_OSPITE = 5;
    public const SOLO_COLLEGATI = -1;

    /** @param list<string> $ruoliSecondari */
    public function __construct(
        public readonly bool $collegato,
        public readonly int $ruolo,
        public readonly array $ruoliSecondari,
    ) {
    }

    /**
     * Dai dati della sessione (utente_id, utente_ruolo_id, utente_ruoli_secondari): li passa il controller.
     *
     * @param array<string, mixed> $sessione
     */
    public static function daSessione(array $sessione): self
    {
        return new self(
            !empty($sessione['utente_id']),
            isset($sessione['utente_ruolo_id']) ? (int) $sessione['utente_ruolo_id'] : self::RUOLO_OSPITE,
            !empty($sessione['utente_ruoli_secondari']) ? explode(',', (string) $sessione['utente_ruoli_secondari']) : [],
        );
    }

    /** Amministratore o gestore (ruolo 1 o 2, principale o secondario). */
    public function amministratore(): bool
    {
        return $this->ruolo === 1 || $this->ruolo === 2 || in_array('1', $this->ruoliSecondari) || in_array('2', $this->ruoliSecondari);
    }

    /** Una voce con questa visibilità: 0 tutti, -1 solo chi è entrato, >0 quel ruolo (o un amministratore). */
    public function vede(int $ruoloVisibilita): bool
    {
        if ($ruoloVisibilita === self::SOLO_COLLEGATI) {
            return $this->collegato;
        }
        if ($ruoloVisibilita > 0) {
            return $this->collegato && ($this->ruolo === $ruoloVisibilita || in_array((string) $ruoloVisibilita, $this->ruoliSecondari) || $this->amministratore());
        }

        return true;
    }
}
