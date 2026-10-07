<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioCorsi;
use App\Anagrafi\Testi;
use App\Core\Sito;
use App\Eventi\EventoRepository;
use App\Eventi\Turni;
use App\Infrastructure\Pdf\Documento;

/**
 * Allegato A della convenzione in PDF: per ogni attività prenotata la scheda completa (titolo, corso, periodo, sede, durata, modalità,
 * destinatari, studenti, tutor della scuola e del Dipartimento, descrizione, percorso, obiettivi, conoscenze, competenze, altre
 * informazioni), con lo spazio per le firme PAdES. Si firma digitalmente in PAdES e si invia con la convenzione.
 */
final class AllegatoAPdf
{
    private const LOGO_DIPARTIMENTO = 'assets/modelli/logo_lettera_incarico.jpg';

    public function __construct(
        private Sito $sito,
        private EventoRepository $eventi,
        private PrenotazioneFslRepository $prenotazioni,
        private PrecompilazioneConvenzione $precompilazione,
        private ServizioCorsi $corsi
    ) {
    }

    /**
     * @param array<string, mixed> $scuola dati della scuola come salvati nella compilazione (denominazione, codice, comune, indirizzo, dirigente…)
     * @param list<array{p: array<string, mixed>, studenti: int, tutor: string}> $attivita prenotazioni (PrenotazioneFslRepository::dati) con studenti e docente referente
     * @param string|null $logo percorso del logo della scuola (PNG o JPG), se c'è
     * @return string il PDF
     */
    public function genera(array $scuola, array $attivita, ?string $logo = null, string $protocollo = ''): string
    {
        $jpeg = $this->logoJpeg($logo);
        $nome = trim((string) ($scuola['denominazione'] ?? ''));
        $pdf = new Documento([
            'logo' => $this->sito->radice() . '/' . self::LOGO_DIPARTIMENTO, 'logo_larghezza' => 210, 'logo_pos' => 'sinistra',
            'logo2' => $jpeg, 'logo2_larghezza' => 120,
            'piede' => 'Allegato A – Formazione Scuola Lavoro' . ($nome !== '' ? ' – ' . $this->pulito($nome) : ''),
            'info' => ['Title' => 'Allegato A – Formazione Scuola Lavoro', 'Author' => 'Dipartimento DiBEST – Università della Calabria', 'Subject' => 'Allegato A alla convenzione per la Formazione Scuola Lavoro'],
        ]);
        if (trim($protocollo) !== '') {
            $pdf->paragrafo('Prot. n. ' . $this->pulito($protocollo), ['al' => 'destra', 'sz' => 9, 'dopo' => 4]);
        }
        $pdf->paragrafo('ALLEGATO A', ['al' => 'centro', 'b' => true, 'sz' => 15, 'dopo' => 2]);
        $pdf->paragrafo('Attività di Formazione Scuola Lavoro', ['al' => 'centro', 'b' => true, 'sz' => 12, 'dopo' => 4]);
        $pdf->paragrafo("alla convenzione tra il Dipartimento di Biologia, Ecologia e Scienze della Terra (DiBEST) dell'Università della Calabria"
            . ($nome !== '' ? ' e **' . $this->pulito($nome) . '**' : " e l'istituzione scolastica"), ['al' => 'centro', 'sz' => 10.5, 'dopo' => 12]);

        // Istituzione scolastica
        $pdf->paragrafo('Istituzione scolastica', ['b' => true, 'sz' => 11.5, 'dopo' => 4, 'al' => 'sinistra']);
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
        $this->coppie($pdf, $righe ?: [['Denominazione', '—']]);

        $n = 0;
        foreach ($attivita as $a) {
            $this->scheda($pdf, ++$n, $a['p'], max(0, (int) $a['studenti']), trim((string) $a['tutor']));
        }
        if ($n === 0) {
            $pdf->paragrafo('Nessuna attività indicata.', ['i' => true]);
        }

        // Firme affiancate
        $pdf->serve(150);
        $pdf->spazio(18);
        $pdf->paragrafo('Le parti sottoscrivono con firma digitale PAdES.', ['sz' => 9.5, 'dopo' => 8, 'al' => 'sinistra']);
        $l = ($pdf->larg - $pdf->sx - $pdf->dx - 20) / 2;
        $top = $pdf->y;
        $pdf->spazioFirma('firma_scuola', "Per l'istituzione scolastica\nIl Dirigente Scolastico", $this->pulito((string) ($scuola['dirigente'] ?? '')), $pdf->sx, $l);
        $fine = $pdf->y;
        $pdf->y = $top;
        $pdf->spazioFirma('firma_dipartimento', "Per il Dipartimento DiBEST\nUniversità della Calabria\nIl Direttore", '', $pdf->sx + $l + 20, $l);
        $pdf->y = max($fine, $pdf->y);

        return $pdf->pdf();
    }

    /**
     * @param array<string, mixed> $p prenotazione con i dati dell'attività
     */
    private function scheda(Documento $pdf, int $n, array $p, int $studenti, string $tutor): void
    {
        $eventoId = (int) $p['evento_id'];
        $d = $this->eventi->dettagliProgetti([$eventoId])[$eventoId] ?? [];
        $ev = $this->prenotazioni->schedaEvento($eventoId);
        $v = $this->precompilazione->dati($p);

        $pdf->serve(120);
        $pdf->spazio(10);
        $pdf->paragrafo('Attività ' . $n . ' – ' . $this->pulito((string) ($ev['titolo'] ?? $p['evento_titolo'])), ['b' => true, 'sz' => 12, 'dopo' => 4, 'al' => 'sinistra']);

        $corsi = array_map(static fn (array $c): string => Testi::nomeSchedaCorso($c), $this->corsi->corsiDi($d));
        $struttura = trim((string) ($d['struttura'] ?? ''));
        if ($struttura !== '' && !in_array(mb_strtolower($struttura), array_map('mb_strtolower', $corsi), true)) {
            array_unshift($corsi, $struttura);
        }
        $corso = implode("\n", $corsi);
        $turno = ($ev['tipo'] ?? '') === 'progetto' ? '' : Turni::etichetta($p);
        $righe = [];
        $riga = static function (string $etichetta, string $valore) use (&$righe): void {
            if (trim($valore) !== '') {
                $righe[] = [$etichetta, $valore];
            }
        };
        $riga('Titolo', (string) ($ev['titolo'] ?? $p['evento_titolo']));
        $riga(count($corsi) > 1 ? 'Corsi di studio / struttura' : 'Corso di studio / struttura', $corso);
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
        $riga('Attestato', !empty($d['attestati']) ? 'Per ogni studente partecipante' : '');
        $this->coppie($pdf, $righe);

        $this->sezione($pdf, 'Descrizione', $this->testoDa(((string) ($ev['descrizione'] ?? '')) !== '' ? (string) $ev['descrizione'] : (string) ($ev['descrizione_breve'] ?? '')));
        $moduli = [];
        foreach (array_values((array) ($d['moduli'] ?? [])) as $i => $m) {
            $dett = array_filter([
                !empty($m['ore']) ? (int) $m['ore'] . ((int) $m['ore'] === 1 ? ' ora' : ' ore') : '', (string) ($m['modalita'] ?? ''), (string) ($m['quando'] ?? ''), (string) ($m['sede'] ?? ''),
            ], static fn ($x): bool => trim((string) $x) !== '');
            $moduli[] = ($i + 1) . '. **' . $this->pulito((string) ($m['titolo'] ?? '')) . '**' . ($dett ? ' (' . $this->pulito(implode(' · ', $dett)) . ')' : '')
                . (trim((string) ($m['descrizione'] ?? '')) !== '' ? "\n" . $this->pulito((string) $m['descrizione']) : '');
        }
        if ($moduli) {
            $pdf->serve(90);
            $pdf->paragrafo('Articolazione del percorso', ['b' => true, 'sz' => 10.5, 'prima' => 4, 'dopo' => 2, 'al' => 'sinistra']);
            foreach ($moduli as $m) {
                $pdf->paragrafo($m, ['sz' => 10, 'dopo' => 3, 'rientro' => 8, 'al' => 'sinistra']);
            }
        }
        foreach (['obiettivi' => 'Obiettivi formativi', 'conoscenze' => 'Conoscenze', 'competenze' => 'Competenze attese'] as $k => $titolo) {
            $this->sezione($pdf, $titolo, $this->testoDa((string) ($d[$k] ?? '')));
        }
        $extra = [];
        foreach ((array) ($d['info_extra'] ?? []) as $ie) {
            if (trim((string) ($ie['etichetta'] ?? '')) !== '' || trim((string) ($ie['valore'] ?? '')) !== '') {
                $extra[] = [(string) ($ie['etichetta'] ?? ''), (string) ($ie['valore'] ?? '')];
            }
        }
        if ($extra) {
            $pdf->serve(70);
            $pdf->paragrafo('Altre informazioni', ['b' => true, 'sz' => 10.5, 'prima' => 4, 'dopo' => 3, 'al' => 'sinistra']);
            $this->coppie($pdf, $extra);
        }
    }

    /** @param list<array{0: string, 1: string}> $righe */
    private function coppie(Documento $pdf, array $righe): void
    {
        $pdf->tabella([], array_map(fn (array $r): array => [$this->pulito($r[0]), $this->pulito($r[1])], $righe), ['larghezze' => [1, 2.6], 'sz' => 9.5, 'dopo' => 8]);
    }

    private function sezione(Documento $pdf, string $titolo, string $testo): void
    {
        if (trim($testo) === '') {
            return;
        }
        $pdf->serve(70);   // il titolo non resta solo in fondo alla pagina
        $pdf->paragrafo($titolo, ['b' => true, 'sz' => 10.5, 'prima' => 4, 'dopo' => 2, 'al' => 'sinistra']);
        $pdf->paragrafo($testo, ['sz' => 10, 'dopo' => 4, 'al' => 'sinistra']);
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

    /** Il testo dell'utente non deve poter accendere il grassetto del PDF (**). */
    private function pulito(string $s): string
    {
        return str_replace('**', '', $s);
    }

    /**
     * Il logo della scuola come JPEG (il PDF accetta solo JPEG): un PNG si converte su fondo bianco con GD, in un file temporaneo
     * che viene tolto alla fine della richiesta.
     */
    private function logoJpeg(?string $logo): ?string
    {
        if (!$logo || !is_file($logo) || !($dim = @getimagesize($logo))) {
            return null;
        }
        if ($dim[2] === IMAGETYPE_JPEG) {
            return $logo;
        }
        if ($dim[2] !== IMAGETYPE_PNG || !function_exists('imagecreatefrompng')) {
            return null;
        }
        $png = @imagecreatefrompng($logo);
        if (!$png) {
            return null;
        }
        $w = imagesx($png);
        $h = imagesy($png);
        $tela = imagecreatetruecolor($w, $h);
        imagefill($tela, 0, 0, (int) imagecolorallocate($tela, 255, 255, 255));
        imagecopy($tela, $png, 0, 0, 0, 0, $w, $h);
        $tmp = tempnam(sys_get_temp_dir(), 'logo') . '.jpg';
        $ok = imagejpeg($tela, $tmp, 90);
        register_shutdown_function(static function () use ($tmp): void {
            @unlink($tmp);
        });

        return $ok ? $tmp : null;
    }
}
