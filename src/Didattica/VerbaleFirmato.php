<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Sistema\FileEnv;
use App\Tutorato\VerificaPdfFirmato;

/**
 * Il verbale firmato in PAdES: si carica il PDF del verbale, firma il segretario e poi il coordinatore (firma_verbale.php, firma remota
 * Aruba se configurata, altrimenti scarica, firma e ricarica); solleciti dal cron.
 */
final class VerbaleFirmato
{
    public function __construct(
        private Database $db,
        private SedutaRepository $sedute,
        private ConsiglioRepository $consigli,
        private Mailer $mailer,
        private Sito $sito,
        private FileEnv $env,
        private VerificaPdfFirmato $verifica,
        private string $cartella = Costanti::DIR_VERBALI
    ) {
    }

    /**
     * Percorso reale del PDF del verbale (solo dentro la cartella dei verbali), null se non c'è.
     *
     * @param array<string, mixed> $s
     */
    public function percorso(array $s): ?string
    {
        if (empty($s['verbale_pdf'])) {
            return null;
        }
        $base = realpath($this->sito->radice() . '/' . $this->cartella);
        $p = realpath($this->sito->radice() . '/' . $s['verbale_pdf']);
        return ($base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p)) ? $p : null;
    }

    private function salvaFile(array $s, string $pdf, string $suffisso): ?string
    {
        $dir = $this->sito->radice() . '/' . $this->cartella;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "# Verbali: si scaricano solo dal pannello o con il link personale\nRequire all denied\n");
        }
        $nome = 'verbale_' . (int)$s['id'] . '_' . ($s['data'] ? date('Ymd', strtotime($s['data'])) : 'seduta') . '_' . $suffisso . '_' . bin2hex(random_bytes(4)) . '.pdf';
        return @file_put_contents($dir . $nome, $pdf) === false ? null : $this->cartella . $nome;
    }

    private function emailFirma(array $s, string $a, string $chi): void
    {
        $h = fn ($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
        $link = $this->sito->urlBase() . '/firma_verbale.php?t=' . $s['verbale_token'];
        $this->mailer->invia(
            $a,
            "Verbale da firmare: " . ServizioSedute::etichetta($s),
            "<p>Gentile,</p><p>il verbale della seduta del <strong>" . $h($s['organo']) . "</strong>" . ($s['data'] ? " del " . date('d/m/Y', strtotime($s['data'])) : '') . " aspetta la sua firma digitale in PAdES come <strong>$chi</strong>.</p>"
            . "<p style='margin-top:18px;'><a href='" . $h($link) . "' style='background:#0056B3;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Apri e firma</a></p>"
            . "<p style='font-size:12px;color:#64748b;'>Si entra con le credenziali Unical, SPID o CIE. Il link è personale: non inoltrarlo.</p>",
            '#0056B3'
        );
    }

    public function inviaAllaFirma(int $sid, string $pdf, string $email_seg, string $email_coo): ?string
    {
        $s = $this->sedute->perId($sid);
        if (!$s) {
            return "Seduta non trovata.";
        }
        if (($s['verbale_stato'] ?? '') === 'firmato') {
            return "Il verbale è già firmato.";
        }
        if (!str_starts_with($pdf, '%PDF')) {
            return "Carica il verbale in PDF (esportalo da Word come PDF).";
        }
        $email_seg = strtolower(trim($email_seg));
        $email_coo = strtolower(trim($email_coo));
        if (!filter_var($email_seg, FILTER_VALIDATE_EMAIL) || !filter_var($email_coo, FILTER_VALIDATE_EMAIL)) {
            return "Indica le email del segretario e del coordinatore.";
        }
        $file = $this->salvaFile($s, $pdf, 'da_firmare');
        if (!$file) {
            return "Non è stato possibile salvare il PDF.";
        }
        $this->db->esegui(
            "UPDATE didattica_sedute SET verbale_pdf = ?, verbale_stato = 'segretario', verbale_token = ?, segretario_email = ?, coordinatore_email = ?, verbale_inviato_il = NOW(), verbale_firmato_il = NULL, verbale_sollecito_il = NULL, verbale_solleciti = 0 WHERE id = ?",
            [$file, bin2hex(random_bytes(20)), $email_seg, $email_coo, $sid]
        );
        $this->emailFirma($this->sedute->perId($sid) ?? $s, $email_seg, 'segretario verbalizzante');
        return null;
    }

    public function sedutaPerToken(string $tok): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $tok)) {
            return null;
        }
        $id = (int)$this->db->valore("SELECT id FROM didattica_sedute WHERE verbale_token = ?", [$tok]);
        return $id ? $this->sedute->perId($id) : null;
    }

    public function firmatario(array $s, ?array $u): bool
    {
        $e = strtolower(trim((string)($u['email'] ?? '')));
        $atteso = ($s['verbale_stato'] ?? '') === 'segretario' ? $s['segretario_email'] : (($s['verbale_stato'] ?? '') === 'coordinatore' ? $s['coordinatore_email'] : '');
        return $e !== '' && $e === strtolower((string)$atteso);
    }

    public function registraFirma(int $sid, string $pdf, string $come = 'caricamento'): ?string
    {
        $s = $this->sedute->perId($sid);
        if (!$s || !in_array($s['verbale_stato'], ['segretario', 'coordinatore'], true) || !($cor = $this->percorso($s))) {
            return "Il verbale non è in attesa di firma.";
        }
        [$err, $info] = $this->verifica->verifica((string)file_get_contents($cor), $pdf, '');
        if ($err) {
            return $err;
        }
        $chi = $s['verbale_stato'] === 'segretario' ? 'segretario' : 'coordinatore';
        $file = $this->salvaFile($s, $pdf, 'firmato_' . $chi);
        if (!$file) {
            return "Non è stato possibile salvare il PDF firmato.";
        }
        if ($chi === 'segretario') {
            $this->db->esegui("UPDATE didattica_sedute SET verbale_pdf = ?, verbale_stato = 'coordinatore', verbale_token = ?, verbale_inviato_il = NOW(), verbale_sollecito_il = NULL, verbale_solleciti = 0 WHERE id = ?", [$file, bin2hex(random_bytes(20)), $sid]);
            $this->emailFirma($this->sedute->perId($sid) ?? $s, (string)$s['coordinatore_email'], 'coordinatore');
        } else {
            $this->db->esegui("UPDATE didattica_sedute SET verbale_pdf = ?, verbale_stato = 'firmato', verbale_token = NULL, verbale_firmato_il = NOW() WHERE id = ?", [$file, $sid]);
            $s = $this->sedute->perId($sid) ?? $s;
            $h = fn ($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
            $a = array_unique(array_filter(array_merge([$s['segretario_email']], $s['consiglio_id'] ? array_column($this->consigli->persone((int)$s['consiglio_id'], 'referente'), 'email') : [])));
            foreach ($a as $e) {
                $this->mailer->invia(
                    $e,
                    "Verbale firmato: " . ServizioSedute::etichetta($s),
                    "<p>Il verbale della seduta del <strong>" . $h($s['organo']) . "</strong>" . ($s['data'] ? " del " . date('d/m/Y', strtotime($s['data'])) : '') . " è firmato in PAdES dal segretario e dal coordinatore" . ($info['nome'] !== '' ? ' (' . $h($info['nome']) . ')' : '') . ".</p><p>Il PDF è in allegato e nel pannello Didattica › Sedute.</p>",
                    '#15803d',
                    [['path' => (string)$this->percorso($s), 'nome' => 'Verbale_' . ($s['data'] ? date('d_m_Y', strtotime($s['data'])) : 'seduta') . '_firmato.pdf']]
                );
            }
        }
        return null;
    }

    public function solleciti(): int
    {
        $gg = max(1, (int)($this->env->valore('FIRME_GIORNI_SOLLECITO') ?? 5));
        $n = 0;
        foreach ($this->db->righe("SELECT id FROM didattica_sedute WHERE verbale_stato IN ('segretario', 'coordinatore') AND verbale_solleciti < 3
                                  AND COALESCE(verbale_sollecito_il, verbale_inviato_il) < NOW() - INTERVAL ? DAY", [$gg]) as $x) {
            $s = $this->sedute->perId((int)$x['id']);
            if ($s === null) {
                continue;
            }
            $seg = $s['verbale_stato'] === 'segretario';
            $this->emailFirma($s, (string)($seg ? $s['segretario_email'] : $s['coordinatore_email']), ($seg ? 'segretario verbalizzante' : 'coordinatore') . ' (sollecito)');
            $this->db->esegui("UPDATE didattica_sedute SET verbale_sollecito_il = NOW(), verbale_solleciti = verbale_solleciti + 1 WHERE id = ?", [(int)$s['id']]);
            $n++;
        }
        return $n;
    }
}
