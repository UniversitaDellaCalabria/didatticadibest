<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use App\Infrastructure\Pdf\Documento;

/**
 * Allegato A della convenzione in PDF: per ogni attività prenotata la scheda completa, raggruppata per corso di laurea e in ordine di data
 * (contenuto in AllegatoAModello, lo stesso del Word di AllegatoAWord), Si firma digitalmente in PAdES
 * (o si invia com'è) con la convenzione: non ha firme, perché è un allegato della convenzione già firmata.
 */
final class AllegatoAPdf
{
    private const LOGO_DIPARTIMENTO = 'assets/modelli/logo_lettera_incarico.jpg';
    /** Colori (r g b da 0 a 1): rosso istituzionale per titolo e attività, ardesia per i corsi di laurea. */
    private const ROSSO = '0.70 0 0';
    private const ARDESIA = '0.16 0.22 0.32';
    private const SFONDO_ETICHETTE = '0.95 0.95 0.97';
    private const BORDO = '0.88';

    public function __construct(private Sito $sito, private AllegatoAModello $modello, private LogoScuola $logoScuola)
    {
    }

    /**
     * @param array<string, mixed> $scuola dati della scuola come salvati nella compilazione (denominazione, codice, comune, indirizzo, dirigente…)
     * @param list<array{p: array<string, mixed>, studenti: int, tutor: string}> $attivita prenotazioni (PrenotazioneFslRepository::dati) con studenti e docente referente
     * @param string|null $logo percorso del logo della scuola (PNG o JPG), se c'è
     * @return string il PDF
     */
    public function genera(array $scuola, array $attivita, ?string $logo = null, string $protocollo = ''): string
    {
        $m = $this->modello->costruisci($scuola, $attivita, $protocollo);
        $nome = $m['nome_scuola'];
        $pdf = new Documento([
            'logo' => $this->sito->radice() . '/' . self::LOGO_DIPARTIMENTO, 'logo_larghezza' => 210, 'logo_pos' => 'sinistra',
            'logo2' => $this->logoScuola->jpeg($logo), 'logo2_larghezza' => 120,
            'piede' => 'Allegato A – Formazione Scuola Lavoro' . ($nome !== '' ? ' – ' . $nome : ''),
            'info' => ['Title' => 'Allegato A – Formazione Scuola Lavoro', 'Author' => 'Dipartimento DiBEST – Università della Calabria', 'Subject' => 'Allegato A alla convenzione per la Formazione Scuola Lavoro'],
        ]);
        if ($m['protocollo'] !== '') {
            $pdf->paragrafo('Prot. n. ' . $m['protocollo'], ['al' => 'destra', 'sz' => 9, 'dopo' => 4]);
        }
        $pdf->fascia('ALLEGATO A – Attività di Formazione Scuola Lavoro', ['sz' => 14, 'al' => 'centro', 'colore' => self::ROSSO, 'dopo' => 8]);
        $pdf->paragrafo("alla convenzione tra il Dipartimento di Biologia, Ecologia e Scienze della Terra (DiBEST) dell'Università della Calabria"
            . ($nome !== '' ? ' e **' . $nome . '**' : " e l'istituzione scolastica"), ['al' => 'centro', 'sz' => 10.5, 'dopo' => 12]);

        $pdf->fascia('Istituzione scolastica', ['sz' => 10.5, 'colore' => self::ARDESIA, 'dopo' => 0]);
        $this->coppie($pdf, $m['scuola'], 10);

        foreach ($m['gruppi'] as $g) {
            $pdf->fascia($g['corso'], ['sz' => 11.5, 'colore' => self::ARDESIA, 'prima' => 8, 'dopo' => 6]);
            foreach ($g['attivita'] as $a) {
                $this->scheda($pdf, $a);
            }
        }
        if (!$m['gruppi']) {
            $pdf->paragrafo('Nessuna attività indicata.', ['i' => true]);
        }

        return $pdf->pdf();
    }

    /** @param array{n: int, titolo: string, righe: list<array{0: string, 1: string}>} $a */
    private function scheda(Documento $pdf, array $a): void
    {
        $pdf->serve(130);
        $pdf->fascia('Attività ' . $a['n'] . ' – ' . $a['titolo'], ['sz' => 10.5, 'colore' => self::ROSSO, 'dopo' => 0]);
        $this->coppie($pdf, $a['righe'], 12);
    }

    /**
     * Tabella etichetta/valore. Un valore molto lungo (es. la descrizione) si spezza in più righe consecutive, con l'etichetta solo nella
     * prima, così non esce dalla pagina.
     *
     * @param list<array{0: string, 1: string}> $righe
     */
    private function coppie(Documento $pdf, array $righe, float $dopo): void
    {
        $tabella = [];
        foreach ($righe as [$etichetta, $valore]) {
            foreach ($this->pezzi($valore) as $k => $pezzo) {
                $tabella[] = [$k === 0 ? $etichetta : '', $pezzo];
            }
        }
        $pdf->tabella([], $tabella, ['larghezze' => [1, 2.6], 'sz' => 9.5, 'dopo' => $dopo, 'col0' => self::SFONDO_ETICHETTE, 'bordo' => self::BORDO]);
    }

    /** @return list<string> il testo in pezzi di al massimo ~1500 caratteri, spezzati tra un paragrafo e l'altro (o tra le parole) */
    private function pezzi(string $testo): array
    {
        $max = 1500;
        $out = [];
        $corrente = '';
        foreach (explode("\n", $testo) as $riga) {
            while (mb_strlen($riga) > $max) {
                $taglio = mb_strrpos(mb_substr($riga, 0, $max), ' ') ?: $max;
                $resto = mb_substr($riga, 0, $taglio);
                $riga = ltrim(mb_substr($riga, $taglio));
                if ($corrente !== '') {
                    $out[] = $corrente;
                    $corrente = '';
                }
                $out[] = $resto;
            }
            if ($corrente !== '' && mb_strlen($corrente) + mb_strlen($riga) + 1 > $max) {
                $out[] = $corrente;
                $corrente = '';
            }
            $corrente .= ($corrente === '' ? '' : "\n") . $riga;
        }
        $out[] = $corrente;

        return $out;
    }
}
