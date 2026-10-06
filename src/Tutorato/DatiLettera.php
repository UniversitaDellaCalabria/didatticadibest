<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Orologio;

/** I valori della lettera di incarico (segnaposto del modello Word e testo del PDF) e piccole utilità sui suoi dati. */
final class DatiLettera
{
    public function __construct(private Orologio $orologio)
    {
    }

    /** «1.250,50», «1250.5», «€ 300» → numero (null se non è un numero). */
    public static function numeroItaliano(mixed $v): ?float
    {
        $v = trim(str_replace(['€', ' ', "\u{00A0}"], '', (string) $v));
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        }

        return $v !== '' && is_numeric($v) ? (float) $v : null;
    }

    /** «123/2026 del 15/09/2026». */
    public static function decretoTesto(?string $num, ?string $data): string
    {
        $num = trim((string) $num);

        return $num . ($data ? ($num !== '' ? ' del ' : '') . date('d/m/Y', (int) strtotime($data)) : '');
    }

    /** Ore con una cifra decimale al massimo («3,5 ore», «12»). */
    public static function oreTesto(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',');
    }

    /**
     * Valori della lettera: segnaposti del modello Word e testo del PDF.
     *
     * @param array<string, mixed> $i lettera con i dati del bando (IncaricoRepository::perId)
     * @return array<string, string>
     */
    public function valori(array $i): array
    {
        $f = $i['genere'] === 'F';
        $num = static fn ($v, int $dec): string => rtrim(rtrim(number_format((float) $v, $dec, ',', '.'), '0'), ',');
        $ind = trim($i['indirizzo'] . ($i['civico'] !== '' ? ', n. ' . $i['civico'] : ''));
        $base = $i['data_lettera'] ?: ($i['inviata_il'] ?: null);
        $dataLettera = $base ? strtotime($base) : $this->orologio->adesso()->getTimestamp();

        return [
            'TITOLO' => $f ? 'Dott.ssa' : 'Dott.', 'NOMINATIVO' => trim($i['nome'] . ' ' . $i['cognome']), 'NATO' => $f ? 'nata' : 'nato', 'RESIDENTE' => 'residente',
            'VINCITORE' => $f ? 'vincitrice' : 'vincitore', 'LUOGO_NASCITA' => $i['luogo_nascita'] ?: '________', 'DATA_NASCITA' => $i['data_nascita'] ? date('d/m/Y', strtotime($i['data_nascita'])) : '________',
            'COMUNE_RESIDENZA' => $i['comune_residenza'] ?: '________', 'INDIRIZZO' => $ind !== '' ? $ind : '________', 'CODICE_FISCALE' => $i['codice_fiscale'],
            'DECRETO_BANDO' => self::decretoTesto($i['decreto_bando'], $i['decreto_bando_data']) ?: '________', 'DECRETO_COMMISSIONE' => self::decretoTesto($i['decreto_commissione'], $i['decreto_commissione_data']) ?: '________',
            'ATTIVITA' => (string) $i['attivita'], 'ORE' => $i['ore'] !== null ? $num($i['ore'], 1) : '', 'PERIODO' => (string) $i['periodo'],
            'COMPENSO' => $i['compenso'] !== null ? '€ ' . number_format((float) $i['compenso'], 2, ',', '.') : '',
            'LUOGO' => $i['luogo'] ?: 'Rende', 'DATA_LETTERA' => date('d/m/Y', $dataLettera),
            'FIRMA_DOCENTE' => 'Prof. ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome']), 'FIRMA_STUDENTE' => trim($i['nome'] . ' ' . $i['cognome']), 'FIRMA_DIRETTORE' => 'Prof. ' . $i['direttore_nome'],
        ];
    }

    /**
     * Impronta dei dati della lettera (cosa ha confermato lo studente): cambia se cambia un solo dato.
     *
     * @param array<string, mixed> $i
     */
    public function impronta(array $i): string
    {
        $d = $this->valori($i);
        unset($d['DATA_LETTERA']);

        return hash('sha256', (string) json_encode([$i['codice'], $d], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Nome del file da scaricare: LETTERA_INCARICO_COGNOME_NOME[_firmata].pdf.
     *
     * @param array<string, mixed> $i
     */
    public static function nomeFile(array $i, string $suffisso = ''): string
    {
        $n = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $i['cognome'] . '_' . $i['nome']) ?: $i['codice']));

        return 'LETTERA_INCARICO_' . trim($n, '_') . ($suffisso !== '' ? '_' . $suffisso : '') . '.pdf';
    }
}
