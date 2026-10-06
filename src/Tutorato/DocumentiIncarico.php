<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Documenti\Xml;
use App\Infrastructure\Pdf\Documento;
use ZipArchive;

/** Documenti della lettera di incarico: Word precompilato dal modello del Dipartimento, PDF della lettera e dichiarazione di fine attività. */
final class DocumentiIncarico
{
    public function __construct(private DatiLettera $dati, private Sito $sito, private Orologio $orologio)
    {
    }

    /**
     * Word precompilato dal modello del Dipartimento (modelli_documenti/lettera_incarico_tutorato.docx): percorso temporaneo o null.
     *
     * @param array<string, mixed> $i
     */
    public function docx(array $i): ?string
    {
        $modello = $this->sito->radice() . '/' . Costanti::MODELLO_LETTERA;
        if (!class_exists('ZipArchive') || !is_file($modello)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'inc');
        copy($modello, $tmp);
        $z = new ZipArchive();
        if ($z->open($tmp) !== true) {
            return null;
        }
        $xml = (string)$z->getFromName('word/document.xml');
        foreach ($this->dati->valori($i) as $k => $v) {
            // a capo nelle attività: interruzione di riga di Word
            $x = str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">', Xml::testo(str_replace("\r", '', $v)));
            $xml = str_replace('{{' . $k . '}}', $x, $xml);
        }
        $z->addFromString('word/document.xml', $xml);
        $z->close();
        return $tmp;
    }

    /**
     * Lettera in PDF (stesso testo del modello Word). $firma = conferma dello studente con SPID/CIE (riquadro e firma per accettazione).
     * Ritorna [contenuto PDF, segnaposti delle firme].
     *
     * @param array<string, mixed> $i
     * @param array<string, mixed>|null $firma
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function pdfLettera(array $i, ?array $firma = null): array
    {
        $d = $this->dati->valori($i);
        $pdf = new Documento(['logo' => $this->sito->radice() . '/' . Costanti::LOGO_LETTERA, 'logo_larghezza' => 210,
                                'piede' => "Dipartimento di Biologia, Ecologia e Scienze della Terra – Università della Calabria – Via P. Bucci, Cubo 4B – 87036 Rende (CS) – dipartimento.best@pec.unical.it – lettera {$i['codice']}",
                                'info' => ['Title' => 'Lettera di incarico di tutorato – ' . $d['NOMINATIVO'], 'Author' => 'Dipartimento DiBEST – Università della Calabria', 'Subject' => 'Conferimento incarico di tutorato ' . $i['codice']]]);
        $pdf->paragrafo($d['TITOLO'] . ' ' . $d['NOMINATIVO'] . "\n" . $i['email'], ['al' => 'destra', 'dopo' => 14]);
        $pdf->paragrafo('**OGGETTO**: Conferimento incarico riguardante attività di tutorato, nonché attività didattico integrative, propedeutiche e di recupero (ART. 1 Co. 1°, lett. b) L. 170/2003) – Bando di selezione di cui al D.D. n. ' . $d['DECRETO_BANDO'] . '.', ['dopo' => 12]);
        $pdf->paragrafo('Gentile ' . $d['NOMINATIVO'] . ', ' . $d['NATO'] . ' a ' . $d['LUOGO_NASCITA'] . ' il ' . $d['DATA_NASCITA'] . ' e residente a ' . $d['COMUNE_RESIDENZA'] . ' in ' . $d['INDIRIZZO'] . ', codice fiscale ' . $d['CODICE_FISCALE']
                       . ', ' . $d['VINCITORE'] . ' della procedura selettiva, i cui atti sono stati approvati con D.D. n. ' . $d['DECRETO_COMMISSIONE'] . ', alla S. V. viene conferito il seguente incarico di “supporto alle attività didattiche, in qualità di tutor”:', ['dopo' => 8]);
        $pdf->tabella(['ATTIVITÀ DA SVOLGERE', 'N. ORE', 'PERIODO', "COMPENSO ASSEGNO\n(al netto degli oneri a carico dell'Ente)"], [[$d['ATTIVITA'], $d['ORE'], $d['PERIODO'], $d['COMPENSO']]], ['larghezze' => [4, 1.2, 2.4, 2.4], 'sz' => 9.5, 'al' => [1 => 'centro', 2 => 'centro', 3 => 'centro']]);
        foreach ([
            'L’incarico deve essere eseguito personalmente dalla S.V. la quale non potrà quindi valersi di sostituti.',
            'Il compenso forfetario lordo sarà erogato in unica soluzione a fine prestazione, previa notifica del completamento delle attività svolte da parte del Responsabile delle attività.',
            'Qualora si dovessero verificare riduzioni o sospensioni dell’attività oggetto della presente lettera d’incarico, per motivi didattici e/o organizzativi, la misura del corrispettivo sarà rapportata alle ore di collaborazione effettivamente svolte, tramite verifica dell’apposito registro delle attività, ove previsto.',
            'All’assegno, si applicano le disposizioni dell’art. 10 bis del decreto legislativo 15 dicembre 1997, n° 446, nonché quelle dell’art. 4 della Legge 13 agosto 1984, n° 476, e successive modificazioni, ed in materia previdenziale quelle dell’art. 2, commi 26 e seguenti, della Legge 8 agosto 1995, n° 335, e successive modificazioni (Gestione Separata INPS).',
            'I compensi per gli incarichi suddetti sono esenti dall’applicazione delle aliquote IRAP ed IRPEF, mentre scontano il contributo INPS – Gestione Separata (art. 2 commi 26 e s.s. L. 335/95).',
            'Durante l’intero periodo della collaborazione sarà cura dell’Università coprire, a proprie spese, con assicurazione il rischio da infortuni.',
            'La prestazione non dà luogo a diritti in ordine all’accesso nei ruoli delle Università e degli Istituti di Istruzione Universitaria Statali.',
            'L’assegno per attività di tutorato, nonché per le attività didattico – integrative, propedeutiche e di recupero, di cui sopra, è compatibile con la fruizione delle borse di studio di cui all’art. 8 della legge 2 dicembre 1991, n. 390.',
        ] as $t) {
            $pdf->paragrafo($t, ['sz' => 10.5, 'dopo' => 5]);
        }
        $pdf->paragrafo($d['LUOGO'] . ', ' . $d['DATA_LETTERA'], ['prima' => 6, 'dopo' => 14, 'al' => 'sinistra']);
        // Firme: presa visione del docente e firma del direttore (PAdES, aspetto visibile negli spazi riservati), accettazione dello studente (SPID/CIE)
        $pdf->serve(150);
        $l3 = ($pdf->larg - $pdf->sx - $pdf->dx) / 3;
        $top = $pdf->y;
        $pdf->spazioFirma('docente', "PRESA VISIONE\nDEL RESPONSABILE DELL’ATTIVITÀ", $d['FIRMA_DOCENTE'] . "\n(firma digitale PAdES)", $pdf->sx, $l3);
        $y1 = $pdf->y;
        $pdf->y = $top;
        $pdf->spazioFirma('studente', "PER ACCETTAZIONE\n ", $firma ? $d['FIRMA_STUDENTE'] . "\nconfermata con " . (Costanti::METODI_ACCESSO[$firma['metodo']] ?? $firma['metodo']) . ' il ' . date('d/m/Y H:i', strtotime($firma['confermata_il'])) : $d['FIRMA_STUDENTE'], $pdf->sx + $l3, $l3);
        $y2 = $pdf->y;
        $pdf->y = $top;
        $pdf->spazioFirma('direttore', "IL DIRETTORE\n ", $d['FIRMA_DIRETTORE'] . "\n(firma digitale PAdES)", $pdf->sx + 2 * $l3, $l3);
        $pdf->y = max($y1, $y2, $pdf->y) + 14;
        if ($firma) {
            $pdf->riquadro('**Accettazione dell’incarico con identità digitale.** ' . $d['NOMINATIVO'] . ' (codice fiscale ' . $i['codice_fiscale'] . ') ha preso visione della lettera e ha confermato l’accettazione '
                . 'dopo l’accesso al portale Didattica DiBEST con ' . (Costanti::METODI_ACCESSO[$firma['metodo']] ?? $firma['metodo']) . ($firma['livello'] ? ' di livello ' . (int)$firma['livello'] : '') . '.' . "\n"
                . 'Identity provider: ' . ($firma['idp'] ?: 'n.d.') . ($firma['spid_code'] !== '' ? ' – spidCode: ' . $firma['spid_code'] : '') . ($firma['contesto'] !== '' ? ' – contesto: ' . $firma['contesto'] : '') . "\n"
                . 'Autenticazione: ' . date('d/m/Y H:i:s', strtotime($firma['istante'])) . ' – conferma: ' . date('d/m/Y H:i:s', strtotime($firma['confermata_il'])) . ' – indirizzo IP: ' . ($firma['ip'] ?: 'n.d.') . "\n"
                . 'Impronta SHA-256 dei dati della lettera: ' . $firma['impronta'], ['sz' => 7.8, 'dopo' => 4]);
        }
        $pdf->paragrafo('Documento firmato digitalmente ai sensi del Codice dell’Amministrazione Digitale (D.Lgs. 82/2005) e norme ad esso connesse.', ['sz' => 8, 'font' => 'H', 'al' => 'centro', 'prima' => 4]);
        return [$pdf->pdf(), $pdf->segnaposti];
    }

    /**
     * Dati della dichiarazione di fine attività (modello del Dipartimento).
     *
     * @param array<string, mixed> $i
     * @return array<string, string>
     */
    public function datiFine(array $i, ?float $ore = null): array
    {
        $d = $this->dati->valori($i);
        $tit = in_array($i['docente_titolo'] ?? '', ['Prof.', 'Prof.ssa', 'Dott.', 'Dott.ssa'], true) ? $i['docente_titolo'] : 'Prof.';
        $f_doc = in_array($tit, ['Prof.ssa', 'Dott.ssa'], true);
        return [
            'DOCENTE' => $tit . ' ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome']), 'SOTTOSCRITTO' => $f_doc ? 'La sottoscritta' : 'Il sottoscritto',
            'INSEGNAMENTO' => trim((string)$i['insegnamento_docente']) ?: '________________', 'CORSO' => trim((string)$i['corso_laurea']) ?: '________________',
            'TUTOR' => $d['TITOLO'] . ' ' . $d['NOMINATIVO'], 'VINCITORE' => $d['VINCITORE'], 'DECRETO_BANDO' => $d['DECRETO_BANDO'],
            'ORE' => $ore !== null ? DatiLettera::oreTesto($ore) : ($i['ore_approvate'] !== null ? DatiLettera::oreTesto($i['ore_approvate']) : '____'),
            'LUOGO' => $d['LUOGO'], 'DATA' => $this->orologio->adesso()->format('d/m/Y'),
        ];
    }

    /**
     * Dichiarazione di fine attività in PDF (con il riepilogo del registro). Ritorna [contenuto PDF, segnaposti della firma].
     *
     * @param array<string, mixed> $i
     * @param list<array<string, mixed>> $registro
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function pdfFine(array $i, array $registro, ?float $ore = null): array
    {
        $d = $this->datiFine($i, $ore);
        $pdf = new Documento(['logo' => $this->sito->radice() . '/' . Costanti::LOGO_LETTERA, 'logo_larghezza' => 210,
                                'piede' => "Dichiarazione di fine attività di tutorato – lettera di incarico {$i['codice']}",
                                'info' => ['Title' => 'Fine attività – ' . $d['TUTOR'], 'Author' => $d['DOCENTE'], 'Subject' => 'Dichiarazione di fine attività di tutorato ' . $i['codice']]]);
        $pdf->paragrafo("Al Direttore del Dipartimento di Biologia,\nEcologia e Scienze della Terra\nUNIVERSITÀ DELLA CALABRIA\nSEDE", ['al' => 'destra', 'dopo' => 22]);
        $pdf->paragrafo('**Oggetto**: fine attività ' . $d['TUTOR'], ['dopo' => 16, 'al' => 'sinistra']);
        $pdf->paragrafo($d['SOTTOSCRITTO'] . ' ' . $d['DOCENTE'] . ', docente titolare di ' . $d['INSEGNAMENTO'] . ' del corso di laurea in ' . $d['CORSO'] . ' dell\'UniCal,', ['dopo' => 12]);
        $pdf->paragrafo('**DICHIARA**', ['al' => 'centro', 'dopo' => 12]);
        $pdf->paragrafo('che ' . ($i['genere'] === 'F' ? 'la' : 'il') . ' ' . $d['TUTOR'] . ', ' . $d['VINCITORE'] . ' del bando emanato con D.D. n. ' . $d['DECRETO_BANDO'] . ', ha regolarmente svolto le attività per un totale di n. ore **' . $d['ORE'] . '**.', ['dopo' => 18]);
        $pdf->paragrafo($d['LUOGO'] . ', ' . $d['DATA'], ['al' => 'sinistra', 'dopo' => 6]);
        $l = ($pdf->larg - $pdf->sx - $pdf->dx) / 3;
        $pdf->serve(130);
        $pdf->spazioFirma('docente', 'Firma', $d['DOCENTE'] . "\n(firma digitale PAdES)", $pdf->sx + 2 * $l, $l);
        $pdf->spazio(14);
        $appr = array_values(array_filter($registro, fn ($r) => $r['stato'] === 'approvata'));
        if ($appr) {
            $pdf->paragrafo('**Riepilogo del registro delle attività** (ore approvate dal docente responsabile)', ['sz' => 10, 'dopo' => 6, 'prima' => 6]);
            $pdf->tabella(['Data', 'Ore', 'Attività svolta'], array_map(fn ($r) => [date('d/m/Y', strtotime($r['data'])), DatiLettera::oreTesto($r['ore']), (string)$r['attivita']], $appr), ['larghezze' => [1.2, 0.7, 6], 'sz' => 9, 'al' => [1 => 'centro']]);
            $pdf->paragrafo('Totale ore approvate: **' . $d['ORE'] . '** su ' . DatiLettera::oreTesto($i['ore']) . ' previste dalla lettera di incarico.', ['sz' => 10]);
        }
        return [$pdf->pdf(), $pdf->segnaposti];
    }
}
