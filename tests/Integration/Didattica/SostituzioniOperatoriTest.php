<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Core\Schema\Definizioni;
use App\Didattica\NotifichePratiche;
use App\Didattica\PraticaRepository;
use App\Didattica\SostituzioniOperatori;
use App\Portale\DaFarePersona;

/** Sostituzioni degli operatori: il sostituto riceve le email dell'assente e ne vede le pratiche, solo nel periodo indicato. */
final class SostituzioniOperatoriTest extends DidatticaBase
{
    private int $anna;
    private int $bruno;
    private int $carla;

    protected function tabelle(): array
    {
        return ['operatori_sostituzioni' => Definizioni::tabelle()['operatori_sostituzioni']] + parent::tabelle();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->operatore('Anna Verdi', 'anna@x.it');
        $this->bruno = $this->operatore('Bruno Neri', 'bruno@x.it');
        $this->carla = $this->operatore('Carla Gialli', 'carla@x.it');
    }

    private function s(): SostituzioniOperatori
    {
        return $this->servizio(SostituzioniOperatori::class);
    }

    private function pratica(int $inCaricoA): array
    {
        $m = $this->modulo('Richiesta tesi');
        $id = $this->db->inserisci("INSERT INTO pratiche (modulo_id, codice, nome, cognome, email, stato, assegnata_a, passo) VALUES (?, 'P1', 'Luca', 'Rossi', 'l@x.it', 'in_lavorazione', ?, 1)", [$m, $inCaricoA]);

        return $this->servizio(PraticaRepository::class)->perId($id);
    }

    public function testRegistraEControllaLeDate(): void
    {
        $this->assertNull($this->s()->aggiungi($this->anna, $this->bruno, '2026-10-05', '2026-10-20', 'ferie', 1));
        $this->assertStringContainsString('già una sostituzione', (string) $this->s()->aggiungi($this->anna, $this->carla, '2026-10-15', '2026-10-30', '', 1), 'periodi sovrapposti');
        $this->assertStringContainsString('sé stessa', (string) $this->s()->aggiungi($this->anna, $this->anna, '2026-11-01', '2026-11-05', '', 1));
        $this->assertStringContainsString('prima di quella di inizio', (string) $this->s()->aggiungi($this->bruno, $this->carla, '2026-11-05', '2026-11-01', '', 1));
        $this->assertStringContainsString('già finito', (string) $this->s()->aggiungi($this->bruno, $this->carla, '2026-09-01', '2026-09-30', '', 1));
        $this->assertStringContainsString('un anno', (string) $this->s()->aggiungi($this->bruno, $this->carla, '2026-10-06', '2028-10-06', '', 1));
        $this->assertStringContainsString('due persone', (string) $this->s()->aggiungi($this->bruno, 9999, '2026-11-01', '2026-11-05', '', 1));
        $this->assertStringContainsString('date', (string) $this->s()->aggiungi($this->bruno, $this->carla, 'ieri', '2026-11-05', '', 1));
        $this->assertCount(1, $this->s()->elenco());
    }

    public function testSoloNelPeriodo(): void
    {
        // l'orologio dei test è al 6 ottobre 2026
        $this->s()->aggiungi($this->anna, $this->bruno, '2026-10-05', '2026-10-06', 'oggi', 1);
        $this->s()->aggiungi($this->bruno, $this->carla, '2026-10-07', '2026-10-20', 'domani', 1);
        $this->assertSame($this->bruno, (int) $this->s()->sostitutoDi($this->anna)['id']);
        $this->assertNull($this->s()->sostitutoDi($this->bruno), 'quella di Bruno comincia domani');
        $this->assertSame([$this->anna], $this->s()->sostituisce($this->bruno));
        $this->assertSame(['anna@x.it', 'bruno@x.it'], $this->s()->destinatari($this->servizio(\App\Didattica\ServizioUffici::class)->operatore($this->anna)));
        $this->assertSame(['carla@x.it'], $this->s()->destinatari($this->servizio(\App\Didattica\ServizioUffici::class)->operatore($this->carla)));
        $this->s()->elimina((int) $this->s()->elenco()[0]['id']);
        $this->assertNull($this->s()->sostitutoDi($this->anna));
    }

    public function testIlMessaggioDelloStudenteArrivaAncheAlSostituto(): void
    {
        $p = $this->pratica($this->anna);
        $this->servizio(NotifichePratiche::class)->email($p, 'msg_studente', 'Buongiorno');
        $this->assertSame(['anna@x.it'], array_column($this->mailer->inviate, 'a'));

        $this->mailer->inviate = [];
        $this->s()->aggiungi($this->anna, $this->bruno, '2026-10-01', '2026-10-31', 'ferie', 1);
        $this->servizio(NotifichePratiche::class)->email($p, 'msg_studente', 'Buongiorno');
        $this->assertSame(['anna@x.it', 'bruno@x.it'], array_column($this->mailer->inviate, 'a'));
    }

    public function testElencoEDaFareDelSostituto(): void
    {
        $this->pratica($this->anna);
        $repo = $this->servizio(PraticaRepository::class);
        $elenco = fn (array $io): int => count($repo->elenco('aperte', 0, '', 'me', '', '', '', $io));
        $this->assertSame(0, $elenco(['id' => $this->bruno]));
        $this->s()->aggiungi($this->anna, $this->bruno, '2026-10-01', '2026-10-31', '', 1);
        $this->assertSame(0, $elenco(['id' => $this->bruno]), 'senza l\'elenco dei sostituiti non cambia nulla');
        $this->assertSame(1, $elenco(['id' => $this->bruno, '_sostituisce' => $this->s()->sostituisce($this->bruno)]));

        $dafare = $this->servizio(DaFarePersona::class)->gruppi(['email' => 'bruno@x.it']);
        $g = array_column($dafare, null, 'chiave');
        $this->assertArrayHasKey('pratiche', $g);
        $this->assertStringContainsString('stai sostituendo', json_encode($g['pratiche'], JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('pratiche', array_column($this->servizio(DaFarePersona::class)->gruppi(['email' => 'carla@x.it']), null, 'chiave'));
    }
}
