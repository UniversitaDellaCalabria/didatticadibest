<?php

declare(strict_types=1);

namespace App\Iscrizioni;

/**
 * Disponibilità dei posti per le pagine pubbliche: ultimi posti, riepilogo per evento o area, posizione in coda,
 * prenotazioni attive dell'utente. Spostato da inc/eventi_progetti.php (get_turni_ultimi_posti, get_riepilogo_posti,
 * get_posizioni_lista_attesa, get_prenotazioni_attive_utente, testo_posti_liberi), che restano come facciate.
 */
final class ServizioDisponibilita
{
    public function __construct(private DisponibilitaRepository $repo)
    {
    }

    /** «12 posti liberi», «Posti disponibili» (senza limite) o '' (nessun posto). */
    public static function testoPostiLiberi(int $liberi): string
    {
        if ($liberi >= POSTI_SENZA_LIMITE) {
            return 'Posti disponibili';
        }

        return $liberi > 0 ? $liberi . ($liberi === 1 ? ' posto libero' : ' posti liberi') : '';
    }

    /**
     * Prenotazioni ancora da vivere dell'utente (turno non concluso), la più urgente per prima.
     *
     * @return list<array<string, mixed>>
     */
    public function attiveDellUtente(int $utenteId, int $limite = 10): array
    {
        return $utenteId <= 0 ? [] : $this->repo->attiveDellUtente($utenteId, $limite);
    }

    /**
     * Posizione in coda (1 = il prossimo a essere promosso) delle prenotazioni «in_attesa» indicate.
     *
     * @param list<mixed> $prenotazioniIds
     * @return array<int, array{posizione: int, totale: int}> [prenotazione_id => posizione e persone in coda nel turno]
     */
    public function posizioniInCoda(array $prenotazioniIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $prenotazioniIds)));

        return $ids ? $this->repo->posizioniInCoda($ids) : [];
    }

    /**
     * Turni prenotabili adesso con pochi posti o con iscrizioni che chiudono entro 48 ore. Un solo turno per evento, i più urgenti prima.
     *
     * @param list<mixed> $pagineIds
     * @return list<array<string, mixed>>
     */
    public function turniUltimiPosti(array $pagineIds, int $limite = 4): array
    {
        $ids = array_values(array_filter(array_map('intval', $pagineIds)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->repo->turniConPochiPosti($ids) as $r) {
            if (isset($out[(int) $r['evento_id']])) {
                continue;
            }
            $r['liberi'] = (int) $r['max_posti'] - (int) $r['occupati'];
            $out[(int) $r['evento_id']] = $r;
            if (count($out) >= $limite) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * Capienza e posti occupati dei turni ancora prenotabili, raggruppati per evento o per area ($per = 'evento' | 'pagina').
     *
     * @param list<mixed> $ids
     * @return array<int, array{capienza: int, occupati: int, liberi: int}>
     */
    public function riepilogoPosti(string $per, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->repo->capienzaEOccupati($per === 'pagina', $ids) as $r) {
            $occ = min($r['capienza'], $r['occupati']);
            $out[$r['chiave']] = ['capienza' => $r['capienza'], 'occupati' => $occ, 'liberi' => $r['capienza'] - $occ];
        }

        return $out;
    }
}
