<?php

declare(strict_types=1);

namespace App\Infrastructure\Sicurezza;

use App\Sistema\FileEnv;

/**
 * Cifratura dei dati personali delicati nel database (IBAN e dati bancari della dichiarazione individuale): AES-256-GCM con una chiave
 * che sta nel file .env (CIFRATURA_CHIAVE, almeno 16 caratteri casuali) e mai nel database. Il testo cifrato comincia con «v1:»
 * ed è autenticato: un dato alterato non si decifra. Senza chiave la cifratura non è disponibile e i dati non si salvano.
 */
final class Cifratura
{
    private const PREFISSO = 'v1:';

    /** @var string|null chiave di 32 byte derivata dalla frase del .env */
    private ?string $chiave;

    public function __construct(?string $frase = null)
    {
        $frase = (string) $frase;
        $this->chiave = strlen($frase) >= 16 ? hash('sha256', $frase, true) : null;
    }

    public static function daEnv(FileEnv $env): self
    {
        return new self((string) $env->valore('CIFRATURA_CHIAVE'));
    }

    public function disponibile(): bool
    {
        return $this->chiave !== null && function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    /** Cifra un testo. Ritorna null se la cifratura non è disponibile. */
    public function cifra(string $chiaro): ?string
    {
        if (!$this->disponibile()) {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($chiaro, 'aes-256-gcm', (string) $this->chiave, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            return null;
        }

        return self::PREFISSO . base64_encode($iv . $tag . $ct);
    }

    /** Decifra un testo prodotto da cifra(); null se la chiave manca o cambiata, o se il dato è stato alterato. */
    public function decifra(string $cifrato): ?string
    {
        if (!$this->disponibile() || strncmp($cifrato, self::PREFISSO, strlen(self::PREFISSO)) !== 0) {
            return null;
        }
        $raw = base64_decode(substr($cifrato, strlen(self::PREFISSO)), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $chiaro = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', (string) $this->chiave, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $chiaro === false ? null : $chiaro;
    }
}
