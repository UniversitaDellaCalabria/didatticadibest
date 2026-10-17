<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Core\Database;
use App\Portale\CatalogoPagineMenu;
use App\Portale\FonteAree;
use App\Portale\MenuRepository;
use Tests\Integration\DatabaseDiProva;

/** Tutte le pagine del portale nel menu, nascoste: raggruppate per argomento, senza ripetere quelle già presenti, rilanciabile. */
final class CatalogoPagineMenuTest extends DatabaseDiProva
{
    protected function tabelle(): array
    {
        return ['menu_voci' => 'CREATE TABLE menu_voci (id INT AUTO_INCREMENT PRIMARY KEY, genitore_id INT NOT NULL DEFAULT 0, etichetta VARCHAR(150) NOT NULL DEFAULT \'\', url VARCHAR(255) NOT NULL DEFAULT \'\', ordine INT NOT NULL DEFAULT 0, apri_nuova_scheda TINYINT NOT NULL DEFAULT 0, ruolo_visibilita_id INT NOT NULL DEFAULT 0, visibile TINYINT NULL DEFAULT 1)'];
    }

    private function catalogo(): CatalogoPagineMenu
    {
        $aree = new class () implements FonteAree {
            public function areeVisibili(): array
            {
                return [
                    ['slug' => 'openlab', 'titolo' => 'OPENLAB', 'visibile' => 1, 'tipo_area' => 'eventi', 'ambito' => 'orientamento'],
                    ['slug' => 'fsl', 'titolo' => 'FORMAZIONE SCUOLA LAVORO', 'visibile' => 1, 'tipo_area' => 'fsl'],
                    ['slug' => 'aule-laboratori', 'titolo' => 'Aule e laboratori', 'visibile' => 1, 'tipo_area' => 'calendario'],
                    ['slug' => 'vecchia', 'titolo' => 'Vecchia', 'visibile' => 0, 'tipo_area' => 'eventi'],
                ];
            }
        };

        return new CatalogoPagineMenu(new MenuRepository($this->db), $aree);
    }

    /** @return list<array<string, mixed>> */
    private function voci(): array
    {
        return $this->db->righe('SELECT * FROM menu_voci ORDER BY id');
    }

    public function testIlCatalogoHaLeAreeELePagineDelPortale(): void
    {
        $c = $this->catalogo()->catalogo();
        $url = [];
        foreach ($c as $pagine) {
            foreach ($pagine as [, $u]) {
                $url[] = $u;
            }
        }
        foreach (['agenda.php', 'agenda.php?ambito=ricerca', 'openlab.php', 'openlab_archivio.php', 'fsl.php', 'aule-laboratori.php', 'modulistica.php', 'pratiche.php', 'ricevimento.php',
            'aziende_convenzionate_tesi.php', 'richiedi_convenzione_tesi.php', 'area_personale.php', 'guida.php', 'verifica_attestato.php', 'privacy.php'] as $atteso) {
            $this->assertContains($atteso, $url, $atteso);
        }
        $this->assertNotContains('vecchia.php', $url, 'un\'area non visibile non entra');
        $this->assertNotContains('aule-laboratori_archivio.php', $url, 'le aule non hanno l\'archivio degli eventi');
        $this->assertSame(count($url), count(array_unique($url)), 'nessun indirizzo ripetuto');
        $this->assertArrayHasKey('Tesi: enti e aziende', $c);
    }

    public function testCreaLeVociNascosteSenzaRipeterleERilanciabile(): void
    {
        $m = new MenuRepository($this->db);
        // il menu di partenza ha già modulistica e l'agenda (anche con l'indirizzo completo)
        $studenti = $m->inserisci(0, 'Studenti', 'modulistica.php', 1);
        $m->inserisci($studenti, 'Agenda', 'https://portale.test/eventi/agenda.php', 1);
        $prima = count($this->voci());
        [$aggiunte, $presenti] = $this->catalogo()->crea();
        $this->assertGreaterThan(20, $aggiunte);
        $this->assertSame(2, $presenti, 'modulistica e agenda erano già nel menu');
        $voci = $this->voci();
        $this->assertSame($prima + $aggiunte + 1 + count(array_filter($voci, static fn (array $v): bool => (int) $v['genitore_id'] > 0 && $v['url'] === '#')), count($voci), 'pagine + voce radice + colonne');
        $radice = array_values(array_filter($voci, static fn (array $v): bool => $v['etichetta'] === CatalogoPagineMenu::RADICE))[0];
        $this->assertSame(0, (int) $radice['genitore_id']);
        $nuove = array_filter($voci, static fn (array $v): bool => (int) $v['id'] > $prima);
        foreach ($nuove as $v) {
            $this->assertSame(0, (int) $v['visibile'], $v['etichetta'] . ' è nascosta');
        }
        $this->assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM menu_voci WHERE url = 'pratiche.php'"));
        $this->assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM menu_voci WHERE url = 'modulistica.php'"), 'quella che c\'era non si duplica');
        // le due voci di partenza sono rimaste visibili e nel loro posto
        $this->assertSame(1, (int) $this->db->valore("SELECT visibile FROM menu_voci WHERE id = $studenti"));

        // seconda volta: niente di nuovo, niente doppioni
        $quante = count($this->voci());
        [$aggiunte2] = $this->catalogo()->crea();
        $this->assertSame(0, $aggiunte2);
        $this->assertSame($quante, count($this->voci()));
        // una pagina tolta dal menu a mano si rimette alla volta successiva, sotto la stessa colonna
        $this->db->esegui("DELETE FROM menu_voci WHERE url = 'crediti.php'");
        [$aggiunte3] = $this->catalogo()->crea();
        $this->assertSame(1, $aggiunte3);
        $this->assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM menu_voci WHERE etichetta = 'Informazioni'"), 'la colonna non si duplica');
    }
}
