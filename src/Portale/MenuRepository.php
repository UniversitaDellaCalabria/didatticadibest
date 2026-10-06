<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * Query del menu del sito (menu_voci), delle copie di sicurezza dell'organizzazione (copie_configurazione)
 * e dei widget della home (configurazione_portale.widgets_home).
 */
final class MenuRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Voci visibili (visibile NULL o 1) in ordine, per costruire il menu.
     *
     * @return list<VoceMenu>
     */
    public function vociVisibili(): array
    {
        return array_map(
            VoceMenu::daRiga(...),
            $this->db->righe('SELECT * FROM menu_voci WHERE visibile IS NULL OR visibile = 1 ORDER BY ordine ASC, id ASC')
        );
    }

    /**
     * Tutte le voci come righe, in ordine (ordine, id).
     *
     * @return list<array<string, mixed>>
     */
    public function righe(): array
    {
        return $this->db->righe('SELECT * FROM menu_voci ORDER BY ordine, id');
    }

    /**
     * Tutte le voci come righe per id (per le copie di sicurezza).
     *
     * @return list<array<string, mixed>>
     */
    public function righePerId(): array
    {
        return $this->db->righe('SELECT * FROM menu_voci ORDER BY id');
    }

    /** @return list<int> */
    public function idPrimoLivello(): array
    {
        return array_map('intval', array_column($this->db->righe('SELECT id FROM menu_voci WHERE genitore_id = 0'), 'id'));
    }

    /**
     * @param list<int> $ids
     * @return list<int> quelli che esistono ancora
     */
    public function esistenti(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        return array_map('intval', array_column($this->db->righe('SELECT id FROM menu_voci WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'), 'id'));
    }

    public function urlDi(int $id): ?string
    {
        $u = $this->db->valore('SELECT url FROM menu_voci WHERE id = ?', [$id]);

        return $u === null ? null : (string) $u;
    }

    /**
     * @param list<int> $ids
     * @return int quante di queste voci sono visibili
     */
    public function quanteVisibili(array $ids): int
    {
        if (!$ids) {
            return 0;
        }

        return (int) $this->db->valore('SELECT COUNT(*) FROM menu_voci WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') AND visibile = 1');
    }

    public function idHome(): ?int
    {
        $id = $this->db->valore("SELECT id FROM menu_voci WHERE genitore_id = 0 AND url IN ('index.php', './', '/') LIMIT 1");

        return $id ? (int) $id : null;
    }

    public function ultimoOrdinePrimoLivello(): int
    {
        return (int) $this->db->valore('SELECT COALESCE(MAX(ordine), 0) FROM menu_voci WHERE genitore_id = 0');
    }

    public function inserisci(int $genitore, string $etichetta, string $url, int $ordine, bool $visibile = true): int
    {
        return $this->db->inserisci(
            'INSERT INTO menu_voci (genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id, visibile) VALUES (?, ?, ?, ?, 0, 0, ?)',
            [$genitore, $etichetta, $url, $ordine, $visibile ? 1 : 0]
        );
    }

    /**
     * Rimette una voce salvata in una copia di sicurezza, con il suo id.
     *
     * @param array<string, mixed> $v
     */
    public function reinserisci(array $v): void
    {
        $this->db->esegui(
            'INSERT INTO menu_voci (id, genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id, visibile) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $v['id'], (int) $v['genitore_id'], (string) $v['etichetta'], (string) $v['url'], (int) $v['ordine'], (int) $v['apri_nuova_scheda'], (int) $v['ruolo_visibilita_id'], (int) ($v['visibile'] ?? 1)]
        );
    }

    public function impostaOrdine(int $id, int $ordine, ?bool $visibile = null): void
    {
        $this->db->esegui('UPDATE menu_voci SET ordine = ?' . ($visibile !== null ? ', visibile = ' . ($visibile ? 1 : 0) : '') . ' WHERE id = ?', [$ordine, $id]);
    }

    public function nascondi(int $id): void
    {
        $this->db->esegui('UPDATE menu_voci SET visibile = 0 WHERE id = ?', [$id]);
    }

    /**
     * Cancella tutte le voci tranne quelle indicate (tutte se l'elenco è vuoto).
     *
     * @param list<int> $tranne
     */
    public function eliminaTranne(array $tranne): void
    {
        $this->db->esegui('DELETE FROM menu_voci' . ($tranne ? ' WHERE id NOT IN (' . implode(',', array_map('intval', $tranne)) . ')' : ''));
    }

    // --- Gestione del menu dal pannello (admin/menu.php) ---

    /** @return list<array<string, mixed>> id ed etichetta delle voci sotto un genitore (0 = primo livello), in ordine */
    public function figli(int $genitore): array
    {
        return $this->db->righe('SELECT id, etichetta FROM menu_voci WHERE genitore_id = ? ORDER BY ordine ASC, id ASC', [$genitore]);
    }

    /** Voce scelta a mano nel pannello (sempre visibile alla creazione). */
    public function inserisciDaPannello(int $genitore, string $etichetta, string $url, int $ordine, bool $nuovaScheda, int $ruoloVisibilita): void
    {
        $this->db->esegui(
            'INSERT INTO menu_voci (genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id, visibile) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$genitore, $etichetta, $url, $ordine, $nuovaScheda ? 1 : 0, $ruoloVisibilita]
        );
    }

    public function aggiornaDaPannello(int $id, int $genitore, string $etichetta, string $url, int $ordine, bool $nuovaScheda, int $ruoloVisibilita): void
    {
        $this->db->esegui(
            'UPDATE menu_voci SET genitore_id = ?, etichetta = ?, url = ?, ordine = ?, apri_nuova_scheda = ?, ruolo_visibilita_id = ? WHERE id = ?',
            [$genitore, $etichetta, $url, $ordine, $nuovaScheda ? 1 : 0, $ruoloVisibilita, $id]
        );
    }

    /** Mostra la voce se era nascosta e viceversa (visibile NULL conta come visibile). */
    public function alternaVisibilita(int $id): void
    {
        $this->db->esegui('UPDATE menu_voci SET visibile = 1 - COALESCE(visibile, 1) WHERE id = ?', [$id]);
    }

    /** Elimina la voce con le sue sottovoci, su tre livelli. */
    public function eliminaConSottomenu(int $id): void
    {
        foreach ($this->db->righe('SELECT id FROM menu_voci WHERE genitore_id = ?', [$id]) as $f) {
            $this->db->esegui('DELETE FROM menu_voci WHERE genitore_id = ?', [(int) $f['id']]);
        }
        $this->db->esegui('DELETE FROM menu_voci WHERE genitore_id = ?', [$id]);
        $this->db->esegui('DELETE FROM menu_voci WHERE id = ?', [$id]);
    }

    // --- Copie di sicurezza (copie_configurazione) ---

    public function salvaCopia(string $tipo, string $datiJson, string $autore): void
    {
        $this->db->esegui('INSERT INTO copie_configurazione (tipo, dati_json, autore) VALUES (?, ?, ?)', [$tipo, $datiJson, $autore]);
    }

    /** @return array<string, mixed>|null */
    public function ultimaCopia(string $tipo): ?array
    {
        return $this->db->riga('SELECT * FROM copie_configurazione WHERE tipo = ? ORDER BY id DESC LIMIT 1', [$tipo]);
    }

    public function eliminaCopia(int $id): void
    {
        $this->db->esegui('DELETE FROM copie_configurazione WHERE id = ?', [$id]);
    }

    public function eliminaCopie(string $tipo): void
    {
        $this->db->esegui('DELETE FROM copie_configurazione WHERE tipo = ?', [$tipo]);
    }

    // --- Widget della home (configurazione_portale) ---

    /** Letti dal database, non dalla cache della configurazione (memorizzata per richiesta). */
    public function widgetsHome(): ?string
    {
        $w = $this->db->valore('SELECT widgets_home FROM configurazione_portale WHERE id = 1');

        return $w === null ? null : (string) $w;
    }

    public function salvaWidgetsHome(?string $json): void
    {
        $this->db->esegui('UPDATE configurazione_portale SET widgets_home = ? WHERE id = 1', [$json]);
    }
}
