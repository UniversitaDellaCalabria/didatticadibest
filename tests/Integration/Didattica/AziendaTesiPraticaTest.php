<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\ConvenzioniTesi\AziendaDellaPratica;
use App\ConvenzioniTesi\Costanti as Tesi;
use App\Core\Schema\Definizioni;
use App\Didattica\CampiModulo;
use App\Didattica\LetturaRisposte;
use App\Didattica\ModelliPratiche;
use App\Didattica\ScelteAnagrafe;
use App\Didattica\ServizioFlussoConvalide;

/** La richiesta di tesi con sede esterna sceglie l'azienda tra quelle convenzionate e il portale avvisa se la convenzione manca, è scaduta o sta per scadere. */
final class AziendaTesiPraticaTest extends DidatticaBase
{
    protected function tabelle(): array
    {
        return parent::tabelle() + ['convenzioni_tesi' => Definizioni::tabelle()['convenzioni_tesi']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->servizio(ServizioFlussoConvalide::class)->preparaFlusso();
        // convenzioni: una valida a lungo, una in scadenza (prima della fine di una tesi di 8 mesi), una scaduta, una ancora in corso
        $d = static fn (string $comune): string => (string) json_encode(['comune' => $comune]);
        $this->conv('BioLab Calabria S.r.l.', $d('Rende'), 'protocollata', '2026-01-01', '2031-01-01', '55/2026');
        $this->conv('Geo Studio S.n.c.', $d('Cosenza'), 'protocollata', '2024-12-01', '2026-12-31', '12/2024');
        $this->conv('Vecchia S.p.A.', $d('Rende'), 'protocollata', '2020-01-01', '2025-01-01', '1/2020');
        $this->conv('Ambiente Sud S.r.l.', $d('Castrovillari'), 'da_firmare', null, null, '');
    }

    private function conv(string $azienda, string $dati, string $stato, ?string $dal, ?string $al, string $prot): void
    {
        $this->db->esegui('INSERT INTO convenzioni_tesi (token, stato, azienda, email, dati_json, valida_dal, valida_al, protocollo, consenso_elenco) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [bin2hex(random_bytes(20)), $stato, $azienda, strtolower(str_replace(' ', '', $azienda)) . '@x.it', $dati, $dal, $al, $prot]);
    }

    /** @return array<string, mixed> */
    private function moduloTesi(): array
    {
        return $this->db->riga("SELECT * FROM didattica_moduli WHERE chiave = 'tesi'") ?? [];
    }

    public function testIlModuloTesiHaLaSceltaDellAzienda(): void
    {
        $campi = CampiModulo::da($this->moduloTesi()['campi_json']);
        $per = array_column($campi, null, 'etichetta');
        $this->assertSame('azienda_tesi', $per['Azienda o ente']['tipo']);
        $this->assertTrue($per['Azienda o ente']['obbligatorio']);
        $this->assertSame('uguale', $per['Azienda o ente']['cond']['op'], 'solo per la sede esterna');
        $altra = $per['Nome dell\'azienda o ente (non convenzionata)'];
        $this->assertSame($per['Azienda o ente']['nome'], $altra['cond']['nome'], 'il nome si chiede solo se si sceglie «Altra azienda»');
        $this->assertArrayNotHasKey('Riferimento convenzione', $per);
    }

    public function testElencoDelleAziendeConvenzionate(): void
    {
        $e = $this->servizio(ScelteAnagrafe::class)->per('azienda_tesi');
        $this->assertSame(['BioLab Calabria S.r.l. – Rende', 'Geo Studio S.n.c. – Cosenza'], $e['Aziende convenzionate'], 'solo le valide, con il comune');
        $this->assertSame([Tesi::ALTRA_AZIENDA], $e['Altro']);
    }

    public function testLaLetturaDelModuloControllaLaScelta(): void
    {
        $campi = CampiModulo::da($this->moduloTesi()['campi_json']);
        $nome = array_column($campi, 'nome', 'etichetta')['Azienda o ente'];
        $leggi = fn (string $v): string => (string) (array_column($this->servizio(LetturaRisposte::class)->leggi(array_values(array_filter($campi, static fn (array $c): bool => $c['nome'] === $nome)), ['campo_' . $nome => $v], [], true)[0], 'valore')[0] ?? '');
        $this->assertSame('BioLab Calabria S.r.l. – Rende', $leggi('BioLab Calabria S.r.l. – Rende'));
        $this->assertSame(Tesi::ALTRA_AZIENDA, $leggi(Tesi::ALTRA_AZIENDA));
        $this->assertSame('', $leggi('Vecchia S.p.A. – Rende'), 'una convenzione scaduta non si può scegliere');
        $this->assertSame('', $leggi('Inventata S.r.l.'));
    }

    public function testStatoEAvvisiNellaPratica(): void
    {
        $s = $this->servizio(AziendaDellaPratica::class);
        $risp = static fn (string $v, string $nome = ''): array => array_values(array_filter([
            ['etichetta' => 'Azienda o ente', 'tipo' => 'azienda_tesi', 'valore' => $v],
            $nome !== '' ? ['etichetta' => 'Nome dell\'azienda o ente (non convenzionata)', 'tipo' => 'text', 'valore' => $nome] : null,
        ]));

        $st = $s->stato('BioLab Calabria S.r.l. – Rende');
        $this->assertSame(['valida', '2031-01-01', '55/2026'], [$st['esito'], $st['valida_al'], $st['protocollo']]);
        $this->assertSame('in_scadenza', $s->stato('Geo Studio S.n.c. – Cosenza')['esito'], 'scade il 31/12/2026, prima della fine di una tesi di otto mesi');
        $this->assertSame('scaduta', $s->stato('Vecchia S.p.A. – Rende')['esito']);
        $this->assertSame('non_trovata', $s->stato('Mai sentita S.r.l.')['esito']);
        $this->assertSame('da_chiedere', $s->stato(Tesi::ALTRA_AZIENDA, 'Zoo Lab')['esito']);
        $in = $s->stato(Tesi::ALTRA_AZIENDA, 'ambiente sud');
        $this->assertSame(['in_corso', 'Ambiente Sud S.r.l.'], [$in['esito'], $in['azienda']], 'una convenzione che l\'azienda sta compilando');
        $this->assertStringContainsString('Dal Direttore per la firma', $in['stato_in_corso']);

        // il riquadro: allo studente e all'ufficio, con parole diverse
        $this->assertSame('', $s->riquadro([['etichetta' => 'Note', 'tipo' => 'text', 'valore' => 'x']], false), 'senza azienda nessun riquadro');
        $this->assertStringContainsString('Azienda convenzionata', $s->riquadro($risp('BioLab Calabria S.r.l. – Rende'), false));
        $this->assertStringContainsString('valida fino al 01/01/2031', $s->riquadro($risp('BioLab Calabria S.r.l. – Rende'), false));
        $this->assertStringContainsString('Convenzione in scadenza', $s->riquadro($risp('Geo Studio S.n.c. – Cosenza'), true));
        $this->assertStringContainsString('chiedi all\'azienda di rinnovarla', $s->riquadro($risp('Vecchia S.p.A. – Rende'), false));
        $this->assertStringContainsString('Archivio → Rinnova', $s->riquadro($risp('Vecchia S.p.A. – Rende'), true));
        $studente = $s->riquadro($risp(Tesi::ALTRA_AZIENDA, 'Zoo Lab'), false);
        $this->assertStringContainsString('Azienda non ancora convenzionata', $studente);
        $this->assertStringContainsString('href="richiedi_convenzione_tesi.php"', $studente);
        $this->assertStringContainsString('<strong>Zoo Lab</strong>', $studente);
        $this->assertStringContainsString('(' . 'richiedi_convenzione_tesi.php' . ')', $s->riquadro($risp(Tesi::ALTRA_AZIENDA, 'Zoo Lab'), true));
        // un campo nascosto (sede interna) non conta
        $this->assertSame('', $s->riquadro([['etichetta' => 'Azienda o ente', 'tipo' => 'azienda_tesi', 'valore' => 'BioLab Calabria S.r.l. – Rende', 'nascosto' => 1]], false));
    }

    public function testIlModuloGiaCreatoSiAggiornaSenzaSpostareICampi(): void
    {
        // com'era prima: due campi di testo
        $campi = json_decode($this->moduloTesi()['campi_json'], true);
        $posizioni = [];
        foreach ($campi as $i => $c) {
            if (($c['tipo'] ?? '') === 'azienda_tesi') {
                $campi[$i] = ['tipo' => 'text', 'etichetta' => 'Azienda o ente', 'cond' => $c['cond']];
                $posizioni['azienda'] = $i;
            } elseif (($c['etichetta'] ?? '') === 'Nome dell\'azienda o ente (non convenzionata)') {
                $campi[$i] = ['tipo' => 'text', 'etichetta' => 'Riferimento convenzione', 'cond' => ['campo' => 'Sede del lavoro di tesi', 'op' => 'uguale', 'valore' => 'Esterna']];
                $posizioni['nome'] = $i;
            }
        }
        $this->db->esegui('UPDATE didattica_moduli SET campi_json = ? WHERE chiave = \'tesi\'', [json_encode($campi, JSON_UNESCAPED_UNICODE)]);
        $prima = array_column(CampiModulo::da($this->moduloTesi()['campi_json']), 'nome', 'etichetta');

        (new ModelliPratiche($this->db))->prepara();
        $dopo = CampiModulo::da($this->moduloTesi()['campi_json']);
        $this->assertSame('azienda_tesi', $dopo[$posizioni['azienda']]['tipo']);
        $this->assertSame('Nome dell\'azienda o ente (non convenzionata)', $dopo[$posizioni['nome']]['etichetta']);
        $this->assertSame($prima['Azienda o ente'], $dopo[$posizioni['azienda']]['nome'], 'i nomi dei campi non cambiano: bozze e pratiche già inviate restano a posto');
        $this->assertSame(count($campi), count($dopo));
        // una seconda volta non cambia nulla
        $json = $this->moduloTesi()['campi_json'];
        (new ModelliPratiche($this->db))->prepara();
        $this->assertSame($json, $this->moduloTesi()['campi_json']);
    }
}
