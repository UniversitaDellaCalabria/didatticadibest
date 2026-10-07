<?php

declare(strict_types=1);

namespace Tests\Unit\Fsl;

use App\Fsl\EsitoProgramma;
use App\Fsl\ProgrammaFsl;
use App\Iscrizioni\EsitoPrenotazione;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\AuthSessioneInMemoria;

/** Il programma FSL della scuola nella sessione, i risultati della conferma e l'esito di una singola prenotazione: nessun database. */
final class ProgrammaFslTest extends TestCase
{
    private AuthSessioneInMemoria $sessione;
    private ProgrammaFsl $programma;

    protected function setUp(): void
    {
        $this->sessione = new AuthSessioneInMemoria();
        $this->programma = new ProgrammaFsl($this->sessione);
    }

    public function testProgrammaVuotoAllInizio(): void
    {
        self::assertSame(0, $this->programma->conta());
        self::assertSame([], $this->programma->voci());
        self::assertFalse($this->programma->contiene(5));
        self::assertNull($this->programma->turnoDi(5));
    }

    public function testAggiungeEdizioneERisposteDelModulo(): void
    {
        self::assertTrue($this->programma->aggiungi(5, 51, ['numero_partecipanti' => '12']));
        self::assertTrue($this->programma->aggiungi(7, 71, []));

        self::assertSame(2, $this->programma->conta());
        self::assertTrue($this->programma->contiene(5));
        self::assertSame(51, $this->programma->turnoDi(5));
        self::assertSame(['numero_partecipanti' => '12'], $this->programma->voci()[5]['custom']);
        self::assertSame([5, 7], array_keys($this->programma->voci()), "l'ordine è quello di inserimento");
    }

    public function testUnaSolaEdizionePerAttivita(): void
    {
        $this->programma->aggiungi(5, 51, ['numero_partecipanti' => '12']);
        $this->programma->aggiungi(5, 52, ['numero_partecipanti' => '20']);

        self::assertSame(1, $this->programma->conta());
        self::assertSame(52, $this->programma->turnoDi(5));
        self::assertSame('20', $this->programma->voci()[5]['custom']['numero_partecipanti']);
    }

    public function testTogliESvuota(): void
    {
        $this->programma->aggiungi(5, 51, []);
        $this->programma->aggiungi(7, 71, []);
        $this->programma->togli(5);
        self::assertSame([7], array_keys($this->programma->voci()));

        $this->programma->svuota();
        self::assertSame(0, $this->programma->conta());
    }

    public function testLimiteDiAttivitaNelProgramma(): void
    {
        for ($i = 1; $i <= ProgrammaFsl::MASSIMO; $i++) {
            self::assertTrue($this->programma->aggiungi($i, $i * 10, []));
        }
        self::assertFalse($this->programma->aggiungi(999, 9990, []), 'oltre il limite non si aggiunge');
        self::assertTrue($this->programma->aggiungi(1, 11, ['x' => 'y']), "ma un'attività già nel programma si può sempre modificare");
        self::assertSame(ProgrammaFsl::MASSIMO, $this->programma->conta());
    }

    public function testDatiDellaSessioneDanneggiatiVengonoIgnorati(): void
    {
        $this->sessione->scrivi('programma_fsl', [
            5 => ['turno_id' => 51, 'custom' => ['a' => 'b', 'lista' => ['x']]],
            'zero' => ['turno_id' => 3],
            9 => ['turno_id' => 0],
            11 => 'non un array',
        ]);

        self::assertSame([5], array_keys($this->programma->voci()));
        self::assertSame(['a' => 'b'], $this->programma->voci()[5]['custom'], 'solo valori semplici');
    }

    public function testEsitoDellaPrenotazioneLettoDalRimando(): void
    {
        $ok = new EsitoPrenotazione('fsl.php?progetto=3&status=success&code=FS-ABC123');
        self::assertTrue($ok->riuscita());
        self::assertSame('FS-ABC123', $ok->codice());
        self::assertFalse($ok->listaAttesa());
        self::assertFalse($ok->inAttesaConvenzione());

        $attesa = new EsitoPrenotazione('fsl.php?status=success&code=FS-X&st_tipo=attesa');
        self::assertTrue($attesa->listaAttesa());
        self::assertFalse($attesa->inAttesaConvenzione());

        $senzaConv = new EsitoPrenotazione('fsl.php?status=success&code=FS-X&st_tipo=attesa&conv=no');
        self::assertTrue($senzaConv->listaAttesa() && $senzaConv->inAttesaConvenzione());

        $conv = new EsitoPrenotazione('fsl.php?status=success&code=FS-X&st_tipo=convenzione&conv_rinnovo=1');
        self::assertTrue($conv->inAttesaConvenzione() && $conv->convenzioneDaRinnovare());

        $pieno = new EsitoPrenotazione('fsl.php?progetto=3&status=full');
        self::assertFalse($pieno->riuscita());
        self::assertSame('full', $pieno->stato());
        self::assertNull($pieno->codice());

        $errore = new EsitoPrenotazione('fsl.php');
        self::assertSame('error', $errore->stato());
        self::assertFalse($errore->riuscita());
    }

    public function testEsitoDelProgramma(): void
    {
        $voce = static fn (string $esito): array => ['evento_id' => 1, 'titolo' => 'T', 'turno' => '', 'esito' => $esito, 'codice' => null, 'messaggio' => null];

        $nulla = new EsitoProgramma(['errore']);
        self::assertFalse($nulla->prenotato());
        self::assertSame('si', $nulla->convenzione);

        $misto = new EsitoProgramma([], [$voce('confermata'), $voce('attesa'), $voce('errore'), $voce('attesa')], 'tok', 'no');
        self::assertTrue($misto->prenotato());
        self::assertSame(2, $misto->inListaAttesa());
        self::assertCount(1, $misto->inErrore());

        $soloErrori = new EsitoProgramma([], [$voce('errore')]);
        self::assertFalse($soloErrori->prenotato());
    }
}
