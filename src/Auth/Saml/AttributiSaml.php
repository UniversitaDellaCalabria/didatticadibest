<?php

declare(strict_types=1);

namespace App\Auth\Saml;

/**
 * Dati della persona negli attributi SAML inviati dall'IdP di Ateneo (o da SPID/CIE): email, codice fiscale,
 * nome e cognome. Spostato da estrai_email_saml(), tipo_utente_saml() (inc/base.php) e da saml_login.php, senza cambiare la logica.
 */
final class AttributiSaml
{
    /**
     * L'IdP può inviare l'email col nome breve (mail/email) o in formato OID (urn:oid:0.9.2342.19200300.100.1.3),
     * anche con più valori. Sceglie l'indirizzo in base al tipo di utente:
     *   'studente'   → @studenti.unical.it
     *   'dipendente' → @unical.it
     *   'esterno'    → email personale (SPID/CIE), cioè non di Ateneo
     * Se l'indirizzo preferito non c'è, usa il primo valido. Restituisce '' se non ne trova.
     *
     * @param array<string, mixed> $attributes
     */
    public static function email(array $attributes, string $tipo = 'esterno'): string
    {
        $chiavi = ['mail', 'email', 'Email', 'emailAddress', 'urn:oid:0.9.2342.19200300.100.1.3',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'];
        $candidate = [];
        foreach ($chiavi as $k) {
            foreach ((array) ($attributes[$k] ?? []) as $v) {
                $v = strtolower(trim((string) $v));
                if (filter_var($v, FILTER_VALIDATE_EMAIL) && !in_array($v, $candidate, true)) {
                    $candidate[] = $v;
                }
            }
        }
        if (empty($candidate)) {
            // Logga i NOMI degli attributi ricevuti (non i valori) per capire cosa manda l'IdP
            error_log('[SSO] Email non trovata negli attributi SAML. Attributi ricevuti: ' . implode(', ', array_keys($attributes)));

            return '';
        }
        $isStudenti = static fn (string $e): bool => substr($e, -strlen('@studenti.unical.it')) === '@studenti.unical.it';
        $isUnical = static fn (string $e): bool => substr($e, -strlen('@unical.it')) === '@unical.it';
        $filtri = [
            'studente' => $isStudenti,
            'dipendente' => $isUnical,
            'esterno' => static fn (string $e): bool => !$isStudenti($e) && !$isUnical($e),
        ];
        foreach ($candidate as $e) {
            if (($filtri[$tipo] ?? $filtri['esterno'])($e)) {
                return $e;
            }
        }

        return $candidate[0];
    }

    /** Stessa priorità del ruolo di default al primo accesso: matricola studente > matricola dipendente > esterno (SPID/CIE) */
    public static function tipoUtente(string $matrStud, string $matrDip): string
    {
        if (trim($matrStud) !== '') {
            return 'studente';
        }
        if (trim($matrDip) !== '') {
            return 'dipendente';
        }

        return 'esterno';
    }

    /**
     * Codice fiscale dall'attributo di Ateneo o dall'identificativo schac (…:CF). Con $ancheSpid anche fiscalNumber/spidCode
     * (senza il prefisso TINIT-), come nella pagina di accesso; l'accesso automatico guarda solo i primi due.
     *
     * @param array<string, mixed> $attributes
     */
    public static function codiceFiscale(array $attributes, bool $ancheSpid): ?string
    {
        $cf = $attributes['codice_fiscale'][0] ?? null;
        if (!$cf && !empty($attributes['urn:oid:1.3.6.1.4.1.25178.1.2.15'][0])) {
            $parts = explode(':', $attributes['urn:oid:1.3.6.1.4.1.25178.1.2.15'][0]);
            $cf = end($parts);
        }
        if (!$cf && $ancheSpid) {
            $cf = $attributes['fiscalNumber'][0] ?? ($attributes['spidCode'][0] ?? null);
            if ($cf && strpos((string) $cf, 'TINIT-') === 0) {
                $cf = str_replace('TINIT-', '', (string) $cf);
            }
        }

        return $cf ? (string) $cf : null;
    }

    /**
     * Nome e cognome (estrazione avanzata SPID / SAML), con ripiego su common name e displayName; nome 'Utente' se manca.
     *
     * @param array<string, mixed> $attributes
     * @return array{0: string, 1: string}
     */
    public static function nomeCognome(array $attributes): array
    {
        $nome = $attributes['givenName'][0]
            ?? $attributes['givenname'][0]
            ?? $attributes['name'][0]
            ?? $attributes['first_name'][0]
            ?? $attributes['urn:oid:2.5.4.42'][0]
            ?? '';
        $cognome = $attributes['sn'][0]
            ?? $attributes['surname'][0]
            ?? $attributes['family_name'][0]
            ?? $attributes['last_name'][0]
            ?? $attributes['urn:oid:2.5.4.4'][0]
            ?? '';
        if (empty($nome) && !empty($attributes['cn'][0])) {
            $parts = explode(' ', trim($attributes['cn'][0]), 2);
            $nome = $parts[0];
            $cognome = $parts[1] ?? '';
        } elseif (empty($nome) && !empty($attributes['displayName'][0])) {
            $parts = explode(' ', trim($attributes['displayName'][0]), 2);
            $nome = $parts[0];
            $cognome = $parts[1] ?? '';
        }
        if (empty($nome)) {
            $nome = 'Utente';
        }

        return [(string) $nome, (string) $cognome];
    }

    /**
     * Come si è autenticata la persona (dall'IdP SAML): SPID (con il livello), CIE o credenziali di Ateneo, con identificativi
     * e ora dell'autenticazione. Si conservano in sessione (auth_meta) e finiscono nella lettera di incarico
     * confermata dallo studente. Da chiamare prima di cleanup(): legge la sessione di SimpleSAML ($as).
     *
     * @param \SimpleSAML\Auth\Simple $as
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function metadatiAccesso(object $as, array $attributes, string $ip): array
    {
        $dato = static function (string $k) use ($as): mixed {
            try {
                return $as->getAuthData($k);
            } catch (\Throwable) {
                return null;
            }
        };
        $a1 = static fn (string $k): string => trim((string) (($attributes[$k][0] ?? '') ?: ''));
        $idp = (string) ($dato('saml:sp:IdP') ?? '');
        $ctx = $dato('saml:sp:AuthnContext');
        $ctx = is_array($ctx) ? implode(' ', $ctx) : (string) ($ctx ?? '');
        $ist = $dato('AuthnInstant');
        $spidCode = $a1('spidCode');
        $tutto = mb_strtolower($idp . ' ' . $ctx . ' ' . implode(' ', array_keys($attributes)));
        $metodo = 'ateneo';
        if (str_contains($tutto, 'cie') && (str_contains($tutto, 'servizicie') || str_contains($tutto, 'idserver') || str_contains($tutto, '/cie'))) {
            $metodo = 'cie';
        } elseif ($spidCode !== '' || str_contains($tutto, 'spid')) {
            $metodo = 'spid';
        }
        $livello = preg_match('/SpidL([123])/i', $ctx, $m) ? (int) $m[1] : null;

        return ['metodo' => $metodo, 'livello' => $livello, 'idp' => mb_substr($idp, 0, 255), 'contesto' => mb_substr($ctx, 0, 255),
            'spid_code' => mb_substr($spidCode, 0, 64), 'cf' => strtoupper(str_replace('TINIT-', '', $a1('fiscalNumber') ?: $a1('codice_fiscale'))),
            'sessione' => mb_substr((string) ($dato('saml:sp:SessionIndex') ?? ''), 0, 120),
            'istante' => is_numeric($ist) ? date('c', (int) $ist) : date('c'), 'ip' => $ip];
    }
}
