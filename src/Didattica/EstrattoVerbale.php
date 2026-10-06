<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Sito;
use App\Infrastructure\Pdf\Documento;

/** L'estratto del verbale per lo studente: PDF/A con la delibera e il quadro delle decisioni, nella pratica quando si applicano gli esiti. */
final class EstrattoVerbale
{
    public function __construct(private Database $db, private Sito $sito, private StoricoPratica $storico, private AllegatiPratiche $allegati, private EsportazioneVerbale $esportazione)
    {
    }

    /**
     * L'estratto in PDF (stringa).
     *
     * @param array<string, mixed> $s
     * @param array<string, mixed> $p
     */
    public function pdf(array $s, array $p): string
    {
        $v = TestiPratica::verbaleModulo(['titolo' => $p['modulo_titolo'] ?? '', 'verbale_json' => $p['verbale_json'] ?? '']);
        $pdf = new Documento(['logo' => $this->sito->radice() . '/' . Costanti::LOGO_VERBALE, 'logo_larghezza' => 200, 'logo_pos' => 'sinistra', 'pdfa' => true, 'piede' => 'Estratto del verbale – pratica ' . $p['codice'],
                                'info' => ['Title' => 'Estratto del verbale – ' . trim($p['cognome'] . ' ' . $p['nome']), 'Subject' => 'Estratto del verbale della seduta del ' . ($s['data'] ? date('d/m/Y', strtotime($s['data'])) : '')]]);
        $pdf->paragrafo('**ESTRATTO DEL VERBALE**', ['al' => 'centro', 'dopo' => 4]);
        $pdf->paragrafo((string)$s['organo'], ['al' => 'centro', 'dopo' => 2]);
        $pdf->paragrafo('Seduta del ' . ($s['data'] ? date('d/m/Y', strtotime($s['data'])) : '____') . ($s['anno_accademico'] !== '' ? ' – a.a. ' . $s['anno_accademico'] : ''), ['al' => 'centro', 'dopo' => 16]);
        $pdf->paragrafo('**' . $v['sezione'] . '**', ['dopo' => 8, 'al' => 'sinistra']);
        $pdf->paragrafo(TestiPratica::segnaposti($v['testo'], $p), ['dopo' => 10]);
        $dec = json_decode((string)($p['decisioni_json'] ?? ''), true);
        if (is_array($dec) && !empty($dec['righe'])) {
            [$int, $righe] = TestiPratica::tabellaDecisioni($dec);
            $pdf->paragrafo('**' . ($dec['tipo'] === 'piano' ? 'Insegnamenti richiesti nel piano di studi' : 'Quadro delle convalide') . '**', ['sz' => 10, 'dopo' => 4]);
            $pdf->tabella($int, $righe, ['sz' => count($int) > 6 ? 7 : 9]);
            foreach (DomandaPdfa::frasiPianoDecisioni($dec) as $fr) {
                $pdf->paragrafo($fr, ['dopo' => 4]);
            }
            $pdf->spazio(8);
        }
        $esito = (string)($p['esito_seduta'] ?? '');
        $del_esito = ['respinta' => 'Il Consiglio non approva la richiesta.'][$esito] ?? '';
        $del = trim((string)$p['delibera']) !== '' ? (string)$p['delibera'] : ($del_esito !== '' ? $del_esito : $v['delibera']);
        $pdf->paragrafo('**Delibera** – ' . (Costanti::ESITI_SEDUTA[$esito][0] ?? ''), ['dopo' => 4]);
        $pdf->paragrafo(TestiPratica::segnaposti($del, $p), ['dopo' => 18]);
        $pdf->paragrafo('Per estratto conforme al verbale della seduta.', ['sz' => 9, 'dopo' => 4]);
        $pdf->paragrafo(trim('Il Segretario verbalizzante ' . $s['segretario']) . "\n" . trim('Il Coordinatore ' . $s['coordinatore']), ['sz' => 9]);
        return $pdf->pdf();
    }

    public function allega(array $s, int $pid, int $uid, string $autore_nome = ''): int
    {
        $p = $this->esportazione->pratichePerEsportazione([$pid])[0] ?? null;
        if (!$p) {
            return 0;
        }
        $dir = $this->sito->radice() . '/' . $this->allegati->cartella();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "# Allegati delle pratiche: si scaricano solo da allegato_pratica.php\nRequire all denied\n");
        }
        $nome = 'estratto_' . preg_replace('/[^A-Za-z0-9_-]/', '', $p['codice']) . '_' . bin2hex(random_bytes(4)) . '.pdf';
        if (@file_put_contents($dir . $nome, $this->pdf($s, $p)) === false) {
            return 0;
        }
        $this->storico->evento(
            $pid,
            'attivita',
            'ufficio',
            $uid,
            null,
            'Estratto del verbale della seduta' . ($s['data'] ? ' del ' . date('d/m/Y', strtotime($s['data'])) : '') . ' con la delibera' . (json_decode((string)$p['decisioni_json'], true) ? ' e il quadro delle decisioni' : '') . '.',
            $this->allegati->cartella() . $nome,
            'Estratto_verbale_' . $p['codice'] . '.pdf',
            false,
            $autore_nome
        );
        return (int)$this->db->valore("SELECT MAX(id) FROM pratiche_eventi WHERE pratica_id = ? AND tipo = 'attivita'", [$pid]);
    }
}
