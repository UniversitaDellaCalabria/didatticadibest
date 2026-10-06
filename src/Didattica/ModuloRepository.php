<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Eventi\Righe;

/** Moduli della didattica (didattica_moduli): documenti da scaricare e moduli online. */
final class ModuloRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM didattica_moduli WHERE id = ?', [$id]);
    }

    /**
     * Tutti i moduli con il numero di pratiche e di quelle ancora aperte (pannello).
     *
     * @return list<array<string, string|null>>
     */
    public function elenco(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT m.*, (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id) AS n_pratiche,
                    (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id AND p.stato IN ('inviata', 'in_lavorazione', 'integrazione')) AS n_aperte
             FROM didattica_moduli m ORDER BY m.categoria, m.ordine, m.titolo"
        ));
    }

    /**
     * Moduli pubblicati divisi per categoria (pagina pubblica della modulistica).
     *
     * @return array<string, list<array<string, string|null>>>
     */
    public function pubblicatiPerCategoria(): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT * FROM didattica_moduli WHERE attivo = 1 ORDER BY categoria, ordine, titolo')) as $x) {
            $out[$x['categoria']][] = $x;
        }

        return $out;
    }

    /**
     * Moduli che hanno un iter (JSON degli uffici).
     *
     * @return list<array<string, string|null>>
     */
    public function conIter(): array
    {
        return Righe::testo($this->db->righe('SELECT iter_json FROM didattica_moduli WHERE iter_json IS NOT NULL'));
    }

    /**
     * Crea il modulo (id = 0) o aggiorna quello indicato. Ritorna l'id.
     *
     * @param array<string, mixed> $d titolo, categoria, descrizione, tipo, link, campi_json, verbale_json, iter_json, destinatari, email_ufficio, attivo, ordine, aperto_dal, aperto_al, giorni_promemoria
     */
    public function salva(int $id, array $d): int
    {
        $v = [$d['titolo'], $d['categoria'], $d['descrizione'], $d['tipo'], $d['link'], $d['campi_json'], $d['verbale_json'], $d['iter_json'], $d['destinatari'], $d['email_ufficio'],
              (int) $d['attivo'], (int) $d['ordine'], $d['aperto_dal'], $d['aperto_al'], (int) $d['giorni_promemoria']];
        if ($id) {
            $this->db->esegui(
                'UPDATE didattica_moduli SET titolo=?, categoria=?, descrizione=?, tipo=?, link=?, campi_json=?, verbale_json=?, iter_json=?, destinatari=?, email_ufficio=?, attivo=?, ordine=?, aperto_dal=?, aperto_al=?, giorni_promemoria=?, aggiornato_il=NOW() WHERE id=?',
                [...$v, $id]
            );

            return $id;
        }

        return $this->db->inserisci(
            'INSERT INTO didattica_moduli (titolo, categoria, descrizione, tipo, link, campi_json, verbale_json, iter_json, destinatari, email_ufficio, attivo, ordine, aperto_dal, aperto_al, giorni_promemoria, aggiornato_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            $v
        );
    }

    public function impostaDomanda(int $id, string $json): void
    {
        $this->db->esegui('UPDATE didattica_moduli SET domanda_json = ? WHERE id = ?', [$json, $id]);
    }

    public function impostaFile(int $id, string $percorso): void
    {
        $this->db->esegui('UPDATE didattica_moduli SET file_path = ? WHERE id = ?', [$percorso, $id]);
    }

    public function nascondi(int $id): void
    {
        $this->db->esegui('UPDATE didattica_moduli SET attivo = 0 WHERE id = ?', [$id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM didattica_moduli WHERE id = ?', [$id]);
    }

    public function contaPratiche(int $id): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM pratiche WHERE modulo_id = ?', [$id]);
    }
}
