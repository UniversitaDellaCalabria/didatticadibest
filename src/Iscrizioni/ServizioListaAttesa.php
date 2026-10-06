<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Portale\ColoriAree;

/**
 * Liste d'attesa: quando si libera un posto lo si offre a chi è in coda (24 ore per confermare) e si gestiscono le scadenze.
 * Spostato da promuovi_lista_attesa() e check_automazioni_sistema() di inc/liste_attesa.php, che restano come facciate.
 */
final class ServizioListaAttesa
{
    public function __construct(
        private ListaAttesaRepository $coda,
        private PrenotazioneRepository $prenotazioni,
        private Mailer $mailer,
        private Sito $sito,
        private ColoriAree $colori,
        private Orologio $orologio,
    ) {
    }

    /**
     * Offre i posti liberi del turno a chi è in coda, in ordine di arrivo, finché c'è spazio; ognuno riceve l'email con il link per confermare.
     * A meno di 24 ore dall'evento non si promuove più nessuno (i turni senza data non hanno scadenza).
     */
    public function promuovi(int $turnoId): void
    {
        $turno = $this->coda->turno($turnoId);
        if ($turno === null) {
            return;
        }
        if (!empty($turno['data_turno'])) {
            $inizio = $turno['data_turno'] . ' ' . ($turno['orario_inizio'] ?: '00:00:00');
            if (strtotime($inizio) <= $this->orologio->adesso()->modify('+24 hours')->getTimestamp()) {
                return;
            }
        }

        $postiLiberi = (int) $turno['max_posti'] - $this->prenotazioni->postiOccupati($turnoId);

        // Si promuove finché c'è spazio
        while ($postiLiberi > 0) {
            $promosso = $this->coda->primoInAttesa($turnoId);
            if ($promosso === null) {
                break; // nessun altro in coda
            }
            if ($postiLiberi < (int) $promosso['num_posti']) {
                break; // il primo in coda chiede più posti di quelli disponibili: ci si ferma
            }
            $scadenza = $this->orologio->adesso()->modify('+24 hours')->format('Y-m-d H:i:s');
            if (!$this->coda->offriPosto((int) $promosso['id'], $scadenza)) {
                break; // se il database non risponde ci si ferma in sicurezza
            }
            $this->avvisa($promosso, $turnoId, $scadenza);
            $postiLiberi -= (int) $promosso['num_posti'];
        }
    }

    /**
     * Controllo periodico (ogni 5 minuti, anche senza cron): chi non ha confermato il posto offerto entro 24 ore lo perde
     * e il posto passa al successivo; a meno di 24 ore dall'evento le liste d'attesa vengono chiuse.
     */
    public function controllaScadenze(): void
    {
        $adesso = $this->orologio->adesso();
        $ora = $adesso->format('Y-m-d H:i:s');

        foreach ($this->coda->offerteScadute($ora) as $riga) {
            $this->coda->segnaScaduta((int) $riga['id']);
            $this->promuovi((int) $riga['turno_id']);
        }

        $limite = $adesso->modify('+24 hours')->format('Y-m-d H:i:s');
        foreach ($this->coda->turniInPartenza($ora, $limite) as $turno) {
            $this->coda->chiudiCoda((int) $turno['id']);
        }
    }

    /** @param array<string, string|null> $promosso */
    private function avvisa(array $promosso, int $turnoId, string $scadenza): void
    {
        $link = $this->sito->urlBase() . '/area_personale.php?conferma_posto=' . $promosso['id'];
        $oggetto = 'Azione Richiesta: Si è liberato un posto per ' . $promosso['evento_titolo'];
        $corpo = '<p>Ottime notizie <strong>' . htmlspecialchars((string) $promosso['nome']) . "</strong>!</p>
                                     <p>Si è appena liberato un posto per l'evento <strong>" . htmlspecialchars((string) $promosso['evento_titolo']) . "</strong>.</p>
                                     <div style='background-color:#fff3cd; color:#856404; padding:15px; border-left:5px solid #ffeeba; margin:20px 0;'>
                                       <strong>ATTENZIONE:</strong> Hai esattamente <strong>24 ore</strong> di tempo per confermare la tua presenza. Se non confermi entro il " . date('d/m/Y H:i', (int) strtotime($scadenza)) . ", il posto verrà riassegnato allo studente successivo.
                                     </div>
                                     <p><a href='$link' style='background-color:#198754; color:white; padding:12px 25px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>CONFERMA IL MIO POSTO</a></p>";

        $this->mailer->invia((string) $promosso['email'], $oggetto, $corpo, $this->colori->delTurno($turnoId));
    }
}
