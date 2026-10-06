<?php

declare(strict_types=1);

namespace App\Iscritti\Vista;

/** Esportazione degli iscritti di un'area in Excel (tabella HTML) e CSV: stesso testo di sempre, scritto direttamente sull'output. */
final class EsportaIscritti
{
    private const INTESTAZIONI = ['Codice', 'Stato', 'Presenza', 'Posti', 'Nome', 'Cognome', 'Matricola', 'Email', 'Evento', 'Turno', 'Data', 'Ora'];

    /**
     * Tabella HTML che Excel apre come foglio.
     *
     * @param list<array<string, mixed>> $righe righe di IscrittiRepository::perEsportazione()
     * @param array<array-key, string|null> $colonneCustom campi personalizzati (nome_campo => etichetta)
     */
    public static function excel(array $righe, array $colonneCustom, bool $conStudenti): void
    {
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body><table border="1">';
        echo '<tr><th>Codice</th><th>Stato</th><th>Presenza</th><th>Posti</th><th>Nome</th><th>Cognome</th><th>Matricola</th><th>Email</th><th>Evento</th><th>Turno</th><th>Data</th><th>Ora</th>';
        foreach ($colonneCustom as $etichetta) {
            echo '<th>' . self::h($etichetta) . '</th>';
        }
        if ($conStudenti) {
            echo '<th>N. studenti in elenco</th><th>Studenti (per gli attestati)</th>';
        }
        echo '<th>Data Registrazione</th></tr>';
        foreach ($righe as $row) {
            $json = self::datiModulo($row['dati_custom_json'] ?? null);
            echo '<tr><td>' . self::h($row['codice_prenotazione']) . '</td><td>' . self::h($row['stato']) . '</td><td>' . ($row['presente'] == 1 ? 'SI' : 'NO') . '</td><td>' . self::h($row['num_posti']) . '</td><td>' . self::h($row['nome']) . '</td><td>' . self::h($row['cognome']) . '</td><td>' . self::h($row['matricola_effettiva']) . '</td><td>' . self::h($row['email']) . '</td><td>' . self::h($row['evento']) . '</td><td>' . self::h($row['nome_turno'] ?? '') . '</td><td>' . self::h($row['data_turno'] ?? '') . '</td><td>' . self::h($row['orario_inizio'] ?? '') . '</td>';
            foreach ($colonneCustom as $chiave => $etichetta) {
                echo '<td>' . self::h(self::valoreCampo($json, (string) $chiave, (string) $etichetta)) . '</td>';
            }
            if ($conStudenti) {
                echo '<td>' . (int) $row['n_studenti'] . '</td><td>' . self::h((string) $row['studenti']) . '</td>';
            }
            echo '<td>' . self::h($row['data_prenotazione']) . '</td></tr>';
        }
        echo '</table></body></html>';
    }

    /**
     * File CSV.
     *
     * @param list<array<string, mixed>> $righe righe di IscrittiRepository::perEsportazione()
     * @param array<array-key, string|null> $colonneCustom campi personalizzati (nome_campo => etichetta)
     */
    public static function csv(array $righe, array $colonneCustom, bool $conStudenti): void
    {
        $output = fopen('php://output', 'w');
        if ($output === false) {
            return;
        }
        $intestazioni = self::INTESTAZIONI;
        foreach ($colonneCustom as $etichetta) {
            $intestazioni[] = $etichetta;
        }
        if ($conStudenti) {
            $intestazioni[] = 'N. studenti in elenco';
            $intestazioni[] = 'Studenti (per gli attestati)';
        }
        $intestazioni[] = 'Data Registrazione';
        fputcsv($output, $intestazioni);
        foreach ($righe as $row) {
            $json = self::datiModulo($row['dati_custom_json'] ?? null);
            $riga = [$row['codice_prenotazione'], $row['stato'], ($row['presente'] == 1 ? 'SI' : 'NO'), $row['num_posti'], $row['nome'], $row['cognome'], $row['matricola_effettiva'], $row['email'], $row['evento'], $row['nome_turno'] ?? '', $row['data_turno'] ?? '', $row['orario_inizio'] ?? ''];
            foreach ($colonneCustom as $chiave => $etichetta) {
                $riga[] = self::valoreCampo($json, (string) $chiave, (string) $etichetta);
            }
            if ($conStudenti) {
                $riga[] = (int) $row['n_studenti'];
                $riga[] = (string) $row['studenti'];
            }
            $riga[] = $row['data_prenotazione'];
            fputcsv($output, $riga);
        }
        fclose($output);
    }

    /**
     * Valore del campo nei dati del modulo: per nome esatto, altrimenti per un nome che differisce solo per maiuscole o per gli spazi
     * dell'etichetta scritti come «_».
     *
     * @param array<array-key, mixed> $json
     */
    private static function valoreCampo(array $json, string $chiave, string $etichetta): mixed
    {
        $valore = $json[$chiave] ?? '';
        if ($valore === '') {
            foreach ($json as $jk => $jv) {
                if (strtolower((string) $jk) === strtolower($chiave) || strtolower((string) $jk) === strtolower(str_replace(' ', '_', $etichetta))) {
                    $valore = $jv;
                    break;
                }
            }
        }

        return $valore;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function datiModulo(mixed $json): array
    {
        $dati = json_decode((string) $json, true);

        return is_array($dati) ? $dati : [];
    }

    private static function h(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''));
    }
}
