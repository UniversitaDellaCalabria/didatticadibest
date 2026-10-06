<?php

declare(strict_types=1);

namespace App\Attestati;

use App\Eventi\Progetti;

/** Campi stampati su un attestato. */
final class DatiAttestato
{
    /**
     * Campi dell'attestato a partire da una prenotazione (con i dati dell'evento). Nei progetti: ore totali e periodo del
     * progetto; negli eventi: data e durata del turno.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public static function crea(array $p, string $nomeCompleto, string $codice, string $matricola = ''): array
    {
        $ore = '';
        $quando = '';
        if (($p['evento_tipo'] ?? '') === 'progetto') {
            if (!empty($p['ore_totali'])) {
                $ore = (string) (int) $p['ore_totali'];
            }
            if (!empty($p['data_inizio']) || !empty($p['data_fine'])) {
                $quando = Progetti::periodo($p);
            }
        } else {
            if (!empty($p['orario_inizio']) && !empty($p['orario_fine'])) {
                $ore = str_replace('.0', '', (string) round((strtotime((string) $p['orario_fine']) - strtotime((string) $p['orario_inizio'])) / 3600, 1));
            }
            if (!empty($p['data_turno'])) {
                $quando = 'In data ' . date('d/m/Y', (int) strtotime((string) $p['data_turno']));
            }
        }

        return [
            'nome' => $nomeCompleto,
            'matricola' => $matricola,
            'evento' => (string) ($p['evento_titolo'] ?? ''),
            'luogo' => (string) ($p['evento_luogo'] ?? ''),
            'quando' => $quando,
            'ore' => $ore,
            'area' => (string) ($p['pagina_titolo'] ?? ''),
            'logo' => !empty($p['logo_attestato_path']) ? $p['logo_attestato_path'] : ($p['logo_path'] ?? ''),
            'portale' => (string) ($p['nome_portale'] ?? ''),
            'sottotitolo' => (string) ($p['sottotitolo_portale'] ?? ''),
            'firma_nome' => !empty($p['firma_nome']) ? $p['firma_nome'] : 'Mauro F. La Russa',
            'firma_titolo' => !empty($p['firma_titolo']) ? $p['firma_titolo'] : 'Il Direttore del Dipartimento',
            'codice' => $codice,
            // Frase prima del titolo: quella dell'area (Impostazioni area → Attestati), altrimenti una predefinita
            'formula' => trim((string) ($p['testo_attestato'] ?? '')) !== '' ? trim((string) $p['testo_attestato'])
                : (($p['evento_tipo'] ?? '') === 'progetto' ? 'ha partecipato al progetto dal titolo:' : "ha partecipato all'attività formativa/evento denominata:"),
        ];
    }
}
