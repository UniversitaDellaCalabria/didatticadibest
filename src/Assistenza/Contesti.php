<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Core\Database;

/**
 * Di cosa si parla in una chat: la pratica dello studente (o la prenotazione) da cui parte la richiesta. Il testo che vedono gli operatori lo scrive
 * sempre il portale, partendo da dati veri e solo se la pratica è della persona che chiede aiuto: da un parametro dell'indirizzo non si accetta nulla.
 */
final class Contesti
{
    public function __construct(private Database $db)
    {
    }

    /**
     * La pratica dello studente, se è sua.
     *
     * @return array{tipo: string, id: int, testo: string}|null
     */
    public function pratica(int $praticaId, int $utenteId): ?array
    {
        if ($praticaId <= 0 || $utenteId <= 0) {
            return null;
        }
        $p = $this->db->riga('SELECT p.id, p.codice, m.titolo FROM pratiche p LEFT JOIN didattica_moduli m ON m.id = p.modulo_id WHERE p.id = ? AND p.utente_id = ?', [$praticaId, $utenteId]);
        if ($p === null) {
            return null;
        }
        $titolo = trim((string) ($p['titolo'] ?? ''));

        return ['tipo' => 'pratica', 'id' => (int) $p['id'], 'testo' => 'la pratica ' . $p['codice'] . ($titolo !== '' ? ' («' . $titolo . '»)' : '')];
    }

    /**
     * La prenotazione a un evento, se è della persona (collegata col login o con la stessa email).
     *
     * @return array{tipo: string, id: int, testo: string}|null
     */
    public function prenotazione(int $prenotazioneId, int $utenteId): ?array
    {
        if ($prenotazioneId <= 0 || $utenteId <= 0) {
            return null;
        }
        $p = $this->db->riga(
            'SELECT pr.id, pr.codice_prenotazione, e.titolo FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id JOIN eventi e ON e.id = t.evento_id
             WHERE pr.id = ? AND pr.utente_id = ?',
            [$prenotazioneId, $utenteId]
        );

        return $p === null ? null : ['tipo' => 'prenotazione', 'id' => (int) $p['id'], 'testo' => 'la prenotazione ' . $p['codice_prenotazione'] . ' («' . $p['titolo'] . '»)'];
    }
}
