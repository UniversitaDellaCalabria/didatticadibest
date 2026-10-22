<?php

declare(strict_types=1);

namespace App\Assistenza\Push;

use RuntimeException;

/**
 * Il messaggio di una notifica push cifrato come vuole il browser (RFC 8291, formato «aes128gcm» della RFC 8188): solo il browser che ha chiesto la
 * notifica, con la sua chiave privata, può leggerlo; il servizio di Google, Mozilla o Microsoft che lo recapita vede soltanto dati cifrati.
 */
final class CifraturaPush
{
    /** Dimensione massima del messaggio in chiaro: i servizi push accettano 4096 byte in tutto, comprese intestazione e padding. */
    public const MAX_BYTE = 3000;

    /**
     * @param string $payload testo del messaggio (di solito JSON)
     * @param string $chiaveBrowser punto pubblico del browser (65 byte), dalla sottoscrizione (p256dh)
     * @param string $auth segreto di autenticazione del browser (16 byte), dalla sottoscrizione (auth)
     * @param string|null $sale 16 byte casuali (solo per le prove con valori fissi)
     * @param array{privata: string, pubblica: string}|null $chiaveNostra coppia di chiavi usa e getta (solo per le prove)
     * @return string il corpo della richiesta: intestazione con sale e chiave nostra, poi il messaggio cifrato
     */
    public static function cifra(string $payload, string $chiaveBrowser, string $auth, ?string $sale = null, ?array $chiaveNostra = null): string
    {
        if (strlen($payload) > self::MAX_BYTE) {
            throw new RuntimeException('Messaggio troppo lungo per una notifica push.');
        }
        if (strlen($chiaveBrowser) !== 65 || strlen($auth) < 16) {
            throw new RuntimeException('Sottoscrizione push non valida.');
        }
        $sale ??= random_bytes(16);
        $nostra = $chiaveNostra ?? CurvaEc::genera();

        $segreto = CurvaEc::accordo($nostra['privata'], $nostra['pubblica'], $chiaveBrowser);
        $ikm = hash_hkdf('sha256', $segreto, 32, "WebPush: info\0" . $chiaveBrowser . $nostra['pubblica'], $auth);
        $chiave = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sale);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sale);

        // un solo blocco: messaggio + 0x02 (fine dei dati)
        $cifrato = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $chiave, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cifrato === false) {
            throw new RuntimeException('Cifratura non riuscita: ' . (string) openssl_error_string());
        }

        return $sale . pack('N', 4096) . chr(65) . $nostra['pubblica'] . $cifrato . $tag;
    }
}
