<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Database;
use RuntimeException;

/** Query delle aree (tabella pagine_eventi) e delle voci di menu create con le aree. */
final class AreaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perSlug(string $slug): ?array
    {
        return $this->db->riga('SELECT * FROM pagine_eventi WHERE slug = ? LIMIT 1', [$slug]);
    }

    /** Id dell'area con questo indirizzo (null se non c'è). */
    public function idPerSlug(string $slug): mixed
    {
        return $this->db->valore('SELECT id FROM pagine_eventi WHERE slug = ?', [$slug]);
    }

    public function esisteSlug(string $slug): bool
    {
        return $this->db->riga('SELECT 1 FROM pagine_eventi WHERE slug = ? LIMIT 1', [$slug]) !== null;
    }

    /** @return list<array<string, string|null>> aree visibili nell'ordine del portale */
    public function visibili(): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM pagine_eventi WHERE visibile = 1 ORDER BY ordine ASC, id ASC'));
    }

    /** @return list<array<string, mixed>> tutte le aree, anche nascoste */
    public function tutte(): array
    {
        return $this->db->righe('SELECT * FROM pagine_eventi');
    }

    /** @return array<string, string> [slug in minuscolo => titolo] di tutte le aree */
    public function titoliPerSlug(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT slug, titolo FROM pagine_eventi') as $a) {
            $out[strtolower((string) $a['slug'])] = (string) $a['titolo'];
        }

        return $out;
    }

    /** @return array<string, string|null> [slug => titolo] delle altre aree, nell'ordine del portale */
    public function altreAree(int $escludi): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT slug, titolo FROM pagine_eventi WHERE id <> ? ORDER BY ordine ASC, titolo ASC', [$escludi])) as $a) {
            $out[$a['slug']] = $a['titolo'];
        }

        return $out;
    }

    public function impostaTipo(int $id, string $tipo): void
    {
        $this->db->esegui('UPDATE pagine_eventi SET tipo_area = ? WHERE id = ?', [$tipo, $id]);
    }

    public function impostaAmbito(int $id, string $ambito): void
    {
        $this->db->esegui('UPDATE pagine_eventi SET ambito = ? WHERE id = ?', [$ambito, $id]);
    }

    /**
     * Numeri dell'area per la pagina Aree: eventi, progetti e iscritti attivi, prossimo turno.
     *
     * @return array<string, string|null>|null
     */
    public function numeri(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT
                (SELECT COUNT(*) FROM eventi e WHERE e.pagina_id = ? AND e.archiviato = 0 AND IFNULL(e.tipo, \'evento\') <> \'progetto\') AS eventi,
                (SELECT COUNT(*) FROM eventi e WHERE e.pagina_id = ? AND e.archiviato = 0 AND e.tipo = \'progetto\') AS progetti,
                (SELECT COUNT(*) FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                  WHERE e.pagina_id = ? AND e.archiviato = 0 AND IFNULL(p.stato, \'confermata\') = \'confermata\') AS iscritti,
                (SELECT MIN(t.data_turno) FROM turni t JOIN eventi e ON t.evento_id = e.id
                  WHERE e.pagina_id = ? AND e.archiviato = 0 AND t.data_turno >= CURDATE()) AS prossimo', [$id, $id, $id, $id]));
    }

    /**
     * Tutto ciò che si cancellerebbe con l'area, archivio compreso.
     *
     * @return array<string, string|null>|null
     */
    public function numeriEliminazione(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT
                (SELECT COUNT(*) FROM eventi e WHERE e.pagina_id = ?) AS eventi,
                (SELECT COUNT(*) FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE e.pagina_id = ?) AS turni,
                (SELECT COUNT(*) FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE e.pagina_id = ?) AS prenotazioni,
                (SELECT COUNT(*) FROM partecipanti_prenotazione pp JOIN prenotazioni p ON pp.prenotazione_id = p.id JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE e.pagina_id = ?) AS studenti,
                (SELECT COUNT(*) FROM sondaggi s JOIN eventi e ON s.evento_id = e.id WHERE e.pagina_id = ?) AS sondaggi', [$id, $id, $id, $id, $id]));
    }

    /**
     * Nuova area, nascosta al pubblico. Lancia RuntimeException se l'inserimento non riesce.
     *
     * @param array<string, mixed> $v valori del modulo di nuova_area.php
     */
    public function crea(array $v, string $hero): int
    {
        $id = $this->db->inserisci(
            'INSERT INTO pagine_eventi (titolo, slug, sottotitolo, colore_primario, colore_secondario, larghezza_contenitore, layout_template, num_colonne,
                                    spazio_card, mostra_sidebar, chiedi_matricola, visibile, mostra_in_home, limite_iscrizioni, sidebar_titolo, hero_descrizione)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?, ?, ?, ?)',
            [(string) $v['titolo'], (string) $v['slug'], (string) $v['sottotitolo'], (string) $v['colore_primario'], (string) $v['colore_secondario'], (string) $v['larghezza_contenitore'],
             (string) $v['layout_template'], (int) $v['num_colonne'], (int) $v['spazio_card'], (int) $v['chiedi_matricola'], (int) $v['mostra_in_home'], (string) $v['limite_iscrizioni'], (string) $v['titolo'], $hero]
        );
        if ($id <= 0) {
            throw new RuntimeException($this->db->mysqli()->error);
        }

        return $id;
    }

    /**
     * Allegati del riquadro informativo e della barra laterale.
     *
     * @return array<string, mixed>|null
     */
    public function allegati(int $id): ?array
    {
        return $this->db->riga('SELECT allegati_box_info, allegati_sidebar FROM pagine_eventi WHERE id = ?', [$id]);
    }

    /**
     * Impostazioni dell'area (impostazioni_area.php): ogni campo => valore (null = NULL).
     *
     * @param array<string, int|string|null> $campi
     */
    public function aggiornaImpostazioni(int $id, array $campi): void
    {
        $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($campi)));
        $this->db->esegui("UPDATE pagine_eventi SET $set WHERE id = ?", [...array_values($campi), $id]);
    }

    /** @return array<int, string|null> voci principali del menu [id => etichetta] */
    public function vociMenuPrincipali(): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT id, etichetta FROM menu_voci WHERE genitore_id = 0 OR genitore_id IS NULL ORDER BY ordine ASC, id ASC')) as $vm) {
            $out[(int) $vm['id']] = $vm['etichetta'];
        }

        return $out;
    }

    /** Voce di menu nascosta che porta all'area, in fondo al gruppo scelto. Lancia RuntimeException se non riesce. */
    public function creaVoceMenu(int $genitore, string $etichetta, string $url): void
    {
        $o = $this->db->valore('SELECT COALESCE(MAX(ordine), 0) + 1 AS o FROM menu_voci WHERE genitore_id = ?', [$genitore]);
        $ord = $o !== null ? (int) $o : 10;
        if ($this->db->esegui('INSERT INTO menu_voci (genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id, visibile) VALUES (?, ?, ?, ?, 0, 0, 0)', [$genitore, $etichetta, $url, $ord]) < 0) {
            throw new RuntimeException($this->db->mysqli()->error);
        }
    }
}
