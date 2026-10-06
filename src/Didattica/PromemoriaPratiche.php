<?php

declare(strict_types=1);

namespace App\Didattica;

/** Promemoria del cron: pratiche aperte ferme da più dei giorni indicati nel modulo. */
final class PromemoriaPratiche
{
    public function __construct(private PraticaRepository $pratiche, private ServizioUffici $ufficio, private UfficioRepository $uffici, private NotifichePratiche $notifiche)
    {
    }

    /**
     * Email a chi ha la pratica in carico (o a chi smista, se non è ancora assegnata). Un promemoria per ogni periodo di attesa.
     * Ritorna il numero di email inviate.
     */
    public function invia(): int
    {
        $n = 0;
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        foreach ($this->pratiche->ferme() as $x) {
            $p = $this->pratiche->perId((int) $x['id']);
            if (!$p) {
                continue;
            }
            $o = !empty($p['assegnata_a']) ? $this->ufficio->operatore((int) $p['assegnata_a']) : null;
            $smistano = array_keys(array_filter($this->uffici->tutti(), fn ($u) => (int) $u['smista'] === 1));
            $dest = $o ? [$o['email']] : (array_column(array_filter($this->ufficio->operatori(), fn ($op) => in_array((int) $op['ufficio_id'], $smistano, true)), 'email') ?: $this->ufficio->emailUfficio((string) $p['email_ufficio']));
            $giorni = (int) floor((time() - strtotime($p['aggiornata_il'] ?: $p['creata_il'])) / 86400);
            foreach ($dest as $e) {
                $this->notifiche->invia(
                    $e,
                    "Pratica ferma da $giorni giorni: " . $p['modulo_titolo'] . ' – ' . trim($p['cognome'] . ' ' . $p['nome']),
                    '<p>La pratica <strong>' . $h($p['codice']) . '</strong> (' . $h($p['modulo_titolo']) . ') di ' . $h(trim($p['nome'] . ' ' . $p['cognome'])) . " non ha movimenti da <strong>$giorni giorni</strong>"
                    . ($o ? ' ed è in carico a te.' : ' e non è ancora stata smistata.') . '</p>' . $this->notifiche->bottone($this->notifiche->linkPannello((int) $p['id']), 'Apri la pratica')
                );
                $n++;
            }
            $this->pratiche->segnaPromemoria((int) $p['id']);
        }

        return $n;
    }
}
