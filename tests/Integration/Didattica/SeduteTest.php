<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Didattica\ConsiglioRepository;
use App\Didattica\DecisioniSeduta;
use App\Didattica\EsportazioneVerbale;
use App\Didattica\EstrattoVerbale;
use App\Didattica\PianificatiDidattica;
use App\Didattica\SedutaRepository;
use App\Didattica\ServizioConsigli;
use App\Didattica\ServizioConvocazioni;
use App\Didattica\ServizioSedute;
use App\Didattica\VerbaleFirmato;

/** Consigli, sedute, presenze, decisioni, verbale (Word, Excel, estratto PDF), convocazioni e verbale firmato. */
final class SeduteTest extends DidatticaBase
{
    private function consigli(): ServizioConsigli
    {
        return $this->servizio(ServizioConsigli::class);
    }

    private function sedute(): ServizioSedute
    {
        return $this->servizio(ServizioSedute::class);
    }

    /** Un consiglio con tre componenti (uno senza email) e un referente. */
    private function consiglio(): int
    {
        $cid = $this->db->inserisci("INSERT INTO didattica_consigli (nome, corsi, ordine) VALUES ('Consiglio di Biologia', '[]', 1)");
        $this->assertNull($this->consigli()->aggiungiPersona($cid, 'componente', 'm.verdi', 'Professori ordinari'));
        $this->assertNull($this->consigli()->aggiungiPersona($cid, 'componente', 'p.bianchi'));
        $this->assertNull($this->consigli()->aggiungiPersona($cid, 'componente', '', 'Studenti', 'Zeta Studente'));
        $this->assertNull($this->consigli()->aggiungiPersona($cid, 'referente', 'm.verdi'));

        return $cid;
    }

    private function seduta(int $cid, string $data = '2099-03-10'): int
    {
        return $this->sedute()->salva(0, ['organo' => 'Consiglio di Biologia', 'anno_accademico' => '2026/2027', 'data' => $data, 'ora_inizio' => '10:00', 'ora_fine' => '12:00', 'luogo' => 'Aula 1',
            'odg' => "Comunicazioni\n2) Pratiche studenti", 'presenze' => '', 'segretario' => 'Maria Verdi', 'coordinatore' => 'Paolo Bianchi', 'consiglio_id' => $cid]);
    }

    private function pratica(int $sid, string $codice = 'PR-1', string $stato = 'inviata', string $esito = ''): int
    {
        $m = $this->modulo('Convalida', [['etichetta' => 'Corso di studio', 'tipo' => 'text']], null, 'tutti', ['verbale' => json_encode(['sezione' => 'Convalide', 'testo' => '{STUDENTE} chiede la convalida.', 'delibera' => 'Il Consiglio approva.'])]);

        return $this->db->inserisci(
            'INSERT INTO pratiche (modulo_id, utente_id, codice, nome, cognome, email, matricola, risposte_json, stato, seduta_id, esito_seduta) VALUES (?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$m, $codice, 'Luca', 'Rossi', 'luca@x.it', '245678', json_encode([['etichetta' => 'Corso di studio', 'tipo' => 'text', 'valore' => 'Biologia']]), $stato, $sid, $esito]
        );
    }

    public function testPersoneDelConsiglio(): void
    {
        $cid = $this->consiglio();
        $c = $this->consigli();
        $comp = $c->persone($cid);
        $this->assertSame(['Professori ordinari', 'Professori associati', 'Studenti'], array_column($comp, 'qualifica'), 'la qualifica viene dal ruolo se non è indicata, in ordine di gruppo');
        $this->assertSame('maria.verdi@x.it', $c->persone($cid, 'referente')[0]['email'], 'email in minuscolo dall\'anagrafe');
        $this->assertSame('È già referente di questo consiglio.', $c->aggiungiPersona($cid, 'referente', 'm.verdi'));
        $this->assertSame('È già tra i componenti.', $c->aggiungiPersona($cid, 'componente', '', '', 'zeta studente'));
        $this->assertSame("Persona non trovata nell'anagrafe di Ateneo.", $c->aggiungiPersona($cid, 'componente', 'nessuno'));
        $this->assertSame("La persona scelta non ha un'email nell'anagrafe: non potrebbe accedere.", $c->aggiungiPersona($cid, 'referente', 's.senzamail'));
        $this->assertSame("Scrivi il nome o scegli dall'anagrafe.", $c->aggiungiPersona($cid, 'componente', '', '', ' '));
        $this->assertSame('Email non valida.', $c->aggiungiPersona($cid, 'componente', '', '', 'Tizio', 'boh'));
        $this->assertSame("Per un referente serve l'email istituzionale (con quella entra nel pannello).", $c->aggiungiPersona($cid, 'referente', '', '', 'Tizio'));
        $this->assertSame('Consiglio non trovato.', $c->aggiungiPersona(99, 'componente', 'm.verdi'));
    }

    public function testReferenteDi(): void
    {
        $cid = $this->consiglio();
        $c = $this->consigli();
        $this->assertSame([$cid], $c->referenteDi(['id' => 3, 'email' => 'MARIA.verdi@x.it']));
        $this->assertSame([$cid], $c->referenteDi(['id' => 3, 'email' => '', 'persona_id' => 'm.verdi']));
        $this->assertSame([], $c->referenteDi(['id' => 4, 'email' => 'paolo@x.it']));
        $this->assertSame([], $c->referenteDi(null));
    }

    public function testImportaSalvaQualificheEToglie(): void
    {
        $da = $this->consiglio();
        $a = $this->db->inserisci("INSERT INTO didattica_consigli (nome) VALUES ('Altro')");
        $c = $this->consigli();
        $this->assertSame(3, $c->importaComponenti($da, $a));
        $this->assertSame(0, $c->importaComponenti($da, $a), 'chi c\'è già non si duplica');
        $this->assertSame(0, $c->importaComponenti($a, $a));
        $id = (int) $c->persone($a)[0]['id'];
        $c->salvaQualifiche($a, [$id => '  Ricercatori  '], [$id => 7]);
        $this->assertSame(['Ricercatori', 7], [$c->persona($id)['qualifica'], (int) $c->persona($id)['ordine']]);
        $c->togliPersona($id);
        $this->assertNull($c->persona($id));
        $r = $c->salvaDaModulo(0, ['c_nome' => ' Nuovo ', 'c_corsi' => [' A ', '', 'B'], 'c_attivo' => '1', 'c_ordine' => '5']);
        $this->assertSame(['Nuovo', null], [$r['nome'], $r['errore']]);
        $nuovo = $c->consiglio($r['id']);
        $this->assertSame(['["A","B"]', 1, 5], [$nuovo['corsi'], (int) $nuovo['attivo'], (int) $nuovo['ordine']]);
        $this->assertSame('nome', $c->salvaDaModulo(0, ['c_nome' => ' '])['errore']);
        $this->assertContains('Nuovo', array_column($c->tutti(true), 'nome'));
    }

    public function testSedutaEPresenze(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid);
        $s = $this->sedute()->seduta($sid);
        $this->assertSame('10/03/2099 – Consiglio di Biologia', ServizioSedute::etichetta($s));
        $this->assertSame([], $this->sedute()->registrate($s), 'senza presenze salvate il verbale non le riporta');
        $pres = $this->sedute()->presenze($s);
        $this->assertCount(3, $pres);
        $this->assertSame(['P', 'P', 'P'], array_column($pres, 'stato'));
        $ids = array_keys($pres);
        $this->assertSame(3, $this->sedute()->salvaPresenze($s, [$ids[0] => 'AG', $ids[1] => 'XX', $ids[2] => 'AI']));
        $reg = $this->sedute()->registrate($s);
        $this->assertSame(['AG', 'P', 'AI'], array_column($reg, 'stato'), 'stato sconosciuto: resta quello di prima');
        $this->assertSame(['P' => 1, 'AG' => 1, 'AI' => 1], ServizioSedute::riepilogo($reg));
        $this->assertSame(['P' => 0, 'AG' => 0, 'AI' => 0], ServizioSedute::riepilogo([]));
        $campi = $this->sedute()->campiDaModulo(['organo' => ' X ', 'data' => '10/03/2099', 'ora_inizio' => '9:00', 'ora_fine' => '11:30', 'consiglio_id' => '77'], [$cid => []]);
        $this->assertSame(['X', null, '', '11:30', null], [$campi['organo'], $campi['data'], $campi['ora_inizio'], $campi['ora_fine'], $campi['consiglio_id']]);
        $this->assertSame($cid, $this->sedute()->campiDaModulo(['consiglio_id' => (string) $cid], [$cid => []])['consiglio_id']);
    }

    public function testEliminaSedutaLasciaLePratiche(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid);
        $pid = $this->pratica($sid);
        $this->sedute()->salvaPresenze($this->sedute()->seduta($sid), []);
        $this->sedute()->elimina($sid);
        $this->assertNull($this->sedute()->seduta($sid));
        $this->assertNull($this->db->valore('SELECT seduta_id FROM pratiche WHERE id = ?', [$pid]));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM didattica_sedute_presenze'));
    }

    public function testVisibilitaDelleSedutePerIlReferente(): void
    {
        $cid = $this->consiglio();
        $altro = $this->db->inserisci("INSERT INTO didattica_consigli (nome) VALUES ('Altro')");
        $sid = $this->seduta($cid);
        $sid2 = $this->seduta($altro);
        $referente = ['id' => 3, 'email' => 'maria.verdi@x.it', 'ruolo_id' => 5];
        $this->assertTrue($this->sedute()->utenteVedePratica($referente, ['seduta_id' => $sid]));
        $this->assertFalse($this->sedute()->utenteVedePratica($referente, ['seduta_id' => $sid2]));
        $this->assertFalse($this->sedute()->utenteVedePratica($referente, ['seduta_id' => null]));
        $this->assertFalse($this->sedute()->utenteVedePratica(['id' => 4, 'email' => 'paolo@x.it', 'ruolo_id' => 5], ['seduta_id' => $sid]));
        $this->assertFalse($this->sedute()->utenteVedePratica(null, ['seduta_id' => $sid]));
    }

    public function testDecisioniDalPost(): void
    {
        $d = $this->servizio(DecisioniSeduta::class);
        $conv = $d->leggiDalPost('convalida', ['d_richiesto' => ['Analisi 1', '', 'Fisica'], 'd_ins' => ['', '', 'Fisica generale'], 'd_cfu' => ['9', '', '6,5'], 'd_esito' => ['totale', '', 'parziale'],
            'd_cfu_int' => ['', '', ''], 'd_ins_cfu' => ['', '', '9'], 'd_piano' => [1 => '1'], 'd_elimina' => [1 => 'x']]);
        $this->assertSame('convalida', $conv['tipo']);
        $this->assertCount(2, $conv['righe'], 'le righe vuote si saltano');
        $this->assertSame(['totale', '9', '0'], [$conv['righe'][0]['esito'], $conv['righe'][0]['cfu_ric'], $conv['righe'][0]['cfu_int']]);
        $this->assertSame(['parziale', '6.5', '2.5'], [$conv['righe'][1]['esito'], $conv['righe'][1]['cfu_ric'], $conv['righe'][1]['cfu_int']]);
        $piano = $d->leggiDalPost('piano', ['d_richiesto' => ['Chimica', ''], 'd_esito' => ['boh', 'in_piano']]);
        $this->assertCount(1, $piano['righe']);
        $this->assertSame('in_piano', $piano['righe'][0]['esito']);
    }

    public function testSalvaEApplicaEsiti(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid, '2026-10-05');
        $a = $this->pratica($sid, 'PR-A');
        $b = $this->pratica($sid, 'PR-B');
        $r = $this->pratica($sid, 'PR-R');
        $d = $this->servizio(DecisioniSeduta::class);
        $this->assertTrue($d->salva($a, 'approvata', ['tipo' => 'convalida', 'righe' => [['richiesto' => 'X', 'ins' => 'Y', 'esito' => 'totale', 'cfu_ric' => '6', 'cfu_int' => '0']]], '  Approvata. '));
        $this->assertTrue($d->salva($b, 'respinta', null, null));
        $this->assertTrue($d->salva($r, 'rinviata', null, ''));
        $this->assertTrue($d->salva($r, 'sconosciuto', null, null));
        $this->assertSame(['approvata', 'Approvata.'], [$this->db->valore('SELECT esito_seduta FROM pratiche WHERE id = ?', [$a]), $this->db->valore('SELECT delibera FROM pratiche WHERE id = ?', [$a])]);
        $this->assertSame('', (string) $this->db->valore('SELECT esito_seduta FROM pratiche WHERE id = ?', [$r]));
        $d->salva($r, 'rinviata', null, null);
        $this->assertSame([1, 1, 1], $d->applicaEsiti($this->sedute()->seduta($sid), 1, 'Ada Admin'));
        $this->assertSame(['accolta', 'respinta', 'inviata'], [
            $this->db->valore('SELECT stato FROM pratiche WHERE id = ?', [$a]), $this->db->valore('SELECT stato FROM pratiche WHERE id = ?', [$b]), $this->db->valore('SELECT stato FROM pratiche WHERE id = ?', [$r]),
        ]);
        $this->assertNull($this->db->valore('SELECT seduta_id FROM pratiche WHERE id = ?', [$r]), 'la pratica rinviata esce dalla seduta');
        $this->assertContains("Approvata dal Consiglio nella seduta del 05/10/2026. Nella pratica trovi l'estratto del verbale in PDF.", array_column($this->db->righe('SELECT testo FROM pratiche_eventi WHERE pratica_id = ?', [$a]), 'testo'));
        $this->assertSame(2, (int) $this->db->valore("SELECT COUNT(*) FROM pratiche_eventi WHERE tipo = 'attivita'"), 'un estratto per ognuna delle pratiche decise');
        $this->assertSame([0, 0, 0], $d->applicaEsiti($this->sedute()->seduta($sid), 1), 'una seconda volta non cambia nulla');
    }

    public function testEsportazioni(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid);
        $pid = $this->pratica($sid, 'PR-E', 'inviata', 'approvata');
        $e = $this->servizio(EsportazioneVerbale::class);
        $pratiche = $e->pratichePerEsportazione([$pid, 0, 999]);
        $this->assertSame(['PR-E'], array_column($pratiche, 'codice'));
        $this->assertSame([], $e->pratichePerEsportazione([]));
        $docx = (string) $e->generaVerbale($this->sedute()->seduta($sid), $pratiche);
        $this->assertStringStartsWith('PK', (string) file_get_contents($docx));
        $this->assertStringContainsString('ROSSI', $this->contenutoZip($docx, 'word/document.xml'));
        $this->assertStringContainsString('Biologia', $this->contenutoZip($docx, 'word/document.xml'));
        foreach ([$e->generaVerbale(null, $pratiche), $e->generaVerbale($this->sedute()->seduta($sid), []), $e->generaExcel($pratiche)] as $f) {
            $this->assertStringStartsWith('PK', (string) file_get_contents((string) $f));
            @unlink((string) $f);
        }
        @unlink($docx);
    }

    /** Un file di un archivio zip (.docx, .xlsx). */
    private function contenutoZip(string $percorso, string $nome): string
    {
        $z = new \ZipArchive();
        $z->open($percorso);
        $xml = (string) $z->getFromName($nome);
        $z->close();

        return $xml;
    }

    public function testEstrattoDelVerbale(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid);
        $pid = $this->pratica($sid, 'PR-X', 'inviata', 'approvata');
        $x = $this->servizio(EstrattoVerbale::class);
        $p = $this->servizio(EsportazioneVerbale::class)->pratichePerEsportazione([$pid])[0];
        $pdf = $x->pdf($this->sedute()->seduta($sid), $p);
        $this->assertStringStartsWith('%PDF', $pdf);
        $eid = $x->allega($this->sedute()->seduta($sid), $pid, 1, 'Ada Admin');
        $this->assertGreaterThan(0, $eid);
        $ev = $this->db->riga('SELECT * FROM pratiche_eventi WHERE id = ?', [$eid]);
        $this->assertSame(['attivita', 'Estratto_verbale_PR-X.pdf', 'Estratto del verbale della seduta del 10/03/2099 con la delibera.'], [$ev['tipo'], $ev['nome_allegato'], $ev['testo']]);
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . $ev['allegato']);
        $this->assertSame(0, $x->allega($this->sedute()->seduta($sid), 999, 1));
    }

    public function testConvocazioneEGiustificazione(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid);
        $c = $this->servizio(ServizioConvocazioni::class);
        $s = $this->sedute()->seduta($sid);
        $this->assertSame([0, 0, "Scrivi l'oggetto e il testo dell'email."], $c->invia($s, '', 'x', true));
        $this->assertSame([0, 0, 'La convocazione si invia per le sedute di un consiglio con i componenti.'], $c->invia(['consiglio_id' => null] + $s, 'o', 't', true));
        $this->assertSame([0, 0, 'La seduta è già passata.'], $c->invia(['data' => '2020-01-01'] + $s, 'o', 't', true));
        [$n, $senza, $err] = $c->invia($s, 'Convocazione {ORGANO}', "Gentile {NOME},\nodg:\n{ODG}\n{LINK_GIUSTIFICA}", true);
        $this->assertSame([2, 1, null], [$n, $senza, $err], 'la studente senza email non riceve nulla');
        $inviate = $this->mailer->inviate;
        $this->assertCount(2, $inviate);
        $this->assertSame('Convocazione Consiglio di Biologia', $inviate[0]['oggetto']);
        $this->assertStringContainsString('1. Comunicazioni<br />', $inviate[0]['corpo']);
        $this->assertStringContainsString('2. Pratiche studenti', $inviate[0]['corpo']);
        $tok = (string) $this->db->valore('SELECT token FROM didattica_convocazioni ORDER BY id LIMIT 1');
        $this->assertStringContainsString('https://portale.test/eventi/giustifica.php?t=' . $tok, $inviate[0]['corpo']);
        $this->assertSame('Professori ordinari', $c->perToken($tok)['qualifica']);
        $this->assertNull($c->perToken('xyz'));
        [$n2] = $c->invia($s, 'Ancora', 'senza link {LINK_GIUSTIFICA}', false);
        $this->assertSame(2, (int) $n2);
        $this->assertSame(2, (int) $this->db->valore('SELECT COUNT(*) FROM didattica_convocazioni'), 'i token si riusano');
        $this->assertStringNotContainsString('giustifica.php', $this->mailer->inviate[2]['corpo']);
        $this->assertSame('Il link non è valido.', $c->giustifica(str_repeat('a', 40), 'x'));
        $this->assertNull($c->giustifica($tok, '  Sono in missione  '));
        $this->assertSame('AG', $this->sedute()->registrate($s)[(int) $c->perToken($tok)['componente_id']]['stato']);
        $this->assertSame('Sono in missione', $c->dellaSeduta($sid)[(int) $c->perToken($tok)['componente_id']]['motivo']);
    }

    public function testGiustificaDopoLaSedutaEConservazione(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid);
        $c = $this->servizio(ServizioConvocazioni::class);
        $c->invia($this->sedute()->seduta($sid), 'o', 't', true);
        $tok = (string) $this->db->valore('SELECT token FROM didattica_convocazioni ORDER BY id LIMIT 1');
        $this->db->esegui("UPDATE didattica_sedute SET data = '2020-01-01' WHERE id = ?", [$sid]);
        $this->assertSame('La seduta si è già svolta: per giustificare scrivi al coordinatore.', $c->giustifica($tok, 'x'));
        $this->assertSame(0, $c->conserva(0));
        $this->assertSame(2, $c->conserva(12));
        $this->assertSame(0, $c->conserva(12));
    }

    private function pdfDaFirmare(int $nFirme = 0): string
    {
        return "%PDF-1.4\nverbale\n" . str_repeat("%%FIRMA\n", $nFirme);
    }

    public function testVerbaleFirmato(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid);
        $v = $this->servizio(VerbaleFirmato::class);
        $this->assertSame('Seduta non trovata.', $v->inviaAllaFirma(999, $this->pdfDaFirmare(), 'a@x.it', 'b@x.it'));
        $this->assertSame('Carica il verbale in PDF (esportalo da Word come PDF).', $v->inviaAllaFirma($sid, 'boh', 'a@x.it', 'b@x.it'));
        $this->assertSame('Indica le email del segretario e del coordinatore.', $v->inviaAllaFirma($sid, $this->pdfDaFirmare(), 'a@x.it', 'no'));
        $this->assertNull($v->inviaAllaFirma($sid, $this->pdfDaFirmare(), ' SEG@x.it ', 'coo@x.it'));
        $s = $this->sedute()->seduta($sid);
        $this->assertSame(['segretario', 'seg@x.it', 'coo@x.it'], [$s['verbale_stato'], $s['segretario_email'], $s['coordinatore_email']]);
        $this->assertFileExists((string) $v->percorso($s));
        $this->assertNull($v->percorso(['verbale_pdf' => '../.env']), 'solo dentro la cartella dei verbali');
        $this->assertSame($sid, (int) $v->sedutaPerToken($s['verbale_token'])['id']);
        $this->assertNull($v->sedutaPerToken('zz'));
        $this->assertTrue($v->firmatario($s, ['email' => 'SEG@x.it']));
        $this->assertFalse($v->firmatario($s, ['email' => 'coo@x.it']));
        $this->assertFalse($v->firmatario($s, null));
        $this->assertCount(1, $this->mailer->inviate);
        $this->assertSame('seg@x.it', $this->mailer->inviate[0]['a']);
        // firma del segretario: il PDF deve contenere il precedente più una firma
        $this->assertNotNull($v->registraFirma($sid, $this->pdfDaFirmare()));
        $this->assertNull($v->registraFirma($sid, $this->pdfDaFirmare(1)));
        $s = $this->sedute()->seduta($sid);
        $this->assertSame('coordinatore', $s['verbale_stato']);
        $this->assertSame('coo@x.it', $this->mailer->inviate[1]['a']);
        $this->assertNull($v->registraFirma($sid, $this->pdfDaFirmare(2)));
        $s = $this->sedute()->seduta($sid);
        $this->assertSame(['firmato', null], [$s['verbale_stato'], $s['verbale_token']]);
        $this->assertContains('maria.verdi@x.it', array_column(array_slice($this->mailer->inviate, 2), 'a'), 'il verbale firmato va anche ai referenti');
        $this->assertSame('Il verbale non è in attesa di firma.', $v->registraFirma($sid, $this->pdfDaFirmare(3)));
        $this->assertSame('Il verbale è già firmato.', $v->inviaAllaFirma($sid, $this->pdfDaFirmare(), 'a@x.it', 'b@x.it'));
    }

    public function testSollecitiEPianificati(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid);
        $v = $this->servizio(VerbaleFirmato::class);
        $v->inviaAllaFirma($sid, $this->pdfDaFirmare(), 'seg@x.it', 'coo@x.it');
        $this->assertSame(0, $v->solleciti(), 'appena inviato: nessun sollecito');
        $this->db->esegui("UPDATE didattica_sedute SET verbale_inviato_il = NOW() - INTERVAL 6 DAY WHERE id = ?", [$sid]);
        $p = $this->servizio(PianificatiDidattica::class);
        $this->assertSame(1, $p->sollecitiVerbali());
        $this->assertSame(0, $p->sollecitiVerbali(), 'uno ogni FIRME_GIORNI_SOLLECITO giorni');
        $this->assertSame(1, (int) $this->sedute()->seduta($sid)['verbale_solleciti']);
        $this->assertStringContainsString('sollecito', $this->mailer->inviate[1]['corpo']);
        $this->assertSame(0, $p->conservaSedute(0));
    }

    public function testRepositoryDelleSedute(): void
    {
        $cid = $this->consiglio();
        $sid = $this->seduta($cid);
        $altro = $this->db->inserisci("INSERT INTO didattica_consigli (nome) VALUES ('Altro')");
        $sid2 = $this->seduta($altro, '2099-04-01');
        $r = $this->servizio(SedutaRepository::class);
        $this->assertCount(2, $r->elenco());
        $this->assertSame([$sid], array_map(fn ($x) => (int) $x['id'], $r->elenco([$cid => ['id' => $cid]])));
        $this->assertSame([$sid2, $sid], array_map(fn ($x) => (int) $x['id'], $r->elenco()), 'le più recenti prima');
        $this->assertNull($r->perId(999));
        $this->assertSame([], $r->idPratiche($sid));
        $p = $this->pratica($sid);
        $this->assertSame([$p], $r->idPratiche($sid));
        $this->assertSame([$altro, $cid], array_map('intval', array_column($this->servizio(ConsiglioRepository::class)->tutti(), 'id')), 'per ordine');
        $this->assertNotContains($p, array_map('intval', array_column($r->pratichePortabili(), 'id')), 'la pratica già in seduta non si porta');
    }
}
