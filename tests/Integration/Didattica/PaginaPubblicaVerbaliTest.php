<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Core\Schema\Definizioni;
use App\Didattica\ServizioVerbaliPubblici;

/** Pagina pubblica dei verbali: chi ha l'accesso a un consiglio vede anche gli altri organi del suo corso, organizzati per organo, con i filtri. */
final class PaginaPubblicaVerbaliTest extends DidatticaBase
{
    private int $x;
    private int $y;

    protected function setUp(): void
    {
        parent::setUp();
        $this->x = $this->db->inserisci("INSERT INTO didattica_consigli (nome) VALUES ('Consiglio Unificato Scienze Naturali')");
        $this->y = $this->db->inserisci("INSERT INTO didattica_consigli (nome) VALUES ('Consiglio Scienze Geologiche')");
        $commX = $this->db->inserisci("INSERT INTO archivio_organi (nome, tipo, corso) VALUES ('Commissione didattica – Consiglio Unificato Scienze Naturali', 'commissione', 'Consiglio Unificato Scienze Naturali')");
        $riesX = $this->db->inserisci("INSERT INTO archivio_organi (nome, tipo, corso) VALUES ('Gruppo di riesame', 'riesame', 'Consiglio Unificato Scienze Naturali')");
        $riesY = $this->db->inserisci("INSERT INTO archivio_organi (nome, tipo, corso) VALUES ('Gruppo di riesame – Geologia', 'riesame', 'Consiglio Scienze Geologiche')");
        $orfano = $this->db->inserisci("INSERT INTO archivio_organi (nome, tipo, corso) VALUES ('Comitato di indirizzo', 'altro', 'Corso senza consiglio')");
        $this->verbale('Consiglio Unificato Scienze Naturali', '2025-06-26', '2024/2025', '4', $this->x, null);
        $this->verbale('Consiglio Unificato Scienze Naturali', '2024-03-01', '2023/2024', '2', $this->x, null);
        $this->verbale('Commissione', '2025-02-02', '2024/2025', '1', null, $commX);
        $this->verbale('Riesame', '2025-04-04', '2024/2025', '3', null, $riesX);
        $this->verbale('Consiglio Scienze Geologiche', '2025-05-05', '2024/2025', '9', $this->y, null);
        $this->verbale('Riesame Geo', '2025-01-01', '2024/2025', '5', null, $riesY);
        $this->verbale('Comitato', '2025-01-02', '2024/2025', '6', null, $orfano);
        // un verbale non pubblicabile
        $this->db->esegui("INSERT INTO didattica_sedute (organo, data, origine, verbale_pdf, consiglio_id, pubblicabile) VALUES ('Segreto', '2025-01-03', 'importato', 'x.pdf', ?, 0)", [$this->x]);
    }

    private function verbale(string $organo, string $data, string $aa, string $n, ?int $consiglio, ?int $organoId): void
    {
        $this->db->esegui("INSERT INTO didattica_sedute (organo, data, anno_accademico, numero_verbale, origine, verbale_pdf, consiglio_id, organo_id, pubblicabile) VALUES (?, ?, ?, ?, 'importato', 'p.pdf', ?, ?, 1)", [$organo, $data, $aa, $n, $consiglio, $organoId]);
    }

    private function s(): ServizioVerbaliPubblici
    {
        return $this->servizio(ServizioVerbaliPubblici::class);
    }

    /** @param list<int|string> $ambiti */
    private function accesso(array $ambiti): array
    {
        return ['id' => 1, 'etichetta' => 'prova', 'ambiti_json' => json_encode($ambiti)];
    }

    /** @return list<string> */
    private function numeri(array $verbali): array
    {
        $n = array_map(static fn (array $v): string => (string) $v['numero_verbale'], $verbali);
        sort($n);

        return $n;
    }

    public function testChiHaIlConsiglioVedeAncheGliAltriOrganiDelSuoCorso(): void
    {
        $this->assertSame(['1', '2', '3', '4'], $this->numeri($this->s()->verbali($this->accesso([$this->x]))), 'consiglio + commissione + riesame del suo corso; non Geologia, non l\'organo senza consiglio, non il non pubblicabile');
        $this->assertSame(['5', '9'], $this->numeri($this->s()->verbali($this->accesso([$this->y]))));
        $this->assertSame(['1', '2', '3', '4', '5', '6', '9'], $this->numeri($this->s()->verbali($this->accesso(['*']))), 'l\'accesso a tutto vede anche l\'organo senza consiglio');
        $this->assertSame([], $this->s()->verbali($this->accesso([])));
    }

    public function testOrganizzaPerCorsoOrganoEAnno(): void
    {
        $o = ServizioVerbaliPubblici::organizza($this->s()->verbali($this->accesso(['*'])));
        $this->assertSame(['Consiglio Scienze Geologiche', 'Consiglio Unificato Scienze Naturali', 'Corso senza consiglio'], $o['corsi']);
        $this->assertSame(['Consiglio', 'Commissione didattica', 'Gruppo di riesame', 'Gruppo di riesame – Geologia', 'Comitato di indirizzo'], $o['organi'], 'il consiglio per primo; il corso in coda al nome non si ripete');
        $this->assertSame(['2024/2025', '2023/2024'], $o['anni']);

        // un corso: gli organi sono quelli del corso
        $o = ServizioVerbaliPubblici::organizza($this->s()->verbali($this->accesso(['*'])), ['corso' => 'Consiglio Unificato Scienze Naturali']);
        $this->assertSame(['Consiglio', 'Commissione didattica', 'Gruppo di riesame'], $o['organi']);
        $this->assertSame(['1', '2', '3', '4'], $this->numeri($o['verbali']));

        // un organo e un anno
        $o = ServizioVerbaliPubblici::organizza($this->s()->verbali($this->accesso(['*'])), ['corso' => 'Consiglio Unificato Scienze Naturali', 'organo' => 'Consiglio', 'aa' => '2023/2024']);
        $this->assertSame(['2'], $this->numeri($o['verbali']));
        $this->assertSame(['2024/2025', '2023/2024'], $o['anni'], 'la tendina degli anni resta quella dell\'organo, anche con un anno scelto');

        // un organo che non è del corso scelto viene ignorato
        $o = ServizioVerbaliPubblici::organizza($this->s()->verbali($this->accesso(['*'])), ['corso' => 'Consiglio Unificato Scienze Naturali', 'organo' => 'Comitato di indirizzo']);
        $this->assertSame(['1', '2', '3', '4'], $this->numeri($o['verbali']));
    }

    public function testVisibilitaPuntuale(): void
    {
        $s = ['pubblicabile' => 1, 'verbale_stato' => '', 'origine' => 'importato', 'verbale_pdf' => 'x.pdf', 'consiglio_id' => null, 'corso_consiglio_id' => $this->x];
        $this->assertTrue(ServizioVerbaliPubblici::visibile($this->accesso([$this->x]), $s));
        $this->assertFalse(ServizioVerbaliPubblici::visibile($this->accesso([$this->y]), $s));
        $this->assertFalse(ServizioVerbaliPubblici::visibile($this->accesso([$this->x]), ['pubblicabile' => 0] + $s));
        $this->assertFalse(ServizioVerbaliPubblici::visibile(['ambiti_json' => 'non json'], $s));
    }
}
