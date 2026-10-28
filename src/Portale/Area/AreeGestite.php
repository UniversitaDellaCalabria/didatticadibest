<?php

declare(strict_types=1);

namespace App\Portale\Area;

use App\Auth\Abilitazioni\AbilitazioniRepository;
use App\Portale\AreeRepository;

/**
 * Le aree che una persona può gestire (tutta l'area, o almeno un'attività, o un perimetro che la riguarda). È la regola che il pannello usa per il menu
 * (admin/admin_header.php) e che l'area personale usa per le tessere di «Il mio lavoro»: una regola sola, così i due non possono dare risposte diverse.
 */
final class AreeGestite
{
    public function __construct(private AreeRepository $aree, private AbilitazioniRepository $abilitazioni)
    {
    }

    /**
     * @param list<int> $areeAmbito aree dei perimetri della persona (aree_da_ambiti)
     * @return list<array<string, mixed>> righe di pagine_eventi
     */
    public function perUtente(int $uid, bool $amministratore, array $areeAmbito): array
    {
        $out = [];
        foreach ($this->aree->tutte() as $riga) {
            if ($amministratore) {
                $out[] = $riga;
                continue;
            }
            $id = (int) $riga['id'];
            $ok = in_array($uid, \ids_gestori_da_campi($riga['gestore_utente_id'], $riga['gestori_utenti_ids'], $riga['permessi_gestori_json']), true) || in_array($id, $areeAmbito, true);
            if (!$ok) {
                foreach ($this->abilitazioni->gestoriAttivitaArea($id) as $evento) {
                    if (in_array($uid, \ids_gestori_da_campi(0, $evento['gestori_utenti_ids'], $evento['permessi_gestori_json']), true)) {
                        $ok = true;
                        break;
                    }
                }
            }
            if ($ok) {
                $out[] = $riga;
            }
        }

        return $out;
    }
}
