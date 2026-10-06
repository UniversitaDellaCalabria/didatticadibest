<?php

declare(strict_types=1);

namespace App\Avvisi;

use App\Core\Esito;
use App\Core\Sito;
use App\Eventi\FonteEventiAgenda;
use App\Infrastructure\Mail\Mailer;

/**
 * Avvisi per email dei nuovi eventi per ambito: iscrizione scegliendo gli ambiti (e/o le attività per le scuole),
 * conferma dal link (chi è entrato con la stessa email è confermato subito), cancellazione dal link in ogni email.
 * Il cron manda a ogni iscritto un solo riepilogo con i nuovi eventi dei suoi ambiti; ogni evento si annuncia una volta.
 */
final class ServizioAvvisi
{
    private const COLORE = '#0056B3';
    private const GIORNI_SENZA_CONFERMA = 30;
    private const MESI = [1 => 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];

    public function __construct(
        private IscrizioneRepository $iscrizioni,
        private Mailer $mailer,
        private FonteEventiAgenda $eventi,
        private Sito $sito,
    ) {
    }

    /**
     * @param list<string> $ambiti
     * @param array<string, mixed>|null $utente utente collegato (se l'email è la sua, l'iscrizione è confermata subito)
     */
    public function iscrivi(string $email, array $ambiti, bool $scuole, ?array $utente = null): Esito
    {
        $email = strtolower(trim($email));
        $ambiti = array_values(array_intersect(array_keys(AMBITI_EVENTO), array_map('strval', $ambiti)));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Esito::errore('Scrivi un indirizzo email valido.');
        }
        if (!$ambiti && !$scuole) {
            return Esito::errore('Scegli almeno un argomento.');
        }
        $sua = $utente && strtolower(trim((string) ($utente['email'] ?? ''))) === $email;
        $utenteId = $sua ? (int) $utente['id'] : null;
        $esistente = $this->iscrizioni->rigaPerEmail($email);
        $token = $esistente['token'] ?? bin2hex(random_bytes(20));
        if ($esistente) {
            $this->iscrizioni->aggiorna((int) $esistente['id'], $ambiti, $scuole, $utenteId, $sua);
        } else {
            $this->iscrizioni->crea($email, $ambiti, $scuole, $token, $utenteId, $sua);
        }
        if ($sua || !empty($esistente['confermata_il'])) {
            return Esito::ok("Preferenze salvate: riceverai un'email quando escono nuovi eventi dei tuoi argomenti.");
        }
        $link = $this->sito->urlBase() . '/avvisi.php?conferma=' . $token;
        $this->mailer->invia(
            $email,
            "Conferma l'iscrizione agli avvisi degli eventi",
            "<p>Hai chiesto di ricevere un'email quando il Dipartimento pubblica nuovi eventi di: <strong>" . self::h(self::testoArgomenti(implode(',', $ambiti), $scuole)) . '</strong>.</p>'
            . "<p style='margin-top:18px;'><a href='" . self::h($link) . "' style='background:#0056B3;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Conferma l'iscrizione</a></p>"
            . "<p style='font-size:12px;color:#64748b;'>Se non sei stato tu, ignora questa email: senza conferma non riceverai nulla e i dati si cancellano dopo 30 giorni.</p>",
            self::COLORE
        );

        return Esito::ok("Ti abbiamo mandato un'email: clicca sul link per confermare l'iscrizione.");
    }

    /**
     * Iscrizione dal token dei link (40 caratteri esadecimali), come riga della tabella.
     *
     * @return array<string, mixed>|null
     */
    public function rigaPerToken(string $token): ?array
    {
        return preg_match('/^[a-f0-9]{40}$/', $token) ? $this->iscrizioni->rigaPerToken($token) : null;
    }

    /** @return array<string, mixed>|null */
    public function rigaPerEmail(string $email): ?array
    {
        return $this->iscrizioni->rigaPerEmail(strtolower(trim($email)));
    }

    public function conferma(string $token): bool
    {
        $r = $this->rigaPerToken($token);

        return $r !== null && $this->iscrizioni->conferma((int) $r['id']);
    }

    public function cancella(string $token): bool
    {
        $r = $this->rigaPerToken($token);

        return $r !== null && $this->iscrizioni->elimina((int) $r['id']);
    }

    /**
     * Iscritti confermati per argomento: ['orientamento' => n, …, 'scuole' => n, '' => totale].
     *
     * @return array<string, int>
     */
    public function contaIscritti(): array
    {
        $n = array_fill_keys(array_keys(AMBITI_EVENTO), 0) + ['scuole' => 0, '' => 0];
        foreach ($this->iscrizioni->confermate() as $i) {
            $n['']++;
            if ($i->scuole) {
                $n['scuole']++;
            }
            foreach ($i->ambiti as $a) {
                if (isset($n[$a]) && $a !== '') {
                    $n[$a]++;
                }
            }
        }

        return $n;
    }

    /**
     * Cron: nuovi eventi in programma non ancora annunciati → un riepilogo per iscritto con quelli dei suoi argomenti.
     * Al primo avvio segna come annunciati gli eventi già esistenti senza inviare nulla. Toglie le iscrizioni mai
     * confermate dopo 30 giorni. Ritorna le email inviate.
     */
    public function inviaNovita(): int
    {
        $this->iscrizioni->eliminaNonConfermate(self::GIORNI_SENZA_CONFERMA);
        $primo = $this->iscrizioni->nessunEventoAnnunciato();
        $gia = $this->iscrizioni->eventiAnnunciati();
        $nuovi = array_values(array_filter($this->eventi->eventiInProgramma(), static fn (array $e): bool => !in_array((int) $e['id'], $gia, true)));
        if ($primo) {
            // Primo avvio: gli eventi già pubblicati non sono «nuovi»; serve anche una riga se non ce ne sono
            foreach ($nuovi as $e) {
                $this->iscrizioni->segnaAnnunciato((int) $e['id']);
            }
            $this->iscrizioni->segnaAnnunciato(0);

            return 0;
        }
        if (!$nuovi) {
            return 0;
        }
        $perEvento = [];
        $inviate = 0;
        foreach ($this->iscrizioni->confermate() as $isc) {
            $suoi = array_values(array_filter($nuovi, static fn (array $e): bool => (bool) array_intersect($isc->ambiti, $e['ambiti']) || ($isc->scuole && $e['per_scuole'])));
            if (!$suoi) {
                continue;
            }
            $righe = '';
            foreach ($suoi as $e) {
                $righe .= $this->rigaEvento($e);
                $perEvento[(int) $e['id']] = ($perEvento[(int) $e['id']] ?? 0) + 1;
            }
            $gestisci = $this->sito->urlBase() . '/avvisi.php?t=' . $isc->token;
            $this->mailer->invia(
                $isc->email,
                count($suoi) === 1 ? 'Nuovo evento: ' . $suoi[0]['titolo'] : count($suoi) . ' nuovi eventi del Dipartimento',
                '<p>Nuovi appuntamenti su: <strong>' . self::h(self::testoArgomenti(implode(',', $isc->ambiti), $isc->scuole)) . "</strong>.</p><table style='width:100%;border-collapse:collapse;font-size:14px;'>$righe</table>"
                . "<p style='margin-top:16px;'><a href='" . self::h($this->sito->urlBase() . '/agenda.php') . "'>Tutta l'agenda</a></p>"
                . "<p style='font-size:12px;color:#64748b;margin-top:18px;'>Ricevi questa email perché ti sei iscritto agli avvisi. <a href='" . self::h($gestisci) . "'>Cambia argomenti o cancellati</a>.</p>",
                self::COLORE
            );
            $this->iscrizioni->segnaInvio($isc->id);
            $inviate++;
        }
        foreach ($nuovi as $e) {
            $this->iscrizioni->segnaAnnunciato((int) $e['id'], $perEvento[(int) $e['id']] ?? 0);
        }

        return $inviate;
    }

    /** "Ricerca, Didattica, attività per le scuole" */
    public static function testoArgomenti(string $ambiti, bool $scuole): string
    {
        $n = array_map(static fn (string $k): string => AMBITI_EVENTO[$k]['nome'], array_values(array_intersect(array_keys(AMBITI_EVENTO), explode(',', $ambiti))));
        if ($scuole) {
            $n[] = 'attività per le scuole';
        }

        return implode(', ', $n);
    }

    /** @param array<string, mixed> $e */
    private function rigaEvento(array $e): string
    {
        $data = $e['prossima_data'] ? strtotime((string) $e['prossima_data']) : null;
        $quando = $data ? (int) date('j', $data) . ' ' . self::MESI[(int) date('n', $data)] . ' ' . date('Y', $data) . ($e['prossimo_orario'] ? ', ore ' . $e['prossimo_orario'] : '') : 'data da definire';

        return "<tr><td style='padding:8px 0;border-bottom:1px solid #e5e7eb;'><a href='" . self::h($this->sito->urlBase() . '/' . $e['url']) . "' style='font-weight:bold;color:#0f172a;'>" . self::h($e['titolo']) . '</a>'
            . (!empty($e['relatore']) ? "<br><span style='color:#475569;'>" . self::h($e['relatore']) . '</span>' : '')
            . "<br><span style='color:#64748b;font-size:13px;'>" . self::h($quando) . ($e['luogo'] ? ' · ' . self::h($e['luogo']) : '') . ' · '
            . self::h(implode(', ', array_map(static fn (string $a): string => AMBITI_EVENTO[$a]['nome'], $e['ambiti']))) . '</span></td></tr>';
    }

    private static function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
