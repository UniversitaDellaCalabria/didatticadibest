<?php

declare(strict_types=1);

namespace App\Assistenza\Push;

use App\Core\Config;
use App\Core\Orologio;

/**
 * Le chiavi con cui il portale si presenta ai servizi push (VAPID, RFC 8292): una coppia P-256 creata una volta sola con
 * strumenti/genera_chiavi_push.php e scritta nel .env del server (PUSH_VAPID_PUBLIC, PUSH_VAPID_PRIVATE, PUSH_VAPID_SUBJECT = indirizzo email di contatto).
 * Senza queste chiavi le notifiche push sono spente e restano le email.
 */
final class ChiaviVapid
{
    public function __construct(private Config $config, private Orologio $orologio)
    {
    }

    public function disponibili(): bool
    {
        return strlen(CurvaEc::b64uDecodifica($this->config->testo('PUSH_VAPID_PUBLIC'))) === 65 && strlen(CurvaEc::b64uDecodifica($this->config->testo('PUSH_VAPID_PRIVATE'))) === 32;
    }

    /** La chiave pubblica nel formato che il browser vuole per iscriversi (base64 senza riempimento). */
    public function pubblica(): string
    {
        return $this->disponibili() ? $this->config->testo('PUSH_VAPID_PUBLIC') : '';
    }

    /** L'indirizzo di contatto (mailto:…) che i servizi push possono usare se qualcosa non va. */
    public function contatto(): string
    {
        $c = trim($this->config->testo('PUSH_VAPID_SUBJECT'));

        return $c === '' ? 'mailto:assistenza@localhost' : (str_starts_with($c, 'mailto:') || str_starts_with($c, 'https://') ? $c : 'mailto:' . $c);
    }

    /**
     * L'intestazione «Authorization» per un servizio push: un token firmato valido 12 ore per quel servizio.
     *
     * @return string «vapid t=…, k=…»
     */
    public function autorizzazione(string $endpoint): string
    {
        $p = parse_url($endpoint);
        $aud = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        $intestazione = CurvaEc::b64uCodifica((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $corpo = CurvaEc::b64uCodifica((string) json_encode(['aud' => $aud, 'exp' => $this->orologio->adesso()->getTimestamp() + 12 * 3600, 'sub' => $this->contatto()], JSON_UNESCAPED_SLASHES));
        $firma = CurvaEc::firma("$intestazione.$corpo", CurvaEc::b64uDecodifica($this->config->testo('PUSH_VAPID_PRIVATE')), CurvaEc::b64uDecodifica($this->config->testo('PUSH_VAPID_PUBLIC')));

        return 'vapid t=' . "$intestazione.$corpo." . CurvaEc::b64uCodifica($firma) . ', k=' . $this->config->testo('PUSH_VAPID_PUBLIC');
    }
}
