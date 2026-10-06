<?php

declare(strict_types=1);

namespace App\Risorse;

/** Gestione delle risorse di un'area dal pannello: salvataggio con orari, eliminazione, chiusure. */
final class ServizioGestioneRisorse
{
    public function __construct(private RisorsaRepository $risorse)
    {
    }

    /**
     * Salva la risorsa (nuova se $id è 0) con colore, docente dell'anagrafe e orari; ritorna l'id.
     * Con un docente le email di notifica e il referente, se vuoti, diventano i suoi. Il docente ($persona) è la sua scheda
     * dell'anagrafe (id, email), $nomeDocente il nome da mostrare come referente.
     *
     * @param array<string, mixed> $v dati validati dal modulo (vedi RisorsaRepository::aggiorna())
     * @param array<string, mixed>|null $persona
     * @param list<array{0: int, 1: string, 2: string}> $fasce [giorno, dalle, alle]
     */
    public function salva(int $paginaId, int $id, array $v, ?string $colore, ?array $persona, ?string $nomeDocente, array $fasce): int
    {
        if ($id) {
            $this->risorse->aggiorna($id, $paginaId, $v);
        } else {
            $id = $this->risorse->crea($paginaId, $v);
        }
        // Colore della riga nella vista a calendario (vuoto = grigio)
        $this->risorse->impostaColore($id, $colore);
        // Sportello di ricevimento di un docente dell'anagrafe: il docente ne gestisce orari e appuntamenti da ricevimento.php
        $this->risorse->impostaPersona($id, $persona['id'] ?? null);
        if ($persona) {
            // Notifiche al docente e referente, se non indicati
            if ($v['emails'] === '' && !empty($persona['email'])) {
                $this->risorse->impostaEmailNotifiche($id, (string) $persona['email']);
            }
            if ($v['referente'] === '') {
                $this->risorse->impostaReferente($id, (string) $nomeDocente);
            }
        }
        $this->risorse->sostituisciOrari($id, $fasce);

        return $id;
    }

    /**
     * Elimina la risorsa solo se non ha prenotazioni future; altrimenti la rende non prenotabile.
     *
     * @return int numero di prenotazioni future (0 = eliminata, altrimenti disattivata)
     */
    public function eliminaODisattiva(int $id): int
    {
        $future = $this->risorse->prenotazioniFuture($id);
        if ($future > 0) {
            $this->risorse->disattiva($id);

            return $future;
        }
        $this->risorse->elimina($id);

        return 0;
    }
}
