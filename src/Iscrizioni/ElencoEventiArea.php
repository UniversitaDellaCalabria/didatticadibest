<?php

declare(strict_types=1);

namespace App\Iscrizioni;

/**
 * Come gli eventi di un'area (con i loro turni) vengono raggruppati per i vari disegni della pagina: elenco piatto dei turni, eventi del calendario, per scheda,
 * per categoria, per giorno, in evidenza e sezioni affiancate in alto. Prima stava dentro master_template.php; qui è una funzione senza database né pagina,
 * da provare da sola (i turni arrivano da una funzione).
 */
final class ElencoEventiArea
{
    public const SENZA_DATA = '9999-12-31';

    /**
     * @param list<array<string, mixed>> $eventi gli eventi pubblici dell'area
     * @param callable(int): list<array<string, mixed>> $turniDelEvento
     * @return array{
     *     all_turni_flat: list<array<string, mixed>>, json_events_calendar: list<array<string, mixed>>, eventi_by_id: array<int, array<string, mixed>>,
     *     eventi_per_categoria: array<string, list<array<string, mixed>>>, evento_evidenza: ?array<string, mixed>, sezioni_superiori: array<string, list<array<string, mixed>>>,
     *     eventi_macro: list<array<string, mixed>>, eventi_per_data: array<string, list<array<string, mixed>>>
     * }
     */
    public static function costruisci(array $eventi, callable $turniDelEvento, string $colorePrimario): array
    {
        $flat = $calendario = $byId = $perCategoria = $superiori = $macro = $perData = [];
        $evidenza = null;
        foreach ($eventi as $ev) {
            $turni = [];
            foreach ($turniDelEvento((int) $ev['id']) as $t) {
                $turni[] = $t;
                $flat[] = $t + [
                    'evento_titolo' => $ev['titolo'], 'evento_luogo' => $ev['luogo'], 'evento_descrizione' => $ev['descrizione'], 'evento_locandina' => $ev['locandina_path'],
                    'richiede_prenotazione' => $ev['richiede_prenotazione'], 'ruolo_accesso_id' => $ev['ruolo_accesso_id'], 'categoria' => $ev['nome_sottocategoria'] ?: 'Altre Attività',
                    'evento_id' => $ev['id'], 'is_evidenza' => $ev['is_evidenza'], 'evento_tipo' => $ev['tipo'] ?? 'evento',
                ];
                if (!empty($t['data_turno'])) {
                    $calendario[] = [
                        'id' => 'turno_' . $t['id'],
                        'title' => htmlspecialchars_decode((string) $ev['titolo']) . (!empty($t['nome_turno']) ? ' – ' . $t['nome_turno'] : ''),
                        'start' => $t['data_turno'] . (!empty($t['orario_inizio']) ? 'T' . $t['orario_inizio'] : ''),
                        'end' => !empty($t['orario_fine']) ? $t['data_turno'] . 'T' . $t['orario_fine'] : null,
                        'allDay' => empty($t['orario_inizio']),
                        'color' => $colorePrimario,
                        'extendedProps' => ['turno_id' => $t['id'], 'luogo' => htmlspecialchars_decode((string) $ev['luogo'])],
                    ];
                }
            }
            $ev['turni'] = $turni;
            $byId[(int) $ev['id']] = $ev;
            $perCategoria[$ev['nome_sottocategoria'] ?: 'Altri gruppi'][] = $ev;

            if ((int) $ev['is_evidenza'] === 1 && $evidenza === null) {
                $evidenza = $ev;
                continue;
            }

            $sezione = $ev['nome_sottocategoria'] ? $ev['nome_sottocategoria'] : 'Altre Attività';
            // Sezione affiancata in alto nel layout Griglia: opzione della sezione (admin > Eventi > Sezioni)
            if ((int) ($ev['affiancata_in_alto'] ?? 0) === 1) {
                $superiori[$sezione][] = $ev;
            } else {
                $macro[] = $ev;
            }

            if (!empty($turni)) {
                foreach ($turni as $t) {
                    $evGiorno = $ev;
                    $evGiorno['turni'] = [$t];
                    $perData[!empty($t['data_turno']) ? $t['data_turno'] : self::SENZA_DATA][] = $evGiorno;
                }
            } else {
                $evGiorno = $ev;
                $evGiorno['turni'] = [];
                $perData[self::SENZA_DATA][] = $evGiorno;
            }
        }
        ksort($perData);

        return [
            'all_turni_flat' => $flat, 'json_events_calendar' => $calendario, 'eventi_by_id' => $byId, 'eventi_per_categoria' => $perCategoria,
            'evento_evidenza' => $evidenza, 'sezioni_superiori' => $superiori, 'eventi_macro' => $macro, 'eventi_per_data' => $perData,
        ];
    }
}
