<?php

declare(strict_types=1);

namespace App\Assistenza\Push;

use Throwable;

/**
 * Manda una notifica push a una sottoscrizione (il «recapito» di un browser): cifra il messaggio, firma la richiesta con le chiavi VAPID e la spedisce
 * al servizio push indicato dalla sottoscrizione. Una risposta 404 o 410 vuol dire che la persona ha tolto il permesso o il browser non c'è più:
 * la sottoscrizione va eliminata.
 */
final class InvioPush
{
    public function __construct(private ChiaviVapid $chiavi, private ClienteHttp $http)
    {
    }

    public function attivo(): bool
    {
        return $this->chiavi->disponibili();
    }

    /**
     * @param array{endpoint: string, p256dh: string, auth: string} $sottoscrizione
     * @param string $urgenza very-low, low, normal o high (le chat in attesa sono «high»)
     * @return array{ok: bool, scaduta: bool, codice: int, errore: string}
     */
    public function invia(array $sottoscrizione, string $messaggio, int $ttl = 3600, string $urgenza = 'normal'): array
    {
        if (!$this->chiavi->disponibili()) {
            return ['ok' => false, 'scaduta' => false, 'codice' => 0, 'errore' => 'Chiavi VAPID mancanti nel .env'];
        }
        try {
            $corpo = CifraturaPush::cifra($messaggio, CurvaEc::b64uDecodifica($sottoscrizione['p256dh']), CurvaEc::b64uDecodifica($sottoscrizione['auth']));
            $r = $this->http->post($sottoscrizione['endpoint'], [
                'Authorization: ' . $this->chiavi->autorizzazione($sottoscrizione['endpoint']),
                'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'Content-Length: ' . strlen($corpo),
                'TTL: ' . max(0, $ttl), 'Urgency: ' . (in_array($urgenza, ['very-low', 'low', 'normal', 'high'], true) ? $urgenza : 'normal'),
            ], $corpo);
        } catch (Throwable $e) {
            return ['ok' => false, 'scaduta' => false, 'codice' => 0, 'errore' => $e->getMessage()];
        }

        return [
            'ok' => $r['codice'] >= 200 && $r['codice'] < 300, 'scaduta' => in_array($r['codice'], [404, 410], true), 'codice' => $r['codice'],
            'errore' => $r['codice'] >= 200 && $r['codice'] < 300 ? '' : $r['corpo'],
        ];
    }
}
