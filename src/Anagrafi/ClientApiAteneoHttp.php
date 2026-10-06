<?php

declare(strict_types=1);

namespace App\Anagrafi;

/** Client HTTP delle API di Ateneo (cURL, o file_get_contents se cURL manca). Spostato da api_unical_get/api_unical_tutte. */
final class ClientApiAteneoHttp implements ClientApiAteneo
{
    private const AGENTE = 'DidatticaDiBEST/1.0';

    public function __construct(private string $base = Anagrafe::API_UNICAL)
    {
    }

    public function get(string $percorso, array $query = [], int $timeout = 25): ?array
    {
        $url = $this->base . ltrim($percorso, '/') . ($query ? '?' . http_build_query($query) : '');
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
                                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_HTTPHEADER => ['Accept: application/json'],
                                    CURLOPT_USERAGENT => self::AGENTE]);
            $corpo = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
                $corpo = false;
            }
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "Accept: application/json\r\nUser-Agent: " . self::AGENTE . "\r\n"]]);
            $corpo = @file_get_contents($url, false, $ctx);
        }
        if (!is_string($corpo) || $corpo === '') {
            return null;
        }
        $j = json_decode($corpo, true);

        return is_array($j) ? $j : null;
    }

    public function tutte(string $percorso, array $query = []): ?array
    {
        $out = [];
        for ($pag = 1; $pag <= 40; $pag++) {
            $j = $this->get($percorso, $query + ['page_size' => 500, 'page' => $pag]);
            if ($j === null || !isset($j['results']) || !is_array($j['results'])) {
                return null;
            }
            array_push($out, ...array_values($j['results']));
            if (empty($j['next'])) {
                break;
            }
        }

        return $out;
    }

    public function scarica(string $url, int $timeout = 10): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => self::AGENTE]);
            $dati = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
                $dati = false;
            }
            curl_close($ch);
        } else {
            $dati = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout]]));
        }

        return is_string($dati) ? $dati : null;
    }
}
