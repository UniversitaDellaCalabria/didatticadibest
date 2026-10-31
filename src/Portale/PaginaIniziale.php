<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;
use App\Uffici\Direzione;

/**
 * La pagina dove si arriva dopo l'accesso senza una pagina di partenza: l'area personale per tutti; il Direttore e il vice possono scegliere nel profilo
 * di andare direttamente al Cruscotto del Direttore. La scelta vale solo finché la persona è davvero il Direttore o il vice.
 */
final class PaginaIniziale
{
    public const AREA = 'area_personale.php';
    public const CRUSCOTTO = 'cruscotto_direttore.php';

    public function __construct(private Database $db, private Direzione $direzione)
    {
    }

    /** @param array<string, mixed>|null $utente */
    public function puoScegliere(?array $utente): bool
    {
        return $this->direzione->direttoreOVice($utente);
    }

    public function sceltaCruscotto(int $utenteId): bool
    {
        return (string) $this->db->valore('SELECT pagina_iniziale FROM utenti WHERE id = ?', [$utenteId]) === 'cruscotto';
    }

    public function imposta(int $utenteId, bool $cruscotto): void
    {
        $this->db->esegui('UPDATE utenti SET pagina_iniziale = ? WHERE id = ?', [$cruscotto ? 'cruscotto' : '', $utenteId]);
    }

    /** @param array<string, mixed>|null $utente */
    public function dopoAccesso(?array $utente): string
    {
        if ($utente && !empty($utente['id']) && $this->puoScegliere($utente) && $this->sceltaCruscotto((int) $utente['id'])) {
            return self::CRUSCOTTO;
        }

        return self::AREA;
    }
}
