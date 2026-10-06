<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Eventi\EventoRepository;
use App\Iscrizioni\Vista\CampiFormAdmin;

/** Campi del modulo di iscrizione di un evento (Form Builder) per le schermate dell'amministratore. */
final class ServizioCampiForm
{
    public function __construct(
        private CampiFormRepository $campi,
        private EventoRepository $eventi,
        private CampiFormAdmin $vista,
    ) {
    }

    /**
     * Campi del Form Builder per l'evento indicato, da usare nell'admin (prenotazione manuale e modifica).
     * $valori = dati_custom_json già salvati; $turnoId (facoltativo) porta i limiti di partecipanti dell'edizione.
     *
     * @param array<string, mixed> $valori
     */
    public function htmlAdmin(int $eventoId, array $valori = [], string $prefisso = 'cf', int $turnoId = 0): string
    {
        $evento = $this->campi->eventoDelModulo($eventoId);
        if ($evento === null) {
            return '';
        }
        $eProgetto = $evento['tipo'] === 'progetto';
        $dettagli = $this->eventi->dettagliProgetti([$eventoId])[$eventoId] ?? null;
        $turno = $turnoId > 0 ? $this->campi->limitiTurno($turnoId, $eventoId) : null;
        $limiti = LimitiPartecipanti::calcola($dettagli, $turno);

        return $this->vista->html($this->campi->perModulo((int) $evento['pagina_id'], $eventoId), $eProgetto, $dettagli, $limiti, $valori, $prefisso);
    }
}
