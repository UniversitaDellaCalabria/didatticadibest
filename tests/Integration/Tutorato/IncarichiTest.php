<?php

declare(strict_types=1);

namespace Tests\Integration\Tutorato;

use App\Tutorato\ArchivioIncarichi;
use App\Tutorato\DocumentiIncarico;
use App\Tutorato\ServizioIncarichi;

/** Bandi, lettere di incarico e iter di firma: studente (SPID/CIE) → docente → direttore → protocollo. */
final class IncarichiTest extends TutoratoBase
{
    private function srv(): ServizioIncarichi
    {
        return $this->servizio(ServizioIncarichi::class);
    }

    /** Porta una lettera nuova fino allo stato indicato e ritorna il suo id. */
    private function fino(string $stato): int
    {
        $this->bando();
        [$id] = $this->srv()->salva($this->campi());
        $utente = ['id' => 7, 'codice_fiscale' => 'rsslcu00a31d086x'];
        if ($stato === 'bozza') {
            return $id;
        }
        $this->srv()->invia($id);
        if ($stato === 'inviata') {
            return $id;
        }
        $this->assertNull($this->srv()->conferma($id, $utente, ['metodo' => 'spid', 'livello' => 2, 'idp' => 'https://idp.test', 'spid_code' => 'ABC123']));
        if ($stato === 'confermata') {
            return $id;
        }
        $pdf = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->pdfCorrente($this->lettera($id)));
        $this->assertNull($this->srv()->registraFirma($id, 'docente', $this->firmato($pdf)));
        if ($stato === 'firmata_docente') {
            return $id;
        }
        $pdf = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->pdfCorrente($this->lettera($id)));
        $this->assertNull($this->srv()->registraFirma($id, 'direttore', $this->firmato($pdf)));
        $this->mailer->inviate = [];

        return $id;
    }

    public function testBandoSalvaEControllaIDati(): void
    {
        $s = $this->srv();
        $this->assertSame([0, 'Scrivi il titolo del bando.'], $s->salvaBando(['titolo' => ' '], 1));
        $this->assertSame([0, 'Indica il decreto del bando (D.D. n.).'], $s->salvaBando(['titolo' => 'B'], 1));
        $this->assertSame([0, "Scegli il direttore dall'anagrafe o scrivi nome ed email."], $s->salvaBando(['titolo' => 'B', 'decreto_bando' => '1/26'], 1));
        $this->assertSame([0, 'Codice fiscale del direttore non valido.'], $s->salvaBando(['titolo' => 'B', 'decreto_bando' => '1/26', 'direttore_nome' => 'D', 'direttore_email' => 'd@x.it', 'direttore_cf' => 'ABC'], 1));

        [$id, $err] = $s->salvaBando(['titolo' => ' Bando II ', 'anno_accademico' => '2026/2027', 'decreto_bando' => '5/26', 'decreto_bando_data' => '2026-09-10', 'decreto_commissione_data' => 'boh',
            'direttore_nome' => 'Dir Nuovo', 'direttore_email' => 'dir@x.it', 'direttore_cf' => 'bncmra60a01d086x', 'operatore_id' => '0'], 9);
        $this->assertNull($err);
        $b = $this->db->riga('SELECT * FROM tutorato_bandi WHERE id = ?', [$id]);
        $this->assertSame(['Bando II', 'BNCMRA60A01D086X', 'Rende', null, '2026-09-10', null, 9], [$b['titolo'], $b['direttore_cf'], $b['luogo'], $b['operatore_id'], $b['decreto_bando_data'], $b['decreto_commissione_data'], $b['creato_da']]);

        // Direttore dall'anagrafe: nome ed email della scheda; con un indirizzo non valido restano quelli scritti; persona sconosciuta ignorata
        $this->assertSame([$id, null], $s->salvaBando(['id' => $id, 'titolo' => 'Bando II', 'decreto_bando' => '5/26', 'direttore_persona_id' => 'm.verdi', 'direttore_nome' => 'x', 'direttore_email' => 'x@y.it', 'operatore_id' => 4, 'luogo' => 'Cosenza'], 9));
        $b = $this->db->riga('SELECT * FROM tutorato_bandi WHERE id = ?', [$id]);
        $this->assertSame(['Maria Verdi', 'maria.verdi@x.it', 'm.verdi', 4, 'Cosenza', 9], [$b['direttore_nome'], $b['direttore_email'], $b['direttore_persona_id'], $b['operatore_id'], $b['luogo'], $b['creato_da']], "la modifica non cambia chi l'ha creato");
        $s->salvaBando(['id' => $id, 'titolo' => 'Bando II', 'decreto_bando' => '5/26', 'direttore_persona_id' => 'd.neri', 'direttore_nome' => 'Scritto', 'direttore_email' => 'scritto@x.it'], 9);
        $this->assertSame(['Dario Neri', 'scritto@x.it'], [$this->db->valore('SELECT direttore_nome FROM tutorato_bandi WHERE id = ?', [$id]), $this->db->valore('SELECT direttore_email FROM tutorato_bandi WHERE id = ?', [$id])]);
        $s->salvaBando(['id' => $id, 'titolo' => 'Bando II', 'decreto_bando' => '5/26', 'direttore_persona_id' => 'sconosciuta', 'direttore_nome' => 'Scritto', 'direttore_email' => 'scritto@x.it'], 9);
        $this->assertNull($this->db->valore('SELECT direttore_persona_id FROM tutorato_bandi WHERE id = ?', [$id]));
        $this->assertCount(1, $s->bandiElenco());
        $this->assertSame('0', $s->bandiElenco()[0]['n_incarichi']);
        $this->assertNull($s->bando(99));
    }

    public function testLetteraControlloDeiDati(): void
    {
        $this->bando();
        $s = $this->srv();
        $this->assertSame([0, 'Bando non trovato.'], $s->salva($this->campi(['bando_id' => 9])));
        [$id, $err] = $s->salva(['bando_id' => 1]);
        $this->assertSame(0, $id);
        $this->assertSame("Controlla: cognome e nome del vincitore, codice fiscale del vincitore (16 caratteri), email del vincitore, attività da svolgere, numero di ore, periodo, compenso, docente responsabile (dall'anagrafe o con nome, cognome ed email).", $err);
        $this->assertSame([0, 'Controlla: codice fiscale del docente.'], $s->salva($this->campi(['docente_persona_id' => '', 'docente_nome' => 'G', 'docente_cognome' => 'N', 'docente_email' => 'g@x.it', 'docente_cf' => 'XYZ'])));
        $this->assertSame([0, 'Controlla: numero di ore.'], $s->salva($this->campi(['ore' => '0'])));
        $this->assertSame([0, 'Controlla: compenso.'], $s->salva($this->campi(['compenso' => '-5'])));
    }

    public function testLetteraNuovaModificaEDuplicazione(): void
    {
        $this->bando();
        $s = $this->srv();
        [$id, $err] = $s->salva($this->campi(['ore' => '35,5', 'telefono' => ' 333  444 ']));
        $this->assertNull($err);
        $l = $this->lettera($id);
        $this->assertSame(['bozza', 'RSSLCU00A31D086X', 'luca@x.it', '333 444', '35.5', '1200.50', 'Maria', 'Verdi', 'maria.verdi@x.it'], [$l['stato'], $l['codice_fiscale'], $l['email'], $l['telefono'], $l['ore'], $l['compenso'], $l['docente_nome'], $l['docente_cognome'], $l['docente_email']]);
        $this->assertMatchesRegularExpression('/^TU-[0-9A-F]{8}$/', $l['codice']);
        $this->assertSame("Tutorato di Chimica\nseconda riga", $l['attivita'], 'le attività vanno a capo');
        $this->assertSame([['creata', 'Lettera preparata', '10.0.0.1']], array_map(static fn (array $e): array => [$e['tipo'], $e['testo'], $e['ip']], $s->eventi($id)));

        $this->assertSame([$id, null], $s->salva($this->campi(['id' => $id, 'genere' => 'F', 'nome' => 'Luisa'])));
        $l = $this->lettera($id);
        $this->assertSame(['F', 'Luisa'], [$l['genere'], $l['nome']]);
        $this->assertCount(1, $s->eventi($id), 'la modifica non scrive un nuovo evento');

        $copia = $s->duplica($id);
        $this->assertGreaterThan($id, $copia);
        $this->assertSame($l['cognome'], $this->lettera($copia)['cognome']);
        $this->assertNotSame($l['codice'], $this->lettera($copia)['codice']);
        $this->assertSame(0, $s->duplica(999));
        $this->assertCount(2, $s->delBando(1));

        $s->invia($id);
        $this->assertSame([$id, 'La lettera è già stata inviata: per cambiarla annullala e preparane una nuova.'], $s->salva($this->campi(['id' => $id])));
    }

    public function testIterConEmailAdOgniPassaggio(): void
    {
        $this->bando();
        $s = $this->srv();
        [$id] = $s->salva($this->campi(['docente_persona_id' => '', 'docente_nome' => 'Maria', 'docente_cognome' => 'Verdi', 'docente_email' => 'maria@x.it']));

        $this->assertSame('Lettera non trovata.', $s->invia(99));
        $this->assertNull($s->invia($id, 'Admin'));
        $l = $this->lettera($id);
        $this->assertSame('inviata', $l['stato']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $l['token_studente']);
        $this->assertSame('2026-10-03', $l['data_lettera']);
        $m = $this->mailer->inviate[0];
        $this->assertSame(['luca@x.it', 'Lettera di incarico di tutorato: controlla e conferma', '#047857'], [$m['a'], $m['oggetto'], $m['colore']]);
        $this->assertStringContainsString("href='https://portale.test/eventi/incarico.php?t=" . $l['token_studente'] . "'", $m['corpo']);
        $this->assertStringContainsString('Il link è personale: non inoltrarlo.', $m['corpo']);
        $this->assertNull($s->invia($id, 'Admin'), 'si può rimandare finché lo studente non conferma');
        $this->assertSame($l['token_studente'], $this->lettera($id)['token_studente'], 'il link resta lo stesso');
        $this->assertSame('Email di nuovo allo studente: luca@x.it', $s->eventi($id)[2]['testo']);

        // Chi può confermare: la persona della lettera, con SPID o CIE
        $altro = ['id' => 5, 'codice_fiscale' => 'ZZZZZZ00A00A000A'];
        $this->assertStringStartsWith("La lettera è intestata a un'altra persona", (string) $s->conferma($id, $altro, ['metodo' => 'spid']));
        $this->assertSame("Per confermare serve l'accesso con SPID o CIE: esci e rientra scegliendo SPID o CIE.", $s->conferma($id, ['id' => 7, 'codice_fiscale' => 'RSSLCU00A31D086X'], ['metodo' => 'ateneo']));
        $this->assertSame('La lettera non è in attesa della tua conferma.', $s->conferma(99, $altro, []));

        $this->assertSame('Scrivi cosa non va.', $s->segnala($id, ' '));
        $this->assertNull($s->segnala($id, 'Periodo <errato>'));
        $this->assertSame('Periodo <errato>', $this->lettera($id)['nota_studente']);
        $adm = array_values(array_filter($this->mailer->inviate, static fn (array $e): bool => str_starts_with($e['oggetto'], 'Lettera di incarico: segnalazione')));
        $this->assertSame('admin@x.it', $adm[0]['a'], 'senza operatori la segnalazione va agli amministratori');
        $this->assertStringContainsString('Periodo &lt;errato&gt;', $adm[0]['corpo']);

        $this->mailer->inviate = [];
        $utente = ['id' => 7, 'codice_fiscale' => 'RSSLCU00A31D086X'];
        $this->assertNull($s->conferma($id, $utente, ['metodo' => 'cie', 'livello' => 3, 'idp' => 'https://idp.test', 'spid_code' => 'SC1']));
        $l = $this->lettera($id);
        $this->assertSame('confermata', $l['stato']);
        $firma = json_decode((string) $l['studente_firma_json'], true);
        $this->assertSame(['cie', 3, '10.0.0.1', 'RSSLCU00A31D086X', 7], [$firma['metodo'], $firma['livello'], $firma['ip'], $firma['cf'], $firma['utente_id']]);
        $this->assertSame('2026-10-05 12:00:00', $firma['confermata_il']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $firma['sha256_pdf']);
        $this->assertStringContainsString('(spidCode SC1)', $s->eventi($id)[4]['testo']);
        $this->assertSame('maria@x.it', $this->mailer->inviate[0]['a']);
        $this->assertStringContainsString('/firma_incarico.php?t=' . $l['token_docente'], $this->mailer->inviate[0]['corpo']);
        $pdf = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->pdfCorrente($l));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame('La lettera non è in attesa della tua conferma.', $s->conferma($id, $utente, ['metodo' => 'cie']), 'una sola conferma');

        // Firma del docente: la stessa lettera con una firma in più
        $this->assertSame('La lettera non è in attesa di questa firma.', $s->registraFirma($id, 'direttore', $this->firmato($pdf)));
        $this->assertSame('Il file non è un PDF: la firma deve essere PAdES (PDF firmato), non CAdES (.p7m).', $s->registraFirma($id, 'docente', 'p7m'));
        $this->assertStringStartsWith('Il PDF firmato non corrisponde alla lettera', (string) $s->registraFirma($id, 'docente', '%PDF-1.4 altro'));
        $this->assertSame("Nel PDF non c'è una nuova firma digitale PAdES.", $s->registraFirma($id, 'docente', $pdf . "\nsolo testo"));
        $this->mailer->inviate = [];
        $this->assertNull($s->registraFirma($id, 'docente', $this->firmato($pdf), 'caricamento'));
        $l = $this->lettera($id);
        $this->assertSame('firmata_docente', $l['stato']);
        $this->assertSame('direttore@x.it', $this->mailer->inviate[0]['a']);
        $this->assertStringContainsString('/firma_incarico.php?t=' . $l['token_direttore'], $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('Firmata in PAdES dal docente (caricamento)', $s->eventi($id)[5]['testo']);

        // Firma del direttore: avvisi al tutor e agli amministratori
        $pdf2 = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->pdfCorrente($l));
        $this->mailer->inviate = [];
        $this->assertNull($s->registraFirma($id, 'direttore', $this->firmato($pdf2), 'firma remota Aruba'));
        $this->assertSame('firmata', $this->lettera($id)['stato']);
        $this->assertSame(['luca@x.it', 'admin@x.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertSame('Tutorato: lettera di incarico firmata, registro delle attività aperto', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('Gentile Luca Rossi,', $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('/registro_tutorato.php?id=' . $id, $this->mailer->inviate[0]['corpo']);
        $this->assertSame('Lettera di incarico firmata: da protocollare – Rossi Luca', $this->mailer->inviate[1]['oggetto']);
    }

    public function testProtocolloCopiaAnnullamentoEDestinatari(): void
    {
        $id = $this->fino('firmata');
        $s = $this->srv();
        $this->assertSame('La lettera non ha ancora tutte le firme.', $s->protocolla($this->fino('bozza'), 'X', null, false));
        $this->assertSame('Scrivi il numero di protocollo.', $s->protocolla($id, ' ', null, false));
        $this->assertNull($s->protocolla($id, ' 123/2026 ', 'boh', true, 'Admin'));
        $l = $this->lettera($id);
        $this->assertSame(['protocollata', '123/2026', '2026-10-05'], [$l['stato'], $l['protocollo'], $l['protocollo_data']], 'data non valida: oggi');
        $m = $this->mailer->inviate[0];
        $this->assertSame(['luca@x.it', 'Lettera di incarico di tutorato firmata (prot. 123/2026)'], [$m['a'], $m['oggetto']]);
        $this->assertNull($s->protocolla($id, '124/2026', '2026-10-06', false));
        $this->assertSame('124/2026', $this->lettera($id)['protocollo'], 'il protocollo si può correggere');
        $this->assertSame('copia', $s->eventi($id)[count($s->eventi($id)) - 2]['tipo']);

        $this->assertSame('La lettera non si può annullare.', $s->annulla($id, ''));
        $b = $this->fino('inviata');
        $this->assertNull($s->annulla($b, str_repeat('x', 600), 'Admin'));
        $l = $this->lettera($b);
        $this->assertSame(['annullata', null], [$l['stato'], $l['token_studente']]);
        $this->assertSame(500 + strlen('Annullata: '), strlen($s->eventi($b)[count($s->eventi($b)) - 1]['testo']));
        $this->assertSame('La lettera non si può annullare.', $s->annulla($b, ''));
        $this->assertNull($s->incaricoPerToken('studente', str_repeat('a', 40)));
        $this->assertNull($s->incaricoPerToken('boh', str_repeat('a', 40)));
        $this->assertNull($s->incaricoPerToken('studente', 'corto'));
    }

    public function testChiRiceveLeLettereFirmate(): void
    {
        $this->operatori->operatori = [['id' => 4, 'email' => 'scelto@x.it', 'compiti' => 'pratiche'], ['id' => 5, 'email' => 'bandi@x.it', 'compiti' => 'bandi,pratiche']];
        $this->bando(4);
        $n = $this->servizio(\App\Tutorato\NotificheIncarichi::class);
        $this->assertSame(['scelto@x.it'], $n->emailOperatori(['operatore_id' => 4]));
        $this->assertSame(['bandi@x.it'], $n->emailOperatori(['operatore_id' => null]), 'senza operatore nel bando: chi ha il compito «bandi»');
        $this->assertSame(['bandi@x.it'], $n->emailOperatori(['operatore_id' => 99]));
        $this->operatori->operatori = [];
        $this->assertSame(['admin@x.it'], $n->emailOperatori(['operatore_id' => null]), 'altrimenti gli amministratori');
    }

    public function testChiPuoFirmare(): void
    {
        $s = $this->srv();
        $i = ['docente_email' => 'Maria@X.it', 'docente_cf' => 'DCNMRA75B41D086X', 'docente_persona_id' => 'm.verdi', 'direttore_email' => 'direttore@x.it', 'direttore_cf' => '', 'direttore_persona_id' => null];
        $this->assertTrue($s->firmatario($i, 'docente', ['id' => 1, 'email' => ' maria@x.it ']));
        $this->assertTrue($s->firmatario($i, 'docente', ['id' => 1, 'email' => 'altra@x.it', 'codice_fiscale' => 'dcnmra75b41d086x']));
        $this->assertTrue($s->firmatario($i, 'docente', ['id' => 1, 'persona_id' => 'm.verdi']));
        $this->assertFalse($s->firmatario($i, 'docente', ['id' => 1, 'email' => 'direttore@x.it']));
        $this->assertTrue($s->firmatario($i, 'direttore', ['id' => 1, 'email' => 'direttore@x.it']));
        $this->assertFalse($s->firmatario($i, 'direttore', ['id' => 1, 'email' => '', 'codice_fiscale' => '', 'persona_id' => '']), 'dati vuoti non coincidono con dati vuoti');
        $this->assertFalse($s->firmatario($i, 'docente', null));
        $this->assertFalse($s->firmatario($i, 'docente', ['email' => 'maria@x.it']), 'senza id non è un utente');
    }

    public function testDocumentiDellaLettera(): void
    {
        $id = $this->fino('inviata');
        $d = $this->servizio(DocumentiIncarico::class);
        [$pdf, $spazi] = $d->pdfLettera($this->lettera($id));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(['docente', 'studente', 'direttore'], array_keys($spazi));
        [$firmato] = $d->pdfLettera($this->lettera($id), ['metodo' => 'spid', 'livello' => 2, 'idp' => 'idp', 'contesto' => '', 'spid_code' => '', 'confermata_il' => '2026-10-05 12:00:00', 'istante' => '2026-10-05 11:59:00', 'ip' => '', 'impronta' => 'abc']);
        $this->assertGreaterThan(strlen($pdf), strlen($firmato), 'con la conferma dello studente si aggiunge il riquadro');
        $docx = $d->docx($this->lettera($id));
        $this->assertNotNull($docx);
        $z = new \ZipArchive();
        $this->assertTrue($z->open((string) $docx));
        $xml = (string) $z->getFromName('word/document.xml');
        $z->close();
        @unlink((string) $docx);
        $this->assertStringContainsString('Luca Rossi', $xml);
        $this->assertStringNotContainsString('{{NOMINATIVO}}', $xml);
        $this->assertStringContainsString('</w:t><w:br/><w:t xml:space="preserve">seconda riga', $xml, 'a capo di Word');
    }
}
