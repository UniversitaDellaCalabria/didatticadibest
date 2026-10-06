<?php

declare(strict_types=1);

namespace App\Iscritti;

/**
 * Filtri dell'elenco iscritti di un'area (turno, stato, ricerca, date) già validati dal controller.
 * La condizione SQL usa i parametri per i valori; $rbac è il filtro dei permessi di admin_header.php (solo id numerici).
 */
final class FiltriIscritti
{
    /** Stati ammessi nel filtro. */
    public const STATI = ['confermata', 'in_attesa', 'da_approvare', 'annullata', 'rifiutata', 'scaduta'];

    public function __construct(
        public readonly int $paginaId,
        public readonly int $archivio = 0,
        public readonly int $turno = 0,
        public readonly string $stato = '',
        public readonly string $cerca = '',
        public readonly string $dataDa = '',
        public readonly string $dataFine = '',
        public readonly string $rbac = '',
    ) {
    }

    /**
     * Condizione WHERE (alias pr, t, e) e parametri, nell'ordine dei segnaposto.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function where(): array
    {
        $sql = 'WHERE e.pagina_id = ? AND e.archiviato = ?';
        $par = [$this->paginaId, $this->archivio];
        if ($this->turno > 0) {
            $sql .= ' AND t.id = ?';
            $par[] = $this->turno;
        }
        if ($this->stato !== '') {
            $sql .= " AND IFNULL(pr.stato, 'confermata') = ?";
            $par[] = $this->stato;
        }
        if (!empty($this->cerca)) {   // come prima: la ricerca "0" non filtra
            $sql .= ' AND (pr.nome LIKE ? OR pr.cognome LIKE ? OR pr.email LIKE ? OR pr.codice_prenotazione LIKE ?)';
            $like = '%' . $this->cerca . '%';
            array_push($par, $like, $like, $like, $like);
        }
        if ($this->dataDa !== '' && $this->dataFine !== '') {
            $sql .= ' AND t.data_turno BETWEEN ? AND ?';
            array_push($par, $this->dataDa, $this->dataFine);
        } elseif ($this->dataDa !== '') {
            $sql .= ' AND t.data_turno >= ?';
            $par[] = $this->dataDa;
        } elseif ($this->dataFine !== '') {
            $sql .= ' AND t.data_turno <= ?';
            $par[] = $this->dataFine;
        }

        return [$sql . ' ' . $this->rbac, $par];
    }
}
