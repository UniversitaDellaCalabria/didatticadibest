<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Infrastructure\Pdf\VerificaFirme;

/**
 * Controllo dei PDF firmati in PAdES: ogni firma si aggiunge alla precedente senza toccarla (il file firmato deve cominciare
 * esattamente con la versione precedente) e i .p7m (CAdES) sono rifiutati.
 */
final class VerificaPdfFirmato
{
    public function __construct(private VerificaFirme $firme)
    {
    }

    /**
     * Verifica crittografica dell'ultima firma del PDF (integrità del documento firmato) e dati del firmatario dal certificato:
     * ['integra' => true|false|null (null = controllo non disponibile), 'nome' => CN, 'cf' => codice fiscale (serialNumber TINIT-…), 'emittente' => …]
     * La catena dei certificati (elenco dei certificatori qualificati) la verifica chi protocolla, con gli strumenti di Ateneo.
     *
     * @return array{integra: bool|null, nome: string, cf: string, emittente: string}
     */
    public function analizza(string $pdf): array
    {
        $out = ['integra' => null, 'nome' => '', 'cf' => '', 'emittente' => ''];
        if (!preg_match_all('#/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $pdf, $mm, PREG_SET_ORDER)) {
            return $out;
        }
        $m = end($mm);
        [$a, $b, $c, $d] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
        $der = @hex2bin((string) preg_replace('/[^0-9A-Fa-f]/', '', substr($pdf, $a + $b, $c - $a - $b)));
        if (!$der || strlen($der) < 4 || ord($der[0]) !== 0x30) {
            return $out;
        }
        // Lunghezza DER: il contenuto è riempito di zeri fino alla dimensione riservata
        $len = ord($der[1]);
        $hl = 2;
        if ($len & 0x80) {
            $n = $len & 0x7f;
            $len = 0;
            for ($k = 0; $k < $n; $k++) {
                $len = ($len << 8) | ord($der[2 + $k]);
            } $hl = 2 + $n;
        }
        $der = substr($der, 0, $hl + $len);
        if (!function_exists('openssl_cms_verify')) {
            return $out;
        }
        $fd = tempnam(sys_get_temp_dir(), 'pd');
        $fs = tempnam(sys_get_temp_dir(), 'ps');
        $fc = tempnam(sys_get_temp_dir(), 'pc');
        file_put_contents($fd, substr($pdf, $a, $b) . substr($pdf, $c, $d));
        file_put_contents($fs, $der);
        $out['integra'] = @openssl_cms_verify($fd, OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY, $fc, [], null, null, null, $fs, OPENSSL_ENCODING_DER) === true;
        while (openssl_error_string() !== false) {
        }
        $pem = (string)@file_get_contents($fc);
        if ($pem !== '' && preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $cc)) {
            foreach ($cc[0] as $cert) {
                $x = @openssl_x509_parse($cert);
                if (!$x) {
                    continue;
                }
                // Il certificato di firma (non quello di una CA): con codice fiscale o il primo
                $sn = (string)($x['subject']['serialNumber'] ?? '');
                $out['nome'] = (string)(is_array($x['subject']['CN'] ?? null) ? end($x['subject']['CN']) : ($x['subject']['CN'] ?? ''));
                $out['cf'] = preg_match('/^(?:TINIT-)?([A-Z0-9]{16})$/i', $sn, $q) ? strtoupper($q[1]) : '';
                $out['emittente'] = (string)($x['issuer']['O'] ?? ($x['issuer']['CN'] ?? ''));
                if ($out['cf'] !== '') {
                    break;
                }
            }
        }
        @unlink($fd);
        @unlink($fs);
        @unlink($fc);
        return $out;
    }

    /**
     * Controlla il PDF firmato rispetto alla versione precedente: deve essere un PDF (non un .p7m CAdES), cominciare esattamente
     * con la versione precedente (firma aggiunta senza modificare il documento), avere una firma PAdES in più che copre tutto il file,
     * integra; se il certificato ha il codice fiscale e quello di chi deve firmare è noto, devono coincidere.
     * Ritorna [errore | null, dati della firma].
     *
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    public function verifica(string $prima, string $dopo, string $cfAtteso = ''): array
    {
        if (strncmp($dopo, '%PDF-', 5) !== 0) {
            return ["Il file non è un PDF: la firma deve essere PAdES (PDF firmato), non CAdES (.p7m).", []];
        }
        if (strlen($dopo) <= strlen($prima) || strncmp($dopo, $prima, strlen($prima)) !== 0) {
            return ["Il PDF firmato non corrisponde alla lettera: deve essere la stessa lettera scaricata dal portale, con la firma aggiunta (senza modifiche o nuovi salvataggi).", []];
        }
        $f0 = $this->firme->firmePades($prima);
        $f1 = $this->firme->firmePades($dopo);
        if (count($f1) !== count($f0) + 1) {
            return ["Nel PDF non c'è una nuova firma digitale PAdES.", []];
        }
        $nuova = end($f1);
        if (!in_array($nuova['subfilter'], ['ETSI.CAdES.detached', 'adbe.pkcs7.detached'], true)) {
            return ["La firma non è nel formato PAdES (" . ($nuova['subfilter'] ?: 'formato sconosciuto') . ").", []];
        }
        if (!$nuova['valida_struttura'] || !$nuova['copre_tutto']) {
            return ["La firma non copre tutto il documento.", []];
        }
        $info = $this->analizza($dopo);
        if ($info['integra'] === false) {
            return ["La firma digitale non è integra: il documento risulta modificato dopo la firma.", $info];
        }
        $cfAtteso = strtoupper(trim($cfAtteso));
        if ($cfAtteso !== '' && $info['cf'] !== '' && $info['cf'] !== $cfAtteso) {
            return ["La firma è di " . ($info['nome'] ?: 'un\'altra persona') . " (codice fiscale " . $info['cf'] . "), non di chi deve firmare la lettera.", $info];
        }
        return [null, $info];
    }
}
