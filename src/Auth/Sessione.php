<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Dati della sessione dell'utente: i servizi la ricevono nel costruttore invece di leggere $_SESSION
 * (nel sito è SessioneNativa, nei test una sessione in memoria).
 */
interface Sessione
{
    /** Valore della chiave, null se assente. */
    public function leggi(string $chiave): mixed;

    public function scrivi(string $chiave, mixed $valore): void;

    public function togli(string $chiave): void;

    /** Nuovo identificativo di sessione (con gli stessi dati) all'accesso: un id impostato prima da altri non vale più. */
    public function rigenera(): void;
}
