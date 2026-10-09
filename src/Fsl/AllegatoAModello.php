<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioCorsi;
use App\Anagrafi\Testi;
use App\Eventi\EventoRepository;
use App\Eventi\Turni;

/**
 * Contenuto dell'Allegato A: la scuola e, per ogni attività, la scheda completa (titolo, corso, periodo, sede, durata, modalità, destinatari,
 * studenti, tutor, descrizione, percorso, obiettivi, conoscenze, competenze, altre informazioni). Le attività sono raggruppate per corso di
 * laurea e, dentro ogni corso, in ordine di data; tutti i campi di un'attività stanno in un'unica tabella etichetta/valore. Lo disegnano allo stesso modo il PDF (AllegatoAPdf) e il Word (AllegatoAWord): il testo
 * è semplice, con **grassetto** e «\n» per andare a capo.
 */
final class AllegatoAModello
{
    /** Gruppo delle attività senza corso di laurea indicato (in fondo). */
    public const SENZA_CORSO = 'Altre attività';

    public function __construct(
        private EventoRepository $eventi,
        private PrenotazioneFslRepository $prenotazioni,
        private PrecompilazioneConvenzione $precompilazione,
        private ServizioCorsi $corsi
    ) {
    }

    /**
     * @param array<string, mixed> $scuola dati della scuola (denominazione, codice, comune, indirizzo, dirigente…)
     * @param list<array{p: array<string, mixed>, studenti: int, tutor: string}> $attivita prenotazioni (PrenotazioneFslRepository::dati) con studenti e docente referente
     * @return array{
     *     protocollo: string, nome_scuola: string, dirigente: string, scuola: list<array{0: string, 1: string}>,
     *     gruppi: list<array{corso: string, attivita: list<array{n: int, titolo: string, righe: list<array{0: string, 1: string}>}>}>
     * }
     */
    public function costruisci(array $scuola, array $attivita, string $protocollo = ''): array
    {
        $nome = trim((string) ($scuola['denominazione'] ?? ''));
        $righe = [];
        $riga = static function (string $etichetta, string $valore) use (&$righe): void {
            if (trim($valore) !== '') {
                $righe[] = [$etichetta, $valore];
            }
        };
        $riga('Denominazione', $nome . (trim((string) ($scuola['codice'] ?? '')) !== '' ? ' (codice meccanografico ' . $scuola['codice'] . ')' : ''));
        $riga('Sede', trim((string) ($scuola['indirizzo'] ?? '') . ((string) ($scuola['comune'] ?? '') !== '' ? ' – ' . $scuola['comune'] : ''), ' –'));
        $riga('Codice fiscale', (string) ($scuola['cf'] ?? ''));
        $riga('Dirigente Scolastico', (string) ($scuola['dirigente'] ?? ''));
        $riga('PEC', (string) ($scuola['pec'] ?? ''));
        $riga('Email di riferimento', (string) ($scuola['email'] ?? ''));

        // Attività con la loro scheda, poi in ordine di corso di laurea e di data
        $schede = [];
        foreach ($attivita as $a) {
            $schede[] = $this->scheda($a['p'], max(0, (int) $a['studenti']), trim((string) $a['tutor']));
        }
        usort($schede, static fn (array $x, array $y): int => [$x['corso'] === self::SENZA_CORSO, mb_strtolower($x['corso']), $x['inizio'], mb_strtolower($x['voce']['titolo'])]
            <=> [$y['corso'] === self::SENZA_CORSO, mb_strtolower($y['corso']), $y['inizio'], mb_strtolower($y['voce']['titolo'])]);
        $gruppi = [];
        $n = 0;
        foreach ($schede as $s) {
            $voce = ['n' => ++$n] + $s['voce'];
            $ultimo = count($gruppi) - 1;
            if ($ultimo < 0 || $gruppi[$ultimo]['corso'] !== $s['corso']) {
                $gruppi[] = ['corso' => $s['corso'], 'attivita' => []];
                ++$ultimo;
            }
            $gruppi[$ultimo]['attivita'][] = $voce;
        }

        return [
            'protocollo' => trim($protocollo), 'nome_scuola' => $this->pulito($nome), 'dirigente' => $this->pulito((string) ($scuola['dirigente'] ?? '')),
            'scuola' => array_map(fn (array $r): array => [$this->pulito($r[0]), $this->pulito($r[1])], $righe ?: [['Denominazione', '—']]), 'gruppi' => $gruppi,
        ];
    }

    /**
     * @param array<string, mixed> $p prenotazione con i dati dell'attività
     * @return array{corso: string, inizio: string, voce: array{titolo: string, righe: list<array{0: string, 1: string}>}}
     */
    private function scheda(array $p, int $studenti, string $tutor): array
    {
        $eventoId = (int) $p['evento_id'];
        $d = $this->eventi->dettagliProgetti([$eventoId])[$eventoId] ?? [];
        $ev = $this->prenotazioni->schedaEvento($eventoId);
        $v = $this->precompilazione->dati($p);
        $titolo = (string) ($ev['titolo'] ?? $p['evento_titolo']);

        $corsi = array_map(static fn (array $c): string => Testi::nomeSchedaCorso($c), $this->corsi->corsiDi($d));
        $struttura = trim((string) ($d['struttura'] ?? ''));
        if ($struttura !== '' && !in_array(mb_strtolower($struttura), array_map('mb_strtolower', $corsi), true)) {
            array_unshift($corsi, $struttura);
        }
        $turno = ($ev['tipo'] ?? '') === 'progetto' ? '' : Turni::etichetta($p);
        $righe = [];
        $riga = function (string $etichetta, string $valore) use (&$righe): void {
            if (trim($valore) !== '') {
                $righe[] = [$this->pulito($etichetta), $this->pulito($valore)];
            }
        };
        $riga('Titolo', $titolo);
        $riga(count($corsi) > 1 ? 'Corsi di studio / struttura' : 'Corso di studio / struttura', implode("\n", $corsi));
        $riga('Periodo', (string) $v['PERIODO']);
        $riga('Calendario', (string) ($d['periodo_note'] ?? ''));
        $riga('Edizione / turno', $turno !== '' && $turno !== 'Turno' ? $turno : trim((string) ($p['nome_turno'] ?? '')));
        $riga('Sede', (string) ($ev['luogo'] ?? ''));
        $riga('Durata', !empty($d['ore_totali']) ? (int) $d['ore_totali'] . ' ore' : (string) $v['DURATA']);
        $riga('Incontri previsti', !empty($d['incontri_previsti']) ? (string) (int) $d['incontri_previsti'] : '');
        $riga('Modalità', (string) ($d['modalita'] ?? ''));
        $riga('Destinatari', (string) ($d['destinatari'] ?? ''));
        $riga('Studenti partecipanti', $studenti > 0 ? (string) $studenti : (string) $v['STUDENTI']);
        $riga('Tutor scolastico (docente referente)', $tutor !== '' ? $tutor : (string) $v['TUTOR_SCUOLA']);
        $referenti = [];
        foreach ((array) ($d['referenti'] ?? []) as $rf) {
            $nomeRef = trim((string) ($rf['nome'] ?? ''));
            if ($nomeRef !== '') {
                $referenti[] = $nomeRef . (!empty($rf['ruolo']) ? ' (' . $rf['ruolo'] . ')' : '') . (!empty($rf['email']) ? ', ' . $rf['email'] : '') . (!empty($rf['telefono']) ? ', tel. ' . $rf['telefono'] : '');
            }
        }
        $riga('Tutor / referenti DiBEST', implode("\n", $referenti));

        // Anche descrizione, percorso, obiettivi, conoscenze, competenze e altre informazioni stanno nella tabella, una riga ciascuno
        $riga('Descrizione', $this->testoDa(((string) ($ev['descrizione'] ?? '')) !== '' ? (string) $ev['descrizione'] : (string) ($ev['descrizione_breve'] ?? '')));
        $moduli = [];
        foreach (array_values((array) ($d['moduli'] ?? [])) as $i => $m) {
            $dett = array_filter([
                !empty($m['ore']) ? (int) $m['ore'] . ((int) $m['ore'] === 1 ? ' ora' : ' ore') : '', (string) ($m['modalita'] ?? ''), (string) ($m['quando'] ?? ''), (string) ($m['sede'] ?? ''),
            ], static fn ($x): bool => trim((string) $x) !== '');
            $moduli[] = ($i + 1) . '. **' . $this->pulito((string) ($m['titolo'] ?? '')) . '**' . ($dett ? ' (' . $this->pulito(implode(' · ', $dett)) . ')' : '')
                . (trim((string) ($m['descrizione'] ?? '')) !== '' ? "\n" . $this->pulito((string) $m['descrizione']) : '');
        }
        $riga('Articolazione del percorso', implode("\n", $moduli));
        foreach (['obiettivi' => 'Obiettivi formativi', 'conoscenze' => 'Conoscenze', 'competenze' => 'Competenze attese'] as $k => $t) {
            $riga($t, $this->testoDa((string) ($d[$k] ?? '')));
        }
        foreach ((array) ($d['info_extra'] ?? []) as $ie) {
            if (trim((string) ($ie['etichetta'] ?? '')) !== '' || trim((string) ($ie['valore'] ?? '')) !== '') {
                $righe[] = [$this->pulito(trim((string) ($ie['etichetta'] ?? '')) !== '' ? (string) $ie['etichetta'] : 'Altre informazioni'), $this->pulito((string) ($ie['valore'] ?? ''))];
            }
        }

        return [
            'corso' => $corsi ? $this->pulito($corsi[0]) : self::SENZA_CORSO,
            'inizio' => (string) (($p['data_turno'] ?? null) ?: ($p['pd_inizio'] ?? null) ?: ($d['data_inizio'] ?? null) ?: '9999-12-31'),
            'voce' => ['titolo' => $this->pulito($titolo), 'righe' => $righe],
        ];
    }

    /** HTML dell'editor → testo semplice con gli elenchi puntati e i paragrafi a capo. */
    private function testoDa(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $t = (string) preg_replace('#<li[^>]*>#i', "\n• ", $html);
        $t = (string) preg_replace('#</(p|div|h[1-6]|ul|ol|tr)>|<br\s*/?>#i', "\n", $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = (string) preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
        $t = (string) preg_replace("/ ?\n ?/", "\n", $t);
        $t = (string) preg_replace("/\n{3,}/", "\n\n", $t);

        return $this->pulito(trim($t));
    }

    /** Il testo dell'utente non deve poter accendere il grassetto (**). */
    private function pulito(string $s): string
    {
        return str_replace('**', '', $s);
    }
}
