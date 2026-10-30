<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Il gruppo «Aziende»: chi rappresenta un'azienda o un ente con cui il Dipartimento ha una convenzione (di tesi). Si riconosce dall'email della convenzione
 * (convenzioni_tesi.email) uguale a quella dell'utente; il profilo «Azienda» dell'area personale usa lo stesso confronto in diretta, quindi funziona anche
 * per chi entra per la prima volta. Il gruppo serve a vederlo in Utenti e a darlo a mano a chi non ha (ancora) una convenzione.
 */
final class GruppoAziende extends GruppoPersone
{
    public const NOME = 'Aziende';

    public function nome(): string
    {
        return self::NOME;
    }

    /** L'email è quella di una convenzione di tesi (annullate comprese: l'azienda ha comunque un rapporto col Dipartimento). */
    public function haConvenzione(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        return $email !== '' && (int) ($this->db->valore('SELECT COUNT(*) FROM convenzioni_tesi WHERE LOWER(email) = ?', [$email]) ?? 0) > 0;
    }

    public function allineaTutti(): int
    {
        // Il confronto si fa in PHP: le due tabelle possono avere collation diverse e MySQL rifiuterebbe il confronto diretto
        $emailConvenzioni = [];
        foreach ($this->db->righe("SELECT DISTINCT email FROM convenzioni_tesi WHERE email <> ''") as $r) {
            $emailConvenzioni[mb_strtolower(trim((string) $r['email']))] = true;
        }
        $n = 0;
        $id = $this->id();
        foreach ($this->db->righe("SELECT id, email, ruoli_secondari FROM utenti WHERE email IS NOT NULL AND email <> ''") as $u) {
            if (isset($emailConvenzioni[mb_strtolower(trim((string) $u['email']))]) && !in_array((string) $id, explode(',', (string) $u['ruoli_secondari']), true)) {
                $this->assegna((int) $u['id']);
                ++$n;
            }
        }

        return $n;
    }
}
