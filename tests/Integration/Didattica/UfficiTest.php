<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Didattica\ServizioUffici;
use App\Didattica\UfficioRepository;

/** Uffici, operatori, avvisi agli uffici e sportelli di ricevimento dell'Ufficio didattico. */
final class UfficiTest extends DidatticaBase
{
    private function srv(): ServizioUffici
    {
        return $this->servizio(ServizioUffici::class);
    }

    public function testUfficiELoroChiaviSiLeggonoUnaVolta(): void
    {
        $r = $this->servizio(UfficioRepository::class);
        $a = $this->ufficio('Carriere', '', 0, 'carriere');
        $this->ufficio('Manager', '', 1, 'manager');
        $this->assertSame([$a, $a + 1], array_keys($r->tutti()));
        $this->assertSame('Carriere', $r->tutti()[$a]['nome']);
        $this->assertSame('0', $r->tutti()[$a]['smista'], 'valori come testo, come con il vecchio query()');
        $this->assertSame($a, $r->idDa('carriere'));
        $this->assertSame($a, $r->idDa((string) $a));
        $this->assertNull($r->idDa('boh'));
        $this->assertNull($r->idDa(999));
        $this->ufficio('Nuovo');
        $this->assertCount(2, $r->tutti(), 'la lettura resta in memoria');
        $this->assertCount(3, $r->tutti(true), 'finché non si chiede di rileggere');
    }

    public function testOperatoriEChiLiRiconosce(): void
    {
        $s = $this->srv();
        $this->operatore('Rossi Anna', 'Anna@x.it', 'pratiche,sedute');
        $this->operatore('Bianchi Paolo', 'paolo@x.it', 'bandi');
        $this->assertSame(['Bianchi Paolo', 'Rossi Anna'], array_column($s->operatori(), 'nominativo'), 'per nominativo');
        $this->assertSame(['Rossi Anna'], array_column($s->operatori('sedute'), 'nominativo'));
        $this->assertSame(['pratiche', 'sedute'], array_values($s->operatori('sedute')[0]['_compiti']));
        $this->db->esegui("UPDATE utenti SET email = 'anna@x.it' WHERE id = 2");
        $this->assertTrue($s->utenteOperatore(['id' => 2, 'email' => 'ANNA@x.it']), "l'email si confronta senza maiuscole");
        $this->assertFalse($s->utenteOperatore(['id' => 2, 'email' => 'anna@x.it'], 'bandi'));
        $this->assertTrue($s->utenteOperatore(['id' => 4, 'email' => 'paolo@x.it'], 'bandi'));
        $this->assertFalse($s->utenteOperatore(['id' => 3, 'email' => 'maria.verdi@x.it']));
        $this->assertFalse($s->utenteOperatore(null));
        $this->assertSame('Rossi Anna', $s->operatore(0, ['email' => 'anna@x.it'])['nominativo']);
        $this->assertSame('Bianchi Paolo', $s->operatore((int) $s->operatori()[0]['id'])['nominativo']);
        $this->assertNull($s->operatore(99));
    }

    public function testChiGestisceLaDidattica(): void
    {
        $s = $this->srv();
        $this->operatore('Maria', 'maria.verdi@x.it');
        $this->assertTrue($s->gestisce(['id' => 1, 'ruolo_id' => 1]), 'amministratore');
        $this->assertTrue($s->gestisce(['id' => 9, 'ruolo_id' => 5, 'ruoli_secondari' => '2,1']), 'amministratore con il ruolo secondario');
        $this->assertTrue($s->gestisce(['id' => 3, 'ruolo_id' => 5, 'email' => 'maria.verdi@x.it']), 'operatore');
        $this->assertFalse($s->gestisce(['id' => 2, 'ruolo_id' => 5, 'email' => 'luca@x.it']));
        $this->db->esegui("INSERT INTO abilitazioni_ambito (utente_id, tipo, pagina_id) VALUES (4, 'modulo_didattica', 0)");
        $this->assertTrue($s->gestisce(['id' => 4, 'ruolo_id' => 5, 'email' => 'paolo@x.it']), 'abilitato al modulo');
        $this->assertFalse($s->gestisce(null));
    }

    public function testOperatoreDallAnagrafe(): void
    {
        $s = $this->srv();
        $u = $this->ufficio('Segreteria');
        $this->assertSame("Persona non trovata nell'anagrafe di Ateneo.", $s->aggiungi('nessuno', 'x', []));
        $this->assertSame("La persona scelta non ha un'email nell'anagrafe.", $s->aggiungi('s.senzamail', 'x', []));
        $this->assertNull($s->aggiungi('m.verdi', ' Referente ', ['pratiche', 'boh', 'bandi'], $u, ['Biologia', ' ', 'Biologia', 'Chimica']));
        $o = $s->operatori()[0];
        $this->assertSame(['Verdi Maria', 'maria.verdi@x.it', 'Referente', 'pratiche,bandi', (string) $u, '["Biologia","Chimica"]'], [$o['nominativo'], $o['email'], $o['ruolo'], $o['compiti'], $o['ufficio_id'], $o['corsi']]);
        $this->assertNull($s->aggiungi('m.verdi', 'Altro', ['sedute'], 999));
        $this->assertCount(1, $s->operatori(), "l'email c'è già: si aggiorna");
        $o = $s->operatori()[0];
        $this->assertSame(['Altro', 'sedute', null, null], [$o['ruolo'], $o['compiti'], $o['ufficio_id'], $o['corsi']], 'ufficio inesistente: nessuno');
    }

    public function testEtichettaEAutore(): void
    {
        $s = $this->srv();
        $u = $this->ufficio('Carriere');
        $id = $this->operatore('Rossi Anna', 'anna@x.it', 'pratiche', $u);
        $this->operatore('Senza Ufficio', 'su@x.it');
        $o = $s->operatore($id);
        $this->assertSame('Rossi Anna · Carriere', $s->etichetta($o));
        $this->assertSame('Carriere', $s->nomeUfficio($o));
        $this->assertSame('', $s->etichetta(null));
        $this->assertSame('Senza Ufficio', $s->etichetta($s->operatore(0, ['email' => 'su@x.it'])));
        $this->assertSame('Rossi Anna · Carriere', $s->nomeAutore(['email' => 'anna@x.it']));
        $this->assertSame('Ada Admin · Ufficio didattico', $s->nomeAutore(['nome' => 'Ada', 'cognome' => 'Admin', 'email' => 'admin@x.it']));
    }

    public function testChiRiceveGliAvvisi(): void
    {
        $s = $this->srv();
        $this->assertSame(['admin@x.it'], $s->emailUfficio(), 'senza operatori: gli amministratori');
        $this->operatore('A', 'a@x.it', 'pratiche');
        $this->operatore('B', 'b@x.it', 'ricevimento');
        $this->assertSame(['a@x.it'], $s->emailUfficio());
        $this->assertSame(['b@x.it'], $s->emailUfficio('', 'ricevimento'));
        $this->assertSame(['u1@x.it', 'u2@x.it'], $s->emailUfficio('u1@x.it; boh, u2@x.it u1@x.it'), "quelle del modulo, valide e senza doppioni");
    }

    public function testSalvataggioEdEliminazioneDegliUffici(): void
    {
        $s = $this->srv();
        $r = $this->servizio(UfficioRepository::class);
        $r->tutti();
        $s->salvaUfficio(0, 'Protocollo', 'Riceve le domande', false, false, 5, 'protocollo', null);
        $id = array_key_first($r->tutti());
        $this->assertSame(['Protocollo', 'protocollo', '0', '5'], [$r->tutti()[$id]['nome'], $r->tutti()[$id]['tipo'], $r->tutti()[$id]['smista'], $r->tutti()[$id]['ordine']], 'la lettura si rinnova dopo il salvataggio');
        $s->salvaUfficio($id, 'Ufficio CdS', '', true, true, 1, 'cdl', 3);
        $this->assertSame(['Ufficio CdS', '1', '3'], [$r->tutti()[$id]['nome'], $r->tutti()[$id]['smista'], $r->tutti()[$id]['consiglio_id']]);
        $this->operatore('Anna', 'anna@x.it', 'pratiche', $id);
        $m = $this->modulo('Modulo', [], [$id, '@cdl']);
        $this->modulo('Altro', [], [999]);
        $this->assertSame(['operatori' => 1, 'moduli' => 1], $s->usoUfficio($id));
        $s->eliminaUfficio($id);
        $this->assertSame([], $r->tutti());
        $s->togliOperatore((int) $s->operatori()[0]['id']);
        $this->assertSame([], $s->operatori());
        $this->assertGreaterThan(0, $m);
    }

    public function testSportelliDiRicevimento(): void
    {
        $s = $this->srv();
        $this->db->esegui("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area) VALUES (7, 'Ricevimento', 'ricevimento', 'calendario')");
        $this->operatore('A', 'a@x.it', 'ricevimento');
        $id = $s->creaSportello(7, '  ', 'Cubo 4B');
        $this->assertGreaterThan(0, $id);
        $r = $this->db->riga('SELECT * FROM risorse WHERE id = ?', [$id]);
        $this->assertSame(['Ufficio didattico – ricevimento studenti', 'Cubo 4B', 'a@x.it', 'didattica', 'sportello'], [$r['nome'], $r['luogo'], $r['email_notifiche'], $r['ufficio'], $r['tipo']]);
        $this->db->esegui("INSERT INTO risorse (pagina_id, nome, ufficio, persona_id, attiva) VALUES (7, 'Mio', '', 'm.verdi', 1)");
        $this->assertSame(['Ufficio didattico – ricevimento studenti'], array_column($s->sportelli(), 'nome'));
        $this->assertSame([], $s->sportelli(true) === [] ? [] : array_diff(array_column($s->sportelli(true), 'nome'), ['Ufficio didattico – ricevimento studenti']));
        $this->assertSame(['Mio'], array_column($s->sportelliUtente(['id' => 3, 'persona_id' => 'm.verdi', 'email' => 'maria.verdi@x.it']), 'nome'), 'il proprio sportello, dall\'anagrafe');
        $this->assertSame(['Ufficio didattico – ricevimento studenti'], array_column($s->sportelliUtente(['id' => 5, 'persona_id' => '', 'email' => 'a@x.it']), 'nome'), 'chi è operatore con il compito vede quello dell\'ufficio');
        $this->assertSame([], $s->sportelliUtente(['id' => 9, 'persona_id' => '', 'email' => 'boh@x.it']));
        $this->assertSame([], $s->sportelliUtente(null));
    }
}
