<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Esito;

/**
 * Promemoria a richiesta dell'ufficio per una pratica ferma: scrive a chi deve fare il passo successivo (lo studente
 * che deve integrare, il firmatario o il Direttore, i docenti che devono confermare).
 */
final class RicordaPratica
{
    public function __construct(
        private PraticaRepository $pratiche,
        private FirmaPratica $firme,
        private ServizioConfermeDocenti $conferme,
        private NotifichePratiche $notifiche
    ) {
    }

    public function ricorda(int $praticaId): Esito
    {
        $p = $this->pratiche->perId($praticaId);
        if ($p === null) {
            return Esito::errore('Pratica non trovata.');
        }
        if (($p['stato'] ?? '') === 'integrazione') {
            if (!filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                return Esito::errore("Lo studente non ha un'email valida.");
            }
            $this->notifiche->email($p, 'msg_ufficio', 'Promemoria: la pratica aspetta ancora le integrazioni richieste. Aprila dal pulsante qui sotto per rispondere.');

            return Esito::ok('Promemoria mandato allo studente.');
        }
        if (!in_array($p['stato'] ?? '', ['inviata', 'in_lavorazione'], true)) {
            return Esito::errore('La pratica non è in attesa di nessuno: il promemoria non serve.');
        }
        $firmatario = $this->firme->ricorda($p);
        if ($firmatario !== null) {
            return Esito::ok("Promemoria di firma mandato a $firmatario.");
        }
        $docenti = $this->conferme->ricorda($p);
        if ($docenti) {
            return Esito::ok('Promemoria di conferma mandato a: ' . implode(', ', $docenti) . '.');
        }

        return Esito::errore('Nessuno deve fare qualcosa su questa pratica: è in carico all\'ufficio.');
    }
}
