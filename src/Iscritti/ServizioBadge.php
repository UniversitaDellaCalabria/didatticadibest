<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Auth\Abilitazioni\IdsGestori;
use App\Auth\Abilitazioni\ServizioAbilitazioni;

/** Badge nominativi (A4) di un turno: iscritti confermati, staff (gestori e amministratori) e badge aggiunti a mano. */
final class ServizioBadge
{
    public function __construct(
        private BadgeRepository $badge,
        private ServizioAbilitazioni $abilitazioni,
    ) {
    }

    /**
     * Eventi dell'area con i loro turni, per la scelta del turno da stampare.
     *
     * @return list<array<string, mixed>>
     */
    public function eventiConTurni(int $paginaId, string $filtroSql): array
    {
        return $this->badge->eventiConTurni($paginaId, $filtroSql);
    }

    /**
     * @param list<string> $nomiExtra
     * @param list<string> $cognomiExtra
     * @param list<string> $ruoliExtra
     * @return array{info: array<string, string|null>|null, badge: list<array{nome: string|null, cognome: string|null, ruolo: string, qr: string|null}>}
     *   info = dati dell'evento del turno (null se il turno non esiste); badge = quelli da stampare, nell'ordine iscritti, staff, extra
     */
    public function genera(int $turnoId, int $paginaId, bool $partecipanti, bool $staff, array $nomiExtra, array $cognomiExtra, array $ruoliExtra): array
    {
        $info = $this->badge->infoTurno($turnoId);
        $badge = [];
        if (!$info) {
            return ['info' => null, 'badge' => []];
        }
        $eventoId = (int) $info['ev_id'];

        // A. Iscritti confermati
        if ($partecipanti) {
            foreach ($this->badge->confermati($turnoId) as $r) {
                $ruolo = !empty($r['matricola']) ? 'STUDENTE - ' . $r['matricola'] : 'PARTECIPANTE';
                $badge[] = ['nome' => $r['nome'], 'cognome' => $r['cognome'], 'ruolo' => $ruolo, 'qr' => $r['codice_prenotazione']];
            }
        }

        // B. Staff / Gestori associati all'evento o all'area
        if ($staff) {
            foreach ($this->badge->nomiUtenti($this->idStaff($eventoId, $paginaId)) as $u) {
                $badge[] = ['nome' => $u['nome'], 'cognome' => $u['cognome'], 'ruolo' => 'STAFF / GESTORE', 'qr' => 'STAFF-' . bin2hex(random_bytes(3))];
            }
        }

        // C. Badge manuali (aggiunti al volo)
        foreach ($nomiExtra as $i => $nome) {
            $nome = trim($nome);
            $cognome = trim($cognomiExtra[$i] ?? '');
            $ruolo = trim($ruoliExtra[$i] ?? 'EXTRA');
            if ($nome || $cognome) {
                $badge[] = ['nome' => $nome, 'cognome' => $cognome, 'ruolo' => strtoupper($ruolo), 'qr' => 'EXT-' . bin2hex(random_bytes(3))];
            }
        }

        return ['info' => $info, 'badge' => $badge];
    }

    /**
     * Id dello staff: gestori dell'evento e dell'area, chi vede l'attività per perimetro, amministratori.
     *
     * @return list<int>
     */
    private function idStaff(int $eventoId, int $paginaId): array
    {
        $ids = [];
        $ev = $this->badge->gestoriEvento($eventoId);
        if ($ev) {
            $ids = array_merge($ids, IdsGestori::daCampi(0, $ev['gestori_utenti_ids'], $ev['permessi_gestori_json']));
        }
        $ids = array_merge($ids, $this->abilitazioni->idsAmbitoAttivita($eventoId, false));

        $area = $this->badge->gestoriArea($paginaId);
        if ($area) {
            $ids = array_merge($ids, explode(',', $area['gestori_utenti_ids'] ?? ''));
            if ($json = json_decode($area['permessi_gestori_json'] ?: '{}', true)) {
                $ids = array_merge($ids, array_keys($json));
            }
        }
        $ids = array_merge($ids, $this->badge->idAmministratori());

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
