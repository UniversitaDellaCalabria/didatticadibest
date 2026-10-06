<?php

declare(strict_types=1);

namespace App\Attestati;

use App\Core\Orologio;
use App\Infrastructure\Mail\Mailer;
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
        private Mailer $mailer,
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

            $oggetto = 'Il tuo Attestato è pronto: ' . $row['titolo'];
            $linkAttestato = $dominio . '/stampa_attestato.php?code=' . urlencode((string) $row['codice_prenotazione']);
            $linkArea = $dominio . '/area_personale.php';

            $corpo = "<p>Gentile <strong>{$row['nome']} {$row['cognome']}</strong>,</p>";
            $corpo .= "<p>Grazie per aver partecipato all'evento <strong>" . htmlspecialchars((string) $row['titolo']) . '</strong>' . (!empty($row['data_turno']) ? ' del ' . date('d/m/Y', (int) strtotime((string) $row['data_turno'])) : '') . '.</p>';
            $corpo .= '<p>Il tuo <strong>Attestato di Partecipazione</strong> è stato generato ed è ora disponibile per il download.</p>';
            $corpo .= "<p style='text-align: center; margin: 30px 0;'>\n"
                    . "                    <a href='$linkAttestato' style='background-color: #198754; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;'>📄 Scarica il tuo Attestato</a>\n"
                    . "                  </p>";
            $corpo .= "<p>In alternativa, puoi sempre recuperarlo accedendo alla tua <a href='$linkArea'>Area Personale</a>.</p>";
            $corpo .= '<p>Cordiali saluti,<br>Il team Didattica DiBEST</p>';

            $this->mailer->invia((string) $row['email'], $oggetto, $corpo, $this->colori->delTurno((int) $row['turno_id']));
            $this->repo->segnaAttestatoInviato((int) $row['id']);
            $inviate++;
        }

        return $inviate;
    }
}
