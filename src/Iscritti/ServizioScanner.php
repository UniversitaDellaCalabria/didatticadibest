<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Eventi\Turni;
use App\Infrastructure\Audit\AuditLog;

/** Check-in dallo scanner: contatori in tempo reale, registrazione della presenza per codice del biglietto o per id, correzioni. */
final class ServizioScanner
{
    public function __construct(
        private ScannerRepository $scanner,
        private IscrittiRepository $iscritti,
        private Attestati $attestati,
        private AuditLog $audit,
    ) {
    }

    /**
     * Turni con check-in attivo dell'area, visibili al gestore.
     *
     * @return array<int, array<string, string|null>>
     */
    public function turni(int $paginaId, string $rbac): array
    {
        return $this->scanner->turni($paginaId, $rbac);
    }

    /**
     * Stato del turno: contatori (presenti e posti) e lista delle prenotazioni confermate.
     *
     * @return array{presenti: int, totale: int, posti_presenti: int, posti_totali: int, lista: list<array<string, mixed>>}
     */
    public function stato(int $turnoId): array
    {
        $lista = [];
        $pres = 0;
        $tot = 0;
        $postiPres = 0;
        $postiTot = 0;
        foreach ($this->scanner->confermatiDelTurno($turnoId) as $r) {
            $n = max(1, (int) $r['num_posti']);
            ++$tot;
            $postiTot += $n;
            if ((int) $r['presente'] === 1) {
                ++$pres;
                $postiPres += $n;
            }
            $lista[] = [
                'id' => (int) $r['id'], 'nome' => trim($r['cognome'] . ' ' . $r['nome']), 'codice' => $r['codice_prenotazione'],
                'posti' => $n, 'presente' => (int) $r['presente'] === 1,
                'ora' => !empty($r['data_presenza']) ? date('H:i', (int) strtotime((string) $r['data_presenza'])) : '',
            ];
        }

        return ['presenti' => $pres, 'totale' => $tot, 'posti_presenti' => $postiPres, 'posti_totali' => $postiTot, 'lista' => $lista];
    }

    /**
     * Registra (azione 'checkin', per codice) o corregge (azione 'presenza', per id) una presenza e ritorna la risposta JSON.
     *
     * @param array<int, array<string, string|null>> $turni turni dello scanner (da turni())
     * @param bool $valore per 'presenza': true = segna presente, false = annulla la presenza
     * @return array<string, mixed>
     */
    public function registra(string $azione, int $turnoId, int $paginaId, array $turni, string $codice, int $prenotazioneId, bool $forza, bool $valore, Operatore $op): array
    {
        // Cerca la prenotazione per codice (scansione) o per id (lista manuale)
        if ($azione === 'checkin') {
            $codice = strtoupper(trim($codice));
            if (!preg_match('/^[A-Z0-9-]{4,40}$/', $codice)) {
                return ['esito' => 'errore', 'titolo' => 'QR non riconosciuto', 'dettaglio' => 'Il codice letto non è un biglietto del portale.'];
            }
            $p = $this->scanner->perCodice($codice);
        } elseif ($azione === 'presenza') {
            $p = $this->scanner->perId($prenotazioneId);
        } else {
            return ['esito' => 'errore', 'titolo' => 'Azione non valida', 'dettaglio' => ''];
        }

        $contatori = function () use ($turnoId): array {
            $s = $this->stato($turnoId);
            unset($s['lista']);

            return $s;
        };
        $nome = $p ? trim($p['nome'] . ' ' . $p['cognome']) : '';

        if (!$p) {
            return ['esito' => 'errore', 'titolo' => 'Biglietto non trovato', 'dettaglio' => 'Nessuna prenotazione con questo codice.'] + $contatori();
        }
        // Stessa area e stesso turno selezionato: niente check-in su eventi di altri
        if ((int) $p['pagina_id'] !== $paginaId || !isset($turni[(int) $p['turno_id']])) {
            return ['esito' => 'errore', 'titolo' => 'Biglietto di un altro evento', 'dettaglio' => $p['evento_titolo'] . ' — non gestito da questo scanner.'] + $contatori();
        }
        if ((int) $p['turno_id'] !== $turnoId && !$forza) {
            return ['esito' => 'turno', 'titolo' => 'Turno diverso', 'nome' => $nome, 'codice' => $p['codice_prenotazione'],
                    'dettaglio' => 'Il biglietto è per: ' . $p['evento_titolo'] . ' · ' . Turni::etichetta($p)] + $contatori();
        }
        if (($p['stato'] ?? 'confermata') !== 'confermata') {
            $stati = ['in_attesa' => "in lista d'attesa", 'da_approvare' => 'da approvare', 'richiesta_conferma' => 'posto non ancora confermato', 'annullata' => 'annullata', 'rifiutata' => 'rifiutata', 'scaduta' => 'scaduta'];

            return ['esito' => 'errore', 'titolo' => 'Ingresso negato', 'nome' => $nome, 'dettaglio' => 'Prenotazione ' . ($stati[$p['stato']] ?? $p['stato']) . '.'] + $contatori();
        }

        $prId = (int) $p['id'];
        if ($azione === 'presenza' && !$valore) {
            // Annulla la presenza (correzione di un errore)
            $this->iscritti->annullaPresenza($prId);
            $this->audit->registra($op->id, 'Check-in annullato', ['Prenotazione' => $prId], $op->ip);

            return ['esito' => 'ok', 'titolo' => 'Presenza annullata', 'nome' => $nome, 'dettaglio' => ''] + $contatori();
        }
        if ((int) $p['presente'] === 1) {
            $ora = !empty($p['data_presenza']) ? ' alle ' . date('H:i', (int) strtotime((string) $p['data_presenza'])) : '';

            return ['esito' => 'gia', 'titolo' => 'Già registrato', 'nome' => $nome, 'dettaglio' => 'Ingresso già registrato' . $ora . '.'] + $contatori();
        }
        $this->iscritti->segnaPresente($prId);
        $this->attestati->inviaSeConcluso($prId);
        $posti = max(1, (int) $p['num_posti']);

        return ['esito' => 'ok', 'titolo' => 'Ingresso consentito', 'nome' => $nome,
                'dettaglio' => ($posti > 1 ? "Prenotazione per $posti persone. " : '') . ($forza ? 'Registrato su un turno diverso da quello del biglietto.' : '')] + $contatori();
    }
}
