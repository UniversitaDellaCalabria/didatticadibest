<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Anagrafi\ServizioPersone;
use App\Anagrafi\Testi;

/**
 * Chi si occupa di una risorsa: l'admin sceglie i responsabili dall'anagrafe di Ateneo (una o più persone). Ricevono le email delle richieste,
 * vedono solo le loro risorse dall'Area personale e, se abilitati, approvano, rifiutano e annullano le prenotazioni (come in LibreBooking).
 */
final class ServizioResponsabiliRisorse
{
    public function __construct(
        private ResponsabileRisorsaRepository $responsabili,
        private RisorsaRepository $risorse,
        private ServizioPersone $persone,
        private ServizioRisorse $servizio
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function elenco(int $risorsaId): array
    {
        return $this->responsabili->elenco($risorsaId);
    }

    /**
     * Aggiunge un responsabile scelto dall'anagrafe. Ritorna l'errore, null se aggiunto. Se la risorsa non ha un referente scritto, diventa il suo nome.
     */
    public function aggiungi(int $risorsaId, string $personaId): ?string
    {
        if (!$this->risorse->perId($risorsaId)) {
            return 'Risorsa non trovata.';
        }
        $p = $this->persone->persona($personaId);
        if (!$p) {
            return "Persona non trovata nell'anagrafe di Ateneo.";
        }
        $email = strtolower(trim((string) ($p['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "La persona scelta non ha un'email nell'anagrafe: non potrebbe ricevere le richieste né accedere.";
        }
        $nome = trim($p['cognome'] . ' ' . $p['nome']);
        if (!$this->responsabili->aggiungi($risorsaId, (string) $personaId, $nome, $email, true, true)) {
            return 'Questa persona è già tra i responsabili.';
        }
        $r = $this->risorse->perId($risorsaId);
        if ($r && trim((string) $r['referente']) === '') {
            $this->risorse->impostaReferente($risorsaId, Testi::nomePersona($p));
        }

        return null;
    }

    public function imposta(int $id, bool $notifiche, bool $approva): void
    {
        $this->responsabili->imposta($id, $notifiche, $approva);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->responsabili->perId($id);
    }

    public function togli(int $id): void
    {
        $this->responsabili->togli($id);
    }

    /**
     * Le risorse di cui l'utente è responsabile (per email), con le richieste da approvare.
     *
     * @param array<string, mixed>|null $utente
     * @return list<array<string, string|null>>
     */
    public function risorseDi(?array $utente): array
    {
        $email = strtolower(trim((string) ($utente['email'] ?? '')));

        return $email === '' || empty($utente['id']) ? [] : $this->responsabili->risorseDi($email);
    }

    /**
     * L'utente è responsabile della risorsa; con $approva anche abilitato a decidere sulle richieste.
     *
     * @param array<string, mixed>|null $utente
     */
    public function responsabileDi(?array $utente, int $risorsaId, bool $approva = false): bool
    {
        $email = strtolower(trim((string) ($utente['email'] ?? '')));
        if ($email === '' || empty($utente['id'])) {
            return false;
        }
        $x = $this->responsabili->perEmail($risorsaId, $email);

        return $x !== null && (!$approva || (int) $x['puo_approvare'] === 1);
    }

    /**
     * Prenotazioni da gestire di una risorsa (da approvare prima, poi confermate future), solo se l'utente ne è responsabile.
     *
     * @param array<string, mixed>|null $utente
     * @return list<array<string, string|null>>
     */
    public function prenotazioni(?array $utente, int $risorsaId): array
    {
        return $this->responsabileDi($utente, $risorsaId) ? $this->responsabili->prenotazioni($risorsaId) : [];
    }

    /**
     * Il responsabile approva, rifiuta o annulla una prenotazione della sua risorsa (chi ha prenotato riceve l'email). Ritorna l'errore, null se fatto.
     *
     * @param array<string, mixed>|null $utente
     */
    public function decidi(?array $utente, int $prenotazioneId, string $azione, string $nota = ''): ?string
    {
        $nuovo = ['approva' => 'confermata', 'rifiuta' => 'rifiutata', 'annulla' => 'annullata'][$azione] ?? null;
        $p = $nuovo ? $this->servizio->prenotazione($prenotazioneId) : null;
        if (!$p) {
            return 'Prenotazione non trovata.';
        }
        if (!$this->responsabileDi($utente, (int) $p['risorsa_id'], true)) {
            return 'Non sei abilitato a decidere su questa risorsa.';
        }
        $nota = mb_substr(trim($nota), 0, 500);
        if ($nota !== '') {
            $this->risorse->impostaNota($prenotazioneId, $nota);
        }

        return $this->servizio->cambiaStato($prenotazioneId, $nuovo, true) ? null : 'La prenotazione era già stata gestita.';
    }
}
