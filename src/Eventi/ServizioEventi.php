<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Copia ed eliminazione di turni ed eventi con tutto ciò che dipende da loro (campi del form, sondaggi, prenotazioni).
 * Spostato da duplica_turno(), duplica_evento(), elimina_turno() ed elimina_evento() di inc/eventi_progetti.php.
 */
final class ServizioEventi
{
    public function __construct(private Database $db, private EventoRepository $eventi, private Anagrafe $anagrafe)
    {
    }

    /** Duplica un turno (stessi dati, nessuna prenotazione) nell'evento indicato. Ritorna il nuovo id. */
    public function duplicaTurno(int $turnoId, int $eventoDestinazione, bool $segnaCopia): int
    {
        $lista = $this->elenco($this->eventi->colonneCopiabili('turni', ['evento_id', 'nome_turno']));
        $nome = $segnaCopia ? "IF(nome_turno IS NULL OR nome_turno = '', NULL, CONCAT(nome_turno, ' (copia)'))" : 'nome_turno';

        return $this->eventi->copiaRighe("INSERT INTO turni (evento_id, nome_turno, $lista) SELECT $eventoDestinazione, $nome, $lista FROM turni WHERE id = $turnoId");
    }

    /**
     * Copia un evento (titolo «(copia)», non archiviato) con campi del form e sondaggi (non attivi, condizioni «mostra se»
     * ricollegate alle domande nuove). $conTurni: copia anche i turni, senza iscritti.
     * Ritorna ['evento' => id, 'turni' => n, 'sondaggi' => n]; in caso di errore annulla tutto e lancia l'eccezione.
     *
     * @return array{evento: int, turni: int, sondaggi: int}
     */
    public function duplicaEvento(int $eventoId, bool $conTurni = true): array
    {
        return $this->db->transazione(function () use ($eventoId, $conTurni): array {
            $lista = $this->elenco($this->eventi->colonneCopiabili('eventi', ['titolo', 'archiviato']));
            $nuovo = $this->eventi->copiaRighe("INSERT INTO eventi (titolo, archiviato, $lista) SELECT CONCAT(titolo, ' (copia)'), 0, $lista FROM eventi WHERE id = $eventoId");
            if ($nuovo <= 0) {
                throw new RuntimeException('Evento da duplicare non trovato');
            }

            $nTurni = 0;
            if ($conTurni) {
                foreach ($this->eventi->idTurni($eventoId) as $t) {
                    $this->duplicaTurno($t, $nuovo, false);
                    $nTurni++;
                }
            }

            $listaCf = $this->elenco($this->eventi->colonneCopiabili('campi_form', ['evento_id']));
            $this->eventi->copiaRighe("INSERT INTO campi_form (evento_id, $listaCf) SELECT $nuovo, $listaCf FROM campi_form WHERE evento_id = $eventoId");

            // Scheda del progetto (se l'evento è un progetto)
            $colsPd = $this->eventi->colonneCopiabili('progetti_dettagli', ['evento_id']);
            if ($colsPd) {
                $listaPd = $this->elenco($colsPd);
                $this->eventi->copiaRighe("INSERT INTO progetti_dettagli (evento_id, $listaPd) SELECT $nuovo, $listaPd FROM progetti_dettagli WHERE evento_id = $eventoId");
            }

            $nSond = 0;
            $listaS = $this->elenco($this->eventi->colonneCopiabili('sondaggi', ['evento_id', 'attivo']));
            $listaD = $this->elenco($this->eventi->colonneCopiabili('sondaggi_domande', ['sondaggio_id']));
            foreach ($this->eventi->idSondaggi($eventoId) as $vecchioS) {
                $nuovoS = $this->eventi->copiaRighe("INSERT INTO sondaggi (evento_id, attivo" . ($listaS !== '' ? ", $listaS" : '') . ") SELECT $nuovo, 0" . ($listaS !== '' ? ", $listaS" : '') . " FROM sondaggi WHERE id = $vecchioS");
                $nSond++;

                $mappa = [];
                foreach ($this->eventi->idDomande($vecchioS) as $vecchiaD) {
                    $mappa[$vecchiaD] = $this->eventi->copiaRighe("INSERT INTO sondaggi_domande (sondaggio_id, $listaD) SELECT $nuovoS, $listaD FROM sondaggi_domande WHERE id = $vecchiaD");
                }
                foreach ($mappa as $nuovaD) {
                    $cond = json_decode((string) $this->eventi->condizioneDomanda($nuovaD), true);
                    if (!is_array($cond) || !isset($cond['se_id'])) {
                        continue;
                    }
                    $cond['se_id'] = $mappa[(int) $cond['se_id']] ?? 0;
                    $this->eventi->impostaCondizioneDomanda($nuovaD, $cond['se_id'] > 0 ? (string) json_encode($cond) : null);
                }
            }

            return ['evento' => $nuovo, 'turni' => $nTurni, 'sondaggi' => $nSond];
        });
    }

    /** Elimina un turno con le sue prenotazioni e i messaggi collegati. Da usare dentro una transazione se serve. */
    public function eliminaTurno(int $turnoId): void
    {
        $this->eventi->eliminaTurno($turnoId);
    }

    /**
     * Elimina definitivamente un evento e TUTTO ciò che dipende da lui (turni, prenotazioni, messaggi, campi del form,
     * sondaggi con domande e risposte), in un'unica transazione: niente dati orfani.
     */
    public function eliminaEvento(int $eventoId): bool
    {
        try {
            return $this->db->transazione(function () use ($eventoId): bool {
                $this->eventi->eliminaDatiEvento($eventoId);
                foreach ($this->eventi->idTurni($eventoId) as $t) {
                    $this->eventi->eliminaTurno($t);
                }
                $this->eventi->eliminaCampiEProgetto($eventoId);
                if (!$this->eventi->eliminaEvento($eventoId)) {
                    throw new RuntimeException($this->eventi->messaggioErrore());
                }

                return true;
            });
        } catch (Throwable $e) {
            error_log('[elimina_evento] ' . $e->getMessage());

            return false;
        }
    }

    /** Referenti di un evento normale: stessi dati dei progetti, salvati nella scheda (progetti_dettagli.referenti_json). */
    public function salvaReferenti(int $eventoId, array $referenti): void
    {
        $this->eventi->salvaReferenti($eventoId, $referenti ? (string) json_encode($referenti, JSON_UNESCAPED_UNICODE) : null);
    }

    /** Insegnamento dell'anagrafe collegato all'attività (aree «Gruppi degli insegnamenti»); 0 = nessuno. $post = dati del modulo. */
    public function salvaInsegnamento(int $eventoId, array $post): void
    {
        if (!isset($post['insegnamento_id'])) {
            return;
        }
        $ins = $this->anagrafe->insegnamento((int) $post['insegnamento_id']);
        $this->eventi->salvaInsegnamento($eventoId, $ins ? (int) $ins['id'] : null);
    }

    /** Corso di laurea / struttura di un evento normale (campi struttura e corso_codice del modulo), nella scheda progetti_dettagli. */
    public function salvaCorso(int $eventoId, array $post): void
    {
        if (!isset($post['struttura']) && !isset($post['corso_codice'])) {
            return;
        }
        $testo = mb_substr(trim((string) ($post['struttura'] ?? '')), 0, 255);
        $corso = $this->anagrafe->corsoStudio((string) ($post['corso_codice'] ?? ''));
        $cod = $corso['codice'] ?? null;
        if ($testo === '' && $cod === null && !$this->eventi->haScheda($eventoId)) {
            return;
        }
        $this->eventi->salvaCorso($eventoId, $testo, $cod);
    }

    /** @param list<string> $colonne */
    private function elenco(array $colonne): string
    {
        return implode(', ', array_map(static fn (string $c): string => "`$c`", $colonne));
    }
}
