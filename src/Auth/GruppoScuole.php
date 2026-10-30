<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Il gruppo «Scuole»: i docenti (e le segreterie) che hanno indicato una scuola. Lo stato vero resta utenti.scuola_codice: il gruppo ne è la conseguenza,
 * assegnata appena la scuola si scrive. L'amministratore può comunque aggiungerlo o toglierlo a mano da Utenti.
 */
final class GruppoScuole extends GruppoPersone
{
    public const NOME = 'Scuole';

    public function nome(): string
    {
        return self::NOME;
    }

    /** Dopo aver scritto (o tolto) la scuola di una persona: il gruppo la segue. */
    public function allinea(int $utenteId, ?string $scuolaCodice): void
    {
        trim((string) $scuolaCodice) !== '' ? $this->assegna($utenteId) : $this->togli($utenteId);
    }

    public function allineaTutti(): int
    {
        return $this->assegnaOve("scuola_codice IS NOT NULL AND scuola_codice <> ''");
    }
}
