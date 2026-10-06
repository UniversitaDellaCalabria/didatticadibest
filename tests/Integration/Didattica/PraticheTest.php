<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Didattica\Costanti;
use App\Didattica\PraticaRepository;
use App\Didattica\PromemoriaPratiche;
use App\Didattica\ServizioIter;
use App\Didattica\ServizioPratiche;
use App\Didattica\StatisticheDidattica;

/** Pratiche degli studenti: invio, storico, stati, messaggi, iter con gli operatori, promemoria e statistiche. */
final class PraticheTest extends DidatticaBase
{
    private int $modulo;
    /** @var array<string, mixed> */
    private array $studente = ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Rossi', 'email' => 'luca@x.it', 'matricola_studente' => '245678', 'matricola_dipendente' => null];

    private function srv(): ServizioPratiche
    {
        return $this->servizio(ServizioPratiche::class);
    }

    /** Una pratica inviata dallo studente di prova sul modulo indicato (o su uno nuovo senza iter). */
    private function pratica(?int $modulo = null, array $risposte = []): int
    {
        $this->modulo ??= $this->modulo('Convalida di esami');
        $m = $this->db->riga('SELECT * FROM didattica_moduli WHERE id = ?', [$modulo ?? $this->modulo]);
        $id = $this->srv()->crea($m, $this->studente, $risposte);
        $this->mailer->inviate = [];

        return $id;
    }

    public function testInvioDellaPratica(): void
    {
        $this->modulo = $this->modulo('Convalida di esami', [], null, 'tutti', ['email' => 'ufficio@x.it']);
        $this->sessione->scrivi('auth_meta', ['metodo' => 'spid', 'livello' => 2, 'idp' => 'idp.test', 'altro' => 'x']);
        $m = $this->db->riga('SELECT * FROM didattica_moduli WHERE id = ?', [$this->modulo]);
        $id = $this->srv()->crea($m, $this->studente, [['etichetta' => 'Corso', 'tipo' => 'text', 'valore' => 'Biologia']]);
        $this->assertGreaterThan(0, $id);
        $p = $this->srv()->pratica($id);
        $this->assertMatchesRegularExpression('/^PR-[0-9A-F]{8}$/', $p['codice']);
        $this->assertSame(['inviata', 'Luca', 'Rossi', '245678', 'Convalida di esami', '10.0.0.1'], [$p['stato'], $p['nome'], $p['cognome'], $p['matricola'], $p['modulo_titolo'], $p['ip_invio']]);
        $this->assertSame(['metodo' => 'spid', 'livello' => 2, 'idp' => 'idp.test'], json_decode($p['accesso_json'], true), 'dell\'accesso si tengono solo i dati utili alla domanda');
        $this->assertSame('Biologia', json_decode($p['risposte_json'], true)[0]['valore']);
        $ev = $this->servizio(PraticaRepository::class)->eventi($id, true);
        $this->assertSame([['stato', 'studente', 'inviata', 'Pratica inviata']], array_map(fn ($e) => [$e['tipo'], $e['autore'], $e['stato'], $e['testo']], $ev));
        $this->assertSame(['luca@x.it', 'ufficio@x.it'], array_column($this->mailer->inviate, 'a'), 'conferma allo studente e avviso all\'ufficio del modulo');
        $this->assertSame(['Pratica ricevuta: Convalida di esami', 'Nuova pratica: Convalida di esami – Rossi Luca'], array_column($this->mailer->inviate, 'oggetto'));
        $this->assertSame('#047857', $this->mailer->inviate[0]['colore']);
        $this->assertStringContainsString('https://portale.test/eventi/pratiche.php?id=' . $id, $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('https://portale.test/eventi/admin/didattica.php?tab=pratiche&amp;id=' . $id, $this->mailer->inviate[1]['corpo']);
        $this->assertStringContainsString('matricola 245678', $this->mailer->inviate[1]['corpo']);
    }

    public function testNuovaPraticaAiManagerSeCiSono(): void
    {
        $manager = $this->ufficio('Manager', '', 1);
        $this->operatore('Capo', 'capo@x.it', 'pratiche', $manager);
        $this->operatore('Altro', 'altro@x.it', 'pratiche');
        $this->modulo = $this->modulo('Modulo');
        $m = $this->db->riga('SELECT * FROM didattica_moduli WHERE id = ?', [$this->modulo]);
        $this->srv()->crea($m, $this->studente, []);
        $this->assertSame(['luca@x.it', 'capo@x.it'], array_column($this->mailer->inviate, 'a'), 'le nuove pratiche vanno a chi smista');
    }

    public function testCambiDiStatoEMail(): void
    {
        $id = $this->pratica();
        $s = $this->srv();
        $this->assertFalse($s->cambiaStato($id, 'boh', '', 1));
        $this->assertFalse($s->cambiaStato(999, 'accolta', '', 1));
        $this->assertTrue($s->cambiaStato($id, 'in_lavorazione', 'Presa visione', 1, 'Ada · Ufficio'));
        $this->assertSame([], $this->mailer->inviate, 'il ritorno in lavorazione non è un passaggio');
        $this->assertTrue($s->cambiaStato($id, 'integrazione', '', 1, 'Ada · Ufficio', ['tipo' => 'autodichiarazione', 'testo' => 'di aver sostenuto l\'esame']));
        $p = $s->pratica($id);
        $rq = json_decode($p['richiesta_json'], true);
        $this->assertSame(['autodichiarazione', 'di aver sostenuto l\'esame', 'Ada · Ufficio'], [$rq['tipo'], $rq['testo'], $rq['da']]);
        $ev = $this->servizio(PraticaRepository::class)->eventi($id, false);
        $this->assertSame("Richiesta un'autodichiarazione: di aver sostenuto l'esame", end($ev)['testo']);
        $this->assertSame(['luca@x.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertSame('Pratica ' . $p['codice'] . ': Integrazione richiesta', $this->mailer->inviate[0]['oggetto']);
        $this->mailer->inviate = [];
        $this->assertTrue($s->cambiaStato($id, 'accolta', 'Tutto <b>ok</b>', 1));
        $this->assertNull($s->pratica($id)['richiesta_json'], 'la richiesta si chiude con lo stato successivo');
        $this->assertStringContainsString('Tutto &lt;b&gt;ok&lt;/b&gt;', $this->mailer->inviate[0]['corpo']);
    }

    public function testMessaggiENote(): void
    {
        $id = $this->pratica();
        $s = $this->srv();
        $this->assertSame('Pratica non trovata.', $s->messaggio(999, 'studente', 2, 'x'));
        $this->assertSame('Scrivi il messaggio o allega un file.', $s->messaggio($id, 'studente', 2, '   '));
        $this->assertNull($s->messaggio($id, 'studente', 2, 'Una domanda'));
        $this->assertSame(['admin@x.it'], array_column($this->mailer->inviate, 'a'), 'la domanda dello studente va all\'ufficio');
        $this->mailer->inviate = [];
        $this->assertNull($s->messaggio($id, 'ufficio', 1, 'Nota riservata', null, true, 'messaggio', 'Ada'));
        $this->assertSame([], $this->mailer->inviate, 'le note interne non mandano email');
        $this->assertNull($s->messaggio($id, 'ufficio', 1, 'Risposta', null, false, 'attivita', 'Ada'));
        $this->assertSame(['luca@x.it'], array_column($this->mailer->inviate, 'a'));
        $visibili = $this->servizio(PraticaRepository::class)->eventi($id, true);
        $tutti = $this->servizio(PraticaRepository::class)->eventi($id, false);
        $this->assertCount(count($tutti) - 1, $visibili, 'la nota interna lo studente non la vede');
        $this->assertSame('attivita', end($tutti)['tipo']);
        // Un'integrazione di documenti si chiude con la risposta dello studente
        $s->cambiaStato($id, 'integrazione', 'Mancano i programmi', 1);
        $s->messaggio($id, 'studente', 2, 'Eccoli');
        $this->assertSame('in_lavorazione', $s->pratica($id)['stato']);
        $this->assertNull($s->pratica($id)['richiesta_json']);
    }

    public function testAutodichiarazione(): void
    {
        $id = $this->pratica();
        $s = $this->srv();
        $this->assertSame("Non c'è un'autodichiarazione da rendere.", $s->autodichiarazione($id, 2, '', true));
        $s->cambiaStato($id, 'integrazione', '', 1, '', ['tipo' => 'autodichiarazione', 'testo' => 'di essere iscritto']);
        $this->mailer->inviate = [];
        $this->assertSame('Per inviare devi spuntare la dichiarazione.', $s->autodichiarazione($id, 2, '', false));
        $this->assertNull($s->autodichiarazione($id, 2, ' con 28/30 ', true));
        $this->assertSame('in_lavorazione', $s->pratica($id)['stato']);
        $ev = $this->servizio(PraticaRepository::class)->eventi($id, true);
        $dich = array_values(array_filter($ev, fn ($e) => $e['tipo'] === 'autodich'))[0]['testo'];
        $this->assertStringStartsWith('Il/La sottoscritto/a Luca Rossi, matricola 245678, dichiara: di essere iscritto' . "\n" . 'con 28/30' . "\n" . 'Dichiarazione resa ai sensi degli artt. 46 e 47', $dich);
        $this->assertSame(['admin@x.it'], array_column($this->mailer->inviate, 'a'));
    }

    public function testIterEAssegnazioni(): void
    {
        $uff1 = $this->ufficio('Carriere');
        $uff2 = $this->ufficio('Segreteria');
        $anna = $this->operatore('Rossi Anna', 'anna@x.it', 'pratiche', $uff1, '["Biologia"]');
        $paolo = $this->operatore('Bianchi Paolo', 'paolo@x.it', 'pratiche', $uff2);
        $mod = $this->modulo('Con iter', [['etichetta' => 'Corso', 'tipo' => 'corso_studio']], [$uff1, $uff2]);
        $m = $this->db->riga('SELECT * FROM didattica_moduli WHERE id = ?', [$mod]);
        $iter = $this->servizio(ServizioIter::class);
        $this->assertSame([$uff1, $uff2], $iter->iter($m));
        $this->assertSame([0 => 'Ricevuta e da smistare', 1 => 'Carriere', 2 => 'Segreteria'], $iter->passi($m));
        $this->assertSame([0], $iter->iter(['iter_json' => null]), 'senza iter: un solo passo generico');
        $this->assertSame([Costanti::ITER_CDL, Costanti::ITER_SEGRETERIA], $iter->iter(['iter_json' => '["@cdl","@segreteria"]']));
        $id = $this->pratica($mod, [['etichetta' => 'Corso', 'tipo' => 'corso_studio', 'valore' => 'Biologia']]);
        $p = $this->srv()->pratica($id);
        $this->assertSame(['Rossi Anna', 'Bianchi Paolo'], array_column($iter->operatoriSuggeriti($p, $m, 1), 'nominativo'), 'prima chi è nell\'ufficio del passo e segue il corso');
        $this->assertTrue($iter->operatoriSuggeriti($p, $m, 1)[0]['_consigliato']);
        $this->assertSame("Scegli la pratica e l'operatore.", $iter->assegna($id, 999, 1, '', 1));
        $this->assertNull($iter->assegna($id, $anna, 1, 'Controlla i programmi', 1, 'Ada · Ufficio'));
        $p = $this->srv()->pratica($id);
        $this->assertSame(['in_lavorazione', $anna, 1, $uff1], [$p['stato'], $p['assegnata_a'], $p['passo'], $p['ufficio_id']]);
        $this->assertSame(['anna@x.it', 'luca@x.it'], array_column($this->mailer->inviate, 'a'), 'avviso all\'operatore e allo studente (passaggio)');
        $this->assertStringContainsString('come <strong>Carriere</strong> da Ada · Ufficio', $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('Controlla i programmi', $this->mailer->inviate[0]['corpo']);
        $this->mailer->inviate = [];
        $this->assertNull($iter->assegna($id, $paolo, 9, '', 1));
        $this->assertSame(2, (int) $this->srv()->pratica($id)['passo'], 'il passo non supera quelli dell\'iter');
        $this->assertSame([$anna, $paolo], $iter->operatoriPratica($id), 'chi l\'ha avuta resta tra gli operatori');
        $this->mailer->inviate = [];
        // Integrazione richiesta a chi l'aveva prima
        $this->assertSame("Scegli l'operatore.", $iter->richiediAOperatore($id, 999, 'x', 1));
        $this->assertSame('Scrivi cosa serve.', $iter->richiediAOperatore($id, $anna, ' ', 1));
        $this->assertNull($iter->richiediAOperatore($id, $anna, 'Serve il piano', 1, 'Paolo'));
        $this->assertSame(['anna@x.it'], array_column($this->mailer->inviate, 'a'));
        $this->mailer->inviate = [];
        // L'operatore precedente integra: lo sa chi ha la pratica adesso
        $this->db->esegui('UPDATE utenti SET email = ? WHERE id = 3', ['anna@x.it']);
        $this->assertNull($this->srv()->messaggio($id, 'ufficio', 3, 'Documento aggiunto'));
        $this->assertContains('paolo@x.it', array_column($this->mailer->inviate, 'a'));
        $this->assertStringContainsString('Integrazione sulla pratica', $this->mailer->inviate[0]['oggetto']);
        // L'iter in HTML: passi fatti, attuale con la persona e conclusione
        $html = $iter->html($this->srv()->pratica($id), $m);
        $this->assertStringContainsString('aria-current="step"', $html);
        $this->assertStringContainsString('Bianchi Paolo', $html);
        $this->assertStringContainsString('Conclusa', $html);
        $this->assertStringContainsString('Accolta', ServizioIter::badge('accolta'));
        $this->assertStringContainsString('background:#64748b', ServizioIter::badge('boh'));
    }

    public function testPromemoriaDellePraticheFerme(): void
    {
        $uffM = $this->ufficio('Manager', '', 1);
        $this->operatore('Capo', 'capo@x.it', 'pratiche', $uffM);
        $mod = $this->modulo('Modulo', [], null, 'tutti', ['giorni' => 5]);
        $ferma = $this->pratica($mod);
        $recente = $this->pratica($mod);
        $assegnata = $this->pratica($mod);
        $uffA = $this->ufficio('Carriere');
        $op = $this->operatore('Anna', 'anna@x.it', 'pratiche', $uffA);
        $this->db->esegui("UPDATE pratiche SET aggiornata_il = '2026-09-01 10:00:00' WHERE id IN (?, ?)", [$ferma, $assegnata]);
        $this->db->esegui('UPDATE pratiche SET assegnata_a = ? WHERE id = ?', [$op, $assegnata]);
        $this->db->esegui("UPDATE pratiche SET aggiornata_il = NOW() WHERE id = ?", [$recente]);
        $this->assertSame(2, $this->servizio(PromemoriaPratiche::class)->invia());
        $this->assertEqualsCanonicalizing(['capo@x.it', 'anna@x.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertStringContainsString('non è ancora stata smistata', $this->mailer->inviate[0]['corpo'] . $this->mailer->inviate[1]['corpo']);
        $this->assertStringContainsString('ed è in carico a te', $this->mailer->inviate[0]['corpo'] . $this->mailer->inviate[1]['corpo']);
        $this->assertSame(0, $this->servizio(PromemoriaPratiche::class)->invia(), 'un promemoria per ogni periodo di attesa');
    }

    public function testStatisticheElencoEIstruttoria(): void
    {
        $uff = $this->ufficio('Carriere');
        $op = $this->operatore('Anna', 'anna@x.it', 'pratiche', $uff);
        $mod = $this->modulo('Modulo A', [['etichetta' => 'Corso', 'tipo' => 'corso_studio'], ['etichetta' => 'Nota', 'tipo' => 'text', 'ufficio' => 1]], [$uff]);
        $a = $this->pratica($mod, [['etichetta' => 'Corso', 'tipo' => 'corso_studio', 'valore' => 'Biologia']]);
        $b = $this->pratica($mod);
        $this->srv()->cambiaStato($a, 'accolta', '', 1);
        $iter = $this->servizio(ServizioIter::class);
        $iter->assegna($b, $op, 1, '', 1);
        $this->db->esegui("UPDATE pratiche SET creata_il = '2026-10-01 10:00:00' WHERE id IN (?, ?)", [$a, $b]);
        $this->db->esegui("UPDATE pratiche_eventi SET creato_il = '2026-10-03 10:00:00' WHERE pratica_id = ? AND stato = 'accolta'", [$a]);
        $this->db->esegui("UPDATE pratiche_eventi SET creato_il = '2026-10-05 10:00:00' WHERE pratica_id = ? AND tipo = 'passaggio'", [$b]);
        $st = $this->servizio(StatisticheDidattica::class)->calcola('2026-10-01', '2026-10-31');
        $this->assertSame(2, $st['totale']);
        $this->assertSame(['accolta' => 1, 'respinta' => 0, 'chiusa' => 0, 'aperte' => 1], $st['esiti']);
        $this->assertSame(['Modulo A'], array_keys($st['per_modulo']));
        $this->assertSame(['Biologia', 'Senza corso di studio'], array_keys($st['per_corso']));
        $this->assertSame(2.0, $st['chiusura_media']);
        $this->assertSame(['media' => 3.0, 'n' => 2, 'max' => 4.0], $st['tempi_passi']['Smistamento']);
        $this->assertSame(0, $this->servizio(StatisticheDidattica::class)->calcola('2020-01-01', '2020-02-01')['totale']);

        $r = $this->servizio(PraticaRepository::class);
        $this->assertSame(1, $r->contaAperte());
        $this->assertSame([$b], array_map(fn ($p) => $p['id'], $r->elenco('aperte', 0, '', '', '', '', '', null)));
        $this->assertCount(2, $r->elenco('tutte', $mod, '', '', '', '', '', null));
        $this->assertSame([$a], array_column($r->elenco('accolta', 0, '', '', '2026-10-01', '2026-10-31', 'ross', null), 'id'));
        $this->assertSame([], $r->elenco('tutte', 0, '', '', '', '', '%', null));
        $this->assertSame([$b], array_column($r->elenco('tutte', 0, '', 'me', '', '', '', ['id' => $op]), 'id'));
        $this->assertSame([$b], array_column($r->elenco('tutte', 0, '', 'ufficio', '', '', '', ['id' => 99, 'ufficio_id' => $uff]), 'id'));
        $this->assertSame([], $r->elenco('tutte', 0, '', 'smistare', '', '', '', null), 'quelle senza persona e senza ufficio: nessuna');
        $this->assertEqualsCanonicalizing([$a, $b], array_column($r->elenco('tutte', 0, 'nessuna', '', '', '', '', null), 'id'));
        // Istruttoria: i campi dell'ufficio, la seduta, la delibera e il protocollo
        $this->assertNull($this->srv()->salvaIstruttoria(999, [], []));
        $codice = $this->srv()->salvaIstruttoria($b, ['campo_c2' => 'Verificato', 'seduta_id' => '4', 'delibera' => ' Approvata ', 'protocollo' => 'P-1', 'protocollo_data' => '2026-10-02'], []);
        $this->assertSame($this->srv()->pratica($b)['codice'], $codice);
        $p = $this->srv()->pratica($b);
        $this->assertSame([4, 'Approvata', 'P-1', '2026-10-02'], [$p['seduta_id'], $p['delibera'], $p['protocollo'], $p['protocollo_data']]);
        $this->assertSame('Verificato', json_decode($p['ufficio_json'], true)[0]['valore']);
        $this->srv()->salvaIstruttoria($b, ['protocollo_data' => 'boh'], []);
        $this->assertNull($this->srv()->pratica($b)['protocollo_data']);
        $this->assertNull($this->srv()->pratica($b)['seduta_id']);
    }
}
