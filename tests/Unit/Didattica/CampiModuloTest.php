<?php

declare(strict_types=1);

namespace Tests\Unit\Didattica;

use App\Didattica\CampiModulo;
use PHPUnit\Framework\TestCase;

/** I campi del modulo letti dal JSON del costruttore: tipi, colonne, condizioni e colonna del piano di studi. */
final class CampiModuloTest extends TestCase
{
    public function testCampiNormalizzati(): void
    {
        $campi = CampiModulo::da(json_encode([
            ['etichetta' => ' Corso ', 'tipo' => 'corso_studio', 'obbligatorio' => 1],
            ['etichetta' => '', 'tipo' => 'text'],
            ['etichetta' => 'Scelta', 'tipo' => 'select', 'opzioni' => "Sì, No;Forse\nMai"],
            ['etichetta' => 'Titolo', 'tipo' => 'titolo', 'obbligatorio' => 1],
            ['etichetta' => 'Strano', 'tipo' => 'inesistente'],
            ['etichetta' => 'Esami', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento:insegnamento, CFU, Voto'],
            ['etichetta' => 'Dettaglio', 'tipo' => 'text', 'cond' => ['campo' => 'scelta', 'op' => 'uguale', 'valore' => 'Sì'], 'auto' => ['campo' => 'Sé stesso', 'op' => 'uguale', 'valore' => 'x', 'imposta' => 'y']],
            ['etichetta' => 'Sé stesso', 'tipo' => 'text', 'cond' => ['campo' => 'Sé stesso', 'op' => 'vuoto']],
        ]));
        $this->assertSame(['c1', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8'], array_column($campi, 'nome'), 'le righe senza domanda si saltano ma la numerazione resta');
        $this->assertSame('Corso', $campi[0]['etichetta']);
        $this->assertTrue($campi[0]['obbligatorio']);
        $this->assertSame(['Sì', 'No', 'Forse', 'Mai'], $campi[1]['opzioni']);
        $this->assertFalse($campi[2]['obbligatorio'], 'i titoli non sono obbligatori');
        $this->assertSame('text', $campi[3]['tipo'], 'tipo sconosciuto: testo breve');
        $this->assertSame(['Insegnamento', 'CFU', 'Voto'], $campi[4]['opzioni']);
        $this->assertSame(['insegnamento', 'cfu', 'voto'], array_column($campi[4]['colonne'], 'tipo'));
        $this->assertSame('c3', $campi[5]['cond']['nome'], 'la condizione si risolve sul nome del campo');
        $this->assertSame(['c8', 'y'], [$campi[5]['auto']['nome'], $campi[5]['auto']['imposta']]);
        $this->assertNull($campi[6]['cond'], 'una condizione su sé stesso si toglie');
    }

    public function testColonneDelleTabelle(): void
    {
        $col = CampiModulo::colonne(['Esito:scelta(Sì|No)', 'Voto', 'S.S.D.', 'Data', 'Relatore', 'Tot. CFU', 'CFU da integrare', 'Piano di studi', 'Codice', ':testo', 'Denominazione']);
        $this->assertSame(['scelta', 'voto', 'ssd', 'data', 'docente', 'cfu', 'testo', 'piano', 'codice', 'denominazione'], array_column($col, 'tipo'));
        $this->assertSame(['Sì', 'No'], $col[0]['scelte']);
        $this->assertSame('Esito', $col[0]['nome']);
        $this->assertSame([['nome' => 'Descrizione', 'tipo' => 'testo', 'scelte' => []]], CampiModulo::colonne([]), 'senza colonne ne resta una');
        $this->assertSame('Esito:scelta(Sì|No), Voto:voto', CampiModulo::testoColonne([$col[0], $col[1]]));
        $this->assertSame('insegnamento', CampiModulo::tipoColonnaDaNome('Esame sostenuto'));
    }

    public function testCondizioni(): void
    {
        $c = fn (string $op, string $v = '') => ['op' => $op, 'valore' => $v];
        $this->assertTrue(CampiModulo::condizioneVera($c('uguale', 'Sì'), ' sì '));
        $this->assertTrue(CampiModulo::condizioneVera($c('uguale', 'b'), 'a, B, c'), 'scelta multipla: basta una');
        $this->assertFalse(CampiModulo::condizioneVera($c('uguale', 'd'), 'a, b'));
        $this->assertTrue(CampiModulo::condizioneVera($c('diverso', 'd'), 'a, b'));
        $this->assertTrue(CampiModulo::condizioneVera($c('contiene', 'ate'), 'Università'.'ate'));
        $this->assertFalse(CampiModulo::condizioneVera($c('contiene', ''), 'x'));
        $this->assertTrue(CampiModulo::condizioneVera($c('compilato'), 'x'));
        $this->assertTrue(CampiModulo::condizioneVera($c('vuoto'), '  '));
        $this->assertTrue(CampiModulo::condizioneVera($c('boh'), 'x'));
    }

    public function testCampiDelloStudenteEDellUfficio(): void
    {
        $campi = CampiModulo::da(json_encode([
            ['etichetta' => 'A', 'tipo' => 'text'],
            ['etichetta' => 'B', 'tipo' => 'text', 'ufficio' => 1],
            ['etichetta' => 'C', 'tipo' => 'file', 'ufficio' => 1],
            ['etichetta' => 'D', 'tipo' => 'info', 'ufficio' => 1],
        ]));
        $this->assertSame(['A'], array_column(CampiModulo::studente($campi), 'etichetta'));
        $this->assertSame(['B'], array_column(CampiModulo::ufficio($campi), 'etichetta'), 'gli allegati e il solo testo non li compila l\'ufficio');
    }

    public function testColonnaDelPiano(): void
    {
        $this->assertSame([false, ''], CampiModulo::valorePiano(''));
        $this->assertSame([true, ''], CampiModulo::valorePiano('A scelta'));
        $this->assertSame([true, 'Botanica – Biologia'], CampiModulo::valorePiano(' A scelta; elimina: Botanica – Biologia '));
        $this->assertSame([false, ''], CampiModulo::valorePiano('Sì'));
    }
}
