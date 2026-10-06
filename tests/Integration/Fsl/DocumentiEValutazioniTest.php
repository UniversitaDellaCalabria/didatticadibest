<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Sito;
use App\Fsl\DocumentoConvenzione;
use App\Fsl\PrecompilazioneConvenzione;
use App\Fsl\ServizioConvenzioneOnline;
use App\Fsl\ServizioPannelloFsl;
use App\Fsl\ServizioRegistroConvenzioni;
use App\Fsl\ServizioValutazioniFsl;
use ZipArchive;

/** Documenti Word, convenzione online, scheda di valutazione, registro nel pannello e riepilogo dell'anno. */
final class DocumentiEValutazioniTest extends FslBase
{
    /** @var list<string> cartelle e file temporanei da togliere */
    private array $temporanei = [];

    protected function tearDown(): void
    {
        foreach ($this->temporanei as $t) {
            if (is_dir($t)) {
                foreach (glob($t . '/uploads/convenzioni/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($t . '/uploads/convenzioni');
                @rmdir($t . '/uploads');
                @unlink($t . '/uploads/.htaccess');
                @rmdir($t);
            } else {
                @unlink($t);
            }
        }
        parent::tearDown();
    }

    private function testo(string $docx): string
    {
        $z = new ZipArchive();
        $this->assertTrue($z->open($docx));
        $xml = (string) $z->getFromName('word/document.xml');
        $z->close();

        return $xml;
    }

    public function testPrecompilazioneDaiDatiDellaPrenotazione(): void
    {
        $p = $this->pren(200, ['nome' => 'Marta', 'cognome' => 'Rossi', 'dati_custom_json' => '{"numero_partecipanti":"18","docente_riferimento":"Prof. Bianchi"}']);
        $dati = $this->servizio(PrecompilazioneConvenzione::class)->dati($this->servizio(\App\Fsl\ServizioConvenzioni::class)->datiPrenotazione($p));
        $this->assertSame('Istituto Galilei (codice meccanografico CSIS00001A)', $dati['ISTITUTO']);
        $this->assertSame('Istituto Galilei', $dati['ISTITUTO_FIRMA']);
        $this->assertSame('Cosenza (Cs)', $dati['COMUNE']);
        $this->assertSame('Via Roma 1, 87100', $dati['INDIRIZZO']);
        $this->assertSame('Geologia sul campo', $dati['TITOLO'], 'progetto: senza il nome dell\'edizione');
        $this->assertSame('Uscite sul campo e laboratorio', $dati['DESCRIZIONE'], 'dalla descrizione breve, senza HTML né punto finale');
        $this->assertSame('18', $dati['STUDENTI']);
        $this->assertSame('dal 13/10/2026 al 02/12/2026', $dati['PERIODO']);
        $this->assertSame('40 ore', $dati['DURATA']);
        $this->assertSame('Prof. Rossi', $dati['TUTOR_DIBEST']);
        $this->assertSame('Prof. Bianchi', $dati['TUTOR_SCUOLA']);
    }

    public function testPrecompilazioneDiUnEventoEDiUnaScuolaScrittaAMano(): void
    {
        $p = $this->pren(100, ['scuola_codice' => null, 'nome' => 'Gino', 'cognome' => 'Prof', 'dati_custom_json' => '{}']);
        $dati = $this->servizio(PrecompilazioneConvenzione::class)->dati($this->servizio(\App\Fsl\ServizioConvenzioni::class)->datiPrenotazione($p));
        $this->assertSame('', $dati['ISTITUTO']);
        $this->assertSame('', $dati['COMUNE']);
        $this->assertSame('Modulo di Genetica – Turno A · 23/10/2026 · 09:00–12:30', $dati['TITOLO'], 'evento: con il turno');
        $this->assertSame('23/10/2026', $dati['PERIODO']);
        $this->assertSame('3,5 ore (09:00–12:30)', $dati['DURATA'], 'dagli orari del turno');
        $this->assertSame('', $dati['STUDENTI']);
        $this->assertSame('Gino Prof', $dati['TUTOR_SCUOLA']);
    }

    public function testAttivitaPrenotateEPrenotabiliDallaScuola(): void
    {
        $mia = $this->pren(200);
        $altraDellaScuola = $this->pren(100, ['convenzione' => 'si']);
        $annullata = $this->pren(200, ['stato' => 'annullata']);
        $diAltri = $this->pren(200, ['scuola_codice' => 'CSPS00002B']);
        [$prenotate, $prenotabili] = $this->servizio(PrecompilazioneConvenzione::class)->attivitaScuola($mia, 'csps00001a');
        $this->assertSame([$mia, $altraDellaScuola], array_keys($prenotate), 'quelle della scuola, non annullate, in ordine di data');
        $this->assertArrayNotHasKey($annullata, $prenotate);
        $this->assertArrayNotHasKey($diAltri, $prenotate);
        $this->assertSame([], $prenotabili, 'le due attività FSL sono già prenotate dalla scuola');

        [$prenotate, $prenotabili] = $this->servizio(PrecompilazioneConvenzione::class)->attivitaScuola($mia, null);
        $this->assertSame([$mia], array_keys($prenotate), 'senza scuola dell\'anagrafe: solo questa prenotazione');
        $this->assertSame([10], array_keys($prenotabili));
        $this->assertSame('Modulo di Genetica', $prenotabili[10]['evento_titolo']);
        $this->assertSame('fsl', $prenotabili[10]['slug']);
        $this->assertSame('', $prenotabili[10]['nome_turno']);
    }

    public function testDocumentoWordConISegnapostiSostituiti(): void
    {
        $d = $this->servizio(DocumentoConvenzione::class);
        $file = $d->genera('convenzione', ['ISTITUTO' => 'Liceo & Prova (codice CSPS)', 'ISTITUTO_FIRMA' => 'Liceo & Prova', 'DIRIGENTE' => 'Dott. X <Y>'], [['TITOLO' => 'Attività uno', 'DESCRIZIONE' => 'D <1>']], null, '45/2026');
        $this->temporanei[] = (string) $file;
        $this->assertNotNull($file);
        $xml = $this->testo($file);
        $this->assertStringContainsString('Liceo &amp; Prova (codice CSPS)', $xml);
        $this->assertStringContainsString('Dott. X &lt;Y&gt;', $xml);
        $this->assertStringContainsString('Prot. n. 45/2026', $xml);
        $this->assertStringNotContainsString('{{ISTITUTO}}', $xml);
        $this->assertStringContainsString('xxxx', $xml, 'il comune non indicato resta con il testo originale da completare');

        $allegato = $d->genera('allegato', [], [['TITOLO' => 'A1'], ['TITOLO' => 'A2']]);
        $this->temporanei[] = (string) $allegato;
        $xml = $this->testo($allegato);
        $this->assertStringContainsString('A1', $xml);
        $this->assertStringContainsString('A2', $xml, 'il blocco dell\'Allegato A si ripete per ogni attività');
    }

    public function testDocumentoWordSenzaModelloNonSiGenera(): void
    {
        $d = new DocumentoConvenzione(new Sito(sys_get_temp_dir() . '/senza-modelli', 'https://portale.test'));
        $this->assertNull($d->genera('convenzione', [], []));
    }

    public function testConvenzionePrecompilataDaUnaPrenotazione(): void
    {
        $p = $this->pren(200);
        $file = $this->servizio(PrecompilazioneConvenzione::class)->genera($p, 'allegato');
        $this->temporanei[] = (string) $file;
        $this->assertNotNull($file);
        $this->assertStringContainsString('Geologia sul campo', $this->testo($file));
        $this->assertNull($this->servizio(PrecompilazioneConvenzione::class)->genera(99999));
    }

    public function testInvitoEScheDiValutazione(): void
    {
        $p = $this->pren(200, ['nome' => 'Marta', 'cognome' => 'Rossi', 'email' => 'marta@prova.it']);
        $v = $this->servizio(ServizioValutazioniFsl::class);
        $this->assertTrue($v->invia($p));
        $r = $this->riga('prenotazioni', $p);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $r['valutazione_token']);
        $this->assertNotNull($r['valutazione_inviata']);
        $m = $this->mailer->inviate[0];
        $this->assertSame('Scheda di valutazione - Geologia sul campo', $m['oggetto']);
        $this->assertStringContainsString('(Liceo Scientifico Galilei – Cosenza)', $m['corpo']);
        $this->assertStringContainsString('https://portale.test/eventi/valutazione_fsl.php?t=' . $r['valutazione_token'], $m['corpo']);

        $this->assertTrue($v->invia($p, true));
        $this->assertSame('Promemoria: Scheda di valutazione - Geologia sul campo', $this->mailer->inviate[1]['oggetto']);
        $this->assertSame(1, (int) $this->riga('prenotazioni', $p)['valutazione_promemoria']);
        $this->assertSame($r['valutazione_token'], $this->riga('prenotazioni', $p)['valutazione_token'], 'il link resta lo stesso');
        $this->assertFalse($v->invia(99999));
        $this->assertFalse($v->invia($this->pren(200, ['email' => 'no'])));

        $this->assertNull($v->prenotazioneDaToken('x'), 'il link ha 40 caratteri esadecimali');
        $this->assertNull($v->prenotazioneDaToken(str_repeat('a', 40)));
        $pr = $v->prenotazioneDaToken($r['valutazione_token']);
        $this->assertSame($p, (int) $pr['id']);
        $this->assertSame('Geologia sul campo', $pr['evento_titolo']);
        $this->assertNull($pr['valutazione_id']);
    }

    public function testSalvataggioDellaScheda(): void
    {
        $p = $this->pren(200, ['nome' => 'Marta', 'cognome' => 'Rossi']);
        $this->db->esegui('UPDATE prenotazioni SET valutazione_token = ? WHERE id = ?', [str_repeat('b', 40), $p]);
        $v = $this->servizio(ServizioValutazioniFsl::class);
        $pr = $v->prenotazioneDaToken(str_repeat('b', 40));
        $voti = array_fill_keys(array_keys(\App\Fsl\Costanti::ASPETTI), 4);

        $this->assertSame('Indica un voto da 1 a 5 per: Accoglienza e organizzazione delle attività.', $v->salva($pr, []));
        $this->assertSame('Indica un voto da 1 a 5 per: Disponibilità e competenza del tutor del Dipartimento.', $v->salva($pr, ['voto' => ['tutor' => 9] + $voti, 'ripeterebbe' => 'si']));
        $this->assertSame("Indica se riproporresti l'attività ad altre classi.", $v->salva($pr, ['voto' => $voti, 'ripeterebbe' => 'forse?']));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM valutazioni_fsl'));

        $voti['tutor'] = 3;
        $this->assertNull($v->salva($pr, ['voto' => $voti, 'ripeterebbe' => 'forse', 'testo' => ['punti_forza' => ' Bene ', 'altro' => 'x'], 'compilata_da' => '']));
        $r = $this->db->riga('SELECT * FROM valutazioni_fsl');
        $this->assertSame([$p, 20, 'CSPS00001A', 'Marta Rossi', 'forse'], [(int) $r['prenotazione_id'], (int) $r['evento_id'], $r['scuola_codice'], $r['compilata_da'], $r['ripeterebbe']], 'senza nome indicato: quello di chi ha prenotato');
        $this->assertSame('3.88', $r['media']);
        $this->assertSame(['voti' => $voti, 'testi' => ['punti_forza' => 'Bene', 'criticita' => '', 'suggerimenti' => '']], json_decode($r['risposte_json'], true));

        $this->assertSame('La scheda di valutazione è già stata compilata. Grazie!', $v->salva($v->prenotazioneDaToken(str_repeat('b', 40)), ['voto' => $voti, 'ripeterebbe' => 'si']));
        $this->assertSame('La scheda di valutazione è già stata compilata. Grazie!', $v->salva($pr, ['voto' => $voti, 'ripeterebbe' => 'si']), 'anche se la scheda è stata letta prima');
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM valutazioni_fsl'));
    }

    public function testCompilazioneOnlineDalCodiceDellaPrenotazione(): void
    {
        $p = $this->pren(200, ['email' => 'anna@prova.it']);
        $o = $this->servizio(ServizioConvenzioneOnline::class);
        $token = $o->tokenDaCodice('FS-1');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $token);
        $this->assertSame($token, $o->tokenDaCodice('FS-1'), 'una sola compilazione per prenotazione');
        $cc = $o->perToken((string) $token);
        $this->assertSame([$p, 'CSPS00001A', 'anna@prova.it'], [(int) $cc['prenotazione_id'], $cc['scuola_codice'], $cc['email']]);
        $this->assertNull($o->tokenDaCodice('NONESISTE'));
        $this->assertNull($o->tokenDaCodice('x'), 'codice troppo corto');
        $this->assertNull($o->perToken(str_repeat('0', 32)));
        $this->db->esegui("UPDATE prenotazioni SET stato = 'annullata' WHERE id = ?", [$p]);
        $this->assertNull($o->tokenDaCodice('FS-2'), 'prenotazione che non esiste');
        $altra = $this->pren(200);
        $this->db->esegui("UPDATE prenotazioni SET stato = 'rifiutata' WHERE id = ?", [$altra]);
        $this->assertNull($o->tokenDaCodice('FS-2'), 'una prenotazione rifiutata non compila');
    }

    public function testSalvataggioEInvioDelLinkDellaConvenzioneOnline(): void
    {
        $p = $this->pren(200, ['email' => 'anna@prova.it', 'nome' => 'Anna']);
        $o = $this->servizio(ServizioConvenzioneOnline::class);
        $cc = $o->perToken((string) $o->tokenDaCodice('FS-1'));
        $ctx = $o->contesto($cc);
        $this->assertSame('Istituto Galilei', $ctx['s']['denominazione'], 'valori proposti dall\'anagrafe');
        $this->assertSame('CSIS00001A', $ctx['s']['codice']);
        $this->assertSame('anna@prova.it', $ctx['s']['email']);
        $this->assertSame([$p], array_keys($ctx['prenotate']));
        $this->assertSame([10], array_keys($ctx['prenotabili']));

        $senza = $o->salva($cc, $ctx, ['denominazione' => ' ', 'cf' => '123', 'dir_cf' => 'abc', 'pec' => 'no', 'email' => 'no'], null);
        $this->assertSame([
            "Indica la denominazione dell'istituzione scolastica.", "Il codice fiscale dell'istituto ha 11 cifre.", 'Indica il Dirigente Scolastico.',
            'Il codice fiscale del Dirigente ha 16 caratteri.', 'La PEC della scuola non è valida.', "L'email di riferimento non è valida.", "Scegli almeno un'attività per l'Allegato A.",
        ], $senza['errori']);
        $this->assertSame('ABC', $senza['s']['dir_cf']);
        $this->assertSame([], $this->mailer->inviate);

        $grande = $o->salva($cc, $ctx, ['denominazione' => 'L', 'dirigente' => 'D', 'att' => ["pr$p" => ['scelta' => 1]]], ['error' => UPLOAD_ERR_OK, 'size' => 3 * 1024 * 1024, 'name' => 'l.png', 'tmp_name' => '/x']);
        $this->assertSame(['Il logo supera 2 MB.'], $grande['errori']);

        $post = ['denominazione' => 'Liceo <Galilei>', 'codice' => 'csis00001a', 'dirigente' => 'Prof.ssa Neri', 'data_nascita' => '1970-02-03', 'pec' => 'liceo@pec.it', 'email' => 'nuova@prova.it',
            'att' => ["pr$p" => ['scelta' => 1, 'studenti' => '900', 'tutor' => ' Prof. Bianchi '], 'ev10' => ['scelta' => 1, 'studenti' => '9'], 'ev999' => ['scelta' => 1], 'pr999' => ['scelta' => 1], "pr{$p}x" => []]];
        $this->assertSame([], $o->salva($cc, $ctx, $post, null)['errori']);
        $r = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $dati = json_decode($r['dati_json'], true);
        $this->assertSame('Liceo <Galilei>', $dati['scuola']['denominazione']);
        $this->assertSame('CSIS00001A', $dati['scuola']['codice']);
        $this->assertSame([['pr' => $p, 'studenti' => 500, 'tutor' => 'Prof. Bianchi'], ['ev' => 10, 'studenti' => 9, 'tutor' => '']], $dati['attivita'], 'attività sconosciute scartate, studenti al massimo 500');
        $this->assertSame('nuova@prova.it', $r['email']);
        $this->assertNotNull($r['aggiornata_il']);

        $this->assertCount(1, $this->mailer->inviate, 'alla prima compilazione parte il link per riprendere');
        $m = $this->mailer->inviate[0];
        $this->assertSame(['nuova@prova.it', 'Convenzione Formazione Scuola Lavoro – documenti da firmare'], [$m['a'], $m['oggetto']]);
        $this->assertStringContainsString('<strong>Liceo &lt;Galilei&gt;</strong>', $m['corpo']);
        $this->assertStringContainsString('https://portale.test/eventi/convenzione_online.php?t=' . $cc['token'], $m['corpo']);
        $this->assertStringContainsString('mailto:' . \App\Fsl\Costanti::PEC, $m['corpo']);

        $cc = $o->perToken((string) $cc['token']);
        $o->salva($cc, $o->contesto($cc), $post, null);
        $this->assertCount(1, $this->mailer->inviate, 'alle correzioni successive non riparte');
    }

    public function testScaricoDeiDocumentiDellaConvenzioneOnline(): void
    {
        $p = $this->pren(200);
        $o = $this->servizio(ServizioConvenzioneOnline::class);
        $cc = $o->perToken((string) $o->tokenDaCodice('FS-1'));
        $o->salva($cc, $o->contesto($cc), ['denominazione' => 'Liceo Prova', 'codice' => 'CSIS00001A', 'dirigente' => 'Dott. Neri', 'att' => ["pr$p" => ['scelta' => 1, 'studenti' => '11', 'tutor' => 'Prof.ssa Verdi']]], null);
        $o->salvaProtocollo((int) $cc['id'], ' 88/2026 ', '2026-10-07');
        $this->assertSame(['88/2026', '2026-10-07'], [$this->riga('convenzioni_compilate', (int) $cc['id'])['protocollo'], $this->riga('convenzioni_compilate', (int) $cc['id'])['protocollo_data']]);
        $o->salvaProtocollo((int) $cc['id'], str_repeat('x', 150), 'data errata');
        $this->assertSame([100, null], [strlen($this->riga('convenzioni_compilate', (int) $cc['id'])['protocollo']), $this->riga('convenzioni_compilate', (int) $cc['id'])['protocollo_data']]);
        $o->salvaProtocollo((int) $cc['id'], '88/2026', '2026-10-07');

        $cc = $o->perToken((string) $cc['token']);
        $ctx = $o->contesto($cc);
        $conv = $o->scarica('convenzione', $cc, $ctx);
        $this->temporanei[] = (string) $conv['file'];
        $this->assertSame('Convenzione_FSL_CSIS00001A.docx', $conv['nome']);
        $xml = $this->testo($conv['file']);
        $this->assertStringContainsString('Liceo Prova (codice meccanografico CSIS00001A)', $xml);
        $this->assertStringContainsString('Prot. n. 88/2026 del 07/10/2026', $xml);
        $this->assertNotNull($this->riga('convenzioni_compilate', (int) $cc['id'])['scaricata_il']);

        $all = $o->scarica('qualsiasi', $cc, $ctx);
        $this->temporanei[] = (string) $all['file'];
        $this->assertSame('Convenzione_FSL_CSIS00001A.docx', $all['nome'], 'un documento sconosciuto è la convenzione');
        $allegato = $o->scarica('allegato', $cc, $ctx);
        $this->temporanei[] = (string) $allegato['file'];
        $this->assertSame('Allegato_A_FSL_CSIS00001A.docx', $allegato['nome']);
        $this->assertTrue(str_contains($this->testo($allegato['file']), '>Verdi<') || str_contains($this->testo($allegato['file']), 'Verdi</w:t>'), 'il tutor della scuola senza «Prof.ssa» davanti compare nell\'Allegato');
        $this->assertCount(1, $o->recenti());
    }

    public function testRegistroNelPannelloConFileEConsegna(): void
    {
        $radice = sys_get_temp_dir() . '/fsl_prova_' . bin2hex(random_bytes(4));
        mkdir($radice . '/uploads/convenzioni', 0755, true);
        $this->temporanei[] = $radice;
        $this->c->istanza(Sito::class, new Sito($radice, 'https://portale.test/eventi'));
        $this->c->set(ServizioRegistroConvenzioni::class, fn () => new ServizioRegistroConvenzioni(
            $this->c->get(\App\Fsl\ConvenzioneRepository::class),
            $this->c->get(\App\Fsl\ServizioConvenzioni::class),
            new \App\Infrastructure\Storage\Upload(),
            $this->c->get(\App\Infrastructure\Pdf\VerificaFirme::class),
            $this->c->get(Sito::class)
        ));
        $reg = $this->servizio(ServizioRegistroConvenzioni::class);

        $nuova = $reg->salvaDalPannello(['scuola_codice' => ['conv' => 'csps00002b'], 'data_stipula' => '2026-10-01', 'scadenza' => '2027-09-30', 'doc_nome' => ['Uno'], 'doc_email' => ['UNO@X.IT']], [
            'file_convenzione' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/non/caricato', 'name' => 'c.pdf'], 'file_allegato' => ['error' => UPLOAD_ERR_NO_FILE],
        ], 'admin@x');
        $this->assertTrue($nuova->salvata);
        $this->assertFalse($nuova->modifica);
        $this->assertSame(['convenzione'], $nuova->fileNonCaricati, 'un file che non arriva da un caricamento non è accettato');
        $this->assertSame('CSPS00002B', $nuova->codiceScuola);
        $this->assertFileExists($radice . '/uploads/convenzioni/.htaccess');

        $this->assertFalse($reg->salvaDalPannello(['scuola_codice' => ['conv' => 'ZZZ']], [], 'admin@x')->salvata);
        $inesistente = $reg->salvaDalPannello(['conv_id' => 99, 'scuola_codice' => ['conv' => 'CSPS00002B']], [], 'admin@x');
        $this->assertFalse($inesistente->salvata);
        $this->assertSame(-1, $inesistente->id);
        $modifica = $reg->salvaDalPannello(['conv_id' => $nuova->id, 'scuola_codice' => ['conv' => 'CSPS00002B'], 'scadenza' => '2028-01-01'], [], 'admin@x');
        $this->assertTrue($modifica->modifica);
        $this->assertSame('2028-01-01', $this->riga('convenzioni_scuole', $nuova->id)['scadenza']);

        // File firmati: solo dentro uploads/convenzioni/
        file_put_contents($radice . '/uploads/convenzioni/a.pdf', '%PDF-1.4 FIRMATO');
        file_put_contents($radice . '/uploads/convenzioni/b.p7m', 'p7m');
        file_put_contents($radice . '/segreto.txt', 'no');
        $this->db->esegui("UPDATE convenzioni_scuole SET file_convenzione = 'uploads/convenzioni/a.pdf', file_allegato = 'uploads/convenzioni/b.p7m' WHERE id = ?", [$nuova->id]);
        $f = $reg->fileDaScaricare($nuova->id, 'file_convenzione');
        $this->assertSame(['Convenzione_CSPS00002B.pdf', 'pdf'], [$f['nome'], $f['ext']]);
        $this->assertSame('Allegato_A_CSPS00002B.p7m', $reg->fileDaScaricare($nuova->id, 'file_allegato')['nome']);
        $this->assertNull($reg->fileDaScaricare($nuova->id, 'docenti_json'), 'solo le colonne dei file');
        $this->assertNull($reg->fileDaScaricare(999, 'file_convenzione'));
        $this->db->esegui("UPDATE convenzioni_scuole SET file_convenzione = 'segreto.txt', file_allegato = 'uploads/convenzioni/../../segreto.txt' WHERE id = ?", [$nuova->id]);
        $this->assertNull($reg->fileDaScaricare($nuova->id, 'file_convenzione'), 'fuori dalla cartella');
        $this->assertNull($reg->fileDaScaricare($nuova->id, 'file_allegato'), 'con il percorso risalito');

        $this->db->esegui("UPDATE convenzioni_scuole SET file_convenzione = 'uploads/convenzioni/a.pdf', file_allegato = NULL WHERE id = ?", [$nuova->id]);
        $this->assertSame('CSPS00002B', $reg->elimina($nuova->id)['scuola_codice']);
        $this->assertFileDoesNotExist($radice . '/uploads/convenzioni/a.pdf');
        $this->assertFileExists($radice . '/segreto.txt');
        $this->assertNull($this->riga('convenzioni_scuole', $nuova->id));
        $this->assertNull($reg->elimina($nuova->id));
        @unlink($radice . '/segreto.txt');
    }

    public function testRiepilogoDelPannello(): void
    {
        $this->convenzione('CSPS00001A', '2026-09-03', '2027-09-02');
        $this->convenzione('CSPS00002B', '2024-01-01', '2025-01-01');
        $a = $this->pren(200, ['dati_custom_json' => '{"numero_partecipanti":"12"}', 'presente' => 1]);
        $b = $this->pren(201, ['scuola_codice' => 'CSPS00002B', 'dati_custom_json' => '{"numero_partecipanti":"8"}']);
        $c = $this->pren(201, ['scuola_codice' => null, 'stato' => 'in_attesa', 'dati_custom_json' => '{"scuola":"Liceo Manuale"}']);
        $this->pren(200, ['stato' => 'annullata']);
        $this->db->esegui(
            'INSERT INTO valutazioni_fsl (prenotazione_id, evento_id, scuola_codice, compilata_da, risposte_json, media, ripeterebbe) VALUES (?, 20, ?, ?, ?, 4.50, ?)',
            [$a, 'CSPS00001A', 'Marta', '{"voti":{"accoglienza":5,"tutor":4},"testi":{}}', 'si']
        );
        $this->db->esegui("UPDATE prenotazioni SET valutazione_inviata = '2026-10-01 10:00:00' WHERE id = ?", [$a]);

        $pannello = $this->servizio(ServizioPannelloFsl::class);
        $this->assertSame(2026, $pannello->annoCorrente());
        $r = $pannello->riepilogo('2026-09-01', '2027-08-31');
        $this->assertCount(3, $r['righe'], 'annullate escluse');
        $this->assertCount(2, $r['conf']);
        $this->assertSame(20, $r['n_studenti']);
        $this->assertSame(2, $r['n_conv_mancanti'], 'la scuola con la convenzione scaduta e quella scritta a mano');
        $this->assertSame(['Liceo Manuale', 'Liceo Scientifico Fermi – Rende', 'Liceo Scientifico Galilei – Cosenza'], array_column($r['per_scuola'], 'nome'), 'in ordine di nome');
        $this->assertSame(1, $r['per_scuola']['CSPS00001A']['presenze']);
        $this->assertSame([4.5], $r['per_scuola']['CSPS00001A']['voti']);
        $this->assertSame(3, $r['per_att'][20]['iscrizioni']);
        $this->assertSame(2, $r['per_att'][20]['confermate']);
        $this->assertSame('2026-10-13', $r['per_att'][20]['dal']);
        $this->assertSame(1, $r['n_inviti']);
        $this->assertSame(['accoglienza' => [5], 'tutor' => [4]], $r['somme']);
        $this->assertSame(['si' => 1, 'forse' => 0, 'no' => 0], $r['rip']);
        $this->assertCount(1, $r['valutazioni']);
        $this->assertSame(0, $pannello->riepilogo('2024-09-01', '2025-08-31')['n_studenti']);

        $registro = $pannello->registro();
        $this->assertSame(['CSPS00001A'], array_keys($registro['vigore']));
        $this->assertSame(['CSPS00002B'], array_keys($registro['archivio']), 'scaduta: in archivio');
        $this->assertSame('1', $registro['archivio']['CSPS00002B'][0]['n_iscr']);

        [$daStipulare, $coperte] = $pannello->daStipulare();
        $this->assertSame([$b, $c], array_map(static fn (array $x): int => (int) $x['id'], $daStipulare), 'la scuola con la convenzione scaduta e quella scritta a mano');
        $this->assertSame(1, $coperte, 'la prenotazione della scuola con la convenzione valida non va in elenco');
        $this->assertSame('2026-09-03', $pannello->convenzione(1)['data_stipula']);
        $this->assertNull($pannello->convenzione(999));
    }
}
