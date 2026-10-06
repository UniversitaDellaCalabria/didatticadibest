<?php

declare(strict_types=1);

namespace App\Sistema;

use App\Auth\ServizioUtenti;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Sistema\Backup\StatoBackup;

/**
 * Riepilogo delle email di sistema degli ultimi 7 giorni agli amministratori: inviate, fallite (raggruppate per
 * errore), invii ripiegati su mail() e stato del backup. Spostato da invia_report_email_settimanale() di inc/sistema.php.
 */
final class ReportEmailSettimanale
{
    public function __construct(
        private Sito $sito,
        private StatoBackup $backup,
        private LogEmailRepository $logEmail,
        private ServizioUtenti $utenti,
        private Mailer $mailer,
    ) {
    }

    /**
     * Una volta a settimana (primo cron del lunedì o dopo); $forza = invio immediato dal pannello Sistema.
     * Ritorna true o il motivo per cui non è partito.
     */
    public function invia(bool $forza = false): true|string
    {
        $cartella = $this->sito->radice() . '/cache';
        $marker = $cartella . '/report_email_' . date('o-W') . '.ok';
        if (!$forza && is_file($marker)) {
            return 'già inviato questa settimana';
        }
        $dest = $this->utenti->emailAmministratori();
        if (!$dest) {
            return 'nessun amministratore con email';
        }
        $tot = $this->logEmail->totaliSettimana() ?? ['ok' => 0, 'ko' => 0, 'mail' => 0];
        $errori = $this->logEmail->erroriSettimana();
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $corpo = '<p>Riepilogo delle email inviate dal portale negli ultimi 7 giorni (fino al ' . date('d/m/Y H:i') . ').</p>'
               . "<table style='border-collapse:collapse;margin:10px 0;'>"
               . "<tr><td style='padding:4px 14px 4px 0;'>✅ Accettate dal server</td><td><strong>{$tot['ok']}</strong></td></tr>"
               . "<tr><td style='padding:4px 14px 4px 0;'>❌ Fallite</td><td><strong style='color:" . ($tot['ko'] ? '#b91c1c' : 'inherit') . ";'>{$tot['ko']}</strong></td></tr>"
               . ($tot['mail'] ? "<tr><td style='padding:4px 14px 4px 0;'>⚠️ Inviate con il ripiego mail() (SMTP non raggiungibile)</td><td><strong>{$tot['mail']}</strong></td></tr>" : '')
               . '</table>';
        if ($errori) {
            $corpo .= '<p><strong>Errori della settimana</strong> (raggruppati):</p><ul>';
            foreach ($errori as $e) {
                $chi = mb_strimwidth((string) $e['chi'], 0, 200, '…');
                $corpo .= "<li><strong>{$e['n']}×</strong> " . $h($e['errore'] ?: 'errore sconosciuto') . "<br><small style='color:#64748b;'>ultimo il " . date('d/m H:i', (int) strtotime((string) $e['ultimo'])) . ' · ' . $h($chi) . '</small></li>';
            }
            $corpo .= "</ul><p style='font-size:13px;color:#475569;'>\"Destinatario rifiutato\" di solito indica un indirizzo sbagliato; errori di autenticazione o di connessione riguardano la configurazione SMTP.</p>";
        } else {
            $corpo .= '<p>Nessun invio fallito. 👍</p>';
        }
        $b = $this->backup->leggi();
        if ($b) {
            $corpo .= '<p><strong>Backup</strong>: ultimo il ' . date('d/m/Y H:i', (int) strtotime((string) ($b['data'] ?? ''))) . ' — ' . (!empty($b['ok']) ? '✅ completato' : '⚠️ con problemi') . '. '
                    . 'NAS: ' . $h($b['nas']['messaggio'] ?? '-') . '. '
                    . (!empty($b['ultima_email']) ? 'Ultima copia via email: ' . date('d/m/Y', (int) strtotime((string) $b['ultima_email'])) . '.' : 'Nessuna copia via email.') . '</p>';
            if (strtotime((string) ($b['data'] ?? '')) < time() - 2 * 86400) {
                $corpo .= "<p style='color:#b91c1c;'><strong>Attenzione: l'ultimo backup ha più di 2 giorni.</strong> Controlla che il cron di admin/cron_backup.php sia attivo.</p>";
            }
        } else {
            $corpo .= "<p style='color:#b91c1c;'><strong>Backup: nessuna esecuzione registrata.</strong> Controlla che il cron di admin/cron_backup.php sia attivo.</p>";
        }
        $corpo .= "<p><a href='" . $h($this->sito->urlBase() . '/admin/sistema.php#log-email') . "'>Apri il registro completo nel pannello</a></p>";
        $oggetto = ($tot['ko'] || ($b && empty($b['ok'])) || !$b ? '⚠️ ' : '✅ ') . "Riepilogo settimanale email Didattica DiBEST: {$tot['ok']} inviate, {$tot['ko']} fallite";
        $inviati = 0;
        foreach ($dest as $em) {
            if ($this->mailer->invia($em, $oggetto, $corpo)) {
                $inviati++;
            }
        }
        if (!$inviati) {
            return 'invio non riuscito: ' . $this->mailer->ultimoErrore();
        }
        if (!is_dir($cartella)) {
            @mkdir($cartella, 0755, true);
        }
        @file_put_contents($marker, date('c'));
        foreach (glob($cartella . '/report_email_*.ok') ?: [] as $f) {
            if ($f !== $marker && filemtime($f) < time() - 60 * 86400) {
                @unlink($f);
            }
        }

        return true;
    }
}
