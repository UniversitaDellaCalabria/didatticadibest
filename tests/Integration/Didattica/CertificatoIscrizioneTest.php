<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Didattica\ModelliPratiche;
use App\Didattica\PraticaRepository;
use App\Didattica\ServizioFlussoConvalide;
use App\Didattica\ServizioIter;
use App\Didattica\ServizioMarcheBollo;
use App\Didattica\ServizioPratiche;

/** La richiesta di certificato di iscrizione: bozza, modifica fino alla presa in carico, marche da bollo e firma del Direttore. */
final class CertificatoIscrizioneTest extends DidatticaBase
{
    private int $prot;
    private int $cdl;
    /** @var array<string, mixed> */
    private array $m;
    /** @var array<string, mixed> */
    private array $studente = ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Rossi', 'email' => 'luca@x.it', 'matricola_studente' => '245678'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->esegui('INSERT INTO didattica_consigli (id, nome, ordine, corsi) VALUES (?, ?, ?, ?)', [1, 'Consiglio di Biologia', 4, '["Corso di laurea in Biologia"]']);
        $this->servizio(ServizioFlussoConvalide::class)->preparaFlusso();
        $this->db->esegui("UPDATE didattica_moduli SET attivo = 1 WHERE chiave = 'certificato_iscrizione'");
        $this->prot = (int) $this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'protocollo'");
        $this->cdl = (int) $this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'cdl_1'");
        $this->operatore('Anna Protocollo', 'protocollo@x.it', 'pratiche', $this->prot);
        $this->operatore('Marco Segreteria', 'segreteria@x.it', 'pratiche', $this->cdl, '["Corso di laurea in Biologia"]');
        $this->m = $this->db->riga("SELECT * FROM didattica_moduli WHERE chiave = 'certificato_iscrizione'");
    }

    /** Risposte del modulo compilato. */
    private function risposte(string $luogo = 'Cosenza (CS)'): array
    {
        return [
            ['etichetta' => 'Codice fiscale', 'tipo' => 'codice_fiscale', 'valore' => 'RSSLCU02A01D086X'], ['etichetta' => 'Luogo di nascita', 'tipo' => 'text', 'valore' => $luogo],
            ['etichetta' => 'Data di nascita', 'tipo' => 'date', 'valore' => '2002-01-01'], ['etichetta' => 'Cellulare', 'tipo' => 'tel', 'valore' => '3331234567'],
            ['etichetta' => 'Email personale', 'tipo' => 'email', 'valore' => 'luca@x.it'], ['etichetta' => "Iscritto per l'A.A.", 'tipo' => 'anno_accademico', 'valore' => '2026/2027'],
            ['etichetta' => 'Matricola', 'tipo' => 'text', 'valore' => '245678'], ['etichetta' => 'Al Corso di Laurea in', 'tipo' => 'corso_studio', 'valore' => 'Corso di laurea in Biologia'],
            ['etichetta' => 'Chiede il rilascio del/i seguente/i certificato/i', 'tipo' => 'multicheck', 'valore' => 'Iscrizione, Iscrizione con esami'],
            ['etichetta' => 'Dichiaro di aver letto e compreso quanto scritto sopra e di assumermi ogni responsabilità su quanto dichiarato', 'tipo' => 'dichiarazione', 'valore' => 'Sì'],
        ];
    }

    private function servizioPratiche(): ServizioPratiche
    {
        return $this->servizio(ServizioPratiche::class);
    }

    private function pratica(int $id): array
    {
        return (array) $this->servizioPratiche()->pratica($id);
    }

    /** Un PDF finto caricato dall'operatore (come l'elemento di $_FILES). */
    private function pdfCaricato(string $nome = 'certificato.pdf'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cert') ?: '';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return ['name' => $nome, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 80];
    }

    public function testModuloPreparatoUnaVoltaConIterEDomanda(): void
    {
        $this->servizio(ServizioFlussoConvalide::class)->preparaFlusso();
        $this->assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM didattica_moduli WHERE chiave = 'certificato_iscrizione'"));
        $this->assertSame(['Richiesta Certificato di Iscrizione', 'online', 'Certificati e attestati', 0], [$this->m['titolo'], $this->m['tipo'], $this->m['categoria'], (int) $this->m['in_verbale']], 'non va in verbale');
        $iter = $this->servizio(ServizioIter::class);
        $this->assertSame([$this->prot, \App\Didattica\Costanti::ITER_CDL, \App\Didattica\Costanti::ITER_DIRETTORE], $iter->iter($this->m));
        $this->assertSame([false, true, false], array_column($iter->definizione($this->m), 'pagamento'), 'il secondo passo aspetta il pagamento delle marche');
        $this->assertSame('Ufficio protocollo', $iter->passi($this->m)[1]);
        $this->assertStringContainsString('marche da bollo', $iter->passi($this->m)[2]);
        $campi = json_decode((string) $this->m['campi_json'], true);
        $corso = array_values(array_filter($campi, fn ($c) => $c['tipo'] === 'corso_studio'));
        $this->assertCount(1, $corso, 'il corso di laurea si sceglie dall\'anagrafe di Ateneo');
        $multi = array_values(array_filter($campi, fn ($c) => $c['tipo'] === 'multicheck'))[0];
        $this->assertSame(ModelliPratiche::CERTIFICATI_ISCRIZIONE, $multi['opzioni']);
        $this->assertCount(9, $multi['opzioni']);
        $this->assertSame(1, json_decode((string) $this->m['domanda_json'], true)['pdf']);
    }

    public function testBozzaSiSalvaSiRiprendeEdEscludeLUfficio(): void
    {
        $s = $this->servizioPratiche();
        $id = $s->salvaBozza($this->m, $this->studente, [['etichetta' => 'Matricola', 'tipo' => 'text', 'valore' => '245']]);
        $this->assertGreaterThan(0, $id);
        $p = $this->pratica($id);
        $this->assertSame(['bozza', null, null], [$p['stato'], $p['ufficio_id'], $p['domanda_pdf']], 'non è una pratica: nessun ufficio e nessuna domanda');
        $this->assertSame([], $this->mailer->inviate, 'nessuna email per una bozza');
        $this->assertTrue(ServizioPratiche::modificabile($p));
        $r = $this->servizio(PraticaRepository::class);
        $this->assertSame([], $r->elenco('tutte', 0, '', '', '', '', '', null), 'gli operatori non vedono le bozze');
        $this->assertSame(0, $r->contaAperte());
        $this->assertSame([$id], array_column($r->dellUtente(2), 'id'), 'lo studente la vede');
        // si riprende e si aggiorna
        $this->assertSame($id, $s->salvaBozza($this->m, $this->studente, [['etichetta' => 'Matricola', 'tipo' => 'text', 'valore' => '245678']], $id));
        $this->assertSame('245678', json_decode((string) $this->pratica($id)['risposte_json'], true)[0]['valore']);
        $this->assertSame(0, $s->salvaBozza($this->m, ['id' => 3] + $this->studente, [], $id), 'non è la sua');
        $this->assertFalse($s->eliminaBozza($id, 3));
        $this->assertTrue($s->eliminaBozza($id, 2));
        $this->assertNull($s->pratica($id));
    }

    public function testInvioDellaBozzaAvviaLIter(): void
    {
        $s = $this->servizioPratiche();
        $id = $s->salvaBozza($this->m, $this->studente, $this->risposte());
        $this->assertTrue($s->inviaBozza($id, $this->m, $this->studente, $this->risposte()));
        $p = $this->pratica($id);
        $this->assertSame(['inviata', $this->prot, 1], [$p['stato'], $p['ufficio_id'], $p['passo']], 'parte dal protocollo');
        $this->assertNotEmpty($p['domanda_pdf'], 'domanda in PDF/A con i dati dell\'accesso');
        $this->assertContains('protocollo@x.it', array_column($this->mailer->inviate, 'a'));
        $this->assertContains('luca@x.it', array_column($this->mailer->inviate, 'a'), 'ricevuta allo studente');
        $this->assertFalse($s->inviaBozza($id, $this->m, $this->studente, $this->risposte()), 'una pratica inviata non è più una bozza');
        $this->assertFalse($s->eliminaBozza($id, 2), 'una pratica inviata non si elimina');
        $this->assertSame([$id], array_column($this->servizio(PraticaRepository::class)->elenco('aperte', 0, '', '', '', '', '', null), 'id'));
    }

    public function testModificabileFinoAllaPresaInCarico(): void
    {
        $s = $this->servizioPratiche();
        $id = $s->crea($this->m, $this->studente, $this->risposte());
        $p = $this->pratica($id);
        $this->assertTrue(ServizioPratiche::modificabile($p), 'inviata ma al protocollo senza nessuno in carico');
        $primaDomanda = $p['domanda_pdf'];
        $this->assertNull($s->modifica($id, $this->m, $this->studente, $this->risposte('Rende (CS)')));
        $p = $this->pratica($id);
        $this->assertSame('Rende (CS)', json_decode((string) $p['risposte_json'], true)[1]['valore']);
        $this->assertNotSame($primaDomanda, $p['domanda_pdf'], 'la domanda in PDF/A si rifà con i dati nuovi');
        $testi = array_column($this->servizio(PraticaRepository::class)->eventi($id, true), 'testo');
        $this->assertContains('Richiesta modificata dallo studente prima della presa in carico.', $testi);
        $this->assertSame('Pratica non trovata.', $s->modifica($id, $this->m, ['id' => 3] + $this->studente, []), 'solo il proprietario');
        // l'operatore del protocollo la prende in carico
        $op = (int) $this->db->valore("SELECT id FROM ufficio_didattica WHERE email = 'protocollo@x.it'");
        $this->db->esegui('UPDATE pratiche SET assegnata_a = ? WHERE id = ?', [$op, $id]);
        $this->assertFalse(ServizioPratiche::modificabile($this->pratica($id)));
        $this->assertStringContainsString('già stata presa in carico', (string) $s->modifica($id, $this->m, $this->studente, $this->risposte()));
        // anche il protocollo registrato la chiude alla modifica
        $id2 = $s->crea($this->m, $this->studente, $this->risposte());
        $this->servizio(ServizioFlussoConvalide::class)->registraProtocollo($id2, '10/2026', '2026-10-05', 1, 'Anna');
        $this->assertFalse(ServizioPratiche::modificabile($this->pratica($id2)));
    }

    public function testAllegatiGiaCaricatiRestanoInModifica(): void
    {
        $vecchie = [['etichetta' => 'Documento', 'tipo' => 'file', 'valore' => 'a.pdf', 'file' => 'cache/x/a.pdf', 'nome_file' => 'a.pdf']];
        $nuove = [['etichetta' => 'Documento', 'tipo' => 'file', 'valore' => '', 'file' => null, 'nome_file' => null]];
        $this->assertSame('cache/x/a.pdf', ServizioPratiche::conservaAllegati($nuove, $vecchie)[0]['file']);
        $nuove[0]['file'] = 'cache/x/b.pdf';
        $this->assertSame('cache/x/b.pdf', ServizioPratiche::conservaAllegati($nuove, $vecchie)[0]['file'], 'un file nuovo sostituisce il vecchio');
        $this->assertSame(['Altro: campo obbligatorio'], ServizioPratiche::senzaErroriAllegati(['Documento: campo obbligatorio', 'Altro: campo obbligatorio'], [], $vecchie));
    }

    /** La pratica passa dal protocollo all'ufficio del corso, dove aspetta le marche da bollo. */
    private function allaSegreteria(): int
    {
        $id = $this->servizioPratiche()->crea($this->m, $this->studente, $this->risposte());
        [$err] = $this->servizio(ServizioFlussoConvalide::class)->registraProtocollo($id, '10/2026', '2026-10-05', 1, 'Anna');
        $this->assertNull($err);
        $this->mailer->inviate = [];

        return $id;
    }

    public function testMarcheDaBolloAvvisoPagamentoEFirmaDelDirettore(): void
    {
        $id = $this->allaSegreteria();
        $marche = $this->servizio(ServizioMarcheBollo::class);
        $p = $this->pratica($id);
        $this->assertSame([2, $this->cdl], [$p['passo'], $p['ufficio_id']]);
        $this->assertTrue($marche->inAttesa($p));
        $this->assertTrue($this->servizio(ServizioIter::class)->passoPagamento($this->m, $p));
        // il pagamento non si registra prima di aver avvisato lo studente
        $this->assertStringContainsString('Prima segnala', $marche->registraPagamento($id, $this->pdfCaricato(), true, 1)[0]);
        // 1. l'operatore carica le marche in Esse3 e avvisa lo studente
        $this->assertNull($marche->avvisaMarche($id, 1, 'Marco'));
        $p = $this->pratica($id);
        $this->assertSame('Marco', $p['marche_da']);
        $this->assertNotNull($p['marche_il']);
        $mail = array_values(array_filter($this->mailer->inviate, fn ($m) => str_contains($m['oggetto'], 'Puoi pagare le marche da bollo')));
        $this->assertCount(1, $mail);
        $this->assertSame('luca@x.it', $mail[0]['a']);
        $this->assertStringContainsString('Situazione tasse', $mail[0]['corpo']);
        $this->assertStringContainsString('già stato avvisato', (string) $marche->avvisaMarche($id, 1, 'Marco'));
        // 2. pagamento: serve la spunta e il certificato in PDF
        $this->assertStringContainsString('Spunta', $marche->registraPagamento($id, $this->pdfCaricato(), false, 1)[0]);
        $this->assertStringContainsString('Carica il certificato', $marche->registraPagamento($id, null, true, 1)[0]);
        $this->assertStringContainsString('deve essere un PDF', $marche->registraPagamento($id, $this->pdfCaricato('certificato.docx'), true, 1)[0]);
        $this->assertNull($this->pratica($id)['bollo_il'], 'nulla è stato registrato');
        // senza il Direttore indicato la firma non parte, ma lo dice
        [$err] = $marche->registraPagamento($id, $this->pdfCaricato(), true, 1, 'Marco');
        $this->assertStringContainsString('Indica prima il Direttore', (string) $err);
    }

    public function testPagamentoPortaAllaFirmaDelDirettoreEStudenteRiceveIlDocumento(): void
    {
        $this->db->esegui("INSERT INTO impostazioni_sistema (id, conv_direttore_nome, conv_direttore_email) VALUES (1, 'Mario Bianchi', 'direttore@x.it')");
        $id = $this->allaSegreteria();
        $marche = $this->servizio(ServizioMarcheBollo::class);
        $marche->avvisaMarche($id, 1, 'Marco');
        $this->mailer->inviate = [];
        [$err, $msg] = $marche->registraPagamento($id, $this->pdfCaricato(), true, 1, 'Marco');
        $this->assertNull($err);
        $this->assertStringContainsString('Pagamento registrato', $msg);
        $p = $this->pratica($id);
        $this->assertSame(['Marco', 3], [$p['bollo_da'], $p['passo']], 'passo della firma del Direttore');
        $this->assertNotNull($p['bollo_il']);
        $firma = $this->servizio(\App\Didattica\FirmaPratica::class)->inAttesa($id);
        $this->assertSame(['direttore', 'direttore@x.it'], [$firma['tipo'], $firma['email']]);
        $this->assertContains('direttore@x.it', array_column($this->mailer->inviate, 'a'), 'il Direttore riceve il link per firmare');
        $eventi = $this->servizio(PraticaRepository::class)->eventi($id, true);
        $testi = array_column($eventi, 'testo');
        $this->assertContains('Pagamento verificato. Marca da bollo assolta virtualmente.', $testi, 'lo studente vede che la marca è assolta virtualmente');
        $this->assertNotContains('Certificato generato da Esse3, da firmare.', $testi, 'il certificato da firmare non è visibile allo studente');
        $this->assertStringContainsString('Certificato_', (string) $this->db->valore("SELECT nome_allegato FROM pratiche_eventi WHERE pratica_id = ? AND interno = 1 AND allegato IS NOT NULL", [$id]));
        $this->assertStringContainsString('non è in attesa del pagamento', $marche->registraPagamento($id, $this->pdfCaricato(), true, 1)[0], 'il pagamento non si registra due volte');
        // Il Direttore firma e ricarica: il documento firmato è visibile; alla fine dell'iter lo studente e l'operatore sono avvisati
        $this->db->esegui("INSERT INTO pratiche_eventi (pratica_id, tipo, autore, testo, allegato, nome_allegato, interno) VALUES (?, 'attivita', 'ufficio', 'Documento firmato', 'cache/x/f.pdf', ?, 0)", [$id, 'Firmato_direttore_' . $p['codice'] . '.pdf']);
        $this->mailer->inviate = [];
        [$err] = $this->servizio(ServizioFlussoConvalide::class)->trasmettiAlPasso($id, 3, 0, 'Mario Bianchi');
        $this->assertNull($err);
        $a = array_column($this->mailer->inviate, 'a');
        $this->assertContains('luca@x.it', $a, 'lo studente può stampare il certificato');
        $oggetti = implode('|', array_column($this->mailer->inviate, 'oggetto'));
        $this->assertStringContainsString('Il tuo documento è pronto', $oggetti);
        $this->assertStringContainsString('Iter concluso', $oggetti, 'l\'operatore è avvisato');
        $this->assertContains('segreteria@x.it', $a);
    }

    public function testElencoEFaseNonCambianoPerLeAltrePratiche(): void
    {
        $this->assertFalse($this->servizio(ServizioMarcheBollo::class)->inAttesa(['modulo_id' => (int) $this->m['id'], 'stato' => 'inviata', 'passo' => 1]), 'il protocollo non è il passo del pagamento');
        $this->assertFalse($this->servizio(ServizioIter::class)->passoPagamento($this->m, ['stato' => 'chiusa', 'passo' => 2]));
        $this->assertFalse($this->servizio(ServizioIter::class)->passoPagamento($this->m, ['stato' => 'bozza', 'passo' => 2]));
    }
}
