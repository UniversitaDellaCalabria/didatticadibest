<?php

declare(strict_types=1);

namespace Tests\Unit\Attestati;

use App\Attestati\Accesso;
use App\Attestati\DatiAttestato;
use App\Attestati\ElencoStudenti;
use App\Attestati\ModelloElenco;
use App\Attestati\NomeFile;
use App\Attestati\RegolaAttestato;
use App\Attestati\ServizioAttestati;
use App\Attestati\Vista\Librerie;
use App\Attestati\Vista\PaginaAttestati;
use App\Attestati\Vista\Qr;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class AttestatiUnitTest extends TestCase
{
    /** @var list<string> */
    private array $temporanei = [];

    protected function tearDown(): void
    {
        foreach ($this->temporanei as $f) {
            @unlink($f);
        }
    }

    private function file(string $contenuto): string
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($f, $contenuto);
        $this->temporanei[] = $f;

        return $f;
    }

    public function testValoriDellaRegolaCompatibiliConLeVecchieStringhe(): void
    {
        self::assertSame(['evento', 'no', 'attendi', 'gruppo', 'singolo'], array_map(static fn (RegolaAttestato $r) => $r->value, RegolaAttestato::cases()));
    }

    public function testElencoDaTestoIncollatoDaExcel(): void
    {
        $t = "\xEF\xBB\xBFCognome\tNome\nRossi\tMario\n  Bianchi ; Anna Maria \n\"Verdi\",\"Luca\"\nNeri Paolo\n\nrossi;mario\n;\nSolo\t\n";
        self::assertSame([
            ['cognome' => 'Rossi', 'nome' => 'Mario'],
            ['cognome' => 'Bianchi', 'nome' => 'Anna Maria'],
            ['cognome' => 'Verdi', 'nome' => 'Luca'],
            ['cognome' => 'Neri Paolo', 'nome' => ''],
            ['cognome' => 'Solo', 'nome' => ''],
        ], ElencoStudenti::daTesto($t), 'intestazione, doppioni (anche con maiuscole diverse) e righe vuote ignorati');
        self::assertCount(2, ElencoStudenti::daTesto("A;a\nB;b\nC;c", 2), 'massimo di righe');
        self::assertSame([['cognome' => 'Cognome', 'nome' => 'Rossi']], ElencoStudenti::daTesto('Cognome;Rossi'), 'solo "Cognome" da solo o con "Nome" è intestazione');
        self::assertSame(100, mb_strlen(ElencoStudenti::daTesto(str_repeat('è', 150) . ';Mario')[0]['cognome']));
    }

    public function testElencoDaCampiDelModulo(): void
    {
        $r = ElencoStudenti::daCampi(['  De   Luca ', 'de luca', '', 'Neri', ''], ['Anna  Maria', 'anna maria', '', 'Ugo']);
        self::assertSame([['cognome' => 'De Luca', 'nome' => 'Anna Maria'], ['cognome' => 'Neri', 'nome' => 'Ugo']], $r);
        self::assertSame([['cognome' => 'Solo', 'nome' => '']], ElencoStudenti::daCampi(['Solo'], []), 'nome mancante');
        self::assertSame([], ElencoStudenti::daCampi([], []));
        self::assertCount(1, ElencoStudenti::daCampi(['A', 'B'], ['a', 'b'], 1));
    }

    public function testTestoDaFileCsvTxtEErrori(): void
    {
        $errore = null;
        $win = $this->file(mb_convert_encoding("Cognome;Nome\nÈsposito;Ciro\n", 'Windows-1252', 'UTF-8'));
        self::assertSame("Cognome;Nome\nÈsposito;Ciro\n", ElencoStudenti::testoDaFile(['error' => UPLOAD_ERR_OK, 'size' => 20, 'name' => 'x.CSV', 'tmp_name' => $win], $errore), 'CSV di Excel in Windows-1252 convertito');
        $utf = $this->file("Àlvaro\tJosé\n");
        self::assertSame("Àlvaro\tJosé\n", ElencoStudenti::testoDaFile(['error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'x.txt', 'tmp_name' => $utf], $errore));

        self::assertNull(ElencoStudenti::testoDaFile([], $errore));
        self::assertSame('Caricamento del file non riuscito.', $errore);
        self::assertNull(ElencoStudenti::testoDaFile(['error' => UPLOAD_ERR_OK, 'size' => 3 * 1024 * 1024, 'name' => 'x.csv', 'tmp_name' => $utf], $errore));
        self::assertSame('Il file supera i 2 MB.', $errore);
        self::assertNull(ElencoStudenti::testoDaFile(['error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'x.pdf', 'tmp_name' => $utf], $errore));
        self::assertSame('Formato non supportato: carica un file .xlsx o .csv.', $errore);
        self::assertNull(ElencoStudenti::testoDaFile(['error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'x.xlsx', 'tmp_name' => $utf], $errore));
        self::assertSame('Il file .xlsx non è leggibile.', $errore);
    }

    public function testTestoDaFileXlsxConStringheCondiviseEInline(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('Estensione zip assente');
        }
        $costruisci = function (array $parti): string {
            $f = (string) tempnam(sys_get_temp_dir(), 'xl');
            $this->temporanei[] = $f;
            $z = new ZipArchive();
            $z->open($f, ZipArchive::OVERWRITE);
            foreach ($parti as $nome => $xml) {
                $z->addFromString($nome, $xml);
            }
            $z->close();

            return $f;
        };
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $condivise = $costruisci([
            'xl/sharedStrings.xml' => "<sst $ns><si><t>Cognome</t></si><si><t>Rossi</t></si><si><t>Anna;Maria</t></si></sst>",
            'xl/worksheets/sheet1.xml' => "<worksheet $ns><sheetData><row r=\"1\"><c r=\"A1\" t=\"s\"><v>0</v></c></row><row r=\"2\"><c r=\"A2\" t=\"s\"><v>1</v></c><c r=\"B2\" t=\"s\"><v>2</v></c><c r=\"C2\"><v>9</v></c></row></sheetData></worksheet>",
        ]);
        $errore = null;
        self::assertSame("Cognome\t\nRossi\tAnna Maria", ElencoStudenti::testoDaFile(['error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'e.xlsx', 'tmp_name' => $condivise], $errore));
        $inline = $costruisci(['xl/worksheets/sheet1.xml' => "<worksheet $ns><sheetData><row r=\"1\"><c r=\"A1\" t=\"inlineStr\"><is><t>Verdi</t></is></c><c r=\"B1\" t=\"inlineStr\"><is><t>Giulia</t></is></c></row></sheetData></worksheet>"]);
        self::assertSame("Verdi\tGiulia", ElencoStudenti::testoDaFile(['error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'e.xlsx', 'tmp_name' => $inline], $errore));
        $senzaFoglio = $costruisci(['docProps/app.xml' => '<x/>']);
        self::assertNull(ElencoStudenti::testoDaFile(['error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'e.xlsx', 'tmp_name' => $senzaFoglio], $errore));
        self::assertSame('Nel file .xlsx non trovo il primo foglio.', $errore);
    }

    public function testModelloDaCompilareSiRileggeComeElenco(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('Estensione zip assente');
        }
        $m = ModelloElenco::crea([['cognome' => 'Rossi & Co', 'nome' => "D'Arco"], ['cognome' => 'Verdi', 'nome' => 'Anna']], 'elenco_prova');
        self::assertSame('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $m['intestazioni'][0]);
        self::assertSame('Content-Disposition: attachment; filename="elenco_prova.xlsx"', $m['intestazioni'][1]);
        self::assertSame('Content-Length: ' . strlen($m['contenuto']), $m['intestazioni'][2]);
        self::assertStringStartsWith('PK', $m['contenuto']);
        $f = $this->file($m['contenuto']);
        $errore = null;
        $testo = ElencoStudenti::testoDaFile(['error' => UPLOAD_ERR_OK, 'size' => 1000, 'name' => 'm.xlsx', 'tmp_name' => $f], $errore);
        self::assertSame("Cognome\tNome\nRossi & Co\tD'Arco\nVerdi\tAnna", $testo);
        self::assertSame([['cognome' => 'Rossi & Co', 'nome' => "D'Arco"], ['cognome' => 'Verdi', 'nome' => 'Anna']], ElencoStudenti::daTesto((string) $testo));
        self::assertStringStartsWith('PK', ModelloElenco::crea([], 'vuoto')['contenuto']);
    }

    public function testDatiDellAttestatoDiUnProgetto(): void
    {
        $d = DatiAttestato::crea([
            'evento_tipo' => 'progetto', 'ore_totali' => '30', 'data_inizio' => '2026-01-10', 'data_fine' => '2026-03-01', 'evento_titolo' => 'Geologia', 'evento_luogo' => 'Lab',
            'pagina_titolo' => 'FSL', 'logo_attestato_path' => '', 'logo_path' => 'logo.png', 'nome_portale' => 'Portale', 'sottotitolo_portale' => 'Dip',
            'firma_nome' => '', 'firma_titolo' => '', 'testo_attestato' => '  ',
        ], 'Mario Rossi', 'AT-X', '123');
        self::assertSame('30', $d['ore']);
        self::assertSame('Dal 10/01/2026 al 01/03/2026', $d['quando']);
        self::assertSame('logo.png', $d['logo'], 'senza logo dell\'area vale quello del portale');
        self::assertSame('Mauro F. La Russa', $d['firma_nome']);
        self::assertSame('Il Direttore del Dipartimento', $d['firma_titolo']);
        self::assertSame('ha partecipato al progetto dal titolo:', $d['formula']);
        self::assertSame(['Mario Rossi', '123', 'AT-X'], [$d['nome'], $d['matricola'], $d['codice']]);
    }

    public function testDatiDellAttestatoDiUnEvento(): void
    {
        $d = DatiAttestato::crea([
            'evento_tipo' => 'evento', 'data_turno' => '2026-09-10', 'orario_inizio' => '10:00:00', 'orario_fine' => '12:30:00', 'logo_attestato_path' => 'area.png', 'logo_path' => 'logo.png',
            'firma_nome' => 'Anna Bianchi', 'testo_attestato' => ' ha seguito: ',
        ], 'Luca Neri', 'ABC');
        self::assertSame('2.5', $d['ore']);
        self::assertSame('In data 10/09/2026', $d['quando']);
        self::assertSame('area.png', $d['logo']);
        self::assertSame('Anna Bianchi', $d['firma_nome']);
        self::assertSame('ha seguito:', $d['formula']);
        self::assertSame('', $d['matricola']);
        $senza = DatiAttestato::crea(['orario_inizio' => '09:00:00', 'orario_fine' => '11:00:00'], 'X', 'Y');
        self::assertSame('2', $senza['ore'], 'niente ".0" finale');
        self::assertSame('', $senza['quando']);
        self::assertSame("ha partecipato all'attività formativa/evento denominata:", $senza['formula']);
        self::assertSame('', DatiAttestato::crea([], 'X', 'Y')['ore']);
    }

    public function testChiPuoVedereLAttestato(): void
    {
        $p = ['utente_id' => '7', 'email' => 'Anna@X.it'];
        self::assertFalse(Accesso::puoVedere($p, 0, 1, null, null), 'non collegato');
        self::assertTrue(Accesso::puoVedere($p, 7, 5, null, null), 'titolare per id');
        self::assertTrue(Accesso::puoVedere(['utente_id' => null, 'email' => 'anna@x.it'], 9, 5, null, 'ANNA@x.IT'), 'titolare per email');
        self::assertFalse(Accesso::puoVedere($p, 9, 5, '', 'altra@x.it'));
        self::assertFalse(Accesso::puoVedere($p, 9, 5, '3,4', null));
        self::assertTrue(Accesso::puoVedere($p, 9, 1, null, null), 'amministratore');
        self::assertTrue(Accesso::puoVedere($p, 9, 2, null, null), 'gestore');
        self::assertTrue(Accesso::puoVedere($p, 9, 5, '3,2', null), 'gestore come ruolo secondario');
        self::assertTrue(Accesso::eStaff(5, '1'));
        self::assertFalse(Accesso::eStaff(5, '12'));
        self::assertFalse(Accesso::eStaff(3, null));
    }

    public function testNomiDiFileECodici(): void
    {
        self::assertSame('rossi_mario', NomeFile::slug('Rossi Mario'));
        self::assertSame('dangelos_pieta_e_cafe', NomeFile::slug(" D'Angelo’s Pietà è Café "));
        self::assertSame('dangelo_pieta', NomeFile::slug("D'Angelo  Pietà"));
        self::assertSame('', NomeFile::slug('***'));
        self::assertSame('strasse', NomeFile::slug('Straße'));
        for ($i = 0; $i < 20; $i++) {
            self::assertMatchesRegularExpression('/^AT-[A-HJ-NP-Z2-9]{10}$/', ServizioAttestati::nuovoCodice());
        }
        self::assertSame('Rossi Mario', ServizioAttestati::nomePartecipante(['cognome' => 'Rossi', 'nome' => 'Mario']));
        self::assertSame('Rossi', ServizioAttestati::nomePartecipante(['cognome' => 'Rossi', 'nome' => '']));
        self::assertSame('', ServizioAttestati::nomePartecipante([]));
    }

    public function testPercorsiDelleLibrerieLocali(): void
    {
        self::assertSame('/eventi/assets/vendor/jsdelivr/x.css', Librerie::urlVendor('https://sito.it/eventi', '/jsdelivr/x.css'));
        self::assertSame('/assets/vendor/a.js', Librerie::urlVendor('https://sito.it', 'a.js'));
        $s = Librerie::scriptLibreria('https://sito.it/eventi', 'qrcode');
        self::assertStringContainsString('<script src="/eventi/assets/js/qrcode-generator-1.4.4.min.js" integrity="sha384-', $s);
        self::assertStringContainsString("document.write('<script src=\"https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js\"", $s);
        self::assertSame('', Librerie::scriptLibreria('https://sito.it', 'sconosciuta'));
    }

    public function testQrConLoScriptUnaSolaVolta(): void
    {
        $qr = new Qr('https://sito.it/eventi');
        $a = $qr->html('https://x.it/?a=1&b=2', 'width:80px', 'QR "uno"', 'extra');
        $b = $qr->html('altro');
        self::assertStringContainsString('<div class="qr-locale extra" data-qr="https://x.it/?a=1&amp;b=2" role="img" aria-label="QR &quot;uno&quot;" style="aspect-ratio:1/1;width:80px"></div>', $a);
        self::assertStringContainsString('qrcode-generator-1.4.4.min.js', $a);
        self::assertStringContainsString('<style>.qr-locale svg', $a);
        self::assertStringNotContainsString('<script', $b);
        self::assertStringContainsString('data-qr="altro"', $b);
        self::assertStringContainsString('style="aspect-ratio:1/1;width:150px"', $b);
    }

    public function testPaginaConPiuAttestati(): void
    {
        $base = ['nome' => 'Mario Rossi', 'matricola' => '', 'evento' => 'Gara "X"', 'luogo' => '', 'quando' => '', 'ore' => '', 'area' => '', 'logo' => '', 'portale' => 'Portale', 'sottotitolo' => 'Dip',
            'firma_nome' => 'Anna Bianchi', 'firma_titolo' => 'La Direttrice', 'codice' => 'AT-AAAA', 'formula' => 'ha partecipato:'];
        $lista = [$base + ['file' => 'attestato_rossi_mario'], ['matricola' => '99', 'quando' => 'In data 10/09/2026', 'ore' => '2', 'area' => 'FSL', 'logo' => 'l.png', 'nome' => 'Anna <b>', 'codice' => 'AT-BBBB'] + $base];
        $h = PaginaAttestati::html($lista, 'Attestati - Gara', 'https://sito.it/eventi');
        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"it\">", $h);
        self::assertStringEndsWith("</html>\n", $h);
        self::assertStringContainsString('<title>Attestati - Gara</title>', $h);
        self::assertStringContainsString('Stampa / PDF unico (2 attestati)', $h);
        self::assertStringContainsString('Scarica ZIP (2 PDF separati)', $h);
        self::assertStringContainsString('data-zip="attestati_gara"', $h);
        self::assertStringContainsString('data-file="attestato_rossi_mario"', $h);
        self::assertStringContainsString('data-file="attestato_anna_b"', $h, 'senza nome file indicato: dal nome');
        self::assertSame(2, substr_count($h, 'class="cert-container"'));
        self::assertStringContainsString('data-qr="https://sito.it/eventi/verifica_attestato.php?c=AT-AAAA"', $h);
        self::assertStringContainsString('data-qr="https://sito.it/eventi/verifica_attestato.php?c=AT-BBBB"', $h);
        self::assertStringContainsString('<span class="cert-name">MARIO ROSSI</span>', $h);
        self::assertStringContainsString('ANNA &lt;B&gt;', $h);
        self::assertStringContainsString('(Matricola: 99)', $h);
        self::assertStringContainsString('"Gara &quot;X&quot;"', $h);
        self::assertStringContainsString('Svoltasi presso le nostre strutture.', $h);
        self::assertStringContainsString('In data 10/09/2026, presso le nostre strutture', $h);
        self::assertStringContainsString('per un numero di ore pari a 2</strong>', $h);
        self::assertStringContainsString('<img src="l.png" class="cert-logo" alt="Logo">', $h);
        self::assertStringContainsString('<strong>Data di rilascio:</strong> ' . date('d/m/Y'), $h);
        self::assertStringContainsString('su sito.it/eventi/verifica_attestato.php', $h);
        self::assertStringContainsString("var lib = '/eventi/assets/vendor/cdnjs/ajax/libs/';", $h);

        $uno = PaginaAttestati::html([$base], 'Attestato - Mario Rossi', 'https://sito.it');
        self::assertStringContainsString('Stampa / PDF unico</button>', $uno);
        self::assertStringContainsString('Scarica PDF</button>', $uno);
        self::assertStringContainsString('data-zip="attestati_attestato_mario_rossi"', $uno, 'come prima: il prefisso "Attestato - " non viene tolto');
        self::assertStringNotContainsString('Scarica ZIP', $uno);
    }
}
