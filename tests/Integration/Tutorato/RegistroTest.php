<?php

declare(strict_types=1);

namespace Tests\Integration\Tutorato;

use App\Tutorato\ArchivioIncarichi;
use App\Tutorato\ServizioIncarichi;
use App\Tutorato\ServizioRegistroTutorato;

/** Registro delle attività, fine attività, promemoria e solleciti del cron, conservazione dei dati. */
final class RegistroTest extends TutoratoBase
{
    private function reg(): ServizioRegistroTutorato
    {
        return $this->servizio(ServizioRegistroTutorato::class);
    }

    /** Una lettera firmata da tutti (il registro è aperto), con periodo e ore da 10; ritorna l'id. */
    private function firmata(array $campi = []): int
    {
        if ($this->db->valore('SELECT COUNT(*) FROM tutorato_bandi WHERE id = 1') === 0) {
            $this->bando();
        }
        $s = $this->servizio(ServizioIncarichi::class);
        [$id] = $s->salva($this->campi($campi));
        $s->invia($id);
        $s->conferma($id, ['id' => 7, 'codice_fiscale' => strtoupper((string) ($campi['codice_fiscale'] ?? 'RSSLCU00A31D086X'))], ['metodo' => 'spid']);
        foreach (['docente', 'direttore'] as $ruolo) {
            $pdf = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->pdfCorrente($this->lettera($id)));
            $this->assertNull($s->registraFirma($id, $ruolo, $this->firmato($pdf)));
        }
        $this->reg()->salvaDatiFine($id, ['docente_titolo' => 'Prof.ssa', 'insegnamento_docente' => 'Chimica', 'corso_laurea' => 'Biologia', 'data_inizio' => '2026-08-24', 'data_fine' => '2026-10-31']);
        $this->mailer->inviate = [];

        return $id;
    }

    public function testDatiDellaFineAttivita(): void
    {
        $id = $this->firmata();
        $r = $this->reg();
        $l = $this->lettera($id);
        $this->assertSame(['Prof.ssa', 'Chimica', 'Biologia', '2026-08-24', '2026-10-31'], [$l['docente_titolo'], $l['insegnamento_docente'], $l['corso_laurea'], $l['data_inizio'], $l['data_fine']]);
        $this->assertSame("La fine del periodo è prima dell'inizio.", $r->salvaDatiFine($id, ['data_inizio' => '2026-09-01', 'data_fine' => '2026-08-01']));
        $this->assertNull($r->salvaDatiFine($id, ['docente_titolo' => 'Dr.', 'data_inizio' => 'boh']));
        $l = $this->lettera($id);
        $this->assertSame(['Prof.', null, null], [$l['docente_titolo'], $l['data_inizio'], $l['data_fine']], 'titolo non ammesso: Prof.; date non valide: vuote');
        $this->assertSame('La dichiarazione di fine attività è già stata preparata.', $r->salvaDatiFine(999, []));
    }

    public function testTutorSegnaLeAttivita(): void
    {
        $id = $this->firmata();
        $r = $this->reg();
        $this->assertSame('Indica la data (non nel futuro).', $r->aggiungi($id, '2026-10-06', 2, 'x'));
        $this->assertSame('Indica la data (non nel futuro).', $r->aggiungi($id, 'ieri', 2, 'x'));
        $this->assertSame("La data è prima dell'inizio dell'incarico (24/08/2026).", $r->aggiungi($id, '2026-08-23', 2, 'x'));
        foreach (['0', '12,5', '13', '1,3', 'abc'] as $ore) {
            $this->assertSame("Indica le ore (da 0,5 a 12, a mezz'ore).", $r->aggiungi($id, '2026-09-01', $ore, 'x'), "ore $ore");
        }
        $this->assertSame("Scrivi l'attività svolta.", $r->aggiungi($id, '2026-09-01', 2, '   '));
        $this->assertNull($r->aggiungi($id, '2026-09-01', '2,5', ' Esercitazione <b>1</b> '));
        $this->assertNull($r->aggiungi($id, '2026-09-02', '7,5', 'Lab'));
        $this->assertSame('Con queste ore superi le 10 ore dell\'incarico (già segnate: 10).', $r->aggiungi($id, '2026-09-03', '0,5', 'Altro'));
        $this->assertSame('Il registro non è aperto per questo incarico.', $r->aggiungi(999, '2026-09-03', 1, 'x'));
        $righe = $r->righe($id);
        $this->assertSame(['Esercitazione <b>1</b>', '2.5', 'inviata'], [$righe[0]['attivita'], $righe[0]['ore'], $righe[0]['stato']]);
        $this->assertSame(['inviata' => 10.0, 'approvata' => 0.0, 'respinta' => 0.0, 'totale' => 10.0], ServizioRegistroTutorato::ore($righe));

        $this->assertNull($r->togli($id, (int) $righe[1]['id']));
        $this->assertSame('Si possono togliere solo le righe non ancora approvate.', $r->togli($id, 9999));
        $this->assertCount(1, $r->righe($id));
        $this->assertSame('Il registro non è aperto.', $r->togli($this->servizio(ServizioIncarichi::class)->duplica($id), 1));
    }

    public function testDocenteApprovaERespingeEConfermaLaFine(): void
    {
        $id = $this->firmata();
        $r = $this->reg();
        $r->aggiungi($id, '2026-09-01', 4, 'A');
        $r->aggiungi($id, '2026-09-02', 3, 'B');
        $r->aggiungi($id, '2026-09-03', 1, 'C');
        [$a, $b, $c] = array_map(static fn (array $x): int => (int) $x['id'], $r->righe($id));

        $this->assertSame('Prima segna nel registro le attività svolte.', $r->richiediFine($this->firmata(['codice_fiscale' => 'bncmra60a01d086x', 'email' => 'altro@x.it'])));
        $this->assertSame([null, 'Ci sono ancora 8 ore da approvare o respingere.'], $r->confermaFine($id));
        $this->assertSame(1, $r->decidi($id, 'respinta', [$c], str_repeat('n', 600)));
        $this->assertSame(strlen(str_repeat('n', 500)), strlen((string) $this->db->valore('SELECT nota_docente FROM tutorato_registro WHERE id = ?', [$c])), 'nota ridotta a 500 caratteri');
        $this->assertSame(0, $r->decidi($id, 'approvata', [$c]), 'una riga già decisa non cambia');
        $this->assertSame(2, $r->decidi($id, 'qualsiasi'), 'senza indicazioni: tutte quelle da approvare, come «approvata»');
        $this->assertSame('approvata', $r->righe($id)[0]['stato']);

        $this->mailer->inviate = [];
        $this->assertNull($r->richiediFine($id));
        $this->assertSame('richiesta', $this->lettera($id)['fine_stato']);
        $m = $this->mailer->inviate[0];
        $this->assertSame(['maria.verdi@x.it', 'Tutorato: fine attività di Rossi Luca'], [$m['a'], $m['oggetto']]);
        $this->assertStringContainsString('Gentile Prof.ssa Maria Verdi,', $m['corpo']);
        $this->assertStringContainsString('Ore nel registro: <strong>7</strong> su 10.', $m['corpo']);
        $this->assertSame('Le attività non si possono dichiarare concluse ora.', $r->richiediFine($id));

        [$tok, $err] = $r->confermaFine($id, 'Maria Verdi');
        $this->assertNull($err);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', (string) $tok);
        $l = $this->lettera($id);
        $this->assertSame(['da_firmare', '7.0', $tok], [$l['fine_stato'], $l['ore_approvate'], $l['token_fine']]);
        $fine = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->fineCorrente($l));
        $this->assertStringStartsWith('%PDF-', $fine);
        $this->assertSame([null, 'La fine delle attività non si può confermare ora.'], $r->confermaFine($id), 'registro chiuso dopo la conferma');
        $this->assertFalse(ServizioRegistroTutorato::aperto($l));

        // Firma del docente sulla dichiarazione, poi protocollo
        $this->assertSame('La dichiarazione non è in attesa di firma.', $r->registraFirmaFine(999, $this->firmato($fine)));
        $this->assertSame("Nel PDF non c'è una nuova firma digitale PAdES.", $r->registraFirmaFine($id, $fine . "\ntesto"));
        $this->mailer->inviate = [];
        $this->assertNull($r->registraFirmaFine($id, $this->firmato($fine), 'caricamento'));
        $this->assertSame('firmata', $this->lettera($id)['fine_stato']);
        $this->assertSame(['admin@x.it', 'luca@x.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertSame('Attività completate: Rossi Luca – 7 ore', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('<td style=\'padding:3px 8px;text-align:center;\'>4</td>', $this->mailer->inviate[0]['corpo'], 'riepilogo delle ore approvate');
        $this->assertStringNotContainsString('>1</td>', $this->mailer->inviate[0]['corpo'], 'la riga respinta non compare');
        $this->assertSame('Tutorato: attività completate', $this->mailer->inviate[1]['oggetto']);

        $this->assertSame('Scrivi il numero di protocollo.', $r->protocollaFine($id, ' '));
        $this->assertNull($r->protocollaFine($id, 'F-1/2026', 'Admin'));
        $this->assertSame(['protocollata', 'F-1/2026'], [$this->lettera($id)['fine_stato'], $this->lettera($id)['fine_protocollo']]);
        $this->assertSame('La dichiarazione non è ancora firmata.', $r->protocollaFine($this->firmata(['codice_fiscale' => 'bncmra60a01d086x', 'email' => 'altro@x.it']), 'X'));
    }

    public function testIncarichiVisibiliNelRegistro(): void
    {
        $id = $this->firmata();
        $r = $this->reg();
        $this->assertSame([], $r->incarichiRegistro(null));
        $this->assertSame([], $r->incarichiRegistro(['email' => 'luca@x.it']));
        $tutor = $r->incarichiRegistro(['id' => 7, 'codice_fiscale' => 'rsslcu00a31d086x']);
        $this->assertSame([$id, 'tutor'], [(int) $tutor[0]['id'], $tutor[0]['_ruolo']]);
        $docente = $r->incarichiRegistro(['id' => 8, 'email' => 'maria.verdi@x.it', 'codice_fiscale' => '']);
        $this->assertSame('docente', $docente[0]['_ruolo']);
        $this->assertSame([], $r->incarichiRegistro(['id' => 9, 'email' => 'nessuno@x.it', 'codice_fiscale' => 'ZZZZZZ00A00A000A']));
    }

    public function testPromemoriaDelCron(): void
    {
        $id = $this->firmata();
        $r = $this->reg();
        // Periodo che finisce il 31/10: da 7 giorni prima il tutor riceve il promemoria di chiusura (una volta a settimana)
        $this->db->esegui("UPDATE tutorato_incarichi SET data_fine = '2026-10-08' WHERE id = ?", [$id]);
        $this->assertSame(1, $r->promemoria());
        $m = $this->mailer->inviate[0];
        $this->assertSame(['luca@x.it', 'Tutorato: registro delle attività'], [$m['a'], $m['oggetto']]);
        $this->assertStringContainsString("il periodo dell'incarico termina il 08/10/2026", $m['corpo']);
        $this->assertStringContainsString('Ore segnate: 0 su 10.', $m['corpo']);
        $this->assertSame(0, $r->promemoria(), 'ancora nello stesso giorno: niente di nuovo');

        $this->db->esegui("UPDATE tutorato_incarichi SET data_fine = '2026-10-01', promemoria_tutor_il = NOW() - INTERVAL 8 DAY WHERE id = ?", [$id]);
        $this->mailer->inviate = [];
        $this->assertSame(1, $r->promemoria());
        $this->assertStringContainsString("il periodo dell'incarico è terminato il 01/10/2026", $this->mailer->inviate[0]['corpo']);

        // Docente: ore da approvare da più di 7 giorni
        $r->aggiungi($id, '2026-09-10', 2, 'A');
        $this->db->esegui('UPDATE tutorato_registro SET creata_il = NOW() - INTERVAL 10 DAY WHERE incarico_id = ?', [$id]);
        $this->mailer->inviate = [];
        $this->assertSame(1, $r->promemoria());
        $this->assertSame(['maria.verdi@x.it', 'Tutorato: registro di Rossi Luca da controllare'], [$this->mailer->inviate[0]['a'], $this->mailer->inviate[0]['oggetto']]);
        $this->assertStringContainsString('ci sono ore da approvare.', $this->mailer->inviate[0]['corpo']);
        $this->assertSame(0, $r->promemoria());
    }

    public function testSollecitiDelleFirmeFermeEFermaDaTreVolte(): void
    {
        $this->bando();
        $s = $this->servizio(ServizioIncarichi::class);
        [$id] = $s->salva($this->campi());
        $s->invia($id);
        $s->conferma($id, ['id' => 7, 'codice_fiscale' => strtoupper((string) ($campi['codice_fiscale'] ?? 'RSSLCU00A31D086X'))], ['metodo' => 'spid']);
        $r = $this->reg();
        $this->mailer->inviate = [];
        $this->assertSame(0, $r->promemoria(), 'firma del docente richiesta da poco');

        $this->db->esegui('UPDATE tutorato_incarichi SET confermata_il = NOW() - INTERVAL 6 DAY WHERE id = ?', [$id]);
        $this->assertSame(1, $r->promemoria());
        $m = $this->mailer->inviate[0];
        $this->assertSame(['maria.verdi@x.it', 'Sollecito: la lettera di incarico di Rossi Luca è in attesa della sua firma'], [$m['a'], $m['oggetto']]);
        $this->assertStringContainsString('/firma_incarico.php?t=' . $this->lettera($id)['token_docente'], $m['corpo']);
        $this->assertSame('1', $this->lettera($id)['solleciti']);
        $this->assertSame('sollecito', array_values(array_filter($s->eventi($id), static fn (array $e): bool => $e['tipo'] === 'sollecito'))[0]['tipo']);

        // Al terzo sollecito lo sa anche l'operatore; poi basta
        $this->db->esegui('UPDATE tutorato_incarichi SET solleciti = 2, sollecito_il = NOW() - INTERVAL 6 DAY WHERE id = ?', [$id]);
        $this->mailer->inviate = [];
        $this->assertSame(1, $r->promemoria());
        $this->assertSame(['maria.verdi@x.it', 'admin@x.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertSame('Firma ferma: la lettera di incarico di Rossi Luca', $this->mailer->inviate[1]['oggetto']);
        $this->db->esegui('UPDATE tutorato_incarichi SET sollecito_il = NOW() - INTERVAL 6 DAY WHERE id = ?', [$id]);
        $this->mailer->inviate = [];
        $this->assertSame(0, $r->promemoria(), 'massimo tre solleciti');
    }

    public function testConservazioneDeiDatiPersonali(): void
    {
        $id = $this->firmata();
        $r = $this->reg();
        $pdf = $this->servizio(ArchivioIncarichi::class)->pdfCorrente($this->lettera($id));
        $this->assertFileExists((string) $pdf);
        $this->db->esegui("UPDATE tutorato_incarichi SET stato = 'protocollata', aggiornata_il = NOW() - INTERVAL 14 MONTH WHERE id = ?", [$id]);
        $r->aggiungi($id, '2026-09-01', 2, 'A');
        $this->assertSame(0, $r->conserva(0), '0 mesi = mai');
        $this->assertSame(0, $r->conserva(24), 'ancora recente');
        $this->assertSame(1, $r->conserva(12));
        $l = $this->lettera($id);
        $this->assertSame(['1', '', '', null, null, 'Rossi', 'Luca'], [$l['anonimizzata'], $l['codice_fiscale'], $l['email'], $l['file_pdf'], $l['token_docente'], $l['cognome'], $l['nome']]);
        $this->assertFileDoesNotExist((string) $pdf);
        $this->assertSame([], $r->righe($id));
        $ultimo = $this->servizio(ServizioIncarichi::class)->eventi($id);
        $this->assertSame(['conservazione', 'Dati personali, PDF e registro cancellati dopo 12 mesi (conservazione)'], [$ultimo[count($ultimo) - 1]['tipo'], $ultimo[count($ultimo) - 1]['testo']]);
        $this->assertSame('', $ultimo[0]['ip'], 'anche gli indirizzi IP dello storico');
        $this->assertSame(0, $r->conserva(12), 'una lettera si anonimizza una volta sola');
        $this->assertSame(0, $r->promemoria(), 'le lettere anonimizzate non ricevono promemoria');
    }
}
