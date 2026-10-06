<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Client SMTP del portale senza librerie esterne: STARTTLS/SSL, autenticazione, verifica di ogni risposta del server,
 * registro degli invii (log_email). Se l'host SMTP manca o non risponde usa mail() di PHP.
 * Con $cartellaLocale (ambiente di prova) non invia nulla: salva l'email in quella cartella (visibile da /__email).
 * Impostazioni SMTP dalla tabella impostazioni_sistema (riga 1), come prima. Spostato da inc/base.php (inviaNotificaEmail).
 */
final class SmtpMailer implements Mailer
{
    private string $ultimoErrore = '';

    public function __construct(
        private Database $db,
        private ImpaginatoreEmail $impaginatore,
        private ?string $cartellaLocale = null,
    ) {
    }

    public function ultimoErrore(): string
    {
        return $this->ultimoErrore;
    }

    public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
    {
        $this->ultimoErrore = '';
        $to = trim($a);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->ultimoErrore = 'Indirizzo destinatario non valido';

            return false;
        }

        if ($this->cartellaLocale !== null) {
            return $this->salvaInLocale($to, $oggetto, $corpoHtml, $colore, count($allegati));
        }

        $sys = $this->db->riga('SELECT * FROM impostazioni_sistema WHERE id = 1');
        if (!$sys) {
            $this->ultimoErrore = 'Impostazioni di sistema mancanti';
            $this->registra($to, $oggetto, false, $this->ultimoErrore, '-');

            return false;
        }

        $host = trim((string) ($sys['smtp_host'] ?? ''));
        $port = (int) ($sys['smtp_port'] ?? 587);
        $user = (string) ($sys['smtp_username'] ?? '');
        $pass = (string) ($sys['smtp_password'] ?? '');
        $fromEmail = !empty($sys['smtp_from_email']) ? trim((string) $sys['smtp_from_email']) : 'noreply.eventi@unical.it';
        $fromNome = !empty($sys['smtp_from_name']) ? (string) $sys['smtp_from_name'] : 'Didattica DiBEST';
        $secure = strtolower((string) ($sys['smtp_secure'] ?? 'tls'));

        $corpoHtml = $this->impaginatore->impagina($corpoHtml, $fromNome, $colore);

        $dominio = substr(strrchr($fromEmail, '@') ?: '@unical.it', 1);
        $headers = 'Date: ' . date('r') . "\r\n";
        $headers .= 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . ">\r\n";
        $fromHdr = 'From: =?UTF-8?B?' . base64_encode($fromNome) . "?= <$fromEmail>\r\n";
        // base64 a righe da 76 caratteri: niente righe oltre il limite SMTP (998) e niente troncamenti su righe che iniziano con "."
        $htmlB64 = chunk_split(base64_encode($corpoHtml), 76, "\r\n");
        $allegati = array_values(array_filter($allegati, static fn ($x) => !empty($x['path']) && is_readable($x['path'])));
        if (!$allegati) {
            $headers .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n";
            $corpoB64 = $htmlB64;
        } else {
            // Messaggio multipart: testo HTML + allegati (nomi dei file ridotti a caratteri sicuri)
            $confine = 'b_' . bin2hex(random_bytes(12));
            $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$confine\"\r\n";
            $corpoB64 = "--$confine\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $htmlB64;
            foreach ($allegati as $x) {
                $nome = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($x['nome'] ?? basename($x['path'])));
                $corpoB64 .= "--$confine\r\nContent-Type: application/octet-stream; name=\"$nome\"\r\nContent-Transfer-Encoding: base64\r\n"
                    . "Content-Disposition: attachment; filename=\"$nome\"\r\n\r\n" . chunk_split(base64_encode((string) file_get_contents($x['path'])), 76, "\r\n");
            }
            $corpoB64 .= "--$confine--\r\n";
        }
        $oggettoEnc = '=?UTF-8?B?' . base64_encode($oggetto) . '?=';

        $conMail = function (string $motivo) use ($to, $oggetto, $oggettoEnc, $headers, $fromHdr, $corpoB64): bool {
            $ok = @mail($to, $oggettoEnc, $corpoB64, $fromHdr . $headers);
            $this->ultimoErrore = $ok ? '' : "$motivo; anche la funzione mail() di PHP ha fallito";
            $this->registra($to, $oggetto, $ok, $ok ? $motivo : $this->ultimoErrore, 'mail()');

            return $ok;
        };

        if ($host === '') {
            return $conMail('Host SMTP non configurato');
        }

        $socket = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10);
        if (!$socket) {
            return $conMail("Connessione SMTP a $host:$port fallita ($errstr)");
        }
        stream_set_timeout($socket, 15);

        try {
            $ehlo = $_SERVER['SERVER_NAME'] ?? (gethostname() ?: 'localhost');
            $this->comando($socket, null, [220], 'Benvenuto server');
            $caps = $this->comando($socket, "EHLO $ehlo", [250], 'EHLO');
            if ($secure === 'tls') {
                $this->comando($socket, 'STARTTLS', [220], 'STARTTLS');
                $metodo = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (!@stream_socket_enable_crypto($socket, true, $metodo)) {
                    throw new RuntimeException('Negoziazione TLS fallita');
                }
                $caps = $this->comando($socket, "EHLO $ehlo", [250], 'EHLO dopo TLS');
            }
            if ($user !== '' && $pass !== '') {
                if (stripos($caps, 'AUTH') === false) {
                    throw new RuntimeException('Il server non accetta autenticazione (AUTH) con questa porta/cifratura');
                }
                $this->comando($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
                $this->comando($socket, base64_encode($user), [334], 'AUTH username');
                $this->comando($socket, base64_encode($pass), [235], 'Autenticazione (credenziali errate?)');
            }
            $this->comando($socket, "MAIL FROM:<$fromEmail>", [250], 'Mittente rifiutato');
            $this->comando($socket, "RCPT TO:<$to>", [250, 251], 'Destinatario rifiutato');
            $this->comando($socket, 'DATA', [354], 'DATA');
            fwrite($socket, $fromHdr . "To: <$to>\r\nSubject: $oggettoEnc\r\n" . $headers . "\r\n" . $corpoB64 . "\r\n.\r\n");
            $this->comando($socket, null, [250], 'Messaggio rifiutato');
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
            $this->registra($to, $oggetto, true);

            return true;
        } catch (Throwable $e) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
            $this->ultimoErrore = $e->getMessage();
            $this->registra($to, $oggetto, false, $e->getMessage());

            return false;
        }
    }

    /** Traccia ogni invio in log_email: quali email partono e quali vengono rifiutate dal server SMTP. */
    public function registra(string $to, string $oggetto, bool $ok, string $errore = '', string $canale = 'smtp'): void
    {
        $this->db->esegui(
            'INSERT INTO log_email (destinatario, oggetto, esito, canale, errore) VALUES (?,?,?,?,?)',
            [mb_substr($to, 0, 255), mb_substr($oggetto, 0, 255), $ok ? 1 : 0, $canale, mb_substr($errore, 0, 500)]
        );
        if (!$ok) {
            error_log("[Email] Invio fallito a $to ($canale): $errore");
        }
    }

    private function salvaInLocale(string $to, string $oggetto, string $corpoHtml, ?string $colore, int $nAllegati): bool
    {
        $dir = rtrim((string) $this->cartellaLocale, '/') . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $nome = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_' . preg_replace('/[^a-z0-9@._-]/i', '_', $to) . '.html';
        @file_put_contents($dir . $nome, "<!-- A: $to | Oggetto: " . htmlspecialchars($oggetto) . " | Allegati: $nAllegati -->\n"
            . $this->impaginatore->impagina($corpoHtml, 'Didattica DiBEST (locale)', $colore));
        $this->registra($to, $oggetto, true, '', 'locale');

        return true;
    }

    /** Legge una risposta SMTP completa (anche multi-riga "250-...") e ne restituisce [codice, testo]. */
    private function risposta(mixed $socket): array
    {
        $testo = '';
        while (($riga = fgets($socket, 515)) !== false) {
            $testo .= $riga;
            if (strlen($riga) < 4 || $riga[3] !== '-') {
                break;
            }
        }

        return [(int) substr($testo, 0, 3), trim($testo)];
    }

    /** Invia un comando (null = solo lettura) e verifica il codice di risposta; eccezione se il server rifiuta. */
    private function comando(mixed $socket, ?string $cmd, array $codiciOk, string $fase): string
    {
        if ($cmd !== null) {
            fwrite($socket, $cmd . "\r\n");
        }
        [$codice, $testo] = $this->risposta($socket);
        if (!in_array($codice, $codiciOk, true)) {
            throw new RuntimeException("$fase: " . ($testo !== '' ? $testo : 'nessuna risposta dal server'));
        }

        return $testo;
    }
}
