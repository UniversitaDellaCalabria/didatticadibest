<?php

declare(strict_types=1);

namespace App\Sondaggi;

use App\Core\Database;
use App\Eventi\Righe;
use InvalidArgumentException;

/**
 * Tutto l'SQL dei sondaggi di gradimento: compilazione anonima (pagina pubblica), gestione dell'admin, risultati.
 * Le letture della pagina pubblica mantengono i tipi nativi (come le vecchie query preparate); quelle dell'admin
 * restano stringhe (come il vecchio $conn->query()).
 */
final class SondaggioRepository
{
    public const GIA_COMPILATO = 'Hai già compilato questo questionario. Grazie per il tuo feedback!';
    public const ERRORE_SALVATAGGIO = 'Errore durante il salvataggio. Riprova.';

    public function __construct(private Database $db)
    {
    }

    // ---- Pagina pubblica -------------------------------------------------------------------------------------------

    /**
     * Prenotazione (con evento e titolo) a cui appartiene il link personale del questionario.
     *
     * @return array<string, mixed>|null
     */
    public function prenotazionePerToken(string $token): ?array
    {
        return $this->db->riga(
            'SELECT pr.*, t.evento_id, e.titolo as evento_titolo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE pr.token_sondaggio = ? LIMIT 1',
            [$token]
        );
    }

    /** @return array<string, mixed>|null */
    public function attivoDelEvento(int $eventoId): ?array
    {
        return $this->db->riga('SELECT * FROM sondaggi WHERE evento_id = ? AND attivo = 1 LIMIT 1', [$eventoId]);
    }

    /** @return list<array<string, mixed>> */
    public function domande(int $sondaggioId): array
    {
        return $this->db->righe('SELECT * FROM sondaggi_domande WHERE sondaggio_id = ? ORDER BY ordine ASC, id ASC', [$sondaggioId]);
    }

    /**
     * Registra le risposte in una transazione: prima segna il questionario come completato (blocca il doppio invio:
     * doppio clic, due schede), poi scrive le risposte. Ritorna null se è andata bene, altrimenti il messaggio per l'utente.
     *
     * @param array<int, string|false> $valori risposta per id della domanda
     */
    public function registraRisposte(int $sondaggioId, int $prenotazioneId, array $valori): ?string
    {
        try {
            $this->db->transazione(function (Database $db) use ($sondaggioId, $prenotazioneId, $valori): void {
                if ($db->esegui('UPDATE prenotazioni SET sondaggio_completato = 1 WHERE id = ? AND COALESCE(sondaggio_completato, 0) = 0', [$prenotazioneId]) !== 1) {
                    throw new RisposteNonRegistrate(self::GIA_COMPILATO);
                }
                foreach ($valori as $domandaId => $valore) {
                    if ($db->esegui('INSERT INTO sondaggi_risposte (sondaggio_id, domanda_id, risposta) VALUES (?, ?, ?)', [$sondaggioId, $domandaId, (string) $valore]) < 0) {
                        throw new RisposteNonRegistrate(self::ERRORE_SALVATAGGIO);
                    }
                }
            });
        } catch (RisposteNonRegistrate $e) {
            return $e->getMessage();
        }

        return null;
    }

    // ---- Gestione (admin) ------------------------------------------------------------------------------------------

    /**
     * Evento, sondaggio o domanda dell'area indicata e visibile all'utente.
     *
     * @param string $filtroRbac frammento SQL ("AND e.id IN (…)") costruito da admin_header.php con soli interi
     */
    public function autorizzato(string $tipo, int $id, int $areaId, string $filtroRbac): bool
    {
        $da = match ($tipo) {
            'evento' => 'FROM eventi e WHERE e.id = ?',
            'sondaggio' => 'FROM sondaggi s JOIN eventi e ON s.evento_id = e.id WHERE s.id = ?',
            'domanda' => 'FROM sondaggi_domande d JOIN sondaggi s ON d.sondaggio_id = s.id JOIN eventi e ON s.evento_id = e.id WHERE d.id = ?',
            default => throw new InvalidArgumentException("Tipo non valido: $tipo"),
        };

        return $this->db->valore("SELECT 1 $da AND e.pagina_id = ? $filtroRbac LIMIT 1", [$id, $areaId]) !== null;
    }

    /**
     * Eventi dell'area (attivi o in archivio) tra cui scegliere il sondaggio.
     *
     * @param string $filtroRbac frammento SQL ("AND e.id IN (…)") costruito da admin_header.php con soli interi
     * @return list<array<string, string|null>>
     */
    public function eventiDellArea(int $areaId, int $archivio, string $filtroRbac): array
    {
        return Righe::testo($this->db->righe(
            "SELECT id, titolo, archiviato FROM eventi e WHERE e.pagina_id = ? AND e.archiviato = ? $filtroRbac ORDER BY e.ordine ASC, e.id DESC",
            [$areaId, $archivio]
        ));
    }

    /** @return array<string, string|null>|null */
    public function delEvento(int $eventoId): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM sondaggi WHERE evento_id = ? LIMIT 1', [$eventoId]));
    }

    /** @return list<array<string, string|null>> */
    public function domandeAdmin(int $sondaggioId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM sondaggi_domande WHERE sondaggio_id = ? ORDER BY ordine ASC, id ASC', [$sondaggioId]));
    }

    /**
     * Risposte di un sondaggio con tipo e testo della domanda (per le statistiche).
     *
     * @return list<array<string, string|null>>
     */
    public function risposteConDomande(int $sondaggioId): array
    {
        return Righe::testo($this->db->righe(
            'SELECT r.*, d.tipo, d.testo_domanda FROM sondaggi_risposte r JOIN sondaggi_domande d ON r.domanda_id = d.id WHERE r.sondaggio_id = ?',
            [$sondaggioId]
        ));
    }

    /**
     * Domande per l'esportazione, per id.
     *
     * @return array<string, array<string, string|null>>
     */
    public function domandeEsportazione(int $sondaggioId): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe(
            'SELECT id, testo_domanda, tipo FROM sondaggi_domande WHERE sondaggio_id = ? ORDER BY ordine ASC, id ASC',
            [$sondaggioId]
        )) as $d) {
            $out[$d['id']] = $d;
        }

        return $out;
    }

    /**
     * Risposte per l'esportazione, dalla più recente.
     *
     * @return list<array<string, string|null>>
     */
    public function risposteEsportazione(int $sondaggioId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM sondaggi_risposte WHERE sondaggio_id = ? ORDER BY data_risposta DESC', [$sondaggioId]));
    }

    public function crea(int $eventoId, string $titolo): void
    {
        $this->db->esegui('INSERT INTO sondaggi (evento_id, titolo, attivo) VALUES (?, ?, 0)', [$eventoId, $titolo]);
    }

    /** Aggiunge una domanda in fondo (ordine a passi di 10). */
    public function aggiungiDomanda(int $sondaggioId, string $testo, string $tipo, string $opzioni, int $obbligatoria, string $condizioneJson): void
    {
        $ordine = (int) $this->db->valore('SELECT COALESCE(MAX(ordine), -10) + 10 as new_ord FROM sondaggi_domande WHERE sondaggio_id = ?', [$sondaggioId]);
        $this->db->esegui(
            'INSERT INTO sondaggi_domande (sondaggio_id, testo_domanda, tipo, obbligatorio, opzioni, condizione_json, ordine) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$sondaggioId, $testo, $tipo, $obbligatoria, $opzioni, $condizioneJson, $ordine]
        );
    }

    public function modificaDomanda(int $domandaId, string $testo, string $tipo, string $opzioni, int $obbligatoria, string $condizioneJson): void
    {
        $this->db->esegui(
            'UPDATE sondaggi_domande SET testo_domanda = ?, tipo = ?, opzioni = ?, obbligatorio = ?, condizione_json = ? WHERE id = ?',
            [$testo, $tipo, $opzioni, $obbligatoria, $condizioneJson, $domandaId]
        );
    }

    /** Sposta una domanda di $passo posizioni di ordine (negativo = su). */
    public function spostaDomanda(int $domandaId, int $passo): void
    {
        $this->db->esegui('UPDATE sondaggi_domande SET ordine = ordine + ? WHERE id = ?', [$passo, $domandaId]);
    }

    public function impostaOrdineDomanda(int $domandaId, int $ordine): void
    {
        $this->db->esegui('UPDATE sondaggi_domande SET ordine = ? WHERE id = ?', [$ordine, $domandaId]);
    }

    public function impostaAttivo(int $sondaggioId, int $attivo): void
    {
        $this->db->esegui('UPDATE sondaggi SET attivo = ? WHERE id = ?', [$attivo, $sondaggioId]);
    }

    public function eliminaDomanda(int $domandaId): void
    {
        $this->db->esegui('DELETE FROM sondaggi_domande WHERE id = ?', [$domandaId]);
    }

    /** Elimina il sondaggio con le sue risposte e domande. */
    public function elimina(int $sondaggioId): void
    {
        $this->db->esegui('DELETE FROM sondaggi_risposte WHERE sondaggio_id = ?', [$sondaggioId]);
        $this->db->esegui('DELETE FROM sondaggi_domande WHERE sondaggio_id = ?', [$sondaggioId]);
        $this->db->esegui('DELETE FROM sondaggi WHERE id = ?', [$sondaggioId]);
    }

    // ---- Invio del link del questionario ---------------------------------------------------------------------------

    /**
     * Testo dell'email del sondaggio dalle impostazioni di sistema (campi vuoti = testi predefiniti).
     *
     * @return array<string, string|null>|null
     */
    public function impostazioniEmail(): ?array
    {
        return Righe::riga($this->db->riga('SELECT email_sondaggio_oggetto, email_sondaggio_corpo FROM impostazioni_sistema WHERE id = 1'));
    }

    /**
     * Prenotazioni confermate dell'evento con turno ed evento (destinatari del link).
     *
     * @return list<array<string, string|null>>
     */
    public function prenotazioniPerInvio(int $eventoId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo as evento_titolo, e.luogo as evento_luogo, e.abilita_presenze
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             WHERE e.id = ? AND IFNULL(pr.stato, 'confermata') = 'confermata'",
            [$eventoId]
        ));
    }

    public function impostaToken(int $prenotazioneId, string $token): void
    {
        $this->db->esegui('UPDATE prenotazioni SET token_sondaggio = ? WHERE id = ?', [$token, $prenotazioneId]);
    }
}
