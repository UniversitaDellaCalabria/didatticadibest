<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Sito;
use App\Eventi\Righe;
use App\Infrastructure\Documenti\Excel;
use App\Infrastructure\Documenti\Word;

/** Pratiche esportate: il verbale in Word (con la seduta o solo la parte delle pratiche) e il foglio Excel con le risposte di tutti i moduli. */
final class EsportazioneVerbale
{
    public function __construct(private Database $db, private Sito $sito, private ServizioSedute $sedute)
    {
    }

    /** Pratiche complete (con modulo) per id, nell'ordine del verbale: categoria e ordine del modulo, poi cognome e nome. */
    public function pratichePerEsportazione(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        return Righe::testo($this->db->righe("SELECT p.*, m.titolo AS modulo_titolo, m.categoria, m.ordine AS modulo_ordine, m.campi_json, m.verbale_json, s.organo AS seduta_organo, s.data AS seduta_data
                           FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id LEFT JOIN didattica_sedute s ON s.id = p.seduta_id
                           WHERE p.id IN (" . implode(',', $ids) . ") ORDER BY m.categoria, m.ordine, m.titolo, m.id, p.cognome, p.nome, p.id"));
    }

    public function corpoPratiche(array $pratiche): string
    {
        $per_modulo = [];
        foreach ($pratiche as $p) {
            $per_modulo[(int)$p['modulo_id']][] = $p;
        }
        $xml = '';
        foreach ($per_modulo as $lista) {
            $m = ['titolo' => $lista[0]['modulo_titolo'], 'verbale_json' => $lista[0]['verbale_json']];
            $v = TestiPratica::verbaleModulo($m);
            $xml .= Word::paragrafo($v['sezione'], ['b' => true, 'u' => true, 'keep' => true, 'dopo' => 120]);
            if (trim($v['intro']) !== '') {
                $xml .= Word::paragrafo(trim($v['intro']), ['al' => 'both']);
            }
            if ($v['stile'] === 'elenco') {
                $campi = CampiModulo::da($lista[0]['campi_json']);
                $colonne = array_values(array_filter(array_map('trim', explode(',', $v['colonne']))));
                if (!$colonne) {
                    $colonne = ['COGNOME', 'NOME', 'MATRICOLA'];
                    foreach ($campi as $c) {
                        if (!in_array($c['tipo'], ['file', 'tabella'], true) && mb_strtolower($c['etichetta']) !== mb_strtolower($v['raggruppa'])) {
                            $colonne[] = mb_strtoupper($c['etichetta']);
                        }
                    }
                }
                $gruppi = [];
                foreach ($lista as $p) {
                    $ris = TestiPratica::risposteTutte($p);
                    $g = $v['raggruppa'] !== '' ? (string)($ris[mb_strtolower($v['raggruppa'])]['valore'] ?? '') : '';
                    $riga = [];
                    foreach ($colonne as $col) {
                        $k = mb_strtolower($col);
                        $riga[] = match ($k) {
                            'cognome' => mb_strtoupper($p['cognome']), 'nome' => mb_strtoupper($p['nome']), 'matricola' => $p['matricola'], 'codice' => $p['codice'],
                            'esito' => Costanti::ESITI_SEDUTA[$p['esito_seduta'] ?? ''][0] ?? '',
                            'delibera' => (string)$p['delibera'], 'protocollo' => (string)($p['protocollo'] ?? ''), default => (string)($ris[$k]['valore'] ?? '')
                        };
                    }
                    $gruppi[$g][] = $riga;
                }
                foreach ($gruppi as $g => $righe) {
                    if ($g !== '') {
                        $xml .= Word::paragrafo($g . ':', ['b' => true, 'keep' => true, 'dopo' => 60]);
                    }
                    $xml .= Word::tabella(array_map('mb_strtoupper', $colonne), $righe, ['sz' => 18]);
                }
            } else {
                foreach ($lista as $p) {
                    $xml .= Word::paragrafo(TestiPratica::segnaposti($v['testo'], $p), ['al' => 'both', 'keep' => true]);
                    // Decisioni prese in seduta (convalide o piano di studi): sostituiscono le tabelle degli esami dello studente
                    $dec = json_decode((string)($p['decisioni_json'] ?? ''), true);
                    $con_dec = is_array($dec) && !empty($dec['righe']);
                    foreach (array_merge(json_decode((string)$p['risposte_json'], true) ?: [], json_decode((string)($p['ufficio_json'] ?? ''), true) ?: []) as $r) {
                        if (($r['tipo'] ?? '') !== 'tabella' || empty($r['righe'])) {
                            continue;
                        }
                        if ($con_dec && array_intersect(['insegnamento', 'insegnamento_dip', 'denominazione'], array_map('tipo_colonna_da_nome', $r['colonne'] ?? []))) {
                            continue;
                        }
                        $xml .= Word::paragrafo($r['etichetta'], ['b' => true, 'sz' => 18, 'keep' => true, 'dopo' => 40]);
                        $xml .= Word::tabella($r['colonne'] ?? [], $r['righe'], ['sz' => count($r['colonne'] ?? []) > 8 ? 13 : 16]);
                    }
                    if ($con_dec) {
                        [$int_d, $righe_d] = TestiPratica::tabellaDecisioni($dec);
                        $xml .= Word::paragrafo($dec['tipo'] === 'piano' ? 'Insegnamenti richiesti nel piano di studi' : 'Quadro delle convalide', ['b' => true, 'sz' => 18, 'keep' => true, 'dopo' => 40]);
                        $xml .= Word::tabella($int_d, $righe_d, ['sz' => count($int_d) > 6 ? 14 : 17]);
                        // Insegnamenti convalidati da inserire nel piano di studi come a scelta (ed eventuali da eliminare)
                        foreach (DomandaPdfa::frasiPianoDecisioni($dec) as $fr) {
                            $xml .= Word::paragrafo($fr, ['al' => 'both', 'dopo' => 60]);
                        }
                    }
                    $esito = (string)($p['esito_seduta'] ?? '');
                    $del_esito = ['respinta' => 'Il Consiglio non approva la richiesta.', 'rinviata' => 'Il Consiglio rinvia l\'esame della richiesta alla prossima seduta.'][$esito] ?? '';
                    $del = trim((string)$p['delibera']) !== '' ? (string)$p['delibera'] : ($del_esito !== '' ? $del_esito : $v['delibera']);
                    if (trim($del) !== '') {
                        $xml .= Word::paragrafo(TestiPratica::segnaposti($del, $p), ['al' => 'both', 'dopo' => 240]);
                    }
                }
            }
            if (trim($v['chiusura']) !== '') {
                $xml .= Word::paragrafo(trim($v['chiusura']), ['al' => 'both', 'dopo' => 240]);
            }
        }
        return $xml;
    }

    public function generaVerbale(?array $s, array $pratiche): ?string
    {
        $mesi = [1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
        $x = '';
        $corpo_pr = $pratiche ? $this->corpoPratiche($pratiche) : Word::paragrafo('Nessuna pratica.', ['i' => true]);
        if ($s) {
            $odg = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$s['odg']) ?: [])));
            $odg = array_map(fn ($t) => (string) preg_replace('/^\d+[\.\)]\s*/', '', $t), $odg);
            $x .= Word::paragrafo((string)$s['organo'], ['b' => true, 'al' => 'center', 'dopo' => 60]);
            if ($s['anno_accademico'] !== '') {
                $x .= Word::paragrafo('a.a. ' . $s['anno_accademico'], ['b' => true, 'al' => 'center', 'dopo' => 240]);
            }
            $ts = $s['data'] ? strtotime($s['data']) : null;
            $quando = $ts ? 'Il giorno ' . date('d', $ts) . ' del mese di ' . $mesi[(int)date('n', $ts)] . ' ' . date('Y', $ts) : 'Il giorno ____';
            $x .= Word::paragrafo($quando . ' alle ore ' . ($s['ora_inizio'] ?: '____') . ', a seguito di convocazione si è riunito' . ($s['luogo'] !== '' ? ' presso ' . $s['luogo'] : '') . ', il ' . $s['organo'] . ', con il seguente o.d.g.:', ['al' => 'both']);
            foreach ($odg as $i => $t) {
                $x .= Word::paragrafo(($i + 1) . ". " . $t, ['rientro' => 720, 'sporgente' => 360, 'dopo' => 0]);
            }
            $x .= Word::paragrafo('', ['dopo' => 120]);
            $pres = $this->sedute->registrate($s);
            if ($pres) {
                // Presenze registrate per componente: gruppi per qualifica, PRESENTE / ASSENTE GIUSTIFICATO / ASSENTE INGIUSTIFICATO
                $g_corr = null;
                foreach ($pres as $r) {
                    if ($r['qualifica'] !== $g_corr) {
                        $g_corr = $r['qualifica'];
                        if ($g_corr !== '') {
                            $x .= Word::paragrafo($g_corr, ['b' => true, 'dopo' => 60, 'keep' => true]);
                        }
                    }
                    $x .= Word::paragrafo($r['nominativo'] . "\t" . mb_strtoupper(Costanti::STATI_PRESENZA[$r['stato']] ?? $r['stato']), ['tab' => 9700, 'dopo' => 0]);
                }
                $np = ServizioSedute::riepilogo($pres);
                $x .= Word::paragrafo('', ['dopo' => 60]);
                $x .= Word::paragrafo('Presenti: ' . $np['P'] . ' – assenti giustificati: ' . $np['AG'] . ' – assenti ingiustificati: ' . $np['AI'] . '.', ['i' => true]);
            } else {
                foreach (preg_split('/\R/', (string)$s['presenze']) ?: [] as $riga) {
                    $riga = rtrim($riga);
                    if (trim($riga) === '') {
                        $x .= Word::paragrafo('', ['dopo' => 0]);
                        continue;
                    }
                    $parti = preg_split('/\s*(\t|\|)\s*|\s{3,}/', trim($riga), 2) ?: [];
                    if (count($parti) === 2) {
                        $x .= Word::paragrafo($parti[0] . "\t" . mb_strtoupper($parti[1]), ['tab' => 9700, 'dopo' => 0]);
                    } else {
                        $x .= Word::paragrafo(trim($riga), ['b' => true, 'dopo' => 60, 'keep' => true]);
                    }
                }
            }
            $x .= Word::paragrafo('', ['dopo' => 120]);
            if ($s['segretario'] !== '') {
                $x .= Word::paragrafo('Risulta presente ' . $s['segretario'] . ' in qualità di segretario verbalizzante.', ['al' => 'both']);
            }
            $x .= Word::paragrafo('Il Coordinatore, accertato di aver raggiunto il numero legale, dichiara aperta la seduta per discutere i punti all’ordine del giorno.', ['al' => 'both', 'dopo' => 240]);
            $messe = false;
            foreach ($odg as $i => $t) {
                $x .= Word::paragrafo(($i + 1) . '. ' . $t, ['b' => true, 'keep' => true, 'dopo' => 120]);
                if (!$messe && preg_match('/pratich/i', $t)) {
                    $x .= $corpo_pr;
                    $messe = true;
                } else {
                    $x .= Word::paragrafo('…', ['i' => true, 'dopo' => 240]);
                }
            }
            if (!$messe) {
                $x .= Word::paragrafo('Pratiche studenti', ['b' => true, 'keep' => true]);
                $x .= $corpo_pr;
            }
            $x .= Word::paragrafo('Non avendo altro da discutere, la seduta si scioglie alle ore ' . ($s['ora_fine'] ?: '____') . '.', ['al' => 'both', 'dopo' => 480]);
            $x .= Word::tabella([], [["Il Segretario verbalizzante\n" . $s['segretario'], "Il Coordinatore\n" . $s['coordinatore']]], ['bordi' => false, 'sz' => 22]);
        } else {
            $x .= Word::paragrafo('Pratiche studenti', ['b' => true, 'al' => 'center', 'dopo' => 240]);
            $x .= $corpo_pr;
        }
        return Word::crea($x, ['logo' => $this->sito->radice() . '/' . Costanti::LOGO_VERBALE, 'font' => 'Times New Roman', 'sz' => 22]);
    }

    public function generaExcel(array $pratiche): ?string
    {
        $prot = fn ($p) => trim(($p['protocollo'] ?? '') . (!empty($p['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($p['protocollo_data'])) : ''));
        $fisse = ['Codice', 'Protocollo', 'Modulo','Categoria', 'Stato', 'Inviata il', 'Aggiornata il', 'Cognome', 'Nome', 'Matricola', 'Email', 'Seduta', 'Esito in seduta', 'Decisioni', 'Delibera'];
        $base = fn ($p) => [$p['codice'], $prot($p), $p['modulo_titolo'], $p['categoria'], Costanti::STATI_PRATICA[$p['stato']][0] ?? $p['stato'], date('d/m/Y H:i', strtotime($p['creata_il'])),
                           $p['aggiornata_il'] ? date('d/m/Y H:i', strtotime($p['aggiornata_il'])) : '', $p['cognome'], $p['nome'], $p['matricola'], $p['email'],
                           $p['seduta_data'] ? date('d/m/Y', strtotime($p['seduta_data'])) : '', Costanti::ESITI_SEDUTA[$p['esito_seduta'] ?? ''][0] ?? '', TestiPratica::testoDecisioni($p['decisioni_json'] ?? null), (string)$p['delibera']];
        $etichette = function (array $lista) {
            $et = [];
            foreach ($lista as $p) {
                foreach (TestiPratica::risposteTutte($p) as $k => $r) {
                    $et[$k] = $r['etichetta'];
                }
            }
            return $et;
        };
        $righe_di = function (array $lista, array $et) use ($base) {
            $out = [];
            foreach ($lista as $p) {
                $ris = TestiPratica::risposteTutte($p);
                $riga = $base($p);
                foreach (array_keys($et) as $k) {
                    $riga[] = (string)($ris[$k]['valore'] ?? '');
                }
                $out[] = $riga;
            }
            return $out;
        };
        $larg = [13, 18, 30, 16, 14, 16, 16, 18, 18, 12, 28, 12, 16, 50, 40];
        $et = $etichette($pratiche);
        $fogli = ['Tutte le pratiche' => ['intestazioni' => array_merge($fisse, array_values($et)), 'righe' => $righe_di($pratiche, $et), 'larghezze' => array_merge($larg, array_fill(0, count($et), 30))]];
        $per_modulo = [];
        foreach ($pratiche as $p) {
            $per_modulo[(int)$p['modulo_id']][] = $p;
        }
        if (count($per_modulo) > 1) {
            foreach ($per_modulo as $lista) {
                $nome = rtrim(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $lista[0]['modulo_titolo']), 0, 28));
                $n = $nome;
                $k = 2;
                while (isset($fogli[$n])) {
                    $n = $nome . ' ' . $k++;
                }
                $e2 = $etichette($lista);
                $fogli[$n] = ['intestazioni' => array_merge($fisse, array_values($e2)), 'righe' => $righe_di($lista, $e2), 'larghezze' => array_merge($larg, array_fill(0, count($e2), 30))];
            }
        }
        return Excel::crea($fogli);
    }
}
