<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;

/** Turni degli eventi e edizioni dei progetti: salvataggio dalla scheda, posti liberati dalla lista d'attesa. */
final class ServizioTurni
{
    public function __construct(
        private TurnoRepository $turni,
        private EventoRepository $eventi,
        private ServizioEventi $servizioEventi,
        private RegoleIscrizioni $regole,
        private AuleTurni $aule,
        private PresentazioneComune $presentazione,
        private Mailer $mailer,
        private Sito $sito,
    ) {
    }

    /**
     * Posti liberati (capienza aumentata): conferma chi è in lista d'attesa, in ordine di arrivo, e lo avvisa per email.
     *
     * @return int quante persone sono state confermate
     */
    public function promuoviAttesa(int $turnoId): int
    {
        $postiLiberi = $this->turni->maxPosti($turnoId) - $this->regole->postiOccupati($turnoId);
        $promossi = 0;
        if ($postiLiberi <= 0) {
            return 0;
        }
        foreach ($this->turni->inAttesa($turnoId) as $pren) {
            $postiRichiesti = (int) $pren['num_posti'];
            if ($postiLiberi < $postiRichiesti) {
                break;
            }
            $this->turni->confermaPrenotazione((int) $pren['id']);
            $this->regole->decadiAtteseVincolate((int) $pren['id']);
            $postiLiberi -= $postiRichiesti;
            ++$promossi;
            $link = $this->sito->urlBase() . '/stampa_ricevuta.php?code=' . urlencode((string) $pren['codice_prenotazione']);
            $body = '<p>Gentile ' . htmlspecialchars((string) $pren['nome']) . ", la tua prenotazione in lista d'attesa è stata <strong>confermata</strong> per l'evento <strong>" . htmlspecialchars((string) $pren['evento_titolo']) . '</strong>.</p>'
                . "<p><a href='" . htmlspecialchars($link) . "' style='background:#B80000; color:#fff; padding:10px; border-radius:6px; text-decoration:none;'>Scarica la ricevuta</a></p>";
            $this->mailer->invia((string) $pren['email'], 'Posto Confermato: ' . $pren['evento_titolo'], $body, $this->presentazione->coloreAreaTurno($turnoId));
        }

        return $promossi;
    }

    /**
     * Salva i turni dell'evento: aggiorna gli esistenti, crea i nuovi, elimina quelli tolti dalla pagina (solo se senza prenotazioni attive).
     * $turni = righe di leggi_turni_post(). Ritorna quante persone sono state confermate dalla lista d'attesa.
     *
     * @param list<array<string, mixed>> $turni
     * @param list<string> $avvisi
     */
    public function salvaTurniEvento(int $eventoId, array $turni, array &$avvisi, int $utenteId): int
    {
        $esistenti = $this->eventi->idTurni($eventoId);
        $tenuti = [];
        $promossi = 0;
        foreach ($turni as $t) {
            if ($t['id'] > 0 && in_array($t['id'], $esistenti, true)) {
                $this->turni->aggiorna((int) $t['id'], $eventoId, $t);
                $tenuti[] = $t['id'];
                $promossi += $this->promuoviAttesa((int) $t['id']);
            } else {
                $tenuti[] = $this->turni->inserisci($eventoId, $t);
            }
            // Aula collegata: si salva sul turno e si occupa (o libera) lo slot nel calendario delle risorse
            $tidA = (int) end($tenuti);
            $this->turni->impostaRisorsa($tidA, $t['aula'] ?? null);
            $this->aule->sincronizza($tidA, $avvisi, $utenteId);
        }
        foreach (array_diff($esistenti, $tenuti) as $via) {
            if ($this->turni->prenotazioniAttive($via) > 0) {
                $avvisi[] = 'non ho eliminato il turno "' . Turni::etichetta($this->turni->nomeEData($via)) . '" perché ha prenotazioni attive: annullale prima da Iscritti';
                continue;
            }
            $this->servizioEventi->eliminaTurno($via);
        }

        return $promossi;
    }

    /**
     * Edizioni (turni senza data) di un progetto: aggiorna le esistenti, crea le nuove ed elimina quelle tolte dalla maschera, ma solo
     * se nessuno è iscritto o in attesa (le altre restano con le loro date). Da usare dentro una transazione.
     *
     * @param list<array<string, mixed>> $edizioni id, nome, posti, apertura, chiusura, min, max
     * @return list<string> nomi delle edizioni tolte dalla maschera ma non eliminate perché hanno iscritti
     */
    public function salvaEdizioniProgetto(int $eventoId, array $edizioni, int $listaAttesa, int $approvazione): array
    {
        $esistenti = $this->eventi->idTurni($eventoId);
        $tenuti = [];
        $nonTolte = [];
        $n = count($edizioni);
        foreach ($edizioni as $k => $ed) {
            // Nome dell'edizione: quello scritto, altrimenti «Edizione N» (o «Iscrizioni» se è l'unica)
            $nome = $ed['nome'] !== '' ? $ed['nome'] : ($n > 1 ? 'Edizione ' . ($k + 1) : 'Iscrizioni');
            if ($ed['id'] > 0 && in_array($ed['id'], $esistenti, true)) {
                $this->turni->aggiornaEdizione((int) $ed['id'], $nome, $ed, $listaAttesa, $approvazione);
                $tenuti[] = $ed['id'];
            } else {
                $tenuti[] = $this->turni->inserisciEdizione($eventoId, $nome, $ed, $listaAttesa, $approvazione);
            }
        }
        foreach (array_diff($esistenti, $tenuti) as $via) {
            if ($this->turni->prenotazioniAttive($via) > 0) {
                $nonTolte[] = (string) $this->turni->nome($via);
                continue;
            }
            $this->servizioEventi->eliminaTurno($via);
        }

        return $nonTolte;
    }
}
