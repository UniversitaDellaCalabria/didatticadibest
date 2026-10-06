<?php

declare(strict_types=1);

namespace App\Sistema;

use App\Auth\ServizioUtenti;
use App\Core\Database;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Sistema\Backup\StatoBackup;

/**
 * Controllo automatico del portale (cron notturno e pulsante in Sistema): database, cartelle scrivibili,
 * spazio su disco, età dell'ultimo backup, email non partite e, via HTTP, pagine pubbliche e file riservati.
 * Spostato da controllo_sito() ed esegui_controllo_sito() di inc/sistema.php.
 */
final class ControlloSito
{
    public function __construct(
        private Database $db,
        private Sito $sito,
        private StatoBackup $backup,
        private LogEmailRepository $logEmail,
        private ServizioUtenti $utenti,
        private Mailer $mailer,
        private bool $ambienteLocale,
    ) {
    }

    /** @return list<array{ok: bool|null, voce: string}> esiti (ok null = solo informazione) */
    public function esiti(): array
    {
        $esiti = [];
        $voce = static function (?bool $ok, string $t) use (&$esiti): void {
            $esiti[] = ['ok' => $ok, 'voce' => $t];
        };

        $voce($this->db->grezza('SELECT 1') !== false, 'Database raggiungibile');
        $radice = $this->sito->radice();
        foreach (['cache', 'uploads', 'backups'] as $cart) {
            $p = $radice . '/' . $cart;
            if ($cart === 'backups' && !is_dir($p)) {
                continue;
            }
            $voce(is_dir($p) && is_writable($p), "Cartella $cart/ scrivibile");
        }
        $libero = @disk_free_space($radice);
        if ($libero !== false) {
            $gb = $libero / 1073741824;
            $voce($gb >= 1, 'Spazio libero sul disco del portale: ' . number_format($gb, 1, ',', '.') . ' GB' . ($gb < 1 ? ' (meno di 1 GB)' : ''));
        }
        $bk = $this->backup->leggi();
        if (!$bk) {
            $voce(null, 'Backup: nessun backup registrato');
        } else {
            $ore = (time() - (int) strtotime((string) ($bk['data'] ?? ''))) / 3600;
            $voce(!empty($bk['ok']) && $ore <= 36, 'Ultimo backup: ' . date('d/m/Y H:i', (int) strtotime((string) ($bk['data'] ?? ''))) . (!empty($bk['ok']) ? '' : ' (con problemi)') . ($ore > 36 ? ' – più di 36 ore fa' : ''));
        }
        $x = $this->logEmail->esitiGiorno();
        if ($x && (int) $x['tot'] > 0) {
            $ko = (int) $x['ko'];
            $voce($ko <= max(3, (int) $x['tot'] / 10), 'Email delle ultime 24 ore: ' . (int) $x['tot'] . " inviate, $ko non partite");
        }

        // Controlli HTTP: in locale il server di sviluppo serve una richiesta alla volta, quindi si saltano
        if ($this->ambienteLocale) {
            $voce(null, "Controlli delle pagine saltati nell'ambiente locale");

            return $esiti;
        }
        $base = rtrim($this->sito->urlBase(), '/');
        $prove = [
            ['index.php', [200], 'Home'], ['privacy.php', [200], 'Privacy'], ['verifica_attestato.php', [200], 'Verifica attestato'],
            ['admin/', [301, 302, 303, 401, 403], 'Pannello senza accesso (deve rimandare al login)'],
            ['.env', [403, 404], 'File .env bloccato'], ['config.php', [403, 404], 'config.php bloccato'],
            ['cache/', [403, 404], 'Cartella cache bloccata'], ['uploads/convenzioni/', [403, 404], 'Convenzioni firmate bloccate'],
            ['strumenti/verifica_sito.sh', [403, 404], 'Strumenti bloccati'], ['inc/base.php', [403, 404], 'Codice in inc/ bloccato'], ['modelli_documenti/convenzione_precompilabile.docx', [403, 404], 'Modelli interni bloccati'], ['cron_background.php', [403], 'Cron senza chiave rifiutato'],
        ];
        foreach ($prove as [$perc, $attesi, $nome]) {
            $codice = $this->codiceHttp($base . '/' . $perc);
            $voce(in_array($codice, $attesi, true), "$nome: " . ($codice === -1 ? 'errore PHP nella pagina' : ($codice ?: 'nessuna risposta')));
        }

        return $esiti;
    }

    /**
     * Esegue il controllo, lo salva in cache/controllo_sito.json e avvisa gli amministratori per email se qualcosa
     * non va (al massimo una volta al giorno per gli stessi problemi) e quando torna tutto a posto.
     *
     * @return array<string, mixed>
     */
    public function esegui(bool $avvisa = true): array
    {
        $file = $this->sito->radice() . '/cache/controllo_sito.json';
        $prec = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $esiti = $this->esiti();
        $problemi = array_values(array_map(static fn (array $e): string => $e['voce'], array_filter($esiti, static fn (array $e): bool => $e['ok'] === false)));
        $firma = md5(implode('|', $problemi));
        $stato = ['data' => date('Y-m-d H:i:s'), 'esiti' => $esiti, 'problemi' => count($problemi), 'firma' => $firma,
            'ultimo_avviso' => $prec['ultimo_avviso'] ?? null, 'firma_avviso' => $prec['firma_avviso'] ?? ''];
        if ($avvisa) {
            $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
            $link = $h($this->sito->urlBase() . '/admin/sistema.php#controllo');
            if ($problemi && ($firma !== $stato['firma_avviso'] || strtotime((string) $stato['ultimo_avviso']) < time() - 86400)) {
                $corpo = '<p>Il controllo automatico del portale ha trovato <strong>' . count($problemi) . ' problemi</strong>:</p><ul><li>' . implode('</li><li>', array_map($h, $problemi)) . '</li></ul>'
                       . "<p>Dettagli e nuovo controllo: <a href='$link'>Sistema → Controllo del sito</a>.</p>";
                foreach ($this->utenti->emailAmministratori() as $em) {
                    $this->mailer->invia($em, 'Portale Didattica DiBEST: ' . count($problemi) . ' problemi rilevati', $corpo);
                }
                $stato['ultimo_avviso'] = date('Y-m-d H:i:s');
                $stato['firma_avviso'] = $firma;
            } elseif (!$problemi && ($prec['problemi'] ?? 0) > 0) {
                foreach ($this->utenti->emailAmministratori() as $em) {
                    $this->mailer->invia($em, 'Portale Didattica DiBEST: tutto di nuovo a posto', "<p>Il controllo automatico non trova più problemi. Dettagli: <a href='$link'>Sistema → Controllo del sito</a>.</p>");
                }
                $stato['firma_avviso'] = '';
            }
        }
        @file_put_contents($file, json_encode($stato, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $stato;
    }

    /** Codice HTTP della pagina (0 = nessuna risposta, -1 = pagina che risponde 200 ma con un errore PHP a schermo). */
    private function codiceHttp(string $url): int
    {
        if (!function_exists('curl_init')) {
            return 0;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'DidatticaDiBEST-controllo']);
        $corpo = curl_exec($ch);
        $codice = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($codice === 200 && is_string($corpo) && preg_match('/<b>(Fatal error|Parse error)<\/b>|Uncaught (Error|Exception|TypeError)/', $corpo)) {
            $codice = -1;
        }

        return $codice;
    }
}
