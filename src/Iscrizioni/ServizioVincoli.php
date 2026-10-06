<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Eventi\Turni;
use App\Infrastructure\Mail\Mailer;
use App\Portale\ColoriAree;

/**
 * Vincolo di iscrizione per area (pagine_eventi.limite_iscrizioni: nessuno | un_evento | un_turno).
 * Le liste d'attesa NON contano: si può stare in attesa su più turni o eventi. Appena una prenotazione dello stesso ambito
 * diventa «confermata», le altre richieste in sospeso della persona decadono (decadiAttese).
 * Spostato dalle funzioni di inc/dati.php (scope_vincolo_sql, trova_iscrizione_vincolata, get_mie_iscrizioni_area,
 * decadi_attese_vincolate), che restano come facciate.
 */
final class ServizioVincoli
{
    public function __construct(
        private PrenotazioneRepository $prenotazioni,
        private ServizioListaAttesa $listaAttesa,
        private Mailer $mailer,
        private ColoriAree $colori,
        private RegistroOperazioni $registro,
    ) {
    }

    /** Condizione SQL (alias t, e) che delimita l'ambito del vincolo, oppure null se non c'è vincolo. */
    public static function condizioneAmbito(string $limite, int $paginaId, int $eventoId): ?string
    {
        if ($limite === 'un_evento') {
            return "e.pagina_id = $paginaId AND e.archiviato = 0";
        }
        if ($limite === 'un_turno') {
            return "t.evento_id = $eventoId";
        }

        return null;
    }

    /**
     * La prenotazione attiva (non in lista d'attesa) che blocca una nuova iscrizione, oppure null.
     *
     * @return array<string, mixed>|null
     */
    public function iscrizioneVincolata(string $limite, int $paginaId, int $eventoId, int $utenteId, string $email, string $matricola): ?array
    {
        $ambito = self::condizioneAmbito($limite, $paginaId, $eventoId);
        if ($ambito === null) {
            return null;
        }

        return $this->prenotazioni->iscrizioneVincolata($ambito, $email, $utenteId, $matricola);
    }

    /**
     * Mappa evento_id => [turno_id => stato] delle prenotazioni attive dell'utente nell'area.
     *
     * @return array<int, array<int, string>>
     */
    public function mieIscrizioniArea(int $paginaId, int $utenteId, string $email): array
    {
        $mappa = [];
        foreach ($this->prenotazioni->iscrizioniAttiveNellArea($paginaId, $utenteId, $email) as $r) {
            $mappa[(int) $r['evento_id']][(int) $r['turno_id']] = (string) $r['stato'];
        }

        return $mappa;
    }

    /**
     * Da chiamare DOPO che una prenotazione è diventata «confermata». Se l'area ha un limite iscrizioni, annulla le altre
     * richieste in sospeso della stessa persona nello stesso ambito (liste d'attesa, posti offerti in attesa di conferma,
     * richieste da approvare), ripassa i posti liberati alla lista d'attesa e avvisa l'utente con una email. Ritorna quante ne annulla.
     */
    public function decadiAttese(int $prenotazioneId): int
    {
        $c = $this->prenotazioni->perVincolo($prenotazioneId);
        if ($c === null || $c['stato'] !== 'confermata') {
            return 0;
        }
        $ambito = self::condizioneAmbito((string) ($c['limite_iscrizioni'] ?? 'nessuno'), (int) $c['pagina_id'], (int) $c['evento_id']);
        if ($ambito === null) {
            return 0;
        }

        $email = strtolower((string) $c['email']);
        $annullate = [];
        $turniDaRipassare = [];
        foreach ($this->prenotazioni->richiesteInSospeso($ambito, $prenotazioneId, $email, (int) $c['utente_id'], (string) ($c['matricola'] ?? '')) as $a) {
            // «AND stato = …» evita di annullare una riga cambiata nel frattempo
            if (!$this->prenotazioni->annullaSeNelloStato((int) $a['id'], (string) $a['stato'])) {
                continue;
            }
            $annullate[] = $a;
            // richiesta_conferma / da_approvare tenevano un posto: va offerto al prossimo in coda
            if ($a['stato'] !== 'in_attesa') {
                $turniDaRipassare[(int) $a['turno_id']] = true;
            }
        }
        if (!$annullate) {
            return 0;
        }

        foreach (array_keys($turniDaRipassare) as $turnoId) {
            $this->listaAttesa->promuovi($turnoId);
        }

        $this->registro->registra("Decadenza liste d'attesa (limite iscrizioni)", ['Prenotazione confermata' => $prenotazioneId, 'Annullate' => implode(',', array_column($annullate, 'id'))]);

        if ($email !== '') {
            $voci = '';
            foreach ($annullate as $a) {
                $voci .= '<li><strong>' . htmlspecialchars((string) $a['evento_titolo']) . '</strong> — ' . htmlspecialchars(Turni::etichetta($a)) . '</li>';
            }
            $corpo = '<p>Gentile <strong>' . htmlspecialchars((string) $c['nome']) . '</strong>,</p>'
                   . '<p>la tua prenotazione per <strong>' . htmlspecialchars((string) $c['evento_titolo']) . '</strong> è <strong>confermata</strong>.</p>'
                   . "<p>Poiché in quest'area è consentita una sola iscrizione, le tue altre richieste in lista d'attesa sono state annullate automaticamente:</p>"
                   . "<ul>$voci</ul>";
            $this->mailer->invia($email, "Liste d'attesa annullate: iscrizione confermata a " . $c['evento_titolo'], $corpo, $this->colori->delTurno((int) $c['turno_id']));
        }

        return count($annullate);
    }
}
