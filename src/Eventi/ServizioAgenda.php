<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Sito;
use App\Portale\Sezioni;

/**
 * Agenda unica del sito: eventi e progetti in programma in tutte le aree visibili (non i calendari a slot), con gli ambiti,
 * il prossimo turno e i posti. Usata dalla home, da agenda.php, da orientamento.php, dagli avvisi e dal calendario agenda_ics.php.
 */
final class ServizioAgenda implements FonteEventiAgenda
{
    public function __construct(private AgendaRepository $agenda, private AreaRepository $aree, private Sito $sito)
    {
    }

    /** Eventi in programma di tutte le aree visibili (senza filtri). */
    public function eventiInProgramma(): array
    {
        return $this->eventi();
    }

    /**
     * $f: ambito (chiave di AMBITI_EVENTO o ''), scuole (bool: solo attività per le scuole), q (testo), limite (0 = tutti),
     * pagine (id delle aree da considerare; vuoto = tutte quelle visibili), solo_home (bool: solo aree mostrate in home).
     * Ritorna eventi ordinati per prossima data (quelli «data da definire» in fondo) con: prossima_data, prossimo_orario,
     * ambiti, per_scuole, area (riga di pagine_eventi), url.
     *
     * @return list<array<string, mixed>>
     */
    public function eventi(array $f = []): array
    {
        $aree = [];
        foreach ($this->aree->visibili() as $p) {
            if (Sezioni::tipoArea($p) === 'calendario') {
                continue;
            }
            if (!empty($f['solo_home']) && (int) ($p['mostra_in_home'] ?? 1) !== 1) {
                continue;
            }
            if (!empty($f['pagine']) && !in_array((int) $p['id'], array_map('intval', $f['pagine']), true)) {
                continue;
            }
            $aree[(int) $p['id']] = $p;
        }
        if (!$aree) {
            return [];
        }
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $out = [];
        foreach ($this->agenda->inProgramma(array_keys($aree)) as $r) {
            $area = $aree[(int) $r['pagina_id']];
            $r['ambiti'] = Sezioni::ambitiEvento($r, $area);
            $r['per_scuole'] = (int) $r['fsl'] === 1 || (int) $r['dedicata'] === 1 || (int) $r['progetto_scuole'] === 1 || Sezioni::tipoArea($area) === 'fsl';
            if (!empty($f['ambito']) && !in_array($f['ambito'], $r['ambiti'], true)) {
                continue;
            }
            if (!empty($f['scuole']) && !$r['per_scuole']) {
                continue;
            }
            if ($q !== '' && !str_contains(mb_strtolower($r['titolo'] . ' ' . $r['luogo'] . ' ' . $area['titolo'] . ' ' . $r['relatore'] . ' ' . strip_tags((string) $r['descrizione_breve'])), $q)) {
                continue;
            }
            $d = substr((string) $r['prossimo'], 0, 10);
            $o = substr((string) $r['prossimo'], 11, 5);
            $r['prossima_data'] = $d === '9999-12-31' ? null : $d;
            $r['prossimo_orario'] = $o === '99:99' ? '' : $o;
            $r['area'] = $area;
            $r['url'] = $area['slug'] . '.php?' . ($r['tipo'] === 'progetto' ? 'progetto' : 'evento') . '=' . (int) $r['id'];
            $out[] = $r;
            if (!empty($f['limite']) && count($out) >= (int) $f['limite']) {
                break;
            }
        }

        return $out;
    }

    /**
     * Quanti eventi in programma per ambito (per i filtri): ['orientamento' => 4, …, '' => totale, 'scuole' => n].
     *
     * @param list<array<string, mixed>> $eventi
     * @return array<string, int>
     */
    public function contaAmbiti(array $eventi): array
    {
        $n = array_fill_keys(array_keys(AMBITI_EVENTO), 0) + ['' => count($eventi), 'scuole' => 0];
        foreach ($eventi as $e) {
            foreach ($e['ambiti'] as $a) {
                ++$n[$a];
            }
            if ($e['per_scuole']) {
                ++$n['scuole'];
            }
        }

        return $n;
    }

    /** Calendario .ics dei turni in programma degli eventi dati (iscrizione da Google Calendar, Outlook…). */
    public function ics(array $eventi, string $nome): string
    {
        $esc = static fn ($s): string => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], (string) $s);
        $urlBase = $this->sito->urlBase();
        $host = parse_url($urlBase, PHP_URL_HOST) ?: 'dibest';
        $o = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//DiBEST//Agenda//IT\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:" . $esc($nome) . "\r\nX-WR-TIMEZONE:Europe/Rome\r\n";
        $ids = array_column($eventi, 'id');
        $perEv = [];
        foreach ($eventi as $e) {
            $perEv[(int) $e['id']] = $e;
        }
        if ($ids) {
            foreach ($this->agenda->turniFuturi($ids) as $t) {
                $e = $perEv[(int) $t['evento_id']];
                $giorno = str_replace('-', '', (string) $t['data_turno']);
                if ($t['orario_inizio']) {
                    $ini = $giorno . 'T' . str_replace(':', '', substr((string) $t['orario_inizio'], 0, 5)) . '00';
                    $fin = $giorno . 'T' . str_replace(':', '', substr((string) ($t['orario_fine'] ?: date('H:i', (int) strtotime($t['orario_inizio'] . ' +1 hour'))), 0, 5)) . '00';
                    $tempi = "DTSTART;TZID=Europe/Rome:$ini\r\nDTEND;TZID=Europe/Rome:$fin\r\n";
                } else {
                    $tempi = "DTSTART;VALUE=DATE:$giorno\r\nDTEND;VALUE=DATE:" . date('Ymd', (int) strtotime($t['data_turno'] . ' +1 day')) . "\r\n";
                }
                $o .= "BEGIN:VEVENT\r\nUID:turno-" . (int) $t['id'] . "@$host\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n" . $tempi
                    . 'SUMMARY:' . $esc($e['titolo'] . ($t['nome_turno'] && (int) $e['n_turni'] > 1 ? ' – ' . $t['nome_turno'] : '')) . "\r\n"
                    . ($e['luogo'] ? 'LOCATION:' . $esc($e['luogo']) . "\r\n" : '')
                    . (trim((string) ($e['relatore'] ?? '')) !== '' ? 'DESCRIPTION:' . $esc('Relatore: ' . $e['relatore'] . ($e['relatore_ente'] ? ' (' . $e['relatore_ente'] . ')' : '') . ($e['link_streaming'] ? "\nDiretta: " . $e['link_streaming'] : '')) . "\r\n" : '')
                    . 'URL:' . $esc($urlBase . '/' . $e['url']) . "\r\nCATEGORIES:" . $esc(implode(',', array_map(static fn ($a): string => AMBITI_EVENTO[$a]['nome'], $e['ambiti']))) . "\r\nEND:VEVENT\r\n";
            }
        }

        return $o . "END:VCALENDAR\r\n";
    }
}
