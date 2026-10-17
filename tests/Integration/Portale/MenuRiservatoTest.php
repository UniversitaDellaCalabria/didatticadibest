<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Portale\AppartenenzeVisitatore;
use App\Portale\CatalogoPagineMenu;
use App\Portale\FonteAree;
use App\Portale\MenuRepository;
use App\Portale\ServizioMenu;
use App\Portale\Visitatore;
use App\Uffici\UfficiRepository;
use Tests\Integration\ConDirezione;
use Tests\Integration\DatabaseDiProva;

/** Voci del menu riservate a un ufficio (per esempio la Direzione) o ai responsabili di risorse. */
final class MenuRiservatoTest extends DatabaseDiProva
{
    use ConDirezione;

    protected function tabelle(): array
    {
        return $this->tabelleUffici() + [
            'menu_voci' => 'CREATE TABLE menu_voci (id INT AUTO_INCREMENT PRIMARY KEY, genitore_id INT NOT NULL DEFAULT 0, etichetta VARCHAR(150) NOT NULL DEFAULT \'\', url VARCHAR(255) NOT NULL DEFAULT \'\', ordine INT NOT NULL DEFAULT 0, apri_nuova_scheda TINYINT NOT NULL DEFAULT 0, ruolo_visibilita_id INT NOT NULL DEFAULT 0, visibile TINYINT NULL DEFAULT 1)',
            'risorse_responsabili' => 'CREATE TABLE risorse_responsabili (id INT AUTO_INCREMENT PRIMARY KEY, risorsa_id INT NOT NULL DEFAULT 0, email VARCHAR(150) NOT NULL DEFAULT \'\')',
            'risorse_uffici' => 'CREATE TABLE risorse_uffici (id INT AUTO_INCREMENT PRIMARY KEY, risorsa_id INT NOT NULL, ufficio_id INT NOT NULL)',
        ];
    }

    private int $direzione = 0;
    private int $protocollo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->direzione = $this->db->inserisci("INSERT INTO uffici (nome, chiave) VALUES ('Direzione del Dipartimento', 'direzione')");
        $this->protocollo = $this->db->inserisci("INSERT INTO uffici (nome, chiave) VALUES ('Protocollo', 'protocollo')");
        $this->db->esegui("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo) VALUES (?, 'direttore@unical.it', 'Mario Bianchi', 'Direttore'), (?, 'Vice@unical.it', 'Vera Vice', 'Vice direttore'), (?, 'anna@unical.it', 'Anna', '')",
            [$this->direzione, $this->direzione, $this->protocollo]);
    }

    private function servizio(): ServizioMenu
    {
        return new ServizioMenu(new MenuRepository($this->db), new AppartenenzeVisitatore($this->db));
    }

    private function visitatore(?string $email, int $ruolo = 4): Visitatore
    {
        return new Visitatore($email !== null, $ruolo, [], (string) $email);
    }

    /** @return list<string> le etichette delle voci di primo livello che il visitatore vede */
    private function vede(Visitatore $v): array
    {
        return array_map(static fn ($x): string => $x->etichetta, $this->servizio()->menuPrincipale($v));
    }

    public function testVociPerUfficioEPerResponsabili(): void
    {
        $m = new MenuRepository($this->db);
        $m->inserisci(0, 'Tutti', 'index.php', 1);
        $m->inserisci(0, 'Cruscotto', 'cruscotto_direttore.php', 2, true, AppartenenzeVisitatore::codiceUfficio($this->direzione));
        $m->inserisci(0, 'Risorse', 'risorse_responsabile.php', 3, true, AppartenenzeVisitatore::RESPONSABILI_RISORSE);
        $m->inserisci(0, 'Protocollo', 'x.php', 4, true, AppartenenzeVisitatore::codiceUfficio($this->protocollo));
        $m->inserisci(0, 'Ufficio inesistente', 'y.php', 5, true, AppartenenzeVisitatore::codiceUfficio(999));

        $this->assertSame(['Tutti'], $this->vede($this->visitatore(null, 5)), 'un ospite non entrato vede solo le voci per tutti');
        $this->assertSame(['Tutti'], $this->vede($this->visitatore('qualcuno@unical.it')), 'un dipendente qualunque no');
        $this->assertSame(['Tutti', 'Cruscotto'], $this->vede($this->visitatore('Direttore@Unical.it')), 'il Direttore (email in maiuscole o minuscole)');
        $this->assertSame(['Tutti', 'Cruscotto'], $this->vede($this->visitatore('vice@unical.it')), 'anche il vice');
        $this->assertSame(['Tutti', 'Protocollo'], $this->vede($this->visitatore('anna@unical.it')));
        $this->assertSame(['Tutti', 'Cruscotto', 'Risorse', 'Protocollo', 'Ufficio inesistente'], $this->vede($this->visitatore('admin@x.it', 1)), 'gli amministratori vedono tutto');

        // responsabile di una risorsa: direttamente o perché è in un ufficio responsabile
        $this->db->esegui("INSERT INTO risorse_responsabili (risorsa_id, email) VALUES (1, 'Giulia@unical.it')");
        $this->assertSame(['Tutti', 'Risorse'], $this->vede($this->visitatore('giulia@unical.it')));
        $this->db->esegui('INSERT INTO risorse_uffici (risorsa_id, ufficio_id) VALUES (2, ?)', [$this->protocollo]);
        $this->assertSame(['Tutti', 'Risorse', 'Protocollo'], $this->vede($this->visitatore('anna@unical.it')));
    }

    public function testSenzaIlServizioLeVociRiservateSonoNascoste(): void
    {
        $m = new MenuRepository($this->db);
        $m->inserisci(0, 'Tutti', 'index.php', 1);
        $m->inserisci(0, 'Cruscotto', 'cruscotto_direttore.php', 2, true, AppartenenzeVisitatore::codiceUfficio($this->direzione));
        $servizio = new ServizioMenu($m);   // come nei vecchi test: solo i ruoli di sempre
        $this->assertSame(['Tutti'], array_map(static fn ($x): string => $x->etichetta, $servizio->menuPrincipale($this->visitatore('direttore@unical.it'))));
    }

    public function testIlCodiceDellUfficio(): void
    {
        $this->assertSame(-1007, AppartenenzeVisitatore::codiceUfficio(7));
        $this->assertSame(7, AppartenenzeVisitatore::ufficioDaCodice(-1007));
        $this->assertNull(AppartenenzeVisitatore::ufficioDaCodice(-2));
        $this->assertNull(AppartenenzeVisitatore::ufficioDaCodice(0));
        $this->assertNull(AppartenenzeVisitatore::ufficioDaCodice(3));
    }

    public function testIlCatalogoRiservaLeSueVoci(): void
    {
        $aree = new class () implements FonteAree {
            public function areeVisibili(): array
            {
                return [];
            }
        };
        $m = new MenuRepository($this->db);
        // una voce già nel menu, per tutti, che conviene riservare
        $m->inserisci(0, 'Cruscotto (già presente)', 'cruscotto_direttore.php', 1);
        (new CatalogoPagineMenu($m, $aree, new UfficiRepository($this->db)))->crea();
        $d = AppartenenzeVisitatore::codiceUfficio($this->direzione);
        $this->assertSame($d, (int) $this->db->valore("SELECT ruolo_visibilita_id FROM menu_voci WHERE url = 'cruscotto_direttore.php'"), 'la voce che c\'era viene riservata alla Direzione');
        $this->assertSame($d, (int) $this->db->valore("SELECT ruolo_visibilita_id FROM menu_voci WHERE url = 'direttore_assente.php'"), 'e quella nuova nasce già riservata');
        $this->assertSame(AppartenenzeVisitatore::RESPONSABILI_RISORSE, (int) $this->db->valore("SELECT ruolo_visibilita_id FROM menu_voci WHERE url = 'risorse_responsabile.php'"));
        $this->assertSame(0, (int) $this->db->valore("SELECT ruolo_visibilita_id FROM menu_voci WHERE url = 'pratiche.php'"), 'le altre restano per tutti');
        // una scelta fatta a mano (voce riservata ad altro) non si tocca
        $this->db->esegui("UPDATE menu_voci SET ruolo_visibilita_id = -1 WHERE url = 'direttore_assente.php'");
        (new CatalogoPagineMenu($m, $aree, new UfficiRepository($this->db)))->crea();
        $this->assertSame(-1, (int) $this->db->valore("SELECT ruolo_visibilita_id FROM menu_voci WHERE url = 'direttore_assente.php'"));
    }
}
