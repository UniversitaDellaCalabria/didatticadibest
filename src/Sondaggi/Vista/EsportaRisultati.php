<?php

declare(strict_types=1);

namespace App\Sondaggi\Vista;

/** Risultati di un sondaggio come tabella HTML che Excel apre (file .xls): una riga per compilazione, una colonna per domanda. */
final class EsportaRisultati
{
    /**
     * @param array<int|string, array<string, mixed>> $domande domande per id (id, testo_domanda, tipo), nell'ordine del questionario
     * @param array<string, array<int|string, mixed>> $risposteRaggruppate risposte per data di compilazione e id della domanda
     */
    public static function html(array $domande, array $risposteRaggruppate): string
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s);
        $o = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body><table border="1">';
        $o .= '<tr><th style="background-color:#198754; color:white;">Data Compilazione</th>';
        foreach ($domande as $dinfo) {
            $o .= '<th style="background-color:#198754; color:white;">' . $h($dinfo['testo_domanda']) . '</th>';
        }
        $o .= '</tr>';
        foreach ($risposteRaggruppate as $dataComp => $rispDate) {
            $o .= '<tr><td>' . $h($dataComp) . '</td>';
            foreach ($domande as $id => $dinfo) {
                $val = $rispDate[$id] ?? 'N/D';
                if ($dinfo['tipo'] === 'matrice' && $val !== 'N/D') {
                    $json = json_decode((string) $val, true);
                    if (is_array($json)) {
                        $arr = [];
                        foreach ($json as $k => $v) {
                            $arr[] = "$k: $v/5";
                        }
                        $val = implode(' | ', $arr);
                    }
                }
                $o .= '<td>' . $h($val) . '</td>';
            }
            $o .= '</tr>';
        }

        return $o . '</table></body></html>';
    }
}
