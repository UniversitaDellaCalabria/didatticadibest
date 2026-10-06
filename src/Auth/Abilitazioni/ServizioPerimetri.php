<?php

declare(strict_types=1);

namespace App\Auth\Abilitazioni;

/** Salvataggio dei perimetri scelti nel pannello Utenti (spostato da salva_perimetro() e dal pannello admin/utenti.php). */
final class ServizioPerimetri
{
    public function __construct(
        private AbilitazioniRepository $repo,
        private AbilitazioniAttesaRepository $attesa,
        private ServizioAbilitazioni $abilitazioni,
        private PermessiGestoreAree $permessi,
    ) {
    }

    /**
     * Sostituisce le abilitazioni dell'utente nell'area e quelle FSL / dei moduli con il perimetro scelto.
     *
     * @param array<string, mixed> $p
     */
    public function salva(int $uid, int $paginaId, array $p, int $da): void
    {
        $this->permessi->revoca($paginaId, $uid);
        $this->repo->togliGestorePrincipale($paginaId, $uid);
        $this->abilitazioni->revocaAmbito($uid, null, $paginaId);
        $this->abilitazioni->revocaAmbito($uid, null, 0);
        foreach (Perimetri::ambiti($p, $paginaId) as [$amb, $pag, $ev]) {
            $this->permessi->applica($uid, $amb, $pag, $ev, $da);
        }
    }

    /**
     * Abilitazione in attesa del primo accesso: sostituisce quelle già in attesa per quell'email (area e FSL).
     *
     * @param list<array{0: string, 1: int, 2: list<int>}> $ambiti
     */
    public function mettiInAttesa(string $email, ?string $personaId, string $nominativo, int $paginaId, array $ambiti, int $da): void
    {
        $this->attesa->eliminaPerEmail($email, $paginaId);
        foreach ($ambiti as [$amb, $pag, $ev]) {
            $this->attesa->inserisci($email, $personaId, $nominativo, $pag, implode(',', $ev), $da, $amb);
        }
    }

    /** Annulla tutte le abilitazioni in attesa della persona (area corrente e FSL); ritorna l'email o null se non trovata. */
    public function annullaAttesa(int $id, int $paginaId): ?string
    {
        $email = $this->attesa->emailDi($id, $paginaId);
        if ($email !== null) {
            $this->attesa->eliminaPerEmail($email, $paginaId);
        }

        return $email;
    }
}
