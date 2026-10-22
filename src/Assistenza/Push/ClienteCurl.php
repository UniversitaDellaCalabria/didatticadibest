<?php

declare(strict_types=1);

namespace App\Assistenza\Push;

/** Spedisce le notifiche con curl. */
final class ClienteCurl implements ClienteHttp
{
    public function post(string $url, array $intestazioni, string $corpo, int $secondi = 10): array
    {
        if (!str_starts_with($url, 'https://') || !function_exists('curl_init')) {
            return ['codice' => 0, 'corpo' => 'Indirizzo non sicuro o curl non disponibile'];
        }
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_HTTPHEADER => $intestazioni, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $secondi, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        ]);
        $risposta = curl_exec($c);
        $codice = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $errore = curl_error($c);
        curl_close($c);

        return ['codice' => $codice, 'corpo' => is_string($risposta) ? substr($risposta, 0, 500) : $errore];
    }
}
