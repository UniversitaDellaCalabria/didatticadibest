<?php

declare(strict_types=1);

namespace App\Portale;

use App\Anagrafi\ServizioScuole;
use App\Iscrizioni\AreaPersonaleRepository;

/**
 * «Sono una scuola» al primo accesso: la persona sceglie la sua scuola dall'anagrafe e da quel momento il suo profilo è «Scuola» (utenti.scuola_codice, gruppo
 * «Scuole»). Vale solo una scuola dell'anagrafe: un codice inventato o di una scuola che non esiste è rifiutato.
 */
final class ScegliScuola
{
    public function __construct(private ServizioScuole $scuole, private AreaPersonaleRepository $area)
    {
    }

    /** @return string|null il messaggio d'errore, null se la scuola è stata impostata */
    public function imposta(int $utenteId, string $codice): ?string
    {
        if ($utenteId <= 0) {
            return 'Accedi per indicare la tua scuola.';
        }
        $scuola = $this->scuole->perCodice($codice);
        if ($scuola === null) {
            return 'Scegli la scuola dall\'elenco che compare mentre scrivi il nome.';
        }
        $this->area->impostaScuolaUtente($utenteId, strtoupper(trim($codice)));

        return null;
    }
}
