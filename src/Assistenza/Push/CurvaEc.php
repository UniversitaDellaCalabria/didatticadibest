<?php

declare(strict_types=1);

namespace App\Assistenza\Push;

use RuntimeException;

/**
 * Le piccole operazioni sulla curva P-256 che servono alle notifiche push: chiavi in formato «grezzo» (punto di 65 byte e numero privato di 32 byte,
 * come le scambiano i browser) trasformate nei file PEM che capisce OpenSSL, accordo di chiave (ECDH) e firma ES256 in formato grezzo (64 byte).
 */
final class CurvaEc
{
    private const OID_P256 = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
    private const PREFISSO_SPKI = "\x30\x59\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07\x03\x42\x00";

    public static function b64uCodifica(string $dati): string
    {
        return rtrim(strtr(base64_encode($dati), '+/', '-_'), '=');
    }

    public static function b64uDecodifica(string $testo): string
    {
        $d = base64_decode(strtr($testo, '-_', '+/') . str_repeat('=', (4 - strlen($testo) % 4) % 4), true);

        return $d === false ? '' : $d;
    }

    private static function pem(string $tipo, string $der): string
    {
        return "-----BEGIN $tipo-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $tipo-----\n";
    }

    /** Il punto pubblico (65 byte, 0x04 + X + Y) come chiave pubblica PEM. */
    public static function pemPubblica(string $punto): string
    {
        if (strlen($punto) !== 65 || $punto[0] !== "\x04") {
            throw new RuntimeException('Chiave pubblica non valida: deve essere un punto P-256 di 65 byte.');
        }

        return self::pem('PUBLIC KEY', self::PREFISSO_SPKI . $punto);
    }

    /** Il numero privato (32 byte) con il suo punto pubblico come chiave privata PEM. */
    public static function pemPrivata(string $privata, string $punto): string
    {
        if (strlen($privata) !== 32 || strlen($punto) !== 65) {
            throw new RuntimeException('Chiave privata non valida.');
        }
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $privata . "\xA0\x0A" . self::OID_P256 . "\xA1\x44\x03\x42\x00" . $punto;

        return self::pem('EC PRIVATE KEY', $der);
    }

    /**
     * Una coppia di chiavi nuova.
     *
     * @return array{privata: string, pubblica: string} privata di 32 byte, pubblica di 65 byte
     */
    public static function genera(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = $k ? openssl_pkey_get_details($k) : false;
        if (!$d || empty($d['ec']['d'])) {
            throw new RuntimeException('OpenSSL non riesce a creare chiavi P-256: ' . (string) openssl_error_string());
        }

        return [
            'privata' => str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT),
            'pubblica' => "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT),
        ];
    }

    /** Il segreto condiviso (32 byte) tra la nostra chiave privata e il punto pubblico dell'altra parte. */
    public static function accordo(string $privata, string $puntoNostro, string $puntoAltro): string
    {
        $s = openssl_pkey_derive(self::pemPubblica($puntoAltro), self::pemPrivata($privata, $puntoNostro), 32);
        if ($s === false) {
            throw new RuntimeException('Accordo di chiave non riuscito: ' . (string) openssl_error_string());
        }

        return $s;
    }

    /** Firma ES256 (ECDSA con SHA-256) in formato grezzo R||S di 64 byte, come richiesto dai token JWT. */
    public static function firma(string $dati, string $privata, string $punto): string
    {
        if (!openssl_sign($dati, $der, self::pemPrivata($privata, $punto), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Firma non riuscita: ' . (string) openssl_error_string());
        }

        return self::derInGrezzo($der);
    }

    /** Controlla una firma ES256 grezza (per le prove). */
    public static function verifica(string $dati, string $firmaGrezza, string $punto): bool
    {
        return strlen($firmaGrezza) === 64 && openssl_verify($dati, self::grezzoInDer($firmaGrezza), self::pemPubblica($punto), OPENSSL_ALGO_SHA256) === 1;
    }

    private static function derInGrezzo(string $der): string
    {
        // SEQUENCE { INTEGER r, INTEGER s }
        $p = 2 + ((ord($der[1]) & 0x80) ? (ord($der[1]) & 0x7F) : 0);
        $parti = [];
        for ($i = 0; $i < 2; ++$i) {
            $lunghezza = ord($der[$p + 1]);
            $parti[] = str_pad(ltrim(substr($der, $p + 2, $lunghezza), "\0"), 32, "\0", STR_PAD_LEFT);
            $p += 2 + $lunghezza;
        }

        return $parti[0] . $parti[1];
    }

    private static function grezzoInDer(string $grezzo): string
    {
        $intero = static function (string $n): string {
            $n = ltrim($n, "\0");
            if ($n === '' || ord($n[0]) >= 0x80) {
                $n = "\0" . $n;
            }

            return "\x02" . chr(strlen($n)) . $n;
        };
        $corpo = $intero(substr($grezzo, 0, 32)) . $intero(substr($grezzo, 32));

        return "\x30" . chr(strlen($corpo)) . $corpo;
    }
}
