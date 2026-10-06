<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Eventi\AuleTurni;
use App\Eventi\Turni;

/**
 * Aule collegate ai turni degli eventi: il turno sceglie un'aula (o laboratorio) di Prenotazioni e risorse e lo slot viene
 * occupato in automatico (prenotazioni_risorse.turno_id), aggiornato quando cambiano data e orari, liberato quando si toglie
 * l'aula o il turno. Se l'aula è già occupata o chiusa il turno non la prenota e si avvisa.
 */
final class ServizioAuleTurni implements AuleTurni
{
    /** @var array<string, array<int, string>>|null */
    private ?array $elenco = null;

    public function __construct(private RisorsaRepository $risorse)
    {
    }

    /**
     * Risorse attive delle aree di Prenotazioni e risorse, per area: [titolo area => [id => nome], …].
     *
     * @return array<string, array<int, string>>
     */
    public function perTurni(): array
    {
        if ($this->elenco === null) {
            $this->elenco = [];
            foreach ($this->risorse->aulePerTurni() as $x) {
                $this->elenco[$x['titolo']][(int) $x['id']] = $x['nome'] . ($x['capienza'] ? ' (' . (int) $x['capienza'] . ' posti)' : '');
            }
        }

        return $this->elenco;
    }

    /**
     * Allinea la prenotazione dell'aula al turno. $avvisi riceve i problemi da mostrare a chi salva.
     *
     * @param list<string> $avvisi
     */
    public function sincronizza(int $turnoId, array &$avvisi, int $utenteId = 0): void
    {
        $t = $this->risorse->turnoConEvento($turnoId);
        if (!$t) {
            return;
        }
        $esist = $this->risorse->prenotazioneDelTurno($turnoId);
        $rid = (int) ($t['risorsa_id'] ?? 0);
        $nomeT = Turni::etichetta($t) ?: $t['titolo'];
        if ($rid <= 0) {
            if ($esist) {
                $this->risorse->liberaTurno($turnoId);
            }

            return;
        }
        $ris = $this->risorse->perId($rid);
        if (!$ris) {
            if ($esist) {
                $this->risorse->liberaTurno($turnoId);
            }

            return;
        }
        if (empty($t['data_turno']) || empty($t['orario_inizio']) || empty($t['orario_fine'])) {
            if ($esist) {
                $this->risorse->liberaTurno($turnoId);
            }
            $avvisi[] = "turno \"$nomeT\": per occupare " . $ris['nome'] . ' servono data, ora di inizio e ora di fine';

            return;
        }
        $ini = $t['data_turno'] . ' ' . substr((string) $t['orario_inizio'], 0, 8);
        $fin = $t['data_turno'] . ' ' . substr((string) $t['orario_fine'], 0, 8);
        if ($esist && (int) $esist['risorsa_id'] === $rid && $esist['inizio'] === $ini && $esist['fine'] === $fin) {
            return; // già a posto
        }
        $chiusa = $this->risorse->chiusura($rid, (int) $ris['pagina_id'], (string) $t['data_turno']);
        $conflitto = $this->risorse->conflitto($rid, $ini, $fin, $turnoId);
        if ($chiusa !== null || $conflitto) {
            if ($esist) {
                $this->risorse->liberaTurno($turnoId);
            }
            $avvisi[] = "turno \"$nomeT\": " . $ris['nome'] . ' non è stata prenotata perché il ' . date('d/m/Y', (int) strtotime($ini)) . ' '
                      . ($chiusa !== null ? "è chiusa ($chiusa)" : 'è già occupata dalle ' . date('H:i', (int) strtotime((string) $conflitto['inizio'])) . ' alle ' . date('H:i', (int) strtotime((string) $conflitto['fine'])))
                      . ": scegli un'altra aula o un altro orario";

            return;
        }
        $motivo = mb_substr('Evento: ' . $t['titolo'] . (Turni::etichetta($t) !== '' ? ' – ' . Turni::etichetta($t) : ''), 0, 500);
        $nome = mb_substr((string) $t['titolo'], 0, 100);
        if ($esist) {
            $this->risorse->aggiornaPrenotazioneAula((int) $esist['id'], $rid, $ini, $fin, $motivo, $nome);
        } else {
            $this->risorse->inserisciPrenotazioneAula($rid, $utenteId > 0 ? $utenteId : null, $nome, $ini, $fin, $motivo, 'AU-' . strtoupper(bin2hex(random_bytes(4))), $turnoId);
        }
    }
}
