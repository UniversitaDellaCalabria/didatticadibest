<?php

declare(strict_types=1);

namespace App\Auth;

/** Risposte che interrompono la richiesta quando l'accesso non è consentito (stessi codici e testi di prima). */
final class RispostaHttp
{
    /** Spostata da nega_accesso() di inc/eventi_progetti.php. */
    public static function negaAccesso(): never
    {
        http_response_code(403);
        die('Accesso negato.');
    }

    /** Token CSRF mancante o diverso (csrf_verify()). */
    public static function csrfNonValido(): never
    {
        http_response_code(403);
        die('Richiesta non valida o sessione scaduta. Torna indietro, ricarica la pagina e riprova.');
    }
}
