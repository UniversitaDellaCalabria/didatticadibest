<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Sistema\FileEnv;

/**
 * Il riepilogo settimanale per email «Da fare per te»: una volta a settimana ognuno riceve un'email con le cose che lo aspettano nei vari moduli (conferme degli
 * attestati, firme, registri del tutorato, schede FSL, elenchi degli studenti, prenotazioni da approvare, verbali, pratiche da lavorare); il Direttore ci trova
 * anche il suo cruscotto. Non parte se non c'è nulla da fare. Ogni email ha il link per non riceverlo più (e per riattivarlo). Si spegne del tutto con
 * RIEPILOGO_PERSONE=0 nel file .env. Si manda al massimo una volta per settimana di calendario e solo dal lunedì al venerdì, dalle 7.
 */
final class RiepilogoPersone
{
    /** Quante email al massimo a ogni passaggio del cron (il resto parte al passaggio dopo). */
    private const MAX_PER_PASSAGGIO = 60;

    public function __construct(
        private Database $db,
        private DaFarePersona $daFare,
        private CruscottoDirettore $cruscotto,
        private Mailer $mailer,
        private Sito $sito,
        private Orologio $orologio,
        private FileEnv $env
    ) {
    }

    /** È il momento di mandarlo: giorno feriale, dalle 7 del mattino. */
    public function ora(\DateTimeInterface $adesso): bool
    {
        return (int) $adesso->format('N') <= 5 && (int) $adesso->format('G') >= 7;
    }

    /**
     * Il riga del registro di una persona (la crea con il suo link personale per disiscriversi).
     *
     * @return array{email: string, token: string, escluso: int, settimana: string}
     */
    private function riga(string $email): array
    {
        $r = $this->db->riga('SELECT * FROM riepilogo_persone WHERE email = ?', [$email]);
        if (!$r) {
            $this->db->esegui('INSERT IGNORE INTO riepilogo_persone (email, token) VALUES (?, ?)', [$email, bin2hex(random_bytes(20))]);
            $r = $this->db->riga('SELECT * FROM riepilogo_persone WHERE email = ?', [$email]);
        }

        return ['email' => $email, 'token' => (string) ($r['token'] ?? ''), 'escluso' => (int) ($r['escluso'] ?? 0), 'settimana' => (string) ($r['settimana'] ?? '')];
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return preg_match('/^[a-f0-9]{40}$/', $token) ? $this->db->riga('SELECT * FROM riepilogo_persone WHERE token = ?', [$token]) : null;
    }

    public function imposta(string $token, bool $ricevi): bool
    {
        return $this->perToken($token) !== null && $this->db->esegui('UPDATE riepilogo_persone SET escluso = ? WHERE token = ?', [$ricevi ? 0 : 1, $token]) >= 0;
    }

    /**
     * L'email di una persona: ritorna [oggetto, corpo] oppure null se non c'è nulla da fare.
     *
     * @return array{0: string, 1: string}|null
     */
    public function messaggio(string $email, string $token, bool $direttore = false): ?array
    {
        $gruppi = $this->daFare->gruppi(['email' => $email]);
        $cru = [];
        if ($direttore) {
            foreach ($this->cruscotto->gruppi() as $g) {
                if ($g['n'] > 0) {
                    $cru[] = $g;
                }
            }
        }
        $n = DaFarePersona::totale($gruppi);
        $nCru = DaFarePersona::totale($cru);
        if ($n + $nCru === 0) {
            return null;
        }
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $base = $this->sito->urlBase();
        $blocco = function (array $g) use ($h, $base): string {
            $o = '<h3 style="margin:18px 0 6px;font-size:15px;color:' . $h($g['colore']) . ';">' . $h($g['titolo']) . ' (' . (int) $g['n'] . ')</h3><ul style="margin:0;padding-left:18px;">';
            foreach (array_slice($g['righe'], 0, 5) as $r) {
                $o .= '<li style="margin-bottom:4px;"><a href="' . $h($base . '/' . ltrim((string) $r['link'], '/')) . '">' . $h($r['testo']) . '</a>'
                    . ($r['giorni'] >= DaFarePersona::GIORNI_FERMA ? ' <span style="color:#b91c1c;">(da ' . (int) $r['giorni'] . ' giorni)</span>' : '')
                    . '<br><span style="color:#64748b;font-size:13px;">' . $h($r['dettaglio']) . '</span></li>';
            }
            if ($g['n'] > 5) {
                $o .= '<li style="color:#64748b;">… e altre ' . ((int) $g['n'] - 5) . '</li>';
            }

            return $o . '</ul>';
        };
        $corpo = '<p>Buongiorno,</p><p>questa settimana ti aspettano <strong>' . $n . ($n === 1 ? ' cosa' : ' cose') . '</strong> nel portale Didattica DiBEST.</p>';
        foreach ($gruppi as $g) {
            $corpo .= $blocco($g);
        }
        if ($cru) {
            $corpo .= '<h2 style="margin:26px 0 0;font-size:16px;border-top:1px solid #e2e8f0;padding-top:14px;">Il cruscotto del Direttore</h2>';
            foreach ($cru as $g) {
                $corpo .= $blocco($g);
            }
        }
        $corpo .= $this->bottone($base . '/area_personale.php', 'Apri la tua Area personale');
        $corpo .= '<p style="margin-top:22px;font-size:12px;color:#64748b;">Ricevi questa email una volta a settimana, solo quando c\'è qualcosa da fare. '
            . '<a href="' . $h($base . '/riepilogo_persone.php?t=' . $token) . '">Non voglio più riceverla</a>.</p>';

        return [($n + $nCru) === 1 ? 'Da fare questa settimana: 1 cosa' : 'Da fare questa settimana: ' . ($n + $nCru) . ' cose', $corpo];
    }

    private function bottone(string $url, string $testo): string
    {
        return "<p style='margin-top:18px;'><a href='" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "' style='background:#047857;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>" . $testo . '</a></p>';
    }

    /**
     * Manda i riepiloghi dovuti. Ritorna [inviati, saltati (nessuna cosa da fare o disiscritti)].
     *
     * @return array{0: int, 1: int}
     */
    public function invia(): array
    {
        $adesso = $this->orologio->adesso();
        $settimana = $adesso->format('o-W');
        $dir = mb_strtolower(trim((string) $this->db->valore('SELECT conv_direttore_email FROM impostazioni_sistema WHERE id = 1')));
        $candidati = $this->daFare->candidati();
        if ($dir !== '' && filter_var($dir, FILTER_VALIDATE_EMAIL)) {
            $candidati[] = $dir;
        }
        $inviati = 0;
        $saltati = 0;
        foreach (array_unique($candidati) as $email) {
            $riga = $this->riga($email);
            if ($riga['settimana'] === $settimana) {
                continue; // già guardato (e mandato, se c'era qualcosa) questa settimana
            }
            if ($inviati >= self::MAX_PER_PASSAGGIO) {
                break;
            }
            if ($riga['escluso'] === 1) {
                $this->db->esegui('UPDATE riepilogo_persone SET settimana = ? WHERE email = ?', [$settimana, $email]);
                ++$saltati;
                continue;
            }
            $m = $this->messaggio($email, $riga['token'], $email === $dir);
            if ($m === null) {
                $this->db->esegui('UPDATE riepilogo_persone SET settimana = ? WHERE email = ?', [$settimana, $email]);
                ++$saltati;
                continue;
            }
            if ($this->mailer->invia($email, $m[0], $m[1])) {
                $this->db->esegui('UPDATE riepilogo_persone SET ultimo_invio = NOW(), settimana = ? WHERE email = ?', [$settimana, $email]);
                ++$inviati;
            }
        }

        return [$inviati, $saltati];
    }

    /** Il passo del cron (cron_background.php): '' se non è il momento o è spento. */
    public function cron(): string
    {
        if (!$this->ora($this->orologio->adesso()) || trim((string) ($this->env->valore('RIEPILOGO_PERSONE') ?? '1')) === '0') {
            return '';
        }
        [$inviati, $saltati] = $this->invia();

        return $inviati || $saltati ? "- Riepilogo «Da fare per te»: $inviati email inviate" . ($saltati ? " ($saltati persone senza nulla da fare o che non lo vogliono)" : '') . ".\n" : '';
    }
}
