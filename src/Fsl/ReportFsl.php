<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Attestati\DatiAttestato;
use App\Core\Database;
use App\Eventi\Progetti;
use App\Infrastructure\Documenti\Excel;

/**
 * Report della Formazione Scuola Lavoro (FSL) per anno scolastico: per ogni scuola quante attività, classi e studenti e quante ore di formazione hanno fatto
 * gli studenti presenti (ore dell'attività × studenti presenti); per attività; e, quando l'elenco dei nomi è stato inserito, le ore di ogni studente, utili alla
 * scuola per certificare il percorso. Non scrive nulla: legge le prenotazioni delle attività FSL (con la convenzione), confermate. Excel con tre fogli.
 */
final class ReportFsl
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Le prenotazioni FSL confermate dell'anno scolastico (dal 1° settembre al 31 agosto), con il necessario per contare studenti e ore.
     *
     * @return list<array<string, mixed>>
     */
    private function prenotazioni(string $dal, string $al): array
    {
        return $this->db->righe(
            "SELECT pr.id, pr.scuola_codice, pr.dati_custom_json, pr.presente, pr.attestato_inviato, sc.denominazione AS scuola_nome, sc.comune AS scuola_comune, sc.provincia AS scuola_prov,
                    e.id AS evento_id, e.titolo, e.tipo, pe.titolo AS area, t.data_turno, t.orario_inizio, t.orario_fine, pd.data_inizio, pd.data_fine, pd.ore_totali,
                    (SELECT COUNT(*) FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id AND pp.escluso = 0) AS n_elenco
             FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id JOIN eventi e ON e.id = t.evento_id JOIN progetti_dettagli pd ON pd.evento_id = e.id
             LEFT JOIN pagine_eventi pe ON pe.id = e.pagina_id LEFT JOIN scuole sc ON sc.codice = pr.scuola_codice
             WHERE pd.convenzione = 1 AND pr.solo_orientamento = 0 AND IFNULL(pr.stato, 'confermata') = 'confermata'
               AND COALESCE(IF(e.tipo = 'progetto', pd.data_inizio, t.data_turno), t.data_turno, pd.data_fine) BETWEEN ? AND ?
             ORDER BY e.titolo, t.data_turno",
            [$dal, $al]
        );
    }

    /** Ore di formazione dell'attività per ogni studente: le ore del progetto, oppure la durata del turno dell'evento. */
    private static function ore(array $x): float
    {
        if (($x['tipo'] ?? '') === 'progetto') {
            return (float) ($x['ore_totali'] ?? 0);
        }
        if (!empty($x['orario_inizio']) && !empty($x['orario_fine'])) {
            return max(0.0, round((strtotime((string) $x['orario_fine']) - strtotime((string) $x['orario_inizio'])) / 3600, 1));
        }

        return (float) ($x['ore_totali'] ?? 0);
    }

    /** Quanti studenti ha la classe: i nomi dell'elenco se c'è, altrimenti il numero indicato all'iscrizione. */
    private static function studenti(array $x): int
    {
        if ((int) $x['n_elenco'] > 0) {
            return (int) $x['n_elenco'];
        }
        $custom = (array) json_decode((string) ($x['dati_custom_json'] ?? ''), true);

        return max(0, (int) ($custom[defined('CAMPO_PARTECIPANTI') ? CAMPO_PARTECIPANTI : 'numero_partecipanti'] ?? 0));
    }

    private static function periodo(array $x): string
    {
        if (($x['tipo'] ?? '') === 'progetto') {
            return Progetti::periodo(['data_inizio' => $x['data_inizio'], 'data_fine' => $x['data_fine']]);
        }

        return !empty($x['data_turno']) ? date('d/m/Y', (int) strtotime((string) $x['data_turno'])) : '';
    }

    /** Nome e comune della scuola: dall'anagrafe, altrimenti la scritta a mano nella prenotazione. */
    private static function scuola(array $x): array
    {
        $nome = DatiAttestato::nomeScuola((string) ($x['scuola_nome'] ?? ''));
        $comune = (string) ($x['scuola_comune'] ?? '');
        if ($nome === '') {
            $nome = trim((string) (((array) json_decode((string) ($x['dati_custom_json'] ?? ''), true))['scuola'] ?? '')) ?: 'Scuola non indicata';
        }

        return [$nome, $comune !== '' ? mb_convert_case(mb_strtolower($comune), MB_CASE_TITLE) : '', (string) ($x['scuola_prov'] ?? '')];
    }

    /**
     * I numeri dell'anno scolastico.
     *
     * @return array{scuole: list<array<string, mixed>>, attivita: list<array<string, mixed>>, studenti: list<array<string, mixed>>, totali: array<string, float|int>}
     */
    public function dati(string $dal, string $al): array
    {
        $scuole = [];
        $attivita = [];
        $studenti = [];
        foreach ($this->prenotazioni($dal, $al) as $x) {
            [$nome, $comune, $prov] = self::scuola($x);
            $n = self::studenti($x);
            $presenti = (int) $x['presente'] === 1 ? $n : 0;
            $ore = self::ore($x);
            $k = $x['scuola_codice'] ?: 'txt:' . mb_strtolower($nome);
            $s = $scuole[$k] ?? ['codice' => (string) $x['scuola_codice'], 'nome' => $nome, 'comune' => $comune, 'provincia' => $prov, 'attivita' => [], 'classi' => 0, 'studenti' => 0, 'presenti' => 0, 'ore_studente' => 0.0];
            $s['attivita'][(int) $x['evento_id']] = true;
            ++$s['classi'];
            $s['studenti'] += $n;
            $s['presenti'] += $presenti;
            $s['ore_studente'] += $presenti * $ore;
            $scuole[$k] = $s;

            $a = $attivita[(int) $x['evento_id']] ?? ['titolo' => (string) $x['titolo'], 'area' => (string) $x['area'], 'periodo' => self::periodo($x), 'ore' => $ore, 'scuole' => [], 'classi' => 0, 'studenti' => 0, 'presenti' => 0, 'ore_studente' => 0.0];
            $a['scuole'][$k] = true;
            ++$a['classi'];
            $a['studenti'] += $n;
            $a['presenti'] += $presenti;
            $a['ore_studente'] += $presenti * $ore;
            $attivita[(int) $x['evento_id']] = $a;

            // Ore di ogni studente: solo se l'elenco dei nomi c'è e la classe era presente
            if ($presenti > 0 && (int) $x['n_elenco'] > 0) {
                foreach ($this->db->righe('SELECT cognome, nome FROM partecipanti_prenotazione WHERE prenotazione_id = ? AND escluso = 0 ORDER BY cognome, nome', [(int) $x['id']]) as $p) {
                    $studenti[] = ['cognome' => (string) $p['cognome'], 'nome' => (string) $p['nome'], 'scuola' => $nome, 'comune' => $comune, 'attivita' => (string) $x['titolo'],
                                   'periodo' => self::periodo($x), 'ore' => $ore, 'attestato' => (int) $x['attestato_inviato'] === 1 ? 'Sì' : 'No'];
                }
            }
        }
        $scuole = array_values(array_map(static function (array $s): array {
            $s['attivita'] = count($s['attivita']);
            $s['ore_medie'] = $s['presenti'] > 0 ? round($s['ore_studente'] / $s['presenti'], 1) : 0.0;

            return $s;
        }, $scuole));
        usort($scuole, static fn (array $a, array $b): int => strcasecmp($a['nome'], $b['nome']));
        $attivita = array_values(array_map(static function (array $a): array {
            $a['scuole'] = count($a['scuole']);

            return $a;
        }, $attivita));
        usort($studenti, static fn (array $a, array $b): int => [$a['scuola'], $a['cognome'], $a['nome'], $a['attivita']] <=> [$b['scuola'], $b['cognome'], $b['nome'], $b['attivita']]);

        return [
            'scuole' => $scuole, 'attivita' => $attivita, 'studenti' => $studenti,
            'totali' => ['scuole' => count($scuole), 'attivita' => count($attivita), 'classi' => array_sum(array_column($scuole, 'classi')), 'studenti' => array_sum(array_column($scuole, 'studenti')),
                         'presenti' => array_sum(array_column($scuole, 'presenti')), 'ore_studente' => round((float) array_sum(array_column($scuole, 'ore_studente')), 1)],
        ];
    }

    /**
     * Il file Excel dell'anno scolastico (percorso temporaneo, null se manca ZipArchive).
     *
     * @param array{scuole: list<array<string, mixed>>, attivita: list<array<string, mixed>>, studenti: list<array<string, mixed>>, totali: array<string, float|int>} $d
     */
    public function esporta(array $d, string $etichetta): ?string
    {
        $t = $d['totali'];
        $riep = [['Anno scolastico', $etichetta], ['Scuole', $t['scuole']], ['Attività', $t['attivita']], ['Classi', $t['classi']], ['Studenti iscritti', $t['studenti']],
                 ['Studenti presenti', $t['presenti']], ['Ore di formazione complessive (ore × studenti presenti)', $t['ore_studente']]];
        $per_scuola = array_map(static fn (array $s): array => [$s['nome'], $s['codice'], $s['comune'], $s['provincia'], $s['attivita'], $s['classi'], $s['studenti'], $s['presenti'], round((float) $s['ore_studente'], 1), $s['ore_medie']], $d['scuole']);
        $per_att = array_map(static fn (array $a): array => [$a['titolo'], $a['area'], $a['periodo'], $a['ore'], $a['scuole'], $a['classi'], $a['studenti'], $a['presenti'], round((float) $a['ore_studente'], 1)], $d['attivita']);
        $per_stu = array_map(static fn (array $x): array => [$x['cognome'], $x['nome'], $x['scuola'], $x['comune'], $x['attivita'], $x['periodo'], $x['ore'], $x['attestato']], $d['studenti']);

        return Excel::crea([
            'Riepilogo' => ['intestazioni' => ['Indicatore', 'Valore'], 'righe' => $riep, 'larghezze' => [54, 22]],
            'Per scuola' => ['intestazioni' => ['Scuola', 'Codice', 'Comune', 'Provincia', 'Attività', 'Classi', 'Studenti iscritti', 'Studenti presenti', 'Ore complessive', 'Ore medie per studente'], 'righe' => $per_scuola,
                             'larghezze' => [38, 12, 18, 10, 10, 8, 16, 16, 16, 20]],
            'Per attività' => ['intestazioni' => ['Attività', 'Area', 'Periodo', 'Ore', 'Scuole', 'Classi', 'Studenti iscritti', 'Studenti presenti', 'Ore complessive'], 'righe' => $per_att,
                               'larghezze' => [40, 24, 24, 8, 8, 8, 16, 16, 16]],
            'Ore per studente' => ['intestazioni' => ['Cognome', 'Nome', 'Scuola', 'Comune', 'Attività', 'Periodo', 'Ore', 'Attestato inviato'], 'righe' => $per_stu, 'larghezze' => [20, 20, 34, 16, 36, 24, 8, 16]],
        ]);
    }
}
