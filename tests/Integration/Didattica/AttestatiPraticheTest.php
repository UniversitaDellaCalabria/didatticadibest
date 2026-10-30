<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Didattica\Costanti;
use App\Didattica\ModelliPratiche;
use App\Didattica\PraticaRepository;
use App\Didattica\ServizioAttestati;
use App\Didattica\ServizioConfermeDocenti;
use App\Didattica\ServizioFlussoConvalide;
use App\Didattica\ServizioIter;
use App\Didattica\ServizioPratiche;

/** Gli attestati (frequenza alle lezioni, esame sostenuto): conferma dei docenti nel portale, attestato in PDF, firma del Direttore e protocollo in uscita. */
final class AttestatiPraticheTest extends DidatticaBase
{
    /** @var array<string, mixed> */
    private array $freq;
    /** @var array<string, mixed> */
    private array $esame;
    private int $uffAtt;
    /** @var array<string, mixed> */
    private array $studente = ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Rossi', 'email' => 'luca@x.it', 'matricola_studente' => '245678'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->servizio(ServizioFlussoConvalide::class)->preparaFlusso();
        $this->db->esegui("UPDATE didattica_moduli SET attivo = 1 WHERE chiave IN ('attestato_frequenza', 'attestato_esame')");
        $this->freq = $this->db->riga("SELECT * FROM didattica_moduli WHERE chiave = 'attestato_frequenza'");
        $this->esame = $this->db->riga("SELECT * FROM didattica_moduli WHERE chiave = 'attestato_esame'");
        $this->uffAtt = (int) $this->db->valore("SELECT id FROM uffici WHERE chiave = 'attestati'");
        $this->operatore('Gianna Manager', 'manager@x.it', 'pratiche', $this->uffAtt);
        $this->db->esegui("INSERT INTO personale_ateneo (id, nome, cognome, email, ruolo, docente, attivo) VALUES ('a.verdi', 'Anna', 'Verdi', 'ANNA.VERDI@x.it', 'PA', 1, 1), ('g.neri', 'Giulia', 'Neri', 'giulia.neri@x.it', 'PO', 1, 1), ('m.gialli', 'Marco', 'Gialli', 'marco.gialli@x.it', 'PO', 1, 1)");
        $this->impostaDirettore('Mario Bianchi', 'direttore@x.it');
    }

    private function pratiche(): ServizioPratiche
    {
        return $this->servizio(ServizioPratiche::class);
    }

    private function conferme(): ServizioConfermeDocenti
    {
        return $this->servizio(ServizioConfermeDocenti::class);
    }

    private function pratica(int $id): array
    {
        return (array) $this->pratiche()->pratica($id);
    }

    private function mail(string $a): array
    {
        return array_values(array_filter($this->mailer->inviate, fn ($m) => $m['a'] === $a));
    }

    /** Risposte dell'attestato di frequenza con le lezioni indicate [insegnamento, docente, dal, al, dalle, alle, aula]. */
    private function risposteFrequenza(array $lezioni): array
    {
        return [
            ['etichetta' => 'Matricola', 'tipo' => 'text', 'valore' => '245678'], ['etichetta' => 'Al Corso di Laurea in', 'tipo' => 'corso_studio', 'valore' => 'Corso di laurea in Biologia'],
            ['etichetta' => 'Motivo della richiesta', 'tipo' => 'text', 'valore' => 'Giustificazione per il datore di lavoro'], ['etichetta' => 'Ente o azienda', 'tipo' => 'text', 'valore' => 'Azienda Calabra S.r.l.'],
            ['etichetta' => "PEC o email dell'azienda", 'tipo' => 'email', 'valore' => 'azienda@pec.test.it'],
            ['etichetta' => 'Lezioni seguite', 'tipo' => 'tabella', 'valore' => '', 'colonne' => ['Insegnamento', 'Docente', 'Dal', 'Al', 'Dalle ore', 'Alle ore', 'Aula'], 'righe' => $lezioni],
        ];
    }

    private function lezioni(): array
    {
        return [
            ['Biologia molecolare – Corso di laurea in Biologia', 'Verdi Anna', '05/10/2026', '', '09:00', '13:00', 'Aula 3'],
            ['Biologia molecolare – Corso di laurea in Biologia', 'Verdi Anna', '07/10/2026', '07/10/2026', '09:00', '11:00', 'Aula 3'],
            ['Zoologia generale', 'Neri Giulia', '09/10/2026', '', '08:30', '10:30', 'Aula 1'],
        ];
    }

    public function testModuliEUfficioPreparati(): void
    {
        $this->assertSame(['attestati', 'Ufficio attestati', 0, ''], array_values($this->db->riga("SELECT chiave, nome, smista, tipo FROM uffici WHERE chiave = 'attestati'")));
        $iter = $this->servizio(ServizioIter::class);
        foreach ([$this->freq, $this->esame] as $m) {
            $def = $iter->definizione($m);
            $this->assertSame(['conferma', 'ufficio', 'direttore', 'ufficio'], array_column($def, 't'));
            $this->assertSame([false, true, false, false], array_column($def, 'genera'));
            $this->assertSame([false, false, false, true], array_column($def, 'uscita'));
            $this->assertSame([Costanti::ITER_CONFERMA, $this->uffAtt, Costanti::ITER_DIRETTORE, $this->uffAtt], $iter->iter($m));
            $this->assertSame(0, (int) $m['in_verbale']);
        }
        $this->assertSame(['Lezioni seguite', 'Docente'], [$iter->definizione($this->freq)[0]['campo'], $iter->definizione($this->freq)[0]['colonna']]);
        $this->assertSame('Docente titolare', $iter->definizione($this->esame)[0]['campo']);
        $this->assertSame('Conferma dei docenti', $iter->passi($this->freq)[1]);
        $this->assertStringContainsString('protocollo in uscita', $iter->passi($this->freq)[4]);
        $tab = array_values(array_filter(json_decode((string) $this->freq['campi_json'], true), fn ($c) => $c['tipo'] === 'tabella'))[0];
        $this->assertSame(['Insegnamento:insegnamento_dip', 'Docente:docente', 'Dal:data', 'Al:data', 'Dalle ore:ora', 'Alle ore:ora', 'Aula:testo'], $tab['opzioni']);
    }

    public function testControlloDeiDocentiIndicati(): void
    {
        $c = $this->conferme();
        $this->assertSame([], $c->controllaDocenti($this->freq, $this->risposteFrequenza($this->lezioni())));
        $r = $this->risposteFrequenza([['Chimica', 'Rossi Nessuno', '05/10/2026', '', '09:00', '10:00', 'A']]);
        $err = $c->controllaDocenti($this->freq, $r);
        $this->assertCount(1, $err);
        $this->assertStringContainsString('«Rossi Nessuno» non è nell\'anagrafe', $err[0]);
        $this->assertStringContainsString('Indica il docente', $c->controllaDocenti($this->freq, [])[0]);
        $this->assertSame('Anna Verdi', $c->nomeVisibile('Verdi Anna'), 'nell\'attestato il docente è «Nome Cognome»');
    }

    public function testFrequenzaConferme(): void
    {
        $id = $this->pratiche()->crea($this->freq, $this->studente, $this->risposteFrequenza($this->lezioni()));
        $p = $this->pratica($id);
        $this->assertSame(1, (int) $p['passo']);
        $conf = $this->conferme()->dellaPratica($id);
        $this->assertSame(['Verdi Anna', 'Neri Giulia'], array_column($conf, 'nome'));
        $this->assertSame(['anna.verdi@x.it', 'giulia.neri@x.it'], array_column($conf, 'email'));
        $mv = $this->mail('anna.verdi@x.it');
        $this->assertCount(1, $mv);
        $this->assertStringContainsString('conferma_attestato.php?t=' . $conf[0]['token'], $mv[0]['corpo']);
        $this->assertStringContainsString('2 righe', $mv[0]['corpo']);
        $this->assertCount(1, $this->mail('giulia.neri@x.it'));
        $this->assertFalse(ServizioPratiche::modificabile($p), 'i docenti hanno già ricevuto la richiesta');
        // la pagina del docente mostra solo le sue righe
        $righe = $this->conferme()->righeDelDocente($p, $conf[0]);
        $this->assertCount(2, $righe);
        $this->assertSame('Aula 3', $righe[0]['Aula']);
        $this->assertCount(1, $this->conferme()->righeDelDocente($p, $conf[1]));
        // chi può confermare
        $this->assertTrue($this->conferme()->puoConfermare($conf[0], ['id' => 9, 'email' => 'Anna.Verdi@x.it']));
        $this->assertFalse($this->conferme()->puoConfermare($conf[0], ['id' => 9, 'email' => 'altro@x.it']));
        $this->assertFalse($this->conferme()->puoConfermare($conf[0], null));
        // stessa persona dell'anagrafe che entra con un altro indirizzo: può confermare; con un'altra scheda (o nessuna) no
        $this->assertSame('a.verdi', $conf[0]['persona_id']);
        $this->assertTrue($this->conferme()->puoConfermare($conf[0], ['id' => 9, 'email' => 'alias@x.it', 'persona_id' => 'a.verdi']));
        $this->assertFalse($this->conferme()->puoConfermare($conf[0], ['id' => 9, 'email' => 'alias@x.it', 'persona_id' => 'g.neri']));
        $this->assertFalse($this->conferme()->puoConfermare($conf[0], ['id' => 9, 'email' => 'alias@x.it', 'persona_id' => '']));
        // la prima conferma non basta, la seconda sì
        $this->assertNull($this->conferme()->conferma($conf[0]));
        $this->assertFalse($this->conferme()->tutteConfermate($id));
        $this->assertSame('La conferma non è più in attesa.', $this->conferme()->conferma($this->conferme()->perToken($conf[0]['token'])));
        $this->assertNull($this->conferme()->conferma($conf[1]));
        $this->assertTrue($this->conferme()->tutteConfermate($id));
        $this->mailer->inviate = [];
        [$err, $msg] = $this->servizio(ServizioFlussoConvalide::class)->trasmettiAlPasso($id, 1, 0, 'Conferma dei docenti');
        $this->assertNull($err);
        $p = $this->pratica($id);
        $this->assertSame([2, $this->uffAtt], [(int) $p['passo'], (int) $p['ufficio_id']], 'passa all\'ufficio attestati');
        $this->assertContains('manager@x.it', array_column($this->mailer->inviate, 'a'));
        $att = $this->servizio(ServizioAttestati::class);
        $this->assertTrue($att->inGenera($p));
        $this->assertFalse($att->inUscita($p));
    }

    public function testRifiutoDelDocenteESuaCorrezione(): void
    {
        $id = $this->pratiche()->crea($this->freq, $this->studente, $this->risposteFrequenza($this->lezioni()));
        $conf = $this->conferme()->dellaPratica($id);
        $this->mailer->inviate = [];
        $this->assertStringContainsString('Scrivi il motivo', (string) $this->conferme()->rifiuta($conf[0], '  '));
        $this->assertNull($this->conferme()->rifiuta($conf[0], 'Il 7 ottobre la lezione non c\'era'));
        $p = $this->pratica($id);
        $this->assertSame('integrazione', $p['stato']);
        $this->assertSame(1, json_decode((string) $p['richiesta_json'], true)['conferma']);
        $this->assertContains('luca@x.it', array_column($this->mailer->inviate, 'a'), 'lo studente è avvisato');
        $stati = array_column($this->db->righe('SELECT nome, stato FROM pratiche_conferme WHERE pratica_id = ? ORDER BY id', [$id]), 'stato', 'nome');
        $this->assertSame(['Verdi Anna' => 'rifiutata', 'Neri Giulia' => 'annullata'], $stati, 'le altre conferme in attesa si annullano');
        $this->assertTrue(ServizioPratiche::modificabile($p), 'lo studente può correggere');
        // corregge: le conferme ripartono con nuovi link
        $this->mailer->inviate = [];
        $nuove = $this->lezioni();
        array_splice($nuove, 1, 1);
        $this->assertNull($this->pratiche()->modifica($id, $this->freq, $this->studente, $this->risposteFrequenza($nuove)));
        $p = $this->pratica($id);
        $this->assertSame('in_lavorazione', $p['stato']);
        $attese = $this->conferme()->dellaPratica($id);
        $this->assertSame(['Verdi Anna', 'Neri Giulia'], array_column($attese, 'nome'));
        $this->assertSame(['attesa', 'attesa'], array_column($attese, 'stato'));
        $this->assertNotSame($conf[0]['token'], $attese[0]['token']);
        $this->assertCount(1, $this->mail('anna.verdi@x.it'));
        $this->assertFalse(ServizioPratiche::modificabile($p));
    }

    public function testAttestatoInPdfFirmaEProtocolloInUscita(): void
    {
        $id = $this->pratiche()->crea($this->freq, $this->studente, $this->risposteFrequenza($this->lezioni()));
        foreach ($this->conferme()->dellaPratica($id) as $c) {
            $this->conferme()->conferma($c);
        }
        $flusso = $this->servizio(ServizioFlussoConvalide::class);
        $flusso->trasmettiAlPasso($id, 1, 0, 'Conferma dei docenti');
        $att = $this->servizio(ServizioAttestati::class);
        $dati = $att->dati($this->pratica($id), $this->freq);
        $this->assertSame(['frequenza', 'Luca Rossi', 'Azienda Calabra S.r.l.', 'azienda@pec.test.it'], [$dati['tipo'], $dati['studente']['nome'], $dati['ente'], $dati['pec']]);
        $this->assertSame(['Biologia molecolare', 'Anna Verdi', '05/10/2026', '05/10/2026'], [$dati['righe'][0]['insegnamento'], $dati['righe'][0]['docente'], $dati['righe'][0]['dal'], $dati['righe'][0]['al']], 'senza «Al» vale lo stesso giorno');
        $this->assertNotNull($dati['righe'][2]['conferma']['il'], 'ogni riga porta la conferma del suo docente');
        $this->assertStringStartsWith('%PDF', (string) $att->pdf($id));
        $this->assertSame('azienda@pec.test.it', $att->destinatario($this->pratica($id)));
        // l'ufficio controlla e manda al Direttore
        $this->mailer->inviate = [];
        [$err, $msg] = $att->mandaAlDirettore($id, 1, 'Gianna');
        $this->assertNull($err);
        $p = $this->pratica($id);
        $this->assertSame(3, (int) $p['passo'], 'firma del Direttore');
        $this->assertContains('direttore@x.it', array_column($this->mailer->inviate, 'a'));
        $this->assertStringContainsString('Attestato_', (string) $this->db->valore('SELECT nome_allegato FROM pratiche_eventi WHERE pratica_id = ? AND interno = 1 AND allegato IS NOT NULL', [$id]));
        $this->assertTrue($this->servizio(ServizioIter::class)->haPassoDopo('uscita', $this->freq, 3), 'dopo la firma c\'è il protocollo in uscita');
        // il Direttore ha firmato: la pratica arriva al protocollo in uscita
        $this->mailer->inviate = [];
        $flusso->trasmettiAlPasso($id, 3, 0, 'Direttore');
        $p = $this->pratica($id);
        $this->assertTrue($att->inUscita($p));
        $this->assertContains('manager@x.it', array_column($this->mailer->inviate, 'a'));
        // protocollo in uscita: servono numero, data e PDF
        $pdf = tempnam(sys_get_temp_dir(), 'att') ?: '';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $file = ['name' => 'attestato.pdf', 'tmp_name' => $pdf, 'error' => UPLOAD_ERR_OK, 'size' => 20];
        $this->assertStringContainsString('numero e la data', $att->protocolloUscita($id, '', '2026-10-06', $file, 1)[0]);
        $this->assertStringContainsString('numero e la data', $att->protocolloUscita($id, '77', '2026-12-31', $file, 1)[0]);
        $this->assertStringContainsString('Carica il PDF', $att->protocolloUscita($id, '77', '2026-10-06', null, 1)[0]);
        $this->mailer->inviate = [];
        [$err] = $att->protocolloUscita($id, '4321/2026', '2026-10-06', $file, 1, 'Gianna');
        $this->assertNull($err);
        $p = $this->pratica($id);
        $this->assertSame(['chiusa', '4321/2026', '2026-10-06'], [$p['stato'], $p['protocollo'], $p['protocollo_data']]);
        $this->assertContains('luca@x.it', array_column($this->mailer->inviate, 'a'), 'lo studente può scaricare l\'attestato');
        $this->assertStringContainsString('Il tuo documento è pronto', implode('|', array_column($this->mailer->inviate, 'oggetto')));
        $visibili = array_column($this->servizio(PraticaRepository::class)->eventi($id, true), 'nome_allegato');
        $this->assertContains('Firmato_direttore_' . $p['codice'] . '.pdf', $visibili);
        $this->assertNotContains('Attestato_' . $p['codice'] . '.pdf', $visibili, 'l\'attestato da firmare non è per lo studente');
    }

    public function testEsameSostenutoUnSoloDocente(): void
    {
        $risposte = [
            ['etichetta' => 'Matricola', 'tipo' => 'text', 'valore' => '245678'], ['etichetta' => 'Al Corso di Laurea in', 'tipo' => 'corso_studio', 'valore' => 'Corso di laurea in Biologia'],
            ['etichetta' => 'Motivo della richiesta', 'tipo' => 'text', 'valore' => 'Datore di lavoro'], ['etichetta' => "PEC o email dell'azienda", 'tipo' => 'email', 'valore' => 'az@pec.it'],
            ['etichetta' => 'Insegnamento', 'tipo' => 'insegnamento', 'valore' => 'Chimica organica – Corso di laurea in Biologia'], ['etichetta' => 'Docente titolare', 'tipo' => 'docente', 'valore' => 'Gialli Marco'],
            ['etichetta' => "Data dell'esame", 'tipo' => 'date', 'valore' => '2026-09-18'], ['etichetta' => 'Dalle ore', 'tipo' => 'time', 'valore' => '09:00'], ['etichetta' => 'Alle ore', 'tipo' => 'time', 'valore' => '12:30'],
            ['etichetta' => 'Aula', 'tipo' => 'text', 'valore' => 'Aula 2'],
        ];
        $this->assertSame([], $this->conferme()->controllaDocenti($this->esame, $risposte));
        $id = $this->pratiche()->crea($this->esame, $this->studente, $risposte);
        $conf = $this->conferme()->dellaPratica($id);
        $this->assertSame(['Gialli Marco'], array_column($conf, 'nome'));
        $righe = $this->conferme()->righeDelDocente($this->pratica($id), $conf[0]);
        $this->assertCount(1, $righe);
        $this->assertSame(['Insegnamento', 'Docente titolare', "Data dell'esame", 'Dalle ore', 'Alle ore', 'Aula'], array_keys($righe[0]));
        $this->assertNull($this->conferme()->conferma($conf[0]));
        $dati = $this->servizio(ServizioAttestati::class)->dati($this->pratica($id), $this->esame);
        $this->assertSame(['esame', 'Chimica organica', 'Marco Gialli', '2026-09-18'], [$dati['tipo'], $dati['righe'][0]['insegnamento'], $dati['righe'][0]['docente'], $dati['righe'][0]['data']]);
        $this->assertStringStartsWith('%PDF', (string) $this->servizio(ServizioAttestati::class)->pdf($id));
        $this->assertNull($this->servizio(ServizioAttestati::class)->dati($this->pratica($id), ['chiave' => 'altro']), 'solo i moduli degli attestati');
    }

    public function testNonSiProseguePrimaDelleConferme(): void
    {
        $id = $this->pratiche()->crea($this->freq, $this->studente, $this->risposteFrequenza($this->lezioni()));
        $att = $this->servizio(ServizioAttestati::class);
        $this->assertStringContainsString('non è nel passo', $att->mandaAlDirettore($id, 1)[0]);
        $this->assertStringContainsString('non è nel passo', $att->protocolloUscita($id, '1', '2026-10-06', null, 1)[0]);
        // anche con la pratica nel passo giusto, senza conferme non si manda al Direttore
        $this->db->esegui('UPDATE pratiche SET passo = 2 WHERE id = ?', [$id]);
        $this->assertSame('Non tutti i docenti hanno confermato.', $att->mandaAlDirettore($id, 1)[0]);
    }

    public function testSollecitoAiDocentiChePossonoNonRispondere(): void
    {
        $id = $this->pratiche()->crea($this->freq, $this->studente, $this->risposteFrequenza($this->lezioni()));
        $this->db->esegui("UPDATE didattica_moduli SET giorni_promemoria = 3 WHERE id = ?", [$this->freq['id']]);
        $this->db->esegui("UPDATE pratiche_conferme SET richiesta_il = '2026-09-28 10:00:00' WHERE pratica_id = ?", [$id]);
        $this->mailer->inviate = [];
        $this->assertSame(2, $this->conferme()->solleciti());
        $this->assertStringContainsString('Promemoria', $this->mail('anna.verdi@x.it')[0]['oggetto']);
        $this->assertSame(0, $this->conferme()->solleciti(), 'un solo promemoria per volta');
    }
}
