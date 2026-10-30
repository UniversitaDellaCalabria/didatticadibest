<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/**
 * Controllo una tantum delle email scelte nel profilo PRIMA della verifica con il codice (v107): email personalizzate non verificate che coincidono
 * con quella di un docente, di un firmatario, di un referente o di un'altra persona dell'anagrafe di Ateneo. Sono i casi in cui qualcuno potrebbe aver
 * ricevuto compiti o permessi di un altro: li guarda l'amministratore (strumenti/controllo_email_personalizzate.php). Solo lettura, tranne azzera().
 */
final class ControlloEmailPersonalizzate
{
    /** Dove un'email dà compiti o permessi: etichetta => query che conta le righe con quell'email (parametro 1: email; 2: persona_id dell'utente). */
    private const FONTI = [
        'docente o Direttore di un incarico di tutorato' => 'SELECT COUNT(*) FROM tutorato_incarichi WHERE LOWER(docente_email) = ? OR LOWER(direttore_email) = ?',
        'conferma di un attestato (docente)' => 'SELECT COUNT(*) FROM pratiche_conferme WHERE LOWER(email) = ?',
        'firma di una pratica (relatore o Direttore)' => 'SELECT COUNT(*) FROM pratiche_firme WHERE LOWER(email) = ?',
        'segretario o coordinatore di un consiglio' => "SELECT COUNT(*) FROM didattica_consigli_persone WHERE LOWER(email) = ? AND ruolo IN ('segretario', 'coordinatore')",
        'persona di un ufficio' => 'SELECT COUNT(*) FROM uffici_persone WHERE LOWER(email) = ?',
        'responsabile di una risorsa' => 'SELECT COUNT(*) FROM risorse_responsabili WHERE LOWER(email) = ?',
        'convenzione di tesi (azienda)' => 'SELECT COUNT(*) FROM convenzioni_tesi WHERE LOWER(email) = ?',
        'altra persona dell\'anagrafe di Ateneo' => 'SELECT COUNT(*) FROM personale_ateneo WHERE LOWER(email) = ? AND id <> ?',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * Gli utenti con email personalizzata non verificata; con $soloSospette solo quelli la cui email compare in una delle fonti.
     *
     * @return list<array{id: int, nome: string, cognome: string, email: string, dove: list<string>}>
     */
    public function elenco(bool $soloSospette = true): array
    {
        $out = [];
        $righe = $this->db->righe("SELECT id, nome, cognome, email, persona_id FROM utenti WHERE email_personalizzata = 1 AND email_verificata_il IS NULL AND email IS NOT NULL AND email <> '' ORDER BY cognome, nome");
        foreach ($righe as $u) {
            $email = mb_strtolower(trim((string) $u['email']));
            $dove = [];
            foreach (self::FONTI as $etichetta => $sql) {
                $param = match (substr_count($sql, '?')) {
                    2 => str_contains($sql, 'id <> ?') ? [$email, (string) ($u['persona_id'] ?? '')] : [$email, $email],
                    default => [$email],
                };
                try {
                    if ((int) $this->db->valore($sql, $param) > 0) {
                        $dove[] = $etichetta;
                    }
                } catch (\Throwable $e) {
                    // tabella assente in questa installazione: la fonte non conta
                }
            }
            if ($dove || !$soloSospette) {
                $out[] = ['id' => (int) $u['id'], 'nome' => (string) $u['nome'], 'cognome' => (string) $u['cognome'], 'email' => $email, 'dove' => $dove];
            }
        }

        return $out;
    }

    /**
     * Toglie l'email scelta a mano: torna vuota e non personalizzata, così al prossimo accesso arriva quella del login di Ateneo.
     * Si usa solo per gli utenti indicati dall'amministratore. Ritorna quanti utenti sono stati cambiati.
     *
     * @param list<int> $ids
     */
    public function azzera(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if (!$ids) {
            return 0;
        }

        return $this->db->esegui('UPDATE utenti SET email = NULL, email_personalizzata = 0 WHERE email_personalizzata = 1 AND email_verificata_il IS NULL AND id IN ('
            . implode(',', $ids) . ')');
    }
}
