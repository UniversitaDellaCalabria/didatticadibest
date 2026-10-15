<?php

declare(strict_types=1);

namespace App\Attestati;

use App\Eventi\Progetti;

/** Campi stampati su un attestato. */
final class DatiAttestato
{
    /** Parole che nei nomi delle scuole restano minuscole (articoli e preposizioni). */
    private const MINUSCOLE = ['di', 'del', 'della', 'delle', 'dei', 'degli', 'dello', 'e', 'ed', 'da', 'in', 'per', 'a', 'al', 'alla', 'con'];

    /** «LICEO SCIENTIFICO GALILEI» → «Liceo Scientifico Galilei» (l'elenco del Ministero è tutto maiuscolo); un nome già misto resta com'è. */
    public static function nomeScuola(string $nome): string
    {
        $nome = trim($nome);
        if ($nome === '' || $nome !== mb_strtoupper($nome)) {
            return $nome;
        }
        $parole = preg_split('/(\s+)/u', mb_strtolower($nome), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($parole as $i => $w) {
            $out .= ($i > 0 && in_array($w, self::MINUSCOLE, true)) || trim($w) === '' ? $w : self::iniziale($w);
        }

        return $out;
    }

    /** «cosenza» → «Cosenza», «reggio-calabria» → «Reggio-Calabria». */
    private static function iniziale(string $s): string
    {
        return implode('-', array_map(static fn (string $p): string => mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1), explode('-', $s)));
    }

    /** «COSENZA» → «Cosenza»; il resto delle parole resta com'è. */
    private static function maiuscolaIniziale(string $s): string
    {
        $s = trim($s);
        if ($s === '' || $s !== mb_strtoupper($s)) {
            return $s;
        }

        return self::iniziale(mb_strtolower($s));
    }

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

        $fsl = !empty($p['fsl']);
        $scuola = self::nomeScuola((string) ($p['scuola_nome'] ?? ''));
        if ($scuola === '' && $fsl) {
            // Scuola scritta a mano nella prenotazione («Liceo Galilei – Cosenza»)
            $custom = (array) json_decode((string) ($p['dati_custom_json'] ?? ''), true);
            $scuola = trim((string) ($custom['scuola'] ?? ''));
        }

        return [
            'fsl' => $fsl,
            'scuola' => $fsl ? $scuola : '',
            'scuola_comune' => $fsl && trim((string) ($p['scuola_nome'] ?? '')) !== '' ? self::maiuscolaIniziale((string) ($p['scuola_comune'] ?? '')) : '',
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
