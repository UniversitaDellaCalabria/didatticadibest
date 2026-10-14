<?php

declare(strict_types=1);

namespace App\Didattica;

/** Promemoria del cron: pratiche aperte ferme da più dei giorni indicati nel modulo. */
final class PromemoriaPratiche
{
    public function __construct(
        private PraticaRepository $pratiche,
        private ServizioUffici $ufficio,
        private UfficioRepository $uffici,
        private NotifichePratiche $notifiche,
        private ModuloRepository $moduli,
        private ServizioIter $iter,
        private FirmaPratica $firme
    )
    {
    }

    /**
     * Email a chi ha la pratica in carico (o a chi smista, se non è ancora assegnata). Un promemoria per ogni periodo di attesa.
     * Ritorna il numero di email inviate.
     */
    public function invia(): int
    {
        // Chi deve ancora firmare riceve il suo promemoria; per le altre pratiche vale il tempo massimo del passo (o quello del modulo)
        $n = $this->firme->solleciti();
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        foreach ($this->pratiche->candidatePromemoria() as $x) {
            $gg = $this->iter->giorniPasso($this->moduli->perId((int) $x['modulo_id']), (int) $x['passo']);
            if ($gg <= 0 || (int) $x['giorni_fermi'] < $gg || ($x['giorni_dal_promemoria'] !== null && (int) $x['giorni_dal_promemoria'] < $gg)) {
                continue;
            }
            $p = $this->pratiche->perId((int) $x['id']);
            if (!$p) {
                continue;
            }
            $o = !empty($p['assegnata_a']) ? $this->ufficio->operatore((int) $p['assegnata_a']) : null;
            $smistano = array_keys(array_filter($this->uffici->tutti(), fn ($u) => (int) $u['smista'] === 1));
            // Pratica in un ufficio ma senza una persona in carico: ricordano tutte le persone di quell'ufficio (altrimenti chi smista)
            $dellUfficio = $o || empty($p['ufficio_id']) ? [] : array_column(array_filter($this->ufficio->operatori(), fn ($op) => in_array((int) $p['ufficio_id'], $op['_uffici'], true)), 'email');
            $dest = $o ? [$o['email']] : ($dellUfficio ?: array_column(array_filter($this->ufficio->operatori(), fn ($op) => array_intersect($op['_uffici'], $smistano)), 'email') ?: $this->ufficio->emailUfficio((string) $p['email_ufficio']));
            $giorni = (int) floor((time() - strtotime($p['aggiornata_il'] ?: $p['creata_il'])) / 86400);
            foreach ($dest as $e) {
                $this->notifiche->invia(
                    $e,
                    "Pratica ferma da $giorni giorni: " . $p['modulo_titolo'] . ' – ' . trim($p['cognome'] . ' ' . $p['nome']),
                    '<p>La pratica <strong>' . $h($p['codice']) . '</strong> (' . $h($p['modulo_titolo']) . ') di ' . $h(trim($p['nome'] . ' ' . $p['cognome'])) . " non ha movimenti da <strong>$giorni giorni</strong>"
                    . ($o ? ' ed è in carico a te.' : ($dellUfficio ? " ed è all'ufficio, ma nessuno l'ha ancora presa in carico." : ' e non è ancora stata smistata.')) . '</p>' . $this->notifiche->bottone($this->notifiche->linkPannello((int) $p['id']), 'Apri la pratica')
                );
                $n++;
            }
            $this->pratiche->segnaPromemoria((int) $p['id']);
        }

        return $n;
    }
}
