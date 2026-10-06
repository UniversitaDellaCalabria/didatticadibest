<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Core\Sito;
use App\Portale\AreeRepository;
use App\Portale\CacheConfigurazione;
use App\Portale\ConfigurazioneRepository;
use App\Portale\HomeRepository;
use App\Portale\MenuRepository;
use App\Portale\ServizioMenu;
use App\Portale\Visitatore;
use Tests\Integration\DatabaseDiProva;

final class PortaleIntegrazioneTest extends DatabaseDiProva
{
    private string $radice;

    protected function tabelle(): array
    {
        return [
            'menu_voci' => "CREATE TABLE menu_voci (id INT AUTO_INCREMENT PRIMARY KEY, genitore_id INT DEFAULT 0, etichetta VARCHAR(100), url VARCHAR(255) NULL, ordine INT DEFAULT 0,
                apri_nuova_scheda TINYINT DEFAULT 0, ruolo_visibilita_id INT DEFAULT 0, visibile TINYINT NULL)",
            'configurazione_portale' => "CREATE TABLE configurazione_portale (id INT PRIMARY KEY, nome_portale VARCHAR(100) NULL, widgets_home TEXT NULL, annuncio_home TEXT NULL, annuncio_colore VARCHAR(20) NULL,
                logo_path VARCHAR(255) NULL, footer_copyright VARCHAR(255) NULL)",
            'slide_home' => "CREATE TABLE slide_home (id INT AUTO_INCREMENT PRIMARY KEY, immagine_path VARCHAR(255), titolo VARCHAR(100) DEFAULT '', sottotitolo VARCHAR(100) DEFAULT '', link VARCHAR(255) DEFAULT '',
                ordine INT DEFAULT 0, attiva TINYINT DEFAULT 1)",
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, titolo VARCHAR(100), slug VARCHAR(50), ordine INT DEFAULT 0, visibile TINYINT DEFAULT 1, colore_primario VARCHAR(10) NULL)",
            'campi_form' => 'CREATE TABLE campi_form (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT)',
            'sottocategorie' => 'CREATE TABLE sottocategorie (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT)',
            'eventi' => "CREATE TABLE eventi (id INT PRIMARY KEY, pagina_id INT, titolo VARCHAR(100) DEFAULT '', tipo VARCHAR(20) NULL, archiviato TINYINT DEFAULT 0, locandina_path VARCHAR(100) NULL)",
            'turni' => 'CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, data_turno DATE NULL, orario_inizio TIME NULL)',
            'prenotazioni' => 'CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, stato VARCHAR(30) NULL)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radice = sys_get_temp_dir() . '/portale_prova_' . uniqid();
        mkdir($this->radice . '/cache', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->radice . '/cache/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->radice . '/cache');
        @rmdir($this->radice);
    }

    private function voce(int $id, int $genitore, string $et, int $ordine = 0, int $vis = 0, ?int $visibile = 1): void
    {
        $this->db->esegui('INSERT INTO menu_voci (id, genitore_id, etichetta, url, ordine, ruolo_visibilita_id, visibile) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $genitore, $et, "$et.php", $ordine, $vis, $visibile]);
    }

    public function testMenuFiltraPerVisitatoreEPerVisibilita(): void
    {
        $this->voce(1, 0, 'Home', 1);
        $this->voce(2, 0, 'Studenti', 2, 3);
        $this->voce(3, 0, 'Riservata', 3, -1);
        $this->voce(4, 0, 'Nascosta', 4, 0, 0);
        $this->voce(5, 0, 'Archivio', 5);
        $this->voce(6, 5, 'Colonna', 1);
        $this->voce(7, 6, 'Link', 1);
        $this->voce(8, 5, 'SoloAdmin', 2, 1);
        $servizio = new ServizioMenu(new MenuRepository($this->db));

        $ospite = $servizio->menuPrincipale(Visitatore::daSessione([]));
        self::assertSame(['Home', 'Archivio'], array_map(static fn ($v): string => $v->etichetta, $ospite));
        $archivio = $ospite[1];
        self::assertTrue($archivio->conSottovoci);
        self::assertSame(['Colonna'], array_map(static fn ($v): string => $v->etichetta, $archivio->figli), 'la voce per il ruolo 1 non si vede');
        self::assertSame(['Link'], array_map(static fn ($v): string => $v->etichetta, $archivio->figli[0]->figli));
        self::assertFalse($ospite[0]->conSottovoci);

        $studente = $servizio->menuPrincipale(Visitatore::daSessione(['utente_id' => 9, 'utente_ruolo_id' => 3]));
        self::assertSame(['Home', 'Studenti', 'Riservata', 'Archivio'], array_map(static fn ($v): string => $v->etichetta, $studente));
        $admin = $servizio->menuPrincipale(Visitatore::daSessione(['utente_id' => 1, 'utente_ruolo_id' => 1]));
        self::assertCount(2, $admin[3]->figli, "l'amministratore vede anche le voci riservate");
    }

    public function testVoceConSoleSottovociNascosteResteATendina(): void
    {
        $this->voce(1, 0, 'Archivio');
        $this->voce(2, 1, 'Riservata', 1, 5);
        $voci = (new ServizioMenu(new MenuRepository($this->db)))->menuPrincipale(Visitatore::daSessione([]));
        self::assertTrue($voci[0]->conSottovoci, 'come prima: resta un menu a tendina, vuoto');
        self::assertSame([], $voci[0]->figli);
    }

    public function testGestioneDelMenuDalPannello(): void
    {
        $m = new MenuRepository($this->db);
        $m->inserisciDaPannello(0, "O'Brien", 'a.php', 3, true, -1);
        $id = (int) $this->db->valore('SELECT MAX(id) FROM menu_voci');
        $m->inserisciDaPannello($id, 'Figlia', '#', 1, false, 0);
        $idF = (int) $this->db->valore('SELECT MAX(id) FROM menu_voci');
        $m->inserisciDaPannello($idF, 'Nipote', 'n.php', 1, false, 0);
        $r = $this->db->riga('SELECT * FROM menu_voci WHERE id = ?', [$id]);
        self::assertSame([-1, 1, 1, "O'Brien"], [(int) $r['ruolo_visibilita_id'], (int) $r['apri_nuova_scheda'], (int) $r['visibile'], $r['etichetta']]);
        $m->aggiornaDaPannello($idF, $id, 'Figlia2', 'f.php', 7, true, 2);
        self::assertSame('Figlia2', $this->db->valore('SELECT etichetta FROM menu_voci WHERE id = ?', [$idF]));
        $m->alternaVisibilita($id);
        self::assertSame('0', (string) $this->db->valore('SELECT visibile FROM menu_voci WHERE id = ?', [$id]));
        $m->alternaVisibilita($id);
        self::assertSame('1', (string) $this->db->valore('SELECT visibile FROM menu_voci WHERE id = ?', [$id]));
        self::assertSame(['Figlia2'], array_column($m->figli($id), 'etichetta'));
        self::assertSame(1, $m->quanteVisibili([$id, $idF]) - 1);
        $m->eliminaConSottomenu($id);
        self::assertSame('0', (string) $this->db->valore('SELECT COUNT(*) FROM menu_voci'), 'voce, figli e nipoti');
    }

    public function testCacheConfigurazioneSuFile(): void
    {
        $this->db->esegui("INSERT INTO configurazione_portale (id, nome_portale) VALUES (1, 'Uno')");
        $sito = new Sito($this->radice);
        $repo = new ConfigurazioneRepository($this->db);
        $cache = new CacheConfigurazione($repo, $sito);
        self::assertSame('Uno', $cache->leggi()['nome_portale']);
        self::assertFileExists($this->radice . '/cache/configurazione_portale.json');
        $this->db->esegui("UPDATE configurazione_portale SET nome_portale = 'Due'");
        self::assertSame('Uno', $cache->leggi()['nome_portale'], 'memoizzata nella richiesta');
        self::assertSame('Uno', (new CacheConfigurazione($repo, $sito))->leggi()['nome_portale'], 'letta dal file finché non scade');
        $cache->invalida();
        self::assertFileDoesNotExist($this->radice . '/cache/configurazione_portale.json');
        self::assertSame('Due', (new CacheConfigurazione($repo, $sito))->leggi()['nome_portale']);
        touch($this->radice . '/cache/configurazione_portale.json', time() - 400);
        $this->db->esegui("UPDATE configurazione_portale SET nome_portale = 'Tre'");
        self::assertSame('Tre', (new CacheConfigurazione($repo, $sito))->leggi()['nome_portale'], 'dopo 5 minuti si rilegge dal database');
    }

    public function testSalvataggioTestataESlide(): void
    {
        $this->db->esegui("INSERT INTO configurazione_portale (id, nome_portale, logo_path) VALUES (1, 'Uno', 'uploads/vecchio.png')");
        $c = new ConfigurazioneRepository($this->db);
        $c->salvaTestata(['nome_portale' => "L'Ateneo", 'footer_copyright' => 'C'], ['logo_path' => '']);
        $r = $c->riga();
        self::assertSame(["L'Ateneo", 'C', ''], [$r['nome_portale'], $r['footer_copyright'], $r['logo_path']]);
        $c->salvaTestata(['nome_portale' => 'X', 'footer_copyright' => 'C'], []);
        self::assertSame('', $c->riga()['logo_path'], 'senza immagini indicate restano quelle di prima');
        $c->salvaHome('{"a":1}', "Avviso 'x'", 'info');
        self::assertSame(['{"a":1}', "Avviso 'x'"], [$c->riga()['widgets_home'], $c->riga()['annuncio_home']]);

        $c->aggiungiSlide('uploads/a.jpg', 'A', '', '');
        $c->aggiungiSlide('uploads/b.jpg', 'B', 's', 'l.php');
        $slide = $c->slide();
        self::assertSame(['A', 'B'], array_column($slide, 'titolo'));
        self::assertSame([1, 2], array_map('intval', array_column($slide, 'ordine')));
        $idB = (int) $slide[1]['id'];
        $c->impostaOrdineSlide($idB, 0);
        $c->alternaSlide($idB);
        self::assertSame(['B', 'A'], array_column($c->slide(), 'titolo'));
        self::assertSame(['A'], array_column((new HomeRepository($this->db))->slideAttive(), 'titolo'));
        self::assertSame('uploads/b.jpg', $c->immagineSlide($idB));
        self::assertTrue($c->esisteSlide($idB));
        $c->eliminaSlide($idB);
        self::assertFalse($c->esisteSlide($idB));
        self::assertNull($c->immagineSlide($idB));
    }

    public function testHomeProssimiEventiEStatistiche(): void
    {
        $this->db->esegui("INSERT INTO pagine_eventi (id, titolo, slug, visibile) VALUES (1, 'A', 'a', 1), (2, 'B', 'b', 0)");
        $this->db->esegui("INSERT INTO eventi (id, pagina_id, titolo, tipo) VALUES (10, 1, 'Futuro', 'evento'), (11, 1, 'Senza data', 'evento'), (12, 2, 'Nascosta', 'evento'), (13, 1, 'Passato', 'evento')");
        $domani = date('Y-m-d', strtotime('+1 day'));
        $this->db->esegui('INSERT INTO turni (evento_id, data_turno, orario_inizio) VALUES (10, ?, ?), (11, NULL, NULL), (12, ?, NULL), (13, ?, NULL)', [$domani, '09:30:00', $domani, date('Y-m-d', strtotime('-3 days'))]);
        $this->db->esegui("INSERT INTO prenotazioni (stato) VALUES ('confermata'), (NULL), ('annullata')");
        $h = new HomeRepository($this->db);
        $ev = $h->prossimiEventi([1], 8);
        self::assertSame(['Futuro', 'Senza data'], array_column($ev, 'titolo'));
        self::assertSame([$domani, null], array_column($ev, 'prossima_data'));
        self::assertSame(['09:30', ''], array_column($ev, 'prossimo_orario'));
        self::assertCount(1, $h->prossimiEventi([1], 1));
        self::assertSame(['eventi' => 2, 'aree' => 1, 'iscritti' => 2], $h->statistiche());
    }

    public function testAreeEliminazioneCompleta(): void
    {
        $this->db->esegui("INSERT INTO pagine_eventi (id, titolo, slug) VALUES (1, 'A', 'a'), (2, 'B', 'b')");
        $this->db->esegui('INSERT INTO campi_form (pagina_id) VALUES (1), (2)');
        $this->db->esegui('INSERT INTO sottocategorie (pagina_id) VALUES (1)');
        $this->db->esegui("INSERT INTO eventi (id, pagina_id) VALUES (10, 1), (20, 2)");
        $this->voce(1, 0, 'a');
        $this->voce(2, 0, 'b');
        $aree = new AreeRepository($this->db);
        self::assertSame(['a', 'b'], array_column($aree->tutte(), 'slug'));
        self::assertSame(['slug' => 'a', 'titolo' => 'A'], $aree->slugETitolo(1));
        self::assertSame([10], $aree->idAttivita(1));
        $aree->impostaVisibile(2, false);
        self::assertSame('0', (string) $aree->perId(2)['visibile']);
        $aree->eliminaDatiDellArea(1, 'a.php');
        self::assertNull($aree->perId(1));
        self::assertSame('1', (string) $this->db->valore('SELECT COUNT(*) FROM campi_form'));
        self::assertSame('0', (string) $this->db->valore('SELECT COUNT(*) FROM sottocategorie'));
        self::assertSame('1', (string) $this->db->valore('SELECT COUNT(*) FROM eventi'));
        self::assertSame(['b.php'], array_column($this->db->righe('SELECT url FROM menu_voci'), 'url'));
    }
}
