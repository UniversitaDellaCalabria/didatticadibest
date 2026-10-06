<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Database;
use App\Eventi\Righe;

/** Dati della pagina pubblica di un'area (master_template.php): la configurazione dell'area, i suoi eventi non archiviati e i loro turni. */
final class PaginaAreaRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * La configurazione dell'area con quello slug; se non esiste, la prima area (la pagina non resta mai senza configurazione).
     *
     * @return array<string, mixed>|null
     */
    public function perSlug(string $slug): ?array
    {
        $riga = $this->db->riga('SELECT * FROM pagine_eventi WHERE slug = ? LIMIT 1', [$slug]);
        if ($riga !== null) {
            return $riga;
        }

        return Righe::riga($this->db->riga('SELECT * FROM pagine_eventi ORDER BY id ASC LIMIT 1'));
    }

    /**
     * Eventi non archiviati dell'area con il nome della sezione, in ordine di visualizzazione (in evidenza, sezione, ordine).
     *
     * @return list<array<string, string|null>>
     */
    public function eventiPubblici(int $paginaId): array
    {
        return Righe::testo($this->db->righe(
            'SELECT e.*, sc.nome as nome_sottocategoria, sc.affiancata_in_alto FROM eventi e LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id WHERE e.pagina_id = ? AND e.archiviato = 0 ORDER BY e.is_evidenza DESC, sc.ordine ASC, e.ordine ASC, e.id DESC',
            [$paginaId]
        ));
    }

    /**
     * Turni dell'evento: prima quelli con data, per data e orario; i turni senza data in coda.
     *
     * @return list<array<string, string|null>>
     */
    public function turniDelEvento(int $eventoId): array
    {
        return Righe::testo($this->db->righe(
            'SELECT * FROM turni WHERE evento_id = ? ORDER BY (data_turno IS NULL), data_turno ASC, orario_inizio ASC, nome_turno ASC, id ASC',
            [$eventoId]
        ));
    }
}
