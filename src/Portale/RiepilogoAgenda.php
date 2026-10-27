<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * Interruttore «Riepilogo settimanale con l'agenda» (Gestione del portale → Email e promemoria): spento di partenza, lo accende l'amministratore.
 * Da acceso, l'email settimanale «Da fare per te» porta anche l'agenda dei sette giorni (eventi, aule, ricevimenti, scadenze) e arriva pure a chi non ha
 * nulla da fare ma ha qualcosa in agenda (studenti compresi). Spento, l'email resta quella di sempre per chi ha cose da fare.
 */
final class RiepilogoAgenda
{
    public function __construct(private Database $db)
    {
    }

    public function attivo(): bool
    {
        return (int) ($this->db->valore('SELECT riepilogo_agenda FROM impostazioni_sistema WHERE id = 1') ?? 0) === 1;
    }

    /** @return array{attivo: bool, da: string, il: ?string} */
    public function stato(): array
    {
        $r = $this->db->riga('SELECT riepilogo_agenda, riepilogo_agenda_da, riepilogo_agenda_il FROM impostazioni_sistema WHERE id = 1') ?: [];

        return ['attivo' => (int) ($r['riepilogo_agenda'] ?? 0) === 1, 'da' => (string) ($r['riepilogo_agenda_da'] ?? ''), 'il' => !empty($r['riepilogo_agenda_il']) ? (string) $r['riepilogo_agenda_il'] : null];
    }

    /** Accende (true) o spegne (false); $da = chi lo ha fatto. */
    public function imposta(bool $attivo, string $da): void
    {
        $this->db->esegui('UPDATE impostazioni_sistema SET riepilogo_agenda = ?, riepilogo_agenda_da = ?, riepilogo_agenda_il = NOW() WHERE id = 1', [$attivo ? 1 : 0, mb_substr($da, 0, 150)]);
    }
}
