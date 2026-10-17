<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Sito;
use App\Infrastructure\Pdf\Documento;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * La convenzione per la tesi dal modello Word del Dipartimento (modelli_documenti/convenzione_tesi.docx): si compila la parte dell'azienda e,
 * se l'azienda ha chiesto modifiche accettate dall'ufficio, si sostituisce il testo degli articoli. Dallo stesso testo si ricava il PDF (che
 * l'azienda firma in PAdES): Word e PDF dicono sempre le stesse cose perché il PDF si legge dal Word compilato.
 *
 * $azienda: ragione_sociale, comune, provincia, indirizzo, piva, cf, pec, rappr_titolo, rappr_nome, rappr_qualifica.
 * $modifiche: [numero articolo => testo concordato] (paragrafi separati da una riga vuota).
 */
final class ModelloConvenzione
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const RE_ARTICOLO = '/^\s*(?:Art\.|Articolo)\s*(\d+)\s*[–-]\s*(.+?)\s*$/u';

    public function __construct(private Sito $sito)
    {
    }

    public function disponibile(): bool
    {
        return is_file($this->sito->radice() . '/' . Costanti::MODELLO) && class_exists(ZipArchive::class);
    }

    /**
     * Gli articoli del modello standard, per mostrarli all'azienda e permetterle di proporre modifiche.
     *
     * @return array<int, array{titolo: string, testo: string}> numero => titolo e testo (paragrafi separati da una riga vuota)
     */
    public function articoli(): array
    {
        [$dom, $xp] = $this->carica($this->sito->radice() . '/' . Costanti::MODELLO);
        if ($dom === null) {
            return [];
        }
        $out = [];
        foreach ($this->sezioni($xp) as $n => $s) {
            $testo = [];
            foreach ($s['corpo'] as $p) {
                $t = $this->testo($p);
                if ($t !== '') {
                    $testo[] = $t;
                }
            }
            $out[$n] = ['titolo' => $s['titolo'], 'testo' => implode("\n\n", $testo)];
        }

        return $out;
    }

    /**
     * Il Word compilato (percorso di un file temporaneo da cancellare dopo l'uso) o null se il modello manca.
     *
     * $direttore: come scrivere il Direttore del Dipartimento (titolo, nome, nato_a, nato_il); null = il testo del modello com'è.
     *
     * @param array<string, mixed> $azienda
     * @param array<int, string> $modifiche
     * @param array{titolo: string, nome: string, nato_a: string, nato_il: string}|null $direttore
     */
    public function word(array $azienda, array $modifiche = [], ?array $direttore = null): ?string
    {
        if (!$this->disponibile()) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tesi') . '.docx';
        if (!@copy($this->sito->radice() . '/' . Costanti::MODELLO, $tmp)) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);

            return null;
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        if ($xml === '' || !@$dom->loadXML($xml)) {
            $zip->close();
            @unlink($tmp);

            return null;
        }
        $xp = $this->xpath($dom);
        $this->compilaAzienda($dom, $xp, $azienda);
        if ($direttore !== null) {
            $this->compilaDirettore($dom, $xp, $direttore);
        }
        foreach ($modifiche as $n => $testo) {
            $this->sostituisciArticolo($dom, $xp, (int) $n, (string) $testo);
        }
        $zip->addFromString('word/document.xml', (string) $dom->saveXML());
        $zip->close();

        return $tmp;
    }

    /**
     * Il PDF della convenzione compilata (stesso testo del Word), con gli spazi per le due firme. Ritorna null se il modello manca.
     *
     * @param array<string, mixed> $azienda
     * @param array<int, string> $modifiche
     * @param array{titolo: string, nome: string, nato_a: string, nato_il: string}|null $direttore
     */
    public function pdf(array $azienda, array $modifiche = [], ?array $direttore = null): ?string
    {
        $word = $this->word($azienda, $modifiche, $direttore);
        if ($word === null) {
            return null;
        }
        $paragrafi = $this->paragrafi($word);
        @unlink($word);
        if (!$paragrafi) {
            return null;
        }
        $pdf = new Documento([
            'logo' => $this->sito->radice() . '/assets/modelli/logo_lettera_incarico.jpg', 'logo_larghezza' => 210, 'logo_pos' => 'sinistra',
            'info' => ['Title' => 'Accordo per attività di studio e ricerca finalizzata al lavoro di tesi', 'Author' => 'Dipartimento DiBEST - Università della Calabria'],
            'piede' => 'Accordo per il lavoro di tesi · ' . (string) ($azienda['ragione_sociale'] ?? ''),
            'pdfa' => true, 'lingua' => 'it-IT',
        ]);
        $pdf->sx = 56;
        $pdf->dx = 56;
        $sz = 10;
        foreach ($paragrafi as $p) {
            $t = $p['testo'];
            switch ($p['tipo']) {
                case 'titolo':
                    $pdf->paragrafo('**' . $t . '**', ['sz' => 12, 'al' => 'centro', 'prima' => 6, 'dopo' => 10]);
                    break;
                case 'centro':
                    $pdf->paragrafo('**' . $t . '**', ['sz' => $sz, 'al' => 'centro', 'prima' => 6, 'dopo' => 6]);
                    break;
                case 'articolo':
                    $pdf->serve(60);
                    $pdf->paragrafo('**' . $t . '**', ['sz' => $sz, 'al' => 'sinistra', 'prima' => 6, 'dopo' => 3]);
                    break;
                case 'elenco':
                    $pdf->paragrafo('• ' . $t, ['sz' => $sz, 'rientro' => 14, 'dopo' => 3]);
                    break;
                default:
                    $pdf->paragrafo($t, ['sz' => $sz, 'dopo' => 4]);
            }
        }
        $pdf->serve(150);
        $pdf->spazio(8);
        $pdf->paragrafo('Arcavacata di Rende (CS), lì ____________________', ['sz' => $sz, 'al' => 'sinistra', 'dopo' => 10]);
        $larg = ($pdf->larg - $pdf->sx - $pdf->dx - 20) / 2;
        $y0 = $pdf->y;
        $pdf->spazioFirma('promotore', 'Per il soggetto promotore (Il Direttore)', '', $pdf->sx, $larg, 60);
        $y1 = $pdf->y;
        $pdf->y = $y0;
        $pdf->spazioFirma('ospitante', 'Per il soggetto ospitante (' . (string) ($azienda['ragione_sociale'] ?? '') . ')', '', $pdf->sx + $larg + 20, $larg, 60);
        $pdf->y = max($y1, $pdf->y) + 8;
        $pdf->paragrafo('Documento firmato digitalmente ai sensi del Codice dell\'Amministrazione Digitale e norme ad esso connesse', ['sz' => 8, 'i' => true, 'al' => 'centro']);

        return $pdf->pdf();
    }

    /**
     * Il testo del Word come sequenza di paragrafi da stampare.
     *
     * @return list<array{tipo: string, testo: string}>
     */
    private function paragrafi(string $docx): array
    {
        [$dom, $xp] = $this->carica($docx);
        if ($dom === null) {
            return [];
        }
        $out = [];
        $primo = true;
        foreach ($xp->query('//w:body//w:p') as $p) {
            $t = $this->testo($p);
            if ($t === '') {
                continue;
            }
            // Le righe delle firme e della data si rifanno in fondo, con gli spazi per la firma digitale
            if (preg_match('/^(Firma del soggetto|Arcavacata|Documento firmato)/u', $t)) {
                continue;
            }
            if ($primo) {
                $out[] = ['tipo' => 'titolo', 'testo' => $t];
                $primo = false;
            } elseif (in_array($t, ['TRA', 'E', 'PREMESSO', 'SI CONVIENE E STIPULA QUANTO SEGUE:'], true) || preg_match('/^Letta, approvata/u', $t)) {
                $out[] = ['tipo' => 'centro', 'testo' => $t];
            } elseif (preg_match(self::RE_ARTICOLO, $t)) {
                $out[] = ['tipo' => 'articolo', 'testo' => $t];
            } elseif ($xp->query('w:pPr/w:numPr', $p)->length > 0) {
                $out[] = ['tipo' => 'elenco', 'testo' => $t];
            } else {
                $out[] = ['tipo' => 'testo', 'testo' => $t];
            }
        }

        return $out;
    }

    // ───────────────────────────── DOM ─────────────────────────────

    /** @return array{0: ?DOMDocument, 1: ?DOMXPath} */
    private function carica(string $docx): array
    {
        $zip = new ZipArchive();
        if (!class_exists(ZipArchive::class) || !is_file($docx) || $zip->open($docx) !== true) {
            return [null, null];
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        if ($xml === '' || !@$dom->loadXML($xml)) {
            return [null, null];
        }

        return [$dom, $this->xpath($dom)];
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::W);

        return $xp;
    }

    private function testo(\DOMNode $p): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $p->textContent)));
    }

    /**
     * Gli articoli come sezioni: il paragrafo del titolo e i paragrafi del corpo fino al titolo seguente (o alla formula «Letta, approvata»).
     *
     * @return array<int, array{titolo: string, intestazione: \DOMElement, corpo: list<\DOMElement>}>
     */
    private function sezioni(DOMXPath $xp): array
    {
        $out = [];
        $corrente = null;
        foreach ($xp->query('//w:body//w:p') as $p) {
            $t = $this->testo($p);
            if (preg_match(self::RE_ARTICOLO, $t, $m)) {
                $corrente = (int) $m[1];
                $out[$corrente] = ['titolo' => $m[2], 'intestazione' => $p, 'corpo' => []];
                continue;
            }
            if ($corrente !== null && preg_match('/^(Letta, approvata|Firma del soggetto)/u', $t)) {
                $corrente = null;
                continue;
            }
            if ($corrente !== null) {
                $out[$corrente]['corpo'][] = $p;
            }
        }

        return $out;
    }

    /** La parte dell'azienda («E … con sede legale in …») e la riga della firma. */
    private function compilaAzienda(DOMDocument $dom, DOMXPath $xp, array $a): void
    {
        $v = static fn (string $k, string $vuoto = '……………………'): string => trim((string) ($a[$k] ?? '')) !== '' ? trim((string) $a[$k]) : $vuoto;
        $sede = $v('comune');
        if (trim((string) ($a['provincia'] ?? '')) !== '' && $sede !== '……………………') {
            $sede .= ' (' . strtoupper(trim((string) $a['provincia'])) . ')';
        }
        $titolo = trim((string) ($a['rappr_titolo'] ?? ''));
        foreach ($xp->query('//w:body//w:p') as $p) {
            $t = $this->testo($p);
            if (str_contains($t, 'con sede legale') && str_contains($t, 'soggetto ospitante')) {
                $nuovo = $v('ragione_sociale') . ', con sede legale in ' . $sede . ', via ' . $v('indirizzo') . ', P. IVA ' . $v('piva') . ' – C.F. ' . $v('cf')
                    . ', d’ora in poi denominato “soggetto ospitante”: indirizzo di posta elettronica certificata (PEC) ' . $v('pec')
                    . ', rappresentato/a dal/la ' . trim($titolo . ' ' . $v('rappr_nome')) . ', in qualità di ' . $v('rappr_qualifica');
                $this->riscrivi($dom, $xp, $p, $nuovo);
            } elseif (str_starts_with($t, 'Firma del soggetto ospitante')) {
                $this->riscrivi($dom, $xp, $p, 'Firma del soggetto ospitante (' . $v('ragione_sociale', '……………………………') . ') ____________________');
            }
        }
    }

    /** Il Direttore («rappresentato dal Direttore, Prof. … nato a … il …, domiciliato per la carica…»): i dati non indicati restano puntini da completare a mano. */
    private function compilaDirettore(DOMDocument $dom, DOMXPath $xp, array $d): void
    {
        foreach ($xp->query('//w:body//w:p') as $p) {
            $t = $this->testo($p);
            if (!str_contains($t, 'soggetto promotore') || !preg_match('/rappresentato dal Direttore, .+?, domiciliato per la carica/u', $t)) {
                continue;
            }
            $v = static fn (string $k): string => trim((string) ($d[$k] ?? '')) !== '' ? trim((string) $d[$k]) : '……………………';
            $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['nato_il'] ?? '')) ? date('d/m/Y', (int) strtotime((string) $d['nato_il'])) : '……………';
            $nuovo = (string) preg_replace('/rappresentato dal Direttore, .+?, domiciliato per la carica/u',
                'rappresentato dal Direttore, ' . trim($v('titolo') . ' ' . $v('nome')) . ', nato a ' . $v('nato_a') . ' il ' . $data . ', domiciliato per la carica', $t);
            $this->riscrivi($dom, $xp, $p, $nuovo);

            return;
        }
    }

    /** Sostituisce tutto il testo di un paragrafo con uno solo (formato del primo pezzo di testo, senza evidenziazioni né colori di segnalazione). */
    private function riscrivi(DOMDocument $dom, DOMXPath $xp, DOMElement $p, string $testo): void
    {
        $modello = $xp->query('w:r[w:t]', $p)->item(0);
        $rpr = $modello ? $xp->query('w:rPr', $modello)->item(0) : null;
        foreach (iterator_to_array($xp->query('w:r | w:proofErr | w:bookmarkStart | w:bookmarkEnd', $p)) as $n) {
            $p->removeChild($n);
        }
        $r = $dom->createElementNS(self::W, 'w:r');
        if ($rpr instanceof DOMElement) {
            $copia = $rpr->cloneNode(true);
            foreach (iterator_to_array($xp->query('w:highlight | w:shd', $copia)) as $x) {
                $copia->removeChild($x);
            }
            $r->appendChild($copia);
        }
        $t = $dom->createElementNS(self::W, 'w:t');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($dom->createTextNode($testo));
        $r->appendChild($t);
        $p->appendChild($r);
    }

    /** Sostituisce il corpo di un articolo con il testo concordato (un paragrafo per ogni blocco separato da una riga vuota). */
    private function sostituisciArticolo(DOMDocument $dom, DOMXPath $xp, int $n, string $testo): void
    {
        $sez = $this->sezioni($xp)[$n] ?? null;
        $blocchi = array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/u', trim($testo)) ?: []), static fn (string $b): bool => $b !== ''));
        if ($sez === null || !$blocchi) {
            return;
        }
        $base = null;
        foreach ($sez['corpo'] as $p) {
            if ($this->testo($p) !== '') {
                $base = $p;
                break;
            }
        }
        if ($base === null) {
            return;
        }
        // I paragrafi nuovi vanno dove stava il primo del corpo (stesso contenitore, anche dentro le celle di layout); i vecchi si tolgono
        $padre = $base->parentNode;
        foreach ($blocchi as $b) {
            $nuovo = $base->cloneNode(true);
            foreach (iterator_to_array($xp->query('w:pPr/w:numPr', $nuovo)) as $num) {
                $num->parentNode?->removeChild($num);
            }
            $this->riscrivi($dom, $xp, $nuovo, (string) preg_replace('/\s*\R\s*/u', ' ', $b));
            $padre->insertBefore($nuovo, $base);
        }
        foreach ($sez['corpo'] as $p) {
            if ($this->testo($p) !== '' && $p->parentNode !== null) {
                $p->parentNode->removeChild($p);
            }
        }
    }
}
