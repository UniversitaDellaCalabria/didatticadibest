<?php

declare(strict_types=1);

namespace Tests\Unit\Iscritti;

use App\Iscritti\FiltriIscritti;
use App\Iscritti\Operatore;
use App\Iscritti\RisultatoMassivo;
use App\Iscritti\ServizioIscritti;
use App\Iscritti\Vista\EsportaIscritti;
use PHPUnit\Framework\TestCase;

/** Parti del modulo Iscritti che non toccano il database: filtri, messaggi delle azioni di massa, campi del modulo, esportazioni. */
final class IscrittiUnitTest extends TestCase
{
    public function testFiltriSenzaCondizioniExtra(): void
    {
        [$sql, $par] = (new FiltriIscritti(3))->where();
        self::assertSame('WHERE e.pagina_id = ? AND e.archiviato = ? ', $sql);
        self::assertSame([3, 0], $par);
    }

    public function testFiltriCompletiConParametriNellOrdineDeiSegnaposto(): void
    {
        $f = new FiltriIscritti(3, 1, 110, 'in_attesa', "o'brien", '2026-10-01', '2026-10-31', ' AND e.id IN (5) ');
        [$sql, $par] = $f->where();
        self::assertSame(substr_count($sql, '?'), count($par));
        self::assertStringContainsString("AND t.id = ? AND IFNULL(pr.stato, 'confermata') = ? AND (pr.nome LIKE ? OR pr.cognome LIKE ? OR pr.email LIKE ? OR pr.codice_prenotazione LIKE ?) AND t.data_turno BETWEEN ? AND ?", $sql);
        self::assertStringEndsWith(' AND e.id IN (5) ', $sql);
        self::assertSame([3, 1, 110, 'in_attesa', "%o'brien%", "%o'brien%", "%o'brien%", "%o'brien%", '2026-10-01', '2026-10-31'], $par);
    }

    public function testFiltriConUnaSolaData(): void
    {
        self::assertStringContainsString('AND t.data_turno >= ?', (new FiltriIscritti(1, 0, 0, '', '', '2026-10-01'))->where()[0]);
        self::assertStringContainsString('AND t.data_turno <= ?', (new FiltriIscritti(1, 0, 0, '', '', '', '2026-10-31'))->where()[0]);
    }

    public function testLaRicercaZeroNonFiltra(): void
    {
        self::assertStringNotContainsString('LIKE', (new FiltriIscritti(1, 0, 0, '', '0'))->where()[0]);
    }

    public function testStatiAmmessi(): void
    {
        self::assertSame(['confermata', 'in_attesa', 'da_approvare', 'annullata', 'rifiutata', 'scaduta'], FiltriIscritti::STATI);
    }

    public function testMessaggioDelleAzioniDiMassa(): void
    {
        $ok = new RisultatoMassivo('presente', 3, 0, 0);
        self::assertSame('3 prenotazioni segnate presenti.', $ok->messaggio());
        self::assertSame('success', $ok->tipo());
        $misto = new RisultatoMassivo('promuovi', 2, 1, 1);
        self::assertSame("2 prenotazioni promosse dalla lista d'attesa. 1 saltate (stato non compatibile con l'azione o permessi mancanti). Attenzione: 1 promozioni superano la capienza del turno.", $misto->messaggio());
        self::assertSame('warning', $misto->tipo());
        foreach (array_keys(ServizioIscritti::AZIONI_DI_MASSA) as $azione) {
            self::assertStringStartsWith('0 prenotazioni ', (new RisultatoMassivo($azione, 0, 0, 0))->messaggio());
        }
    }

    public function testCampiDelModuloDalPost(): void
    {
        $dati = ServizioIscritti::campiDaPost(['nome' => 'Ada', 'custom_dieta' => '  Vegana ', 'custom_multi' => ['a', 'b'], 'custom_' => 'x', 'csrf_token' => 'z', 'custom_n' => '']);
        self::assertSame(['dieta' => 'Vegana', 'multi' => 'a, b', '' => 'x', 'n' => ''], $dati);
    }

    public function testOperatorePredefinito(): void
    {
        $op = new Operatore(4);
        self::assertSame([4, '', 'Sconosciuto'], [$op->id, $op->email, $op->ip]);
    }

    public function testEsportazioneExcel(): void
    {
        ob_start();
        EsportaIscritti::excel([$this->riga()], ['dieta' => 'Dieta', 'Docente Rif' => 'Docente Rif'], true);
        $html = (string) ob_get_clean();
        self::assertStringStartsWith('<html xmlns:o="urn:schemas-microsoft-com:office:office"', $html);
        self::assertStringContainsString('<th>Ora</th><th>Dieta</th><th>Docente Rif</th><th>N. studenti in elenco</th><th>Studenti (per gli attestati)</th><th>Data Registrazione</th></tr>', $html);
        self::assertStringContainsString('<td>T1</td><td>confermata</td><td>SI</td><td>2</td><td>Anna &amp; Co</td>', $html);
        // valore trovato per nome esatto, e per nome che differisce per maiuscole e spazi dell'etichetta
        self::assertStringContainsString('<td>Vegana</td><td>Prof. X</td><td>2</td><td>Alfa Aldo; Beta Bea</td><td>2026-10-01 10:00:00</td></tr></table></body></html>', $html);
    }

    public function testEsportazioneCsvSenzaStudenti(): void
    {
        ob_start();
        EsportaIscritti::csv([$this->riga()], ['dieta' => 'Dieta'], false);
        $csv = (string) ob_get_clean();
        $righe = explode("\n", trim($csv));
        self::assertSame('Codice,Stato,Presenza,Posti,Nome,Cognome,Matricola,Email,Evento,Turno,Data,Ora,Dieta,"Data Registrazione"', $righe[0]);
        self::assertSame('T1,confermata,SI,2,"Anna & Co",Rossi,M1,a@x.it,Genetica,"Turno A",2999-10-23,09:00:00,Vegana,"2026-10-01 10:00:00"', $righe[1]);
    }

    /** @return array<string, mixed> */
    private function riga(): array
    {
        return ['codice_prenotazione' => 'T1', 'stato' => 'confermata', 'presente' => '1', 'num_posti' => '2', 'nome' => 'Anna & Co', 'cognome' => 'Rossi',
            'matricola_effettiva' => 'M1', 'email' => 'a@x.it', 'evento' => 'Genetica', 'nome_turno' => 'Turno A', 'data_turno' => '2999-10-23', 'orario_inizio' => '09:00:00',
            'dati_custom_json' => '{"dieta":"Vegana","docente_rif":"Prof. X"}', 'n_studenti' => '2', 'studenti' => 'Alfa Aldo; Beta Bea', 'data_prenotazione' => '2026-10-01 10:00:00'];
    }
}
