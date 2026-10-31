<?php

declare(strict_types=1);

namespace App\Portale;

use App\Didattica\ConfermeInBlocco;
use App\Firme\ScrivaniaFirme;
use App\Iscrizioni\AreaPersonaleRepository;
use App\Tutorato\ServizioRegistroTutorato;

/**
 * «Le mie approvazioni» del docente: tutto ciò che aspetta la sua firma, conferma o approvazione, con numeri separati e un pulsante per ogni gruppo:
 * documenti da firmare (pratiche come relatore, lettere di tutorato, verbali, convenzioni: firme.php), conferme degli attestati (mie_conferme.php),
 * registri e fine attività dei tutor da controllare (registro_tutorato.php). Non scrive niente; una fonte che non risponde conta zero e l'errore va nel log.
 * Le regole di cosa mostrare stanno in riepilogo() (senza database), la raccolta in perUtente().
 */
final class ApprovazioniDocente
{
    public function __construct(
        private ScrivaniaFirme $firme,
        private ConfermeInBlocco $conferme,
        private ServizioRegistroTutorato $registri,
        private AreaPersonaleRepository $area
    ) {
    }

    /**
     * @param array<string, mixed>|null $utente
     * @return array{firme: int, firme_per_tipo: array<string, int>, conferme: int, registri: int}
     */
    public function perUtente(?array $utente): array
    {
        $out = ['firme' => 0, 'firme_per_tipo' => [], 'conferme' => 0, 'registri' => 0];
        if (!$utente || empty($utente['id'])) {
            return $out;
        }
        try {
            $etichette = [];
            foreach ($this->firme->fonti() as $f) {
                $etichette[$f->tipo()] = $f->etichetta();
            }
            foreach ($this->firme->documenti($utente) as $d) {
                $nome = $etichette[$d->tipo] ?? $d->tipo;
                $out['firme_per_tipo'][$nome] = ($out['firme_per_tipo'][$nome] ?? 0) + 1;
                ++$out['firme'];
            }
        } catch (\Throwable $e) {
            error_log('Le mie approvazioni (firme): ' . $e->getMessage());
        }
        try {
            $out['conferme'] = count($this->conferme->inAttesa($utente));
        } catch (\Throwable $e) {
            error_log('Le mie approvazioni (conferme): ' . $e->getMessage());
        }
        try {
            foreach ($this->registri->incarichiRegistro($utente) as $i) {
                if ($i['_ruolo'] === 'docente' && ($i['fine_stato'] === 'richiesta' || $this->area->registriTutoratoInviati((int) $i['id']) > 0)) {
                    ++$out['registri'];
                }
            }
        } catch (\Throwable $e) {
            error_log('Le mie approvazioni (registri): ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Testo, pulsanti e numero della tessera. Con tutti i numeri a zero la tessera non si mostra (testo vuoto).
     *
     * @param array{firme: int, firme_per_tipo: array<string, int>, conferme: int, registri: int} $d risultato di perUtente()
     * @return array{testo: string, azioni: list<array{0: string, 1: string, 2: bool, 3: bool}>, totale: int}
     */
    public static function riepilogo(array $d): array
    {
        $totale = $d['firme'] + $d['conferme'] + $d['registri'];
        if ($totale === 0) {
            return ['testo' => '', 'azioni' => [], 'totale' => 0];
        }
        $parti = [];
        if ($d['firme'] > 0) {
            $dettaglio = [];
            foreach ($d['firme_per_tipo'] as $nome => $n) {
                $dettaglio[] = $n . ' ' . mb_strtolower((string) $nome);
            }
            $parti[] = $d['firme'] . ($d['firme'] === 1 ? ' documento da firmare' : ' documenti da firmare') . ($dettaglio ? ' (' . implode(', ', $dettaglio) . ')' : '');
        }
        if ($d['conferme'] > 0) {
            $parti[] = $d['conferme'] . ($d['conferme'] === 1 ? ' conferma di attestato' : ' conferme di attestati');
        }
        if ($d['registri'] > 0) {
            $parti[] = $d['registri'] . ($d['registri'] === 1 ? ' registro di tutorato da controllare' : ' registri di tutorato da controllare');
        }
        $azioni = [];
        if ($d['firme'] > 0) {
            $azioni[] = ['Firma ora (' . $d['firme'] . ')', 'firme.php', false, true];
        }
        if ($d['conferme'] > 0) {
            $azioni[] = ['Conferma (' . $d['conferme'] . ')', 'mie_conferme.php', false, $d['firme'] === 0];
        }
        if ($d['registri'] > 0) {
            $azioni[] = ['Registri (' . $d['registri'] . ')', 'registro_tutorato.php', false, $d['firme'] === 0 && $d['conferme'] === 0];
        }

        return ['testo' => implode(' · ', $parti), 'azioni' => $azioni, 'totale' => $totale];
    }
}
