<?php

declare(strict_types=1);

namespace Tests\Unit\Fsl;

use App\Core\Sito;
use App\Fsl\ConvenzioneRepository;
use App\Fsl\Costanti;
use App\Fsl\ModelliConvenzione;
use App\Fsl\PeriodiConvenzione;
use App\Fsl\RegoleClasse;
use App\Fsl\Vista\Istruzioni;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\OrologioFisso;

/** Regole di classe, periodi, modelli e istruzioni della convenzione: nessun database. */
final class FslUnitTest extends TestCase
{
    private function sito(): Sito
    {
        return new Sito(sys_get_temp_dir() . '/sito-inesistente', 'https://portale.test/eventi');
    }

    public function testPrenotazioneDiClasseDiProgettiEdEventi(): void
    {
        $r = new RegoleClasse(new OrologioFisso());
        $this->assertTrue($r->prenotazioneDiClasse(true, null), 'progetto senza scheda: per le scuole');
        $this->assertTrue($r->prenotazioneDiClasse(true, ['per_scuole' => 1]));
        $this->assertFalse($r->prenotazioneDiClasse(true, ['per_scuole' => 0]));
        $this->assertFalse($r->prenotazioneDiClasse(false, null));
        $this->assertTrue($r->prenotazioneDiClasse(false, ['attestati' => 1]));
        $this->assertTrue($r->prenotazioneDiClasse(false, ['dedicata_scuole' => 1]));
        $this->assertTrue($r->prenotazioneDiClasse(false, ['convenzione' => 1]));
        $this->assertFalse($r->prenotazioneDiClasse(false, ['attestati' => 0, 'dedicata_scuole' => 0, 'convenzione' => 0]));
    }

    public function testAttestatiDiClasseSoloConAttestatiEPrenotazioneDiClasse(): void
    {
        $r = new RegoleClasse(new OrologioFisso());
        $this->assertTrue($r->attestatiDiClasse(['evento_tipo' => 'progetto', 'attestati' => 1, 'per_scuole' => 1]));
        $this->assertFalse($r->attestatiDiClasse(['tipo' => 'progetto', 'attestati' => 1, 'per_scuole' => 0]));
        $this->assertTrue($r->attestatiDiClasse(['attestati' => 1, 'dedicata_scuole' => 1]));
        $this->assertTrue($r->attestatiDiClasse(['evento_tipo' => 'evento', 'attestati' => 1]), 'un evento con gli attestati per la classe è di classe');
        $this->assertFalse($r->attestatiDiClasse(['attestati' => 0, 'dedicata_scuole' => 1]));
    }

    public function testAttivitaConclusaSecondoLaDataDiOggi(): void
    {
        $r = new RegoleClasse(new OrologioFisso('2026-10-05 12:00:00'));
        $this->assertTrue($r->attivitaConclusaClasse(['evento_tipo' => 'progetto', 'data_fine' => '2026-10-04']));
        $this->assertFalse($r->attivitaConclusaClasse(['evento_tipo' => 'progetto', 'data_fine' => '2026-10-05']), 'il giorno della fine non è ancora concluso');
        $this->assertFalse($r->attivitaConclusaClasse(['evento_tipo' => 'progetto']));
        $this->assertTrue($r->attivitaConclusaClasse(['data_turno' => '2026-10-04']));
        $this->assertFalse($r->attivitaConclusaClasse(['data_turno' => '2026-10-05']));
        $this->assertTrue($r->attivitaConclusaClasse([]), 'turno senza data: subito');
    }

    public function testCampoPartecipantiSoloPerLePrenotazioniDiClasse(): void
    {
        $r = new RegoleClasse(new OrologioFisso());
        $this->assertTrue($r->campoFormVisibile(['nome_campo' => 'nome'], false, null));
        $this->assertFalse($r->campoFormVisibile(['nome_campo' => CAMPO_PARTECIPANTI], false, null));
        $this->assertTrue($r->campoFormVisibile(['nome_campo' => CAMPO_PARTECIPANTI], false, ['attestati' => 1]));
        $this->assertTrue($r->campoFormVisibile(['nome_campo' => CAMPO_PARTECIPANTI], true, null));
    }

    public function testPeriodoDellAttivita(): void
    {
        $p = new PeriodiConvenzione(new OrologioFisso('2026-10-05 12:00:00'));
        $this->assertSame(['2026-10-10', '2026-12-10'], $p->attivita('2026-10-10', '2026-12-10'));
        $this->assertSame(['2026-11-02', '2026-11-02'], $p->attivita(null, null, '2026-11-02'), 'evento: il giorno del turno');
        $this->assertSame(['2026-01-01', '2026-01-01'], $p->attivita(null, '2026-01-01', null));
        $this->assertSame(['2026-05-05', '2026-05-05'], $p->attivita('2026-05-05', '2026-01-01'), 'la fine non precede l\'inizio');
        $this->assertSame(['2026-10-05', '2026-10-05'], $p->attivita(null, null), 'senza date: oggi');
        $this->assertSame(['2026-01-01', '2026-01-01'], $p->prenotazione(['pd_inizio' => '2026-01-01']));
        $this->assertSame(['2026-03-03', '2026-03-03'], $p->prenotazione(['data_turno' => '2026-03-03']));
    }

    public function testValiditaProposta(): void
    {
        $p = new PeriodiConvenzione(new OrologioFisso('2026-10-05 12:00:00'));
        $this->assertSame(['2026-10-05', '2027-10-04'], $p->nuova());
        $this->assertSame(['2026-01-01', '2030-01-01'], $p->nuova('2026-01-01', '2030-01-01'), 'dall\'inizio dell\'attività, allungata se finisce dopo');
        $this->assertSame(['2026-10-05', '2027-10-04'], $p->nuova('2026-11-01', '2027-01-01'));
    }

    public function testTestoDellaValidita(): void
    {
        $this->assertSame('dal 01/01/2026 al 31/12/2026', PeriodiConvenzione::testoValidita(['data_stipula' => '2026-01-01', 'scadenza' => '2026-12-31']));
        $this->assertSame('fino al 31/12/2026', PeriodiConvenzione::testoValidita(['scadenza' => '2026-12-31']));
        $this->assertSame('dal 01/01/2026, senza scadenza', PeriodiConvenzione::testoValidita(['data_stipula' => '2026-01-01']));
        $this->assertSame('senza scadenza', PeriodiConvenzione::testoValidita([]));
    }

    public function testCodiceMeccanograficoValido(): void
    {
        $this->assertSame('CSPS00001A', ConvenzioneRepository::codiceValido(' csps00001a '));
        $this->assertNull(ConvenzioneRepository::codiceValido('CSPS0001'));
        $this->assertNull(ConvenzioneRepository::codiceValido(''));
        $this->assertNull(ConvenzioneRepository::codiceValido(null));
    }

    public function testModelliEPecDellArea(): void
    {
        $m = new ModelliConvenzione($this->sito());
        $d = $m->dati([]);
        $this->assertSame('https://portale.test/eventi/' . Costanti::URL_MODELLO, $d['modello']);
        $this->assertSame('https://portale.test/eventi/' . Costanti::URL_ALLEGATO, $d['allegato']);
        $this->assertSame(Costanti::PEC, $d['pec']);

        $d = $m->dati(['conv_url_modello' => 'uploads/modelli_convenzione/x.docx', 'conv_url_allegato' => 'https://x.org/a.pdf', 'conv_pec' => 'a@b.it']);
        $this->assertSame('https://portale.test/eventi/uploads/modelli_convenzione/x.docx', $d['modello']);
        $this->assertSame('https://x.org/a.pdf', $d['allegato']);
        $this->assertSame('a@b.it', $d['pec']);

        $d = $m->dati(['conv_url_modello' => '../../etc/passwd', 'conv_url_allegato' => 'uploads/altro/x.pdf', 'conv_pec' => 'non-una-email']);
        $this->assertSame('https://portale.test/eventi/' . Costanti::URL_MODELLO, $d['modello'], 'percorso non ammesso: predefinito');
        $this->assertSame('https://portale.test/eventi/' . Costanti::URL_ALLEGATO, $d['allegato']);
        $this->assertSame(Costanti::PEC, $d['pec']);
    }

    public function testIstruzioniSenzaModelloPrecompilabileElencanoIModelli(): void
    {
        $i = new Istruzioni(new ModelliConvenzione($this->sito()), $this->sito());
        $html = $i->html([]);
        $this->assertStringContainsString('La prenotazione resta <strong>in attesa</strong>', $html);
        $this->assertStringContainsString('Scarica il modello di Convenzione</a> <span style="font-weight:normal;">(DOC)</span>', $html);
        $this->assertStringContainsString("Scarica l'Allegato A</a>", $html);
        $this->assertStringContainsString("href='mailto:" . Costanti::PEC . "'", $html);
        $this->assertStringContainsString("class='fw-bold'", $html);

        $email = $i->html([], true, 'FS-1', false);
        $this->assertStringContainsString("Per partecipare la scuola deve stipulare la <strong>convenzione</strong>", $email);
        $this->assertStringContainsString("style='color:#B30000;font-weight:bold;'", $email);
        $this->assertStringContainsString('Se la scuola l\'ha già inviata, puoi ignorare questo messaggio.', $email);
    }

    public function testIstruzioniConIlModelloDelDipartimentoPropongonoLaCompilazioneOnline(): void
    {
        $sito = new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi');
        $i = new Istruzioni(new ModelliConvenzione($sito), $sito);

        $moduloPrenotazione = $i->html([]);
        $this->assertStringContainsString('<strong>Al termine della prenotazione</strong> potrai compilare online', $moduloPrenotazione);

        $conCodice = $i->html([], false, 'FS-C902');
        $this->assertStringContainsString('https://portale.test/eventi/convenzione_online.php?code=FS-C902', $conCodice);
        $this->assertStringContainsString("class='btn btn-danger btn-sm fw-bold'", $conCodice);

        $perEmail = $i->html([], true, 'FS-C902');
        $this->assertStringContainsString("style='background:#B30000;color:#fff;padding:10px 18px", $perEmail);

        // Con un modello caricato dall'area non c'è la compilazione online
        $proprio = $i->html(['conv_url_modello' => 'https://x.org/m.pdf'], false, 'FS-C902');
        $this->assertStringNotContainsString('convenzione_online.php', $proprio);
        $this->assertStringContainsString('Scarica il modello di Convenzione</a>', $proprio);
    }

    public function testCostantiDellaValutazione(): void
    {
        $this->assertCount(8, Costanti::ASPETTI);
        $this->assertSame(['punti_forza', 'criticita', 'suggerimenti'], array_keys(Costanti::APERTE));
        $this->assertSame(Costanti::SEGNAPOSTI['ISTITUTO'], 'Denominazione Istituzione Scolastica');
    }
}
