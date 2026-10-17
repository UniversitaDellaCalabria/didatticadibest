<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Core\Schema\Definizioni;
use App\Didattica\NotifichePratiche;
use App\Didattica\PraticaRepository;
use App\Didattica\SondaggioPratica;

/** Sondaggio facoltativo a fine pratica: link solo a pratica conclusa, una sola risposta, riepilogo anonimo. */
final class SondaggioPraticaTest extends DidatticaBase
{
    protected function tabelle(): array
    {
        return ['pratiche_sondaggi' => Definizioni::tabelle()['pratiche_sondaggi'], 'operatori_sostituzioni' => Definizioni::tabelle()['operatori_sostituzioni']] + parent::tabelle();
    }

    private function s(): SondaggioPratica
    {
        return $this->servizio(SondaggioPratica::class);
    }

    private function pratica(string $stato, string $codice = 'P1'): array
    {
        $m = $this->db->valore("SELECT id FROM didattica_moduli WHERE titolo = 'Richiesta tesi'") ?: $this->modulo('Richiesta tesi');
        $id = $this->db->inserisci("INSERT INTO pratiche (modulo_id, codice, nome, cognome, email, stato, creata_il) VALUES (?, ?, 'Luca', 'Rossi', 'l@x.it', ?, '2026-10-01 10:00:00')", [$m, $codice, $stato]);

        return $this->servizio(PraticaRepository::class)->perId($id);
    }

    public function testIlLinkCompareSoloAPraticaConclusaEUnaVolta(): void
    {
        $this->assertSame('', $this->s()->link($this->pratica('in_lavorazione')));
        $p = $this->pratica('accolta', 'P2');
        $link = $this->s()->link($p);
        $this->assertStringContainsString('/sondaggio_pratica.php?t=', $link);
        $this->assertSame($link, $this->s()->link($p), 'lo stesso link ogni volta');
        $t = substr($link, strpos($link, 't=') + 2);
        $this->assertNull($this->s()->rispondi($t, 5, 4, ' Tutto chiaro '));
        $this->assertSame('', $this->s()->link($p), 'dopo la risposta il link non si propone più');
        $this->assertStringContainsString('già risposto', (string) $this->s()->rispondi($t, 1, 1, ''));
    }

    public function testControlliSullaRisposta(): void
    {
        $p = $this->pratica('chiusa');
        $t = substr($this->s()->link($p), -32);
        $this->assertStringContainsString('da 1 a 5', (string) $this->s()->rispondi($t, 0, 3, ''));
        $this->assertStringContainsString('da 1 a 5', (string) $this->s()->rispondi($t, 3, 6, ''));
        $this->assertStringContainsString('non è valido', (string) $this->s()->rispondi('xyz', 3, 3, ''));
        $this->assertStringContainsString('non è valido', (string) $this->s()->rispondi(str_repeat('a', 32), 3, 3, ''));
        $this->assertNull($this->s()->perToken(str_repeat('a', 32)));
        // se la pratica viene riaperta il link non funziona
        $this->db->esegui("UPDATE pratiche SET stato = 'in_lavorazione' WHERE id = ?", [$p['id']]);
        $this->assertNull($this->s()->perToken($t));
    }

    public function testRiepilogoAnonimoPerModulo(): void
    {
        foreach ([['A1', 5, 4, 'Ottimo'], ['A2', 3, 2, ''], ['A3', 4, 4, 'Veloci']] as [$cod, $a, $b, $c]) {
            $p = $this->pratica('accolta', $cod);
            $this->s()->rispondi(substr($this->s()->link($p), -32), $a, $b, $c);
        }
        $this->s()->link($this->pratica('respinta', 'A4')); // concluse ma senza risposta
        $r = $this->s()->riepilogo('2026-09-01', '2026-10-31');
        $this->assertSame([4, 3], [$r['conclusi'], $r['risposte']]);
        $this->assertSame([4.0, 3.3], [$r['media_servizio'], $r['media_tempi']]);
        $this->assertSame(3, $r['per_modulo']['Richiesta tesi']['n']);
        $this->assertCount(2, $r['commenti'], 'solo chi ha scritto');
        $this->assertArrayNotHasKey('nome', $r['commenti'][0]);
        $this->assertSame(0, $this->s()->riepilogo('2025-01-01', '2025-02-01')['risposte']);
    }

    public function testLEmailDiConclusioneHaIlLinkMaNonQuellaDiLavorazione(): void
    {
        $this->servizio(NotifichePratiche::class)->email($this->pratica('accolta'), 'stato');
        $this->servizio(NotifichePratiche::class)->email($this->pratica('in_lavorazione', 'P9'), 'stato');
        $this->assertCount(2, $this->mailer->inviate);
        $this->assertStringContainsString('sondaggio_pratica.php?t=', $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('facoltativo', $this->mailer->inviate[0]['corpo']);
        $this->assertStringNotContainsString('sondaggio_pratica', $this->mailer->inviate[1]['corpo']);
    }
}
