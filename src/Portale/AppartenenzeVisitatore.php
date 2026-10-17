<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * Le voci del menu riservate a un gruppo di persone: chi fa parte di un ufficio (per esempio la Direzione: Direttore e vice) o chi è responsabile di una risorsa.
 * La visibilità di una voce (menu_voci.ruolo_visibilita_id) resta un numero: 0 tutti, -1 chi è entrato, un numero positivo un ruolo; qui si aggiungono
 * -2 = responsabili di risorse e -(1000 + id dell'ufficio) = persone di quell'ufficio. La persona si riconosce dall'email (la stessa dei link e delle firme).
 * Gli amministratori vedono tutte le voci. Se la tabella manca (installazione non aggiornata) la voce resta nascosta, senza errori.
 * Nascondere una voce non protegge la pagina: ogni pagina controlla da sola chi può aprirla.
 */
final class AppartenenzeVisitatore
{
    public const RESPONSABILI_RISORSE = -2;
    public const BASE_UFFICIO = 1000;

    /** @var array<string, list<int>> uffici per email (una sola lettura per richiesta) */
    private array $uffici = [];
    /** @var array<string, bool> */
    private array $responsabili = [];

    public function __construct(private Database $db)
    {
    }

    public static function codiceUfficio(int $ufficioId): int
    {
        return -(self::BASE_UFFICIO + $ufficioId);
    }

    /** L'id dell'ufficio a cui rimanda la visibilità, null se non è una visibilità «ufficio». */
    public static function ufficioDaCodice(int $codice): ?int
    {
        return $codice < -self::BASE_UFFICIO ? -$codice - self::BASE_UFFICIO : null;
    }

    /** Il visitatore vede una voce con questa visibilità? (per le visibilità di sempre decide il Visitatore). */
    public function vede(Visitatore $v, int $codice): bool
    {
        if ($codice >= -1) {
            return $v->vede($codice);
        }
        if (!$v->collegato) {
            return false;
        }
        if ($v->amministratore()) {
            return true;
        }
        $email = mb_strtolower(trim($v->email));
        if ($email === '') {
            return false;
        }
        if ($codice === self::RESPONSABILI_RISORSE) {
            return $this->responsabileDiRisorse($email);
        }
        $id = self::ufficioDaCodice($codice);

        return $id !== null && in_array($id, $this->ufficiDi($email), true);
    }

    /** @return list<int> */
    private function ufficiDi(string $email): array
    {
        if (!isset($this->uffici[$email])) {
            try {
                $this->uffici[$email] = array_map('intval', array_column($this->db->righe('SELECT DISTINCT ufficio_id FROM uffici_persone WHERE LOWER(email) = ?', [$email]), 'ufficio_id'));
            } catch (\Throwable $e) {
                error_log('Menu, uffici della persona: ' . $e->getMessage());
                $this->uffici[$email] = [];
            }
        }

        return $this->uffici[$email];
    }

    private function responsabileDiRisorse(string $email): bool
    {
        if (!isset($this->responsabili[$email])) {
            try {
                $this->responsabili[$email] = (int) $this->db->valore('SELECT COUNT(*) FROM risorse_responsabili WHERE LOWER(email) = ?', [$email]) > 0
                    || (int) $this->db->valore('SELECT COUNT(*) FROM risorse_uffici r JOIN uffici_persone p ON p.ufficio_id = r.ufficio_id WHERE LOWER(p.email) = ?', [$email]) > 0;
            } catch (\Throwable $e) {
                error_log('Menu, responsabili di risorse: ' . $e->getMessage());
                $this->responsabili[$email] = false;
            }
        }

        return $this->responsabili[$email];
    }
}
