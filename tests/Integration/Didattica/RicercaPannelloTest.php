<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Core\Schema\Definizioni;
use App\Portale\RicercaPannello;

/** «Cerca ovunque» nel pannello: trova per parole, rispetta i permessi di ciascun gruppo. */
final class RicercaPannelloTest extends DidatticaBase
{
    protected function tabelle(): array
    {
        $d = Definizioni::tabelle();

        return array_intersect_key($d, array_flip(['scuole', 'convenzioni_tesi', 'personale_ateneo', 'risorse'])) + parent::tabelle();
    }

    private function cerca(string $q, array $permessi): array
    {
        $r = [];
        foreach ($this->servizio(RicercaPannello::class)->cerca($q, $permessi, 3) as $g) {
            $r[$g['id']] = $g;
        }

        return $r;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->esegui("INSERT INTO didattica_moduli (titolo, tipo) VALUES ('Richiesta tesi', 'online')");
        $m = (int) $this->db->valore('SELECT MAX(id) FROM didattica_moduli');
        $this->db->esegui("INSERT INTO pratiche (modulo_id, codice, nome, cognome, email, matricola, stato) VALUES ($m, 'ABC123', 'Maria', 'Rossi', 'maria@x.it', '234567', 'inviata')");
        $this->db->esegui("INSERT INTO pratiche (modulo_id, codice, nome, cognome, email, matricola, stato) VALUES ($m, 'BOZ001', 'Mario', 'Rossini', 'm@x.it', '1', 'bozza')");
        $this->db->esegui("INSERT INTO personale_ateneo (id, cognome, nome, email, ruolo, struttura, gruppo, attivo) VALUES ('anna.rossi', 'Rossi', 'Anna', 'anna.rossi@unical.it', 'Docente', 'DiBEST', 'docenti', 1)");
        $this->db->esegui("INSERT INTO didattica_sedute (organo, data, origine, numero_verbale) VALUES ('Consiglio di Dipartimento', '2026-05-10', 'portale', '7')");
        $this->db->esegui("INSERT INTO scuole (codice, denominazione, comune, provincia) VALUES ('CSIS001', 'Liceo Rossi', 'Cosenza', 'CS')");
        $this->db->esegui("INSERT INTO convenzioni_tesi (token, stato, azienda, email, dati_json) VALUES ('t1', 'protocollata', 'Rossi Lab S.r.l.', 'a@b.it', '{}')");
    }

    public function testTrovaInOgniGruppoEOrdinaPerPermessi(): void
    {
        $tutto = ['pratiche' => true, 'persone' => true, 'verbali' => true, 'convenzioni_tesi' => true, 'scuole' => true, 'risorse' => true];
        $r = $this->cerca('rossi', $tutto);
        $this->assertSame(['pratiche', 'persone', 'convenzioni_tesi', 'scuole'], array_keys($r), 'la bozza e i verbali senza «rossi» non compaiono');
        $this->assertSame(1, $r['pratiche']['totale'], 'le bozze non si cercano');
        $this->assertSame('didattica.php?p_id=3&tab=pratiche&id=1', $r['pratiche']['voci'][0]['url']);
        $this->assertStringContainsString('ABC123', $r['pratiche']['voci'][0]['titolo']);

        $solo = $this->cerca('rossi', ['pratiche' => false, 'persone' => true, 'scuole' => false]);
        $this->assertSame(['persone'], array_keys($solo), 'senza permesso il gruppo non compare');
    }

    public function testTutteLeParoleDevonoTrovarsi(): void
    {
        $tutto = ['pratiche' => true, 'persone' => true];
        $this->assertArrayHasKey('pratiche', $this->cerca('maria rossi', $tutto));
        $this->assertArrayHasKey('pratiche', $this->cerca('rossi maria', $tutto), 'in qualunque ordine');
        $this->assertSame([], $this->cerca('maria bianchi', $tutto));
        $this->assertArrayHasKey('pratiche', $this->cerca('234567', $tutto), 'per matricola');
        $this->assertArrayHasKey('pratiche', $this->cerca('abc123', $tutto), 'per codice');
    }

    public function testVerbaliEParoleTroppoCorte(): void
    {
        $r = $this->cerca('consiglio dipartimento', ['verbali' => true]);
        $this->assertSame('didattica.php?p_id=3&tab=sedute&id=1', $r['verbali']['voci'][0]['url']);
        $this->assertSame([], $this->cerca('c', ['verbali' => true]), 'una lettera sola non basta');
        $this->assertSame([], $this->cerca('%%', ['verbali' => true]), 'i caratteri jolly non trovano tutto');
    }

    public function testRisorseSoloDelleAreeDelGestore(): void
    {
        $this->db->esegui("INSERT INTO risorse (pagina_id, nome, tipo, luogo) VALUES (5, 'Aula Rossi', 'aula', 'Cubo 1'), (6, 'Laboratorio Rossi', 'laboratorio', 'Cubo 2')");
        $this->assertSame(2, $this->cerca('rossi', ['risorse' => true])['risorse']['totale']);
        $mie = $this->cerca('rossi', ['risorse' => [6]]);
        $this->assertSame(1, $mie['risorse']['totale']);
        $this->assertSame('risorse.php?p_id=6', $mie['risorse']['voci'][0]['url']);
        $this->assertArrayNotHasKey('risorse', $this->cerca('rossi', ['risorse' => []]));
    }
}
