<?php

declare(strict_types=1);

namespace Tests\Unit\Didattica;

use App\Didattica\ServizioConsigli;
use App\Didattica\ServizioSedute;
use App\Didattica\TestiPratica;
use PHPUnit\Framework\TestCase;

/** Testi e dati di una pratica per il verbale e l'estratto: segnaposti, richieste dello studente, quadro delle decisioni, presenze. */
final class TestiPraticaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function pratica(array $campi = []): array
    {
        return $campi + [
            'cognome' => 'Rossi', 'nome' => 'Luca', 'matricola' => '245678', 'email' => 'luca@x.it', 'codice' => 'PR-1', 'modulo_titolo' => 'Convalida', 'creata_il' => '2026-10-03 10:00:00',
            'protocollo' => '0001/2026', 'protocollo_data' => '2026-10-05',
            'risposte_json' => json_encode([
                ['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio', 'valore' => 'Biologia'],
                ['etichetta' => 'Data', 'tipo' => 'date', 'valore' => '2026-09-01'],
                ['etichetta' => 'Ateneo della carriera precedente', 'tipo' => 'radio', 'valore' => 'Altro Ateneo'],
                ['etichetta' => "Denominazione dell'Ateneo", 'tipo' => 'text', 'valore' => 'Messina'],
                ['etichetta' => 'Esami', 'tipo' => 'tabella', 'valore' => 'x', 'colonne' => ['Insegnamento', 'CFU', 'Piano'], 'righe' => [['Fisica', '6', 'A scelta; elimina: Botanica'], ['', '3', ''], ['Chimica', '8', '']]],
            ]),
            'ufficio_json' => json_encode([['etichetta' => 'Nota', 'tipo' => 'text', 'valore' => 'Verificato']]),
        ];
    }

    public function testSegnaposti(): void
    {
        $p = $this->pratica();
        $this->assertSame(
            'ROSSI LUCA, matr. 245678, Convalida, 03/10/2026, 0001/2026 del 05/10/2026, Messina, Biologia, 03/10/2026, Verificato, {Boh}, ',
            TestiPratica::segnaposti('{STUDENTE}, matr. {matricola}, {MODULO}, {DATA}, {PROTOCOLLO}, {ATENEO_PRECEDENTE}, {Corso di studio}, {data}, {nota}, {Boh}, {Esami}', $p)
        );
        $this->assertSame('Luca Rossi luca@x.it PR-1', TestiPratica::segnaposti('{Nome} {cognome} {EMAIL} {codice}', $p));
        $this->assertSame(['corso di studio', 'data', 'ateneo della carriera precedente', "denominazione dell'ateneo", 'esami', 'nota'], array_keys(TestiPratica::risposteTutte($p)), 'prima le risposte dello studente, poi i campi dell\'ufficio');
    }

    public function testComeCompareIlModuloNelVerbale(): void
    {
        $v = TestiPratica::verbaleModulo(['titolo' => 'Convalida', 'verbale_json' => null]);
        $this->assertSame(['Convalida', 'scheda', '{STUDENTE}, matricola {MATRICOLA}, presenta la richiesta: {MODULO}.', 'Il Consiglio approva.', ''], [$v['sezione'], $v['stile'], $v['testo'], $v['delibera'], $v['decisione']]);
        $v = TestiPratica::verbaleModulo(['modulo_titolo' => 'T', 'verbale_json' => json_encode(['sezione' => ' Sez ', 'stile' => 'elenco', 'decisione' => 'piano', 'colonne' => 'COGNOME, NOME'])]);
        $this->assertSame(['Sez', 'elenco', 'piano', 'COGNOME, NOME'], [$v['sezione'], $v['stile'], $v['decisione'], $v['colonne']]);
        $this->assertSame('', TestiPratica::decisioneModulo(['verbale_json' => '{"decisione":"boh"}']));
        $this->assertSame('convalide', TestiPratica::decisioneModulo(['verbale_json' => '{"decisione":"convalide"}']));
    }

    public function testRichiesteDelloStudenteEDecisioniProposte(): void
    {
        $p = $this->pratica();
        $righe = TestiPratica::righeRichieste($p);
        $this->assertSame(['Fisica', 'Chimica'], array_column($righe, 'richiesto'), 'le righe senza insegnamento si saltano');
        $this->assertSame([1, 'Botanica'], [$righe[0]['piano'], $righe[0]['elimina']]);
        $this->assertArrayNotHasKey('piano', $righe[1]);
        $this->assertSame(['6', '8'], array_column($righe, 'cfu'));
        $d = TestiPratica::decisioniPratica($p, 'convalide');
        $this->assertTrue($d['_proposte']);
        $this->assertSame(['totale', '', 0], [$d['righe'][0]['esito'], $d['righe'][0]['ins'], $d['righe'][0]['ins_id']]);
        $this->assertSame('in_piano', TestiPratica::decisioniPratica($p, 'piano')['righe'][0]['esito']);
        $salvate = ['tipo' => 'convalide', 'righe' => [['richiesto' => 'X']]];
        $this->assertSame($salvate, TestiPratica::decisioniPratica($p + ['decisioni_json' => json_encode($salvate)], 'convalide'), 'le decisioni salvate in seduta valgono più delle proposte');
    }

    public function testQuadroDelleDecisioni(): void
    {
        [$int, $righe] = TestiPratica::tabellaDecisioni(['tipo' => 'piano', 'righe' => [['richiesto' => 'Fisica', 'cfu' => '6', 'esito' => 'fuori_piano']]]);
        $this->assertSame(['Insegnamento', 'CFU', 'Data sostenimento', 'Decisione'], $int);
        $this->assertSame([['Fisica', '6', '', 'Approvato fuori piano']], $righe);
        [$int, $righe] = TestiPratica::tabellaDecisioni(['tipo' => 'convalide', 'righe' => [
            ['richiesto' => 'Zoologia', 'cfu' => '9', 'voto' => '27/30', 'ssd' => 'BIO/05', 'esito' => 'parziale', 'ins' => 'Zoologia generale', 'ins_cfu' => '12', 'cfu_ric' => '9', 'cfu_int' => '3'],
            ['richiesto' => 'Altro', 'cfu' => '6', 'voto' => '', 'ssd' => '', 'esito' => 'no', 'ins' => 'Zoologia', 'ins_cfu' => '', 'cfu_ric' => '0', 'cfu_int' => ''],
        ]]);
        $this->assertCount(10, $int);
        $this->assertSame(['Zoologia', '9', '27/30', 'BIO/05', '', 'Zoologia generale', '12', '9', '3', 'Convalida parziale'], $righe[0]);
        $this->assertSame('—', $righe[1][5], 'non convalidato: nessun insegnamento');
        $this->assertSame("Zoologia | 9 | 27/30 | BIO/05 | Zoologia generale | 12 | 9 | 3 | Convalida parziale\nAltro | 6 | — | 0 | Non convalidato", TestiPratica::testoDecisioni(json_encode(['tipo' => 'convalide', 'righe' => [
            ['richiesto' => 'Zoologia', 'cfu' => '9', 'voto' => '27/30', 'ssd' => 'BIO/05', 'esito' => 'parziale', 'ins' => 'Zoologia generale', 'ins_cfu' => '12', 'cfu_ric' => '9', 'cfu_int' => '3'],
            ['richiesto' => 'Altro', 'cfu' => '6', 'voto' => '', 'ssd' => '', 'esito' => 'no', 'ins' => 'Zoologia', 'ins_cfu' => '', 'cfu_ric' => '0', 'cfu_int' => ''],
        ]])));
        $this->assertSame('', TestiPratica::testoDecisioni('boh'));
    }

    public function testPresenzeEQualifiche(): void
    {
        $this->assertSame(['P' => 2, 'AG' => 1, 'AI' => 0], ServizioSedute::riepilogo([['stato' => 'P'], ['stato' => 'AG'], ['stato' => 'P']]));
        $this->assertSame('06/10/2026 – Consiglio', ServizioSedute::etichetta(['data' => '2026-10-06', 'organo' => 'Consiglio']));
        $this->assertStringEndsWith('…', ServizioSedute::etichetta(['data' => null, 'organo' => str_repeat('x', 200)]));
        $this->assertSame('Professori ordinari', ServizioConsigli::qualificaDaRuolo('Professori Ordinari'));
        $this->assertSame('Professori associati', ServizioConsigli::qualificaDaRuolo('Professore Associato'));
        $this->assertSame('Ricercatori', ServizioConsigli::qualificaDaRuolo('Ricercatore a tempo determinato'));
        $this->assertSame('Docenti a contratto', ServizioConsigli::qualificaDaRuolo('Docente a contratto'));
        $this->assertSame('Rappresentanti degli studenti', ServizioConsigli::qualificaDaRuolo('Rappresentante studenti'));
        $this->assertSame('Professori associati', ServizioConsigli::qualificaDaRuolo('Altro ruolo'));
        $this->assertSame('', ServizioConsigli::qualificaDaRuolo(''));
    }
}
