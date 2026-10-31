<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Sito;
use App\Didattica\Costanti as CostantiDidattica;
use App\Infrastructure\Pdf\Documento;

/**
 * Riepilogo mensile del registro delle attività di tutorato, in PDF, per il tutor: le righe del mese con ore e stato e il totale delle ore.
 * Le regole (quali righe, quali totali) stanno in righeDelMese() e totali(), senza database, per provarle da sole.
 */
final class RiepilogoRegistroPdf
{
    public function __construct(private Sito $sito)
    {
    }

    /**
     * @param list<array<string, mixed>> $righe righe del registro
     * @return list<array<string, mixed>> quelle del mese «aaaa-mm», in ordine di data
     */
    public static function righeDelMese(array $righe, string $mese): array
    {
        $r = array_values(array_filter($righe, static fn (array $x): bool => str_starts_with((string) $x['data'], $mese)));
        usort($r, static fn (array $a, array $b): int => [$a['data'], (int) $a['id']] <=> [$b['data'], (int) $b['id']]);

        return $r;
    }

    /**
     * @param list<array<string, mixed>> $righe
     * @return array{approvata: float, inviata: float, respinta: float, totale: float} le ore per stato; «totale» = approvate + da approvare
     */
    public static function totali(array $righe): array
    {
        $t = ['approvata' => 0.0, 'inviata' => 0.0, 'respinta' => 0.0];
        foreach ($righe as $r) {
            $t[$r['stato']] = ($t[$r['stato']] ?? 0.0) + (float) $r['ore'];
        }

        return $t + ['totale' => $t['approvata'] + $t['inviata']];
    }

    /** «settembre 2026» da «2026-09». */
    public static function nomeMese(string $mese): string
    {
        $nomi = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

        return preg_match('/^(\d{4})-(\d{2})$/', $mese, $m) && isset($nomi[(int) $m[2] - 1]) ? $nomi[(int) $m[2] - 1] . ' ' . $m[1] : $mese;
    }

    /**
     * @param array<string, mixed> $i incarico (con i dati del bando)
     * @param list<array<string, mixed>> $righe tutte le righe del registro
     */
    public function genera(array $i, array $righe, string $mese): string
    {
        $del_mese = self::righeDelMese($righe, $mese);
        $t = self::totali($del_mese);
        $nome_mese = self::nomeMese($mese);
        $tutor = trim((string) $i['nome'] . ' ' . (string) $i['cognome']);
        $pdf = new Documento([
            'logo' => $this->sito->radice() . '/' . CostantiDidattica::LOGO_VERBALE, 'logo_larghezza' => 135, 'logo_pos' => 'sinistra',
            'piede' => 'Registro attività di tutorato ' . $i['codice'] . ' – ' . $nome_mese,
            'info' => ['Title' => 'Riepilogo del registro di tutorato – ' . $nome_mese, 'Author' => 'Dipartimento di Biologia, Ecologia e Scienze della Terra'],
        ]);
        $pdf->fascia('RIEPILOGO MENSILE DEL REGISTRO DELLE ATTIVITÀ DI TUTORATO', ['al' => 'centro', 'sz' => 11.5, 'dopo' => 8, 'prima' => 4]);
        $pdf->tabella([], [
            ['Tutor', $tutor], ['Incarico', (string) $i['codice']], ['Bando', (string) ($i['bando_titolo'] ?? '')], ['Mese', ucfirst($nome_mese)],
            ['Docente responsabile', trim((string) ($i['docente_titolo'] ?? '') . ' ' . (string) ($i['docente_nome'] ?? '') . ' ' . (string) ($i['docente_cognome'] ?? ''))],
        ], ['larghezze' => [1.2, 3.8], 'col0' => '0.96 0.96 0.97', 'dopo' => 8]);
        if (!$del_mese) {
            $pdf->paragrafo('Nel mese non ci sono attività segnate.', ['dopo' => 6, 'al' => 'sinistra']);
        } else {
            $tab = [];
            foreach ($del_mese as $r) {
                $tab[] = [date('d/m/Y', (int) strtotime((string) $r['data'])), DatiLettera::oreTesto($r['ore']), (string) $r['attivita'], Costanti::STATI_REGISTRO[$r['stato']][0] ?? (string) $r['stato']];
            }
            $pdf->tabella(['Giorno', 'Ore', 'Attività svolta', 'Stato'], $tab, ['larghezze' => [1.1, 0.7, 4.0, 1.2], 'dopo' => 8]);
        }
        $pdf->paragrafo('**Ore del mese:** ' . DatiLettera::oreTesto($t['totale']) . ' (di cui approvate dal docente: ' . DatiLettera::oreTesto($t['approvata']) . '; da approvare: ' . DatiLettera::oreTesto($t['inviata']) . ').', ['dopo' => 4, 'al' => 'sinistra']);
        $tutte = self::totali($righe);
        $pdf->paragrafo('**Ore complessive dell\'incarico:** ' . DatiLettera::oreTesto($tutte['totale']) . ($i['ore'] !== null ? ' su ' . DatiLettera::oreTesto($i['ore']) . ' previste' : '') . '.', ['dopo' => 8, 'al' => 'sinistra']);
        $pdf->paragrafo('Riepilogo generato dal portale il ' . date('d/m/Y') . ': non sostituisce la dichiarazione di fine attività.', ['sz' => 8, 'al' => 'sinistra']);

        return $pdf->pdf();
    }
}
