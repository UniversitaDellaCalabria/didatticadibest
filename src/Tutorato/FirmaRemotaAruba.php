<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Sistema\FileEnv;

/**
 * Firma remota Aruba (ArubaSignService, ARSS) – PAdES. Configurazione nel .env: ARUBA_ARSS_URL (servizio SOAP), ARUBA_ARSS_DOMINIO
 * (dominio di autenticazione OTP, es. frRESIGN o quello dell'Ateneo), ARUBA_ARSS_CERTID (di solito AS0). Utente, password e OTP li
 * scrive chi firma: non si salvano.
 */
final class FirmaRemotaAruba implements FirmaRemota
{
    public function __construct(private FileEnv $env)
    {
    }

    public function disponibile(): bool
    {
        return function_exists('curl_init') && (string) $this->env->valore('ARUBA_ARSS_URL') !== '';
    }

    /**
     * Firma PAdES (pdfsignatureV2, profilo PADESBES) del PDF con la firma remota Aruba. $aspetto = segnaposto della firma visibile
     * (pagina, x, y, l, a in punti). Ritorna [PDF firmato, null] oppure [null, messaggio di errore].
     *
     * @param array<string, mixed>|null $aspetto
     * @return array{0: string|null, 1: string|null}
     */
    public function firma(string $pdf, string $utente, string $password, string $otp, ?array $aspetto = null, string $motivo = ''): array
    {
        $url = (string)$this->env->valore('ARUBA_ARSS_URL');
        if ($url === '' || !function_exists('curl_init')) {
            return [null, "La firma remota non è configurata sul portale: scarica il PDF, firmalo in PAdES e caricalo."];
        }
        $utente = trim($utente);
        $otp = preg_replace('/\s+/', '', $otp);
        if ($utente === '' || $password === '' || $otp === '') {
            return [null, "Scrivi utente, password (PIN) e codice OTP della firma remota."];
        }
        $x = fn ($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $app = '';
        if ($aspetto) {
            $app = '<Apparence><leftx>' . (int)$aspetto['x'] . '</leftx><lefty>' . (int)$aspetto['y'] . '</lefty><page>' . (int)$aspetto['pagina'] . '</page>'
                 . '<reason>' . $x($motivo) . '</reason><rightx>' . (int)($aspetto['x'] + $aspetto['l']) . '</rightx><righty>' . (int)($aspetto['y'] + $aspetto['a']) . '</righty>'
                 . '<testo>' . $x($motivo) . '</testo><bScaleFont>true</bScaleFont><bShowDateTime>true</bShowDateTime></Apparence>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:arub="http://arubasignservice.arubapec.it/"><soapenv:Header/><soapenv:Body>'
             . '<arub:pdfsignatureV2><SignRequestV2><binaryinput>' . base64_encode($pdf) . '</binaryinput><certID>' . $x($this->env->valore('ARUBA_ARSS_CERTID') ?? 'AS0') . '</certID>'
             . '<identity><otpPwd>' . $x($otp) . '</otpPwd><typeOtpAuth>' . $x($this->env->valore('ARUBA_ARSS_DOMINIO') ?? 'frRESIGN') . '</typeOtpAuth><user>' . $x($utente) . '</user><userPWD>' . $x($password) . '</userPWD></identity>'
             . '<profile>PADESBES</profile><requiredmark>false</requiredmark><transport>BYNARYNET</transport></SignRequestV2>' . $app . '<pdfprofile>PADESBES</pdfprofile></arub:pdfsignatureV2>'
             . '</soapenv:Body></soapenv:Envelope>';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15,
                                CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""']]);
        $risp = curl_exec($ch);
        $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($risp === false || $risp === '') {
            return [null, "Il servizio di firma Aruba non risponde" . ($err !== '' ? " ($err)" : '') . ": riprova o carica il PDF firmato."];
        }
        $stato = preg_match('#<status>([^<]*)</status>#', (string)$risp, $m) ? $m[1] : '';
        $descr = preg_match('#<description>([^<]*)</description>#', (string)$risp, $m) ? html_entity_decode($m[1]) : '';
        $codice = preg_match('#<return_code>([^<]*)</return_code>#', (string)$risp, $m) ? $m[1] : '';
        if ($stato !== 'OK' || !preg_match('#<binaryoutput>([^<]+)</binaryoutput>#', (string)$risp, $m)) {
            $fault = preg_match('#<faultstring>([^<]*)</faultstring>#', (string)$risp, $f) ? html_entity_decode($f[1]) : '';
            return [null, "Firma non riuscita" . ($descr !== '' || $fault !== '' ? ': ' . ($descr ?: $fault) : '') . ($codice !== '' ? " (codice $codice)" : '') . ($cod !== 200 && !$descr && !$fault ? " (HTTP $cod)" : '') . ". Controlla credenziali e OTP."];
        }
        $firmato = base64_decode($m[1], true);
        return $firmato ? [$firmato, null] : [null, "Risposta del servizio di firma non leggibile."];
    }
}
