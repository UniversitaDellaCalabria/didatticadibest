<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Sito;
use App\Infrastructure\Pdf\Documento;

/**
 * Il PDF degli attestati che il Dipartimento rilascia agli studenti (frequenza alle lezioni, esame sostenuto) per un'azienda o un ente: i dati dello
 * studente, il motivo, l'attestazione dei docenti (con la loro conferma registrata dal portale) e lo spazio per la firma digitale del Direttore.
 * È la stessa pagina che prima si otteneva dal ticket, ma impaginata con cura. Il documento è PDF/A, con i dati dell'accesso e l'impronta delle risposte.
 */
final class AttestatoPdf
{
    public const TIPI = [
        'frequenza' => 'ATTESTATO DI FREQUENZA ALLE LEZIONI',
        'esame'     => 'ATTESTATO DI ESAME SOSTENUTO',
    ];

    public function __construct(private Sito $sito)
    {
    }

    /** Data «gg/mm/aaaa» da un testo «aaaa-mm-gg» o gg/mm/aaaa (testo vuoto se non si capisce). */
    public static function data(string $v): string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return "$m[3]/$m[2]/$m[1]";
        }

        return preg_match('#^\d{2}/\d{2}/\d{4}$#', $v) ? $v : $v;
    }

    /** Il nome del docente come arriva dall'anagrafe (nome e cognome), senza titolo: così non serve indovinare Prof./Prof.ssa. */
    public static function nomeDocente(string $cognomeNome): string
    {
        $c = trim($cognomeNome);

        return $c;
    }

    /**
     * @param array{
     *   tipo: string, codice: string, inviata_il: int, studente: array{nome: string, matricola: string, corso: string, cf?: string, email?: string},
     *   motivo: string, ente: string, pec: string, accesso: string, ip?: string,
     *   righe: list<array{insegnamento: string, docente: string, dal?: string, al?: string, data?: string, dalle: string, alle: string, aula: string, conferma?: array{il: int, metodo?: string}|null}>,
     *   rilasciato_il?: int
     * } $d
     */
    public function pdf(array $d): string
    {
        $tipo = isset(self::TIPI[$d['tipo']]) ? $d['tipo'] : 'frequenza';
        $s = $d['studente'];
        $righe = $d['righe'];
        $quando = (int) ($d['inviata_il'] ?? time());
        $rilascio = (int) ($d['rilasciato_il'] ?? time());
        $titolo = self::TIPI[$tipo] . ' – ' . $s['nome'];
        $impronta = hash('sha256', (string) json_encode(['codice' => $d['codice'], 'tipo' => $tipo, 'studente' => $s, 'motivo' => $d['motivo'], 'ente' => $d['ente'], 'righe' => $righe, 'inviata' => $quando], JSON_UNESCAPED_UNICODE));

        $pdf = new Documento([
            'logo' => $this->sito->radice() . '/' . Costanti::LOGO_VERBALE, 'logo_larghezza' => 135, 'logo_pos' => 'sinistra', 'pdfa' => true,
            'piede' => 'Attestato ' . $d['codice'] . ' – ' . ucfirst(mb_strtolower(self::TIPI[$tipo])),
            'info' => ['Title' => $titolo, 'Author' => 'Dipartimento di Biologia, Ecologia e Scienze della Terra', 'Subject' => self::TIPI[$tipo] . ' – pratica ' . $d['codice'],
                       'Keywords' => implode('; ', array_filter([$d['codice'], self::TIPI[$tipo], $s['corso'], $s['matricola'] !== '' ? 'matricola ' . $s['matricola'] : ''])), 'Creator' => 'Didattica DiBEST – modulistica online'],
            'xmp' => ['codicePratica' => $d['codice'], 'modulo' => self::TIPI[$tipo], 'richiedente' => $s['nome'], 'codiceFiscale' => (string) ($s['cf'] ?? ''), 'matricola' => $s['matricola'],
                      'email' => (string) ($s['email'] ?? ''), 'corsoDiStudio' => $s['corso'], 'annoAccademico' => '', 'dataInvio' => date('c', $quando), 'accesso' => $d['accesso'],
                      'indirizzoIP' => (string) ($d['ip'] ?? ''), 'improntaDatiSHA256' => $impronta, 'destinatario' => $d['ente']],
        ]);
        $pdf->fascia(self::TIPI[$tipo], ['al' => 'centro', 'sz' => 12.5, 'dopo' => 8, 'prima' => 4]);

        // Studente
        $pdf->paragrafo('**Dati dello studente**', ['dopo' => 4, 'al' => 'sinistra']);
        $dati = [['Cognome e nome', $s['nome']], ['Matricola', $s['matricola']], ['Corso di laurea', $s['corso']]];
        $pdf->tabella([], $dati, ['larghezze' => [1.2, 3.8], 'col0' => '0.96 0.96 0.97', 'dopo' => 6]);

        // Motivo
        $pdf->paragrafo('**Motivo della richiesta**', ['dopo' => 4, 'al' => 'sinistra']);
        $motivo = [['Motivo', $d['motivo']]];
        if (trim($d['ente']) !== '') {
            $motivo[] = ['Ente o azienda', $d['ente']];
        }
        if (trim($d['pec']) !== '') {
            $motivo[] = ['PEC o email', $d['pec']];
        }
        $pdf->tabella([], $motivo, ['larghezze' => [1.2, 3.8], 'col0' => '0.96 0.96 0.97', 'dopo' => 8]);

        // Attestazione
        $pdf->paragrafo('**Attestazione**', ['dopo' => 4, 'al' => 'sinistra']);
        if ($tipo === 'esame') {
            $r = $righe[0] ?? ['insegnamento' => '', 'docente' => '', 'data' => '', 'dalle' => '', 'alle' => '', 'aula' => ''];
            $pdf->paragrafo(
                'Il/La sottoscritto/a **' . self::nomeDocente($r['docente']) . '**, in qualità di docente titolare del corso dell\'insegnamento di **' . $r['insegnamento'] . '**, '
                . 'attesta che lo/la studente/ssa **' . $s['nome'] . '**, matricola n. ' . $s['matricola'] . ', iscritto/a al ' . $s['corso'] . ', ha sostenuto l\'esame in data **' . self::data((string) ($r['data'] ?? '')) . '**, '
                . 'dalle ore ' . $r['dalle'] . ' alle ore ' . $r['alle'] . ', in aula ' . $r['aula'] . '.',
                ['interlinea' => 1.5, 'dopo' => 8]
            );
        } else {
            $pdf->paragrafo(
                'I/Le sottoscritti/e docenti titolari dei corsi indicati nella tabella attestano che lo/la studente/ssa **' . $s['nome'] . '**, matricola n. ' . $s['matricola'] . ', iscritto/a al ' . $s['corso']
                . ', ha seguito le lezioni di seguito elencate.',
                ['interlinea' => 1.5, 'dopo' => 8]
            );
            $tab = [];
            foreach ($righe as $r) {
                $dal = self::data((string) ($r['dal'] ?? ''));
                $al = self::data((string) ($r['al'] ?? ''));
                $tab[] = [$r['insegnamento'], self::nomeDocente($r['docente']), $al !== '' && $al !== $dal ? "$dal\n$al" : $dal, $r['dalle'] . '–' . $r['alle'], $r['aula'],
                          !empty($r['conferma']['il']) ? date('d/m/Y H:i', (int) $r['conferma']['il']) : '—'];
            }
            $pdf->tabella(['Insegnamento', 'Docente', 'Data (dal–al)', 'Orario', 'Aula', 'Confermata il'], $tab, ['sz' => 8.5, 'larghezze' => [2.6, 2.1, 1.3, 1.1, 0.9, 1.3]]);
        }
        $pdf->paragrafo('Si rilascia su richiesta dell\'interessato' . (trim($d['ente']) !== '' ? ', per essere presentato a ' . $d['ente'] : '') . ', per gli usi consentiti dalla legge.', ['dopo' => 4, 'interlinea' => 1.4]);

        // Conferme dei docenti e metadati
        $conferme = [];
        foreach ($righe as $r) {
            if (!empty($r['conferma']['il'])) {
                $conferme[self::nomeDocente($r['docente'])] = date('d/m/Y \a\l\l\e H:i', (int) $r['conferma']['il']) . (!empty($r['conferma']['metodo']) ? ' (accesso con ' . $r['conferma']['metodo'] . ')' : '');
            }
        }
        $testo = 'DATI DEL DOCUMENTO – pratica ' . $d['codice'] . "\nRichiesta presentata il " . date('d/m/Y', $quando) . ' alle ore ' . date('H:i:s', $quando)
            . ' dal portale Didattica DiBEST (' . parse_url($this->sito->urlBase(), PHP_URL_HOST) . ') da ' . $s['nome'] . ($s['matricola'] !== '' ? ' – matricola ' . $s['matricola'] : '') . "\n"
            . 'Identificazione dello studente: accesso con ' . $d['accesso'] . (!empty($d['ip']) ? ' – indirizzo IP ' . $d['ip'] : '') . "\n";
        foreach ($conferme as $doc => $q) {
            $testo .= 'Attestazione confermata nel portale da ' . $doc . ' il ' . $q . "\n";
        }
        $testo .= 'Impronta SHA-256 dei dati: ' . $impronta . "\nPDF/A-2b generato automaticamente; da firmare digitalmente dal Direttore. Protocollo in uscita attribuito dall'Ufficio protocollo.";
        $pdf->riquadro($testo, ['sz' => 7]);

        // Luogo, data e firma del Direttore: sempre in fondo all'ultima pagina, sopra il riquadro della firma digitale (in basso a sinistra, come lo mette FirmaPratica)
        $inizio = $pdf->alt - 150 - 38;
        if ($pdf->y > $inizio + 6) {
            $pdf->nuovaPagina();
        }
        $pdf->y = max($pdf->y, $inizio);
        $pdf->paragrafo('Rende, ' . date('d/m/Y', $rilascio), ['dopo' => 4, 'al' => 'sinistra']);
        $pdf->paragrafo('**Il Direttore del Dipartimento**', ['al' => 'sinistra', 'dopo' => 2]);

        return $pdf->pdf();
    }
}
