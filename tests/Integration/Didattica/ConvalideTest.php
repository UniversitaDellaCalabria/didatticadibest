<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Didattica\Costanti;
use App\Didattica\DomandaPdfa;
use App\Didattica\EditorDecisioni;
use App\Didattica\PraticaRepository;
use App\Didattica\ServizioFlussoConvalide;
use App\Didattica\ServizioPratiche;

/** Pratiche che passano dal protocollo: uffici del flusso, domanda in PDF/A, protocollo e bollo, ufficio del corso, segreteria studenti. */
final class ConvalideTest extends DidatticaBase
{
    private function flusso(): ServizioFlussoConvalide
    {
        return $this->servizio(ServizioFlussoConvalide::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->esegui('INSERT INTO didattica_consigli (id, nome, ordine, corsi) VALUES (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)', [
            1, 'Consiglio di Biologia', 4, '["Corso di laurea in Biologia"]',
            2, 'Consiglio del Corso di Laurea in Scienze Geologiche, e altro', 2, null,
            3, 'Consiglio nuovo', 9, null,
        ]);
    }

    public function testPartenzaDelFlusso(): void
    {
        $f = $this->flusso();
        $f->preparaFlusso();
        $uffici = $this->db->righe('SELECT nome, chiave, tipo, consiglio_id, segue_corsi, ordine FROM didattica_uffici ORDER BY id');
        $this->assertSame(['protocollo', 'protocollo', null, 0, 0], [$uffici[0]['chiave'], $uffici[0]['tipo'], $uffici[0]['consiglio_id'], $uffici[0]['segue_corsi'], $uffici[0]['ordine']]);
        $this->assertSame(['Ufficio CdS Scienze Geologiche', 'cdl_2', 12], [$uffici[1]['nome'], $uffici[1]['chiave'], $uffici[1]['ordine']]);
        $this->assertSame(['Ufficio CdS Biologia, Scienze e Tecnologie Biologiche, Health Biotechnology', 'cdl_1', 14], [$uffici[2]['nome'], $uffici[2]['chiave'], $uffici[2]['ordine']]);
        $this->assertSame('Ufficio del Consiglio nuovo', $uffici[3]['nome'], 'per i consigli nuovi: «Ufficio del <consiglio>»');
        $this->assertCount(4, $uffici);
        $f->preparaFlusso();
        $this->assertSame(4, (int) $this->db->valore('SELECT COUNT(*) FROM didattica_uffici'), 'una sola volta');
        $m = $this->db->riga("SELECT * FROM didattica_moduli WHERE chiave = 'convalida_esami'");
        $this->assertSame(['Richiesta di convalida/riconoscimento esami', 0, 'studenti', '["protocollo","@cdl","@segreteria"]'], [$m['titolo'], $m['attivo'], $m['destinatari'], $m['iter_json']]);
        $campi = json_decode($m['campi_json'], true);
        $this->assertCount(24, $campi);
        $this->assertSame(Costanti::TESTO_DICHIARAZIONE_DPR, $campi[22]['aiuto']);
    }

    public function testUfficioDelCorso(): void
    {
        $f = $this->flusso();
        $f->preparaFlusso();
        $bio = (int) $this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'cdl_1'");
        $geo = (int) $this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'cdl_2'");
        $this->assertSame($bio, $f->ufficioDelCorso(' CORSO di laurea in biologia '), 'dai corsi del consiglio');
        $this->assertSame($geo, $f->ufficioDelCorso('Scienze Geologiche'), 'dal nome del consiglio se non ha i corsi indicati');
        $this->assertNull($f->ufficioDelCorso('Scienze Geo'), 'la parola deve finire');
        $this->assertNull($f->ufficioDelCorso(''));
        $this->assertSame([$geo, $bio, $bio + 1], array_keys($f->ufficiDiTipo('cdl')), 'in ordine di visualizzazione');
        $this->assertSame('Biologia', ServizioFlussoConvalide::corsoPratica(['risposte_json' => json_encode([['tipo' => 'corso_studio', 'valore' => 'Altro', 'nascosto' => true], ['tipo' => 'corso_studio', 'valore' => ' Biologia ']])]));
        $this->assertSame('', ServizioFlussoConvalide::corsoPratica(['risposte_json' => '[]']));
    }

    /** Una pratica di convalida (modulo del flusso, attivo) inviata da uno studente di Biologia. */
    private function pratica(): int
    {
        $this->flusso()->preparaFlusso();
        $this->db->esegui("UPDATE didattica_moduli SET attivo = 1 WHERE chiave = 'convalida_esami'");
        $this->operatore('Anna Protocollo', 'protocollo@x.it', 'pratiche', (int) $this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'protocollo'"));
        $bio = (int) $this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'cdl_1'");
        $this->operatore('Maria Referente', 'maria.verdi@x.it', 'pratiche', $bio, '["Corso di laurea in Biologia"]');
        $this->operatore('Paolo Altro', 'paolo@x.it', 'pratiche', $bio);
        $m = $this->db->riga("SELECT * FROM didattica_moduli WHERE chiave = 'convalida_esami'");
        $risposte = [
            ['etichetta' => 'Luogo di nascita', 'tipo' => 'text', 'valore' => 'Cosenza (CS)'], ['etichetta' => 'Data di nascita', 'tipo' => 'date', 'valore' => '2002-01-01'],
            ['etichetta' => 'Codice fiscale', 'tipo' => 'codice_fiscale', 'valore' => 'RSSLCU02A01D086X'], ['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio', 'valore' => 'Corso di laurea in Biologia'],
            ['etichetta' => 'Anno accademico di iscrizione', 'tipo' => 'anno_accademico', 'valore' => '2026/2027'], ['etichetta' => 'Ateneo della carriera precedente', 'tipo' => 'radio', 'valore' => 'Altro Ateneo'],
            ['etichetta' => "Denominazione dell'Ateneo", 'tipo' => 'text', 'valore' => 'Università di Messina'], ['etichetta' => 'Corso di studio della carriera precedente', 'tipo' => 'text', 'valore' => 'Scienze Biologiche'],
            ['etichetta' => 'Esami sostenuti in altro Ateneo', 'tipo' => 'tabella', 'valore' => '', 'colonne' => ['Codice insegnamento', 'Denominazione insegnamento', 'CFU', 'Settore scientifico disciplinare', 'Voto', 'Data sostenimento', 'Da inserire nel piano come a scelta'],
             'righe' => [['BIO-1', 'Zoologia', '9', 'BIO/05', '27/30', '20/06/2023', 'A scelta; elimina: Botanica'], ['CHI-1', 'Chimica', '8', 'CHIM/03', '25/30', '10/02/2023', '']]],
            ['etichetta' => 'Dichiaro che quanto indicato corrisponde al vero', 'tipo' => 'dichiarazione', 'valore' => 'Sì'],
        ];
        $id = $this->servizio(ServizioPratiche::class)->crea($m, ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Rossi', 'email' => 'luca@x.it', 'matricola_studente' => '245678'], $risposte);
        $this->mailer->inviate = [];

        return $id;
    }

    public function testInvioConDomandaInPdfAEPassaggioAlProtocollo(): void
    {
        $this->flusso()->preparaFlusso();
        $this->db->esegui("UPDATE didattica_moduli SET attivo = 1 WHERE chiave = 'convalida_esami'");
        $prot = (int) $this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'protocollo'");
        $this->operatore('Anna Protocollo', 'protocollo@x.it', 'pratiche', $prot);
        $m = $this->db->riga("SELECT * FROM didattica_moduli WHERE chiave = 'convalida_esami'");
        $id = $this->servizio(ServizioPratiche::class)->crea($m, ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Rossi', 'email' => 'luca@x.it'], []);
        $p = $this->servizio(ServizioPratiche::class)->pratica($id);
        $this->assertSame([$prot, 1], [$p['ufficio_id'], $p['passo']], 'il primo ufficio dell\'iter è il protocollo');
        $this->assertNotEmpty($p['domanda_pdf']);
        $this->assertStringStartsWith($this->cartella . 'domanda_', $p['domanda_pdf']);
        $file = dirname(__DIR__, 3) . '/' . $p['domanda_pdf'];
        $pdf = (string) file_get_contents($file);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(hash('sha256', $pdf), $p['domanda_sha']);
        $this->assertStringContainsString('/GTS_PDFA1', $pdf, 'PDF/A');
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . $this->cartella . '.htaccess', 'cartella bloccata al web');
        $ev = $this->servizio(PraticaRepository::class)->eventi($id, true);
        $this->assertSame(['Pratica inviata', 'Domanda in PDF/A pronta per il protocollo (impronta SHA-256 del file: ' . $p['domanda_sha'] . ').', 'In carico a: Ufficio protocollo (da protocollare)'], array_column($ev, 'testo'));
        $this->assertSame(['Domanda_' . $p['codice'] . '.pdf'], array_values(array_filter(array_column($ev, 'nome_allegato'))));
        $this->assertSame((int) $ev[1]['id'], $this->servizio(DomandaPdfa::class)->eventoDomanda($p));
        $this->assertContains('protocollo@x.it', array_column($this->mailer->inviate, 'a'), 'la pratica avvisa le persone dell\'ufficio protocollo');
    }

    public function testProtocolloBolloEPassaggioAllUfficioDelCorso(): void
    {
        $id = $this->pratica();
        $f = $this->flusso();
        $p = $this->servizio(ServizioPratiche::class)->pratica($id);
        $this->assertSame(['Pratica non trovata.', ''], $f->registraProtocollo(999, '1', '2026-10-01', 1));
        $this->assertSame(["Indica il numero e la data del protocollo (non nel futuro).", ''], $f->registraProtocollo($id, '', '', 1));
        $this->assertSame(["Indica il numero e la data del protocollo (non nel futuro).", ''], $f->registraProtocollo($id, '1', '2026-10-30', 1));
        [$err, $msg] = $f->registraProtocollo($id, '0001/2026', '2026-10-05', 1, 'Anna');
        $this->assertNull($err);
        $this->assertStringContainsString('marca da bollo', $msg);
        $this->assertSame('0001/2026', $this->servizio(ServizioPratiche::class)->pratica($id)['protocollo']);
        $this->assertSame((int) $p['ufficio_id'], (int) $this->servizio(ServizioPratiche::class)->pratica($id)['ufficio_id'], 'senza bollo resta al protocollo');
        [$err, $msg] = $f->registraProtocollo($id, '', '', 1, 'Anna', true);
        $this->assertSame([null, 'Protocollo registrato: la pratica è passata a «Ufficio CdS Biologia, Scienze e Tecnologie Biologiche, Health Biotechnology».'], [$err, $msg]);
        $p = $this->servizio(ServizioPratiche::class)->pratica($id);
        $maria = (int) $this->db->valore("SELECT id FROM ufficio_didattica WHERE email = 'maria.verdi@x.it'");
        $this->assertSame([$maria, 2, 'in_lavorazione', 'Anna'], [$p['assegnata_a'], $p['passo'], $p['stato'], $p['bollo_da']], 'la persona che segue il corso della pratica');
        $this->assertSame($p['ufficio_id'], $p['cdl_id']);
        $this->assertEqualsCanonicalizing(['maria.verdi@x.it', 'paolo@x.it', 'luca@x.it'], array_column($this->mailer->inviate, 'a'), 'le persone dell\'ufficio e lo studente');
        $this->assertSame([null, 'Dati del protocollo aggiornati.'], $f->registraProtocollo($id, '0002/2026', '2026-10-05', 1));
    }

    public function testCorsoSenzaUfficioVaAssegnatoAMano(): void
    {
        $id = $this->pratica();
        $this->db->esegui("UPDATE pratiche SET risposte_json = '[]' WHERE id = ?", [$id]);
        [$err, $msg] = $this->flusso()->registraProtocollo($id, '5/2026', '2026-10-05', 1, 'Anna', true);
        $this->assertNull($err);
        $this->assertStringContainsString('Nessun ufficio di corso di studio ha il corso «—»', $msg);
        $ev = $this->servizio(PraticaRepository::class)->eventi($id, true);
        $this->assertStringNotContainsString('Nessun ufficio per il corso', implode('|', array_column($ev, 'testo')), 'la nota è interna');
    }

    public function testInvioAllaSegreteria(): void
    {
        $id = $this->pratica();
        $seg = $this->ufficio('Segreteria studenti', 'segreteria');
        $this->operatore('Gianni Segreteria', 'gianni@x.it', 'pratiche', $seg);
        $f = $this->flusso();
        $this->assertSame([0, ['Scegli una segreteria studenti.']], $f->inviaPraticheSegreteria([$id], 999, 1));
        [$n, $escluse] = $f->inviaPraticheSegreteria([$id, 999], $seg, 1, 'Maria');
        $this->assertSame(0, $n);
        $this->assertStringContainsString('non ancora esaminata in seduta', $escluse[0]);
        $this->db->esegui("INSERT INTO didattica_sedute (id, data, organo) VALUES (7, '2026-10-02', 'Consiglio di Biologia')");
        $this->db->esegui("UPDATE pratiche SET seduta_id = 7, esito_seduta = 'approvata' WHERE id = ?", [$id]);
        [$n, $escluse] = $f->inviaPraticheSegreteria([$id], $seg, 1, 'Maria');
        $this->assertSame([1, []], [$n, $escluse]);
        $p = $this->servizio(ServizioPratiche::class)->pratica($id);
        $this->assertSame([$seg, $seg], [$p['ufficio_id'], $p['segreteria_id']]);
        $this->assertNotNull($p['inviata_segreteria_il']);
        $a = array_column($this->mailer->inviate, 'a');
        $this->assertSame(['gianni@x.it', 'luca@x.it'], array_values(array_intersect(['gianni@x.it', 'luca@x.it'], $a)));
        $riepilogo = array_values(array_filter($this->mailer->inviate, fn ($m) => str_contains($m['oggetto'], 'pratiche lavorate')));
        $this->assertCount(1, $riepilogo, 'una sola email di riepilogo alla segreteria');
        $this->assertSame('1 pratiche lavorate per Segreteria studenti', $riepilogo[0]['oggetto']);
        $this->assertStringContainsString('Approvata', $riepilogo[0]['corpo']);
        $this->assertStringContainsString('02/10/2026', $riepilogo[0]['corpo']);
    }

    public function testDomandaEFrasiDelPiano(): void
    {
        $d = $this->servizio(DomandaPdfa::class);
        $this->assertSame(['pdf' => true, 'bollo' => false, 'oggetto' => 'Titolo', 'destinatario' => '', 'chiede' => ''], $d->config(['titolo' => 'Titolo', 'domanda_json' => '{"pdf":1}']));
        $this->assertSame(['pdf' => false, 'bollo' => false, 'oggetto' => '', 'destinatario' => '', 'chiede' => ''], $d->config(null));
        $this->assertSame('Università di Messina', $d->ateneoPrecedente(['risposte_json' => json_encode([['etichetta' => 'Ateneo della carriera precedente', 'valore' => 'Altro Ateneo'], ['etichetta' => "Denominazione dell'Ateneo", 'valore' => ' Università di Messina ']])]));
        $this->assertSame('Università della Calabria', $d->ateneoPrecedente(['risposte_json' => json_encode([['etichetta' => 'Ateneo della carriera precedente', 'valore' => 'Università della Calabria'], ['etichetta' => "Denominazione dell'Ateneo", 'valore' => '', 'nascosto' => true]])]));
        $righe = [['piano' => 1, 'richiesto' => 'Zoologia', 'esito' => 'totale', 'ins' => 'Zoologia generale', 'elimina' => ' Botanica '], ['richiesto' => 'Altro'], ['piano' => 1, 'richiesto' => 'Chimica', 'esito' => 'no', 'ins' => 'Chimica 2']];
        $this->assertSame([
            "Lo/La studente/ssa chiede che l'insegnamento «Zoologia generale» sia inserito nel piano di studi come insegnamento a scelta, eliminando dal piano l'insegnamento «Botanica».",
            "Lo/La studente/ssa chiede che l'insegnamento «Chimica» sia inserito nel piano di studi come insegnamento a scelta.",
        ], $d->frasiPiano($righe));
        $this->assertSame([], $d->frasiPianoDecisioni(['tipo' => 'piano', 'righe' => $righe]));
        $this->assertCount(2, $d->frasiPianoDecisioni(['tipo' => 'convalide', 'righe' => $righe]));
        $this->assertStringStartsWith('Chiede inoltre', $d->frasiPiano($righe, 'Chiede inoltre')[0]);
    }

    public function testEditorDelleDecisioni(): void
    {
        $e = $this->servizio(EditorDecisioni::class);
        $html = $e->editor('piano', ['righe' => [['richiesto' => 'Fisica <1>', 'cfu' => '6', 'data' => '', 'esito' => 'fuori_piano']]], []);
        $this->assertStringContainsString('value="Fisica &lt;1&gt;"', $html);
        $this->assertStringContainsString('<option value="fuori_piano" selected>Approvato fuori piano</option>', $html);
        $this->assertSame(2, substr_count($html, 'name="d_richiesto[]"'), 'la riga e una vuota da compilare');
        $html = $e->editor('convalide', ['righe' => []], []);
        $this->assertStringContainsString('Convalida totale', $html);
        $this->assertStringContainsString('name="d_cfu_ric[]"', $html);
        $s = $e->supporto([['nome' => 'Zoologia', 'corso' => 'Biologia', 'cfu' => 9, 'id' => 1, 'partizione' => '', 'ssd' => 'BIO/05']]);
        $this->assertStringContainsString('<datalist id="dlInsDip">', $s);
        $this->assertStringContainsString('decisioni-pratica.js', $s);
        $this->assertSame('RSSLCU02A01D086X', EditorDecisioni::valoreIniziale(['etichetta' => 'Codice fiscale', 'tipo' => 'codice_fiscale'], ['codice_fiscale' => 'rsslcu02a01d086x']));
        $this->assertSame('245678', EditorDecisioni::valoreIniziale(['etichetta' => 'Matricola', 'tipo' => 'text'], ['matricola_studente' => '245678']));
        $this->assertSame('333', EditorDecisioni::valoreIniziale(['etichetta' => 'Cellulare', 'tipo' => 'tel'], ['telefono' => '333']));
        $this->assertSame('', EditorDecisioni::valoreIniziale(['etichetta' => 'Altro', 'tipo' => 'text'], null));
    }
}
