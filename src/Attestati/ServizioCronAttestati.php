<?php

declare(strict_types=1);

namespace App\Attestati;

use App\Core\Orologio;
use App\Email\InvioEmail;
use App\Portale\ColoriAree;

/**
 * Invio automatico degli attestati (cron_attestati.php): a chi era presente a un evento ormai concluso e non ha ancora
 * ricevuto l'email; per le classi gli attestati degli studenti vanno al docente.
 */
final class ServizioCronAttestati
{
    public function __construct(
        private AttestatoRepository $repo,
        private ServizioAttestati $attestati,
        private InvioEmail $invio,
        private ColoriAree $colori,
        private Orologio $orologio,
    ) {
    }

    /**
     * Invia le email in attesa e ritorna quante ne sono partite.
     *
     * @param string $dominio indirizzo del sito per i link nelle email (https://host/cartella)
     */
    public function invia(string $dominio): int
    {
        $inviate = 0;
        $adesso = $this->orologio->adesso()->format('Y-m-d H:i:s');
        foreach ($this->repo->daInviare($adesso) as $row) {
            // Progetti: attestato solo se previsto e a progetto concluso; per le scuole gli attestati degli studenti vanno al docente
            $regola = $this->attestati->regola((int) $row['evento_id']);
            if ($regola === RegolaAttestato::No || $regola === RegolaAttestato::Attendi) {
                continue;
            }
            if ($regola === RegolaAttestato::Gruppo) {
                if ($this->attestati->inviaGruppo((int) $row['id']) === true) {
                    $inviate++;
                }
                continue;
            }

            $linkAttestato = $dominio . '/stampa_attestato.php?code=' . urlencode((string) $row['codice_prenotazione']);
            $this->invio->invia(
                (string) $row['email'],
                'evento_attestato_cron',
                ['nome' => $row['nome'] . ' ' . $row['cognome'], 'titolo' => (string) $row['titolo'], 'data_testo' => !empty($row['data_turno']) ? ' del ' . date('d/m/Y', (int) strtotime((string) $row['data_turno'])) : ''],
                $linkAttestato,
                [],
                '',
                $this->colori->delTurno((int) $row['turno_id'])
            );
            $this->repo->segnaAttestatoInviato((int) $row['id']);
            $inviate++;
        }

        return $inviate;
    }
}
