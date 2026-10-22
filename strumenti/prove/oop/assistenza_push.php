<?php
// Prove del motore delle notifiche push (App\Assistenza\Push): cifratura del messaggio secondo la RFC 8291 (con il vettore di prova della RFC), firma VAPID (RFC 8292)
// e invio con un finto servizio push. Chiamato da esegui.php.
sezione("Assistenza: notifiche push (cifratura RFC 8291, firma VAPID, invio)");

use App\Assistenza\Push\ChiaviVapid;
use App\Assistenza\Push\CifraturaPush;
use App\Assistenza\Push\ClienteHttp;
use App\Assistenza\Push\CurvaEc;
use App\Assistenza\Push\InvioPush;
use App\Core\Config;

$b = fn(string $s) => CurvaEc::b64uDecodifica($s);

prova(CurvaEc::b64uCodifica("\xfb\xff\xfe") === '-__-' && CurvaEc::b64uDecodifica('-__-') === "\xfb\xff\xfe" && CurvaEc::b64uDecodifica(CurvaEc::b64uCodifica('ab')) === 'ab' && CurvaEc::b64uDecodifica('!!') === '', "base64 senza riempimento: andata e ritorno");

// Vettore di prova dell'Appendice A della RFC 8291
$rfc = [
    'testo' => 'When I grow up, I want to be a watermelon',
    'as_priv' => $b('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), 'as_pub' => $b('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
    'ua_pub' => $b('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'), 'auth' => $b('BTBZMqHH6r4Tts7J_aSIgg'), 'sale' => $b('DGv6ra1nlYgDCS1FRnbzlw'),
    'atteso' => 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
];
$corpo_rfc = CifraturaPush::cifra($rfc['testo'], $rfc['ua_pub'], $rfc['auth'], $rfc['sale'], ['privata' => $rfc['as_priv'], 'pubblica' => $rfc['as_pub']]);
prova(CurvaEc::b64uCodifica($corpo_rfc) === $rfc['atteso'], "cifratura: identica al vettore di prova della RFC 8291", CurvaEc::b64uCodifica($corpo_rfc));

// Andata e ritorno con chiavi nuove: il browser (qui simulato) decifra quello che spediamo
$decifra = function (string $corpo, string $privBrowser, string $pubBrowser, string $auth): string {
    $sale = substr($corpo, 0, 16);
    $lung = ord($corpo[20]);
    $pubNostra = substr($corpo, 21, $lung);
    $cifrato = substr($corpo, 21 + $lung);
    $segreto = CurvaEc::accordo($privBrowser, $pubBrowser, $pubNostra);
    $ikm = hash_hkdf('sha256', $segreto, 32, "WebPush: info\0" . $pubBrowser . $pubNostra, $auth);
    $chiave = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sale);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sale);
    $chiaro = (string) openssl_decrypt(substr($cifrato, 0, -16), 'aes-128-gcm', $chiave, OPENSSL_RAW_DATA, $nonce, substr($cifrato, -16));

    return rtrim($chiaro, "\x02") === substr($chiaro, 0, -1) ? substr($chiaro, 0, -1) : '';
};
$browser = CurvaEc::genera();
$auth = random_bytes(16);
$messaggio = (string) json_encode(['titolo' => 'Nuova richiesta', 'testo' => 'Sara Studente: città, è già così? 🙂'], JSON_UNESCAPED_UNICODE);
$c1 = CifraturaPush::cifra($messaggio, $browser['pubblica'], $auth);
$c2 = CifraturaPush::cifra($messaggio, $browser['pubblica'], $auth);
prova($decifra($c1, $browser['privata'], $browser['pubblica'], $auth) === $messaggio, "cifratura: il browser decifra il messaggio (accenti ed emoji compresi)");
prova($c1 !== $c2 && substr($c1, 0, 16) !== substr($c2, 0, 16) && strlen($c1) === 16 + 4 + 1 + 65 + strlen($messaggio) + 1 + 16, "cifratura: ogni messaggio ha sale e chiave nuovi e la lunghezza prevista");
prova($decifra($c1, $browser['privata'], $browser['pubblica'], random_bytes(16)) === '', "cifratura: con un segreto di autenticazione sbagliato non si legge nulla");
$errore_lungo = false;
try { CifraturaPush::cifra(str_repeat('x', CifraturaPush::MAX_BYTE + 1), $browser['pubblica'], $auth); } catch (RuntimeException $e) { $errore_lungo = true; }
$errore_chiave = false;
try { CifraturaPush::cifra('x', 'corta', $auth); } catch (RuntimeException $e) { $errore_chiave = true; }
prova($errore_lungo && $errore_chiave, "cifratura: messaggio troppo lungo o chiave del browser non valida rifiutati");

// Curva: accordo simmetrico e firma
$a = CurvaEc::genera();
$bb = CurvaEc::genera();
prova(strlen($a['pubblica']) === 65 && $a['pubblica'][0] === "\x04" && strlen($a['privata']) === 32 && CurvaEc::accordo($a['privata'], $a['pubblica'], $bb['pubblica']) === CurvaEc::accordo($bb['privata'], $bb['pubblica'], $a['pubblica']), "curva P-256: chiavi di 65 e 32 byte, accordo uguale da entrambe le parti");
$f = CurvaEc::firma('dati', $a['privata'], $a['pubblica']);
prova(strlen($f) === 64 && CurvaEc::verifica('dati', $f, $a['pubblica']) && !CurvaEc::verifica('altri', $f, $a['pubblica']) && !CurvaEc::verifica('dati', $f, $bb['pubblica']), "firma ES256: 64 byte, valida solo per quei dati e quella chiave");

// VAPID
$vapid_k = CurvaEc::genera();
$cfg = new Config(['PUSH_VAPID_PUBLIC' => CurvaEc::b64uCodifica($vapid_k['pubblica']), 'PUSH_VAPID_PRIVATE' => CurvaEc::b64uCodifica($vapid_k['privata']), 'PUSH_VAPID_SUBJECT' => 'assistenza@example.org']);
$orologio_p = new class implements \App\Core\Orologio { public function adesso(): DateTimeImmutable { return new DateTimeImmutable('2026-10-22 10:00:00 UTC'); } };
$vapid = new ChiaviVapid($cfg, $orologio_p);
$auth_h = $vapid->autorizzazione('https://fcm.googleapis.com/fcm/send/abc123');
preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $auth_h, $m);
$claims = json_decode($b($m[2] ?? ''), true);
prova($vapid->disponibili() && $m && $m[4] === CurvaEc::b64uCodifica($vapid_k['pubblica']) && json_decode($b($m[1]), true) === ['typ' => 'JWT', 'alg' => 'ES256']
    && $claims === ['aud' => 'https://fcm.googleapis.com', 'exp' => (new DateTimeImmutable('2026-10-22 10:00:00 UTC'))->getTimestamp() + 43200, 'sub' => 'mailto:assistenza@example.org'], "VAPID: intestazione con chiave pubblica e token (servizio, scadenza a 12 ore, contatto)", $auth_h);
prova(CurvaEc::verifica($m[1] . '.' . $m[2], $b($m[3]), $vapid_k['pubblica']), "VAPID: la firma del token è valida con la chiave pubblica");
prova((new ChiaviVapid(new Config([]), $orologio_p))->disponibili() === false && (new ChiaviVapid(new Config(['PUSH_VAPID_PUBLIC' => 'x', 'PUSH_VAPID_PRIVATE' => 'y']), $orologio_p))->disponibili() === false
    && (new ChiaviVapid(new Config(['PUSH_VAPID_SUBJECT' => 'https://x.it/c']), $orologio_p))->contatto() === 'https://x.it/c', "VAPID: senza chiavi (o con chiavi rovinate) le notifiche sono spente");

// Invio con un finto servizio push
$finto = new class implements ClienteHttp {
    public array $richieste = [];
    public int $risposta = 201;
    public function post(string $url, array $intestazioni, string $corpo, int $secondi = 10): array { $this->richieste[] = compact('url', 'intestazioni', 'corpo'); return ['codice' => $this->risposta, 'corpo' => 'risposta']; }
};
$invio = new InvioPush($vapid, $finto);
$sott = ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/xyz', 'p256dh' => CurvaEc::b64uCodifica($browser['pubblica']), 'auth' => CurvaEc::b64uCodifica($auth)];
$r = $invio->invia($sott, $messaggio, 600, 'high');
$ric = $finto->richieste[0] ?? ['intestazioni' => [], 'corpo' => '', 'url' => ''];
prova($r === ['ok' => true, 'scaduta' => false, 'codice' => 201, 'errore' => ''] && $ric['url'] === $sott['endpoint'] && in_array('TTL: 600', $ric['intestazioni'], true) && in_array('Urgency: high', $ric['intestazioni'], true)
    && in_array('Content-Encoding: aes128gcm', $ric['intestazioni'], true) && $decifra($ric['corpo'], $browser['privata'], $browser['pubblica'], $auth) === $messaggio, "invio: richiesta con TTL, urgenza, intestazioni giuste e messaggio che il browser sa leggere", json_encode($r));
prova(count(array_filter($ric['intestazioni'], fn($h) => str_starts_with($h, 'Authorization: vapid t='))) === 1, "invio: la richiesta è firmata con le chiavi VAPID");
$finto->risposta = 410;
$r410 = $invio->invia($sott, 'x');
$finto->risposta = 404;
$r404 = $invio->invia($sott, 'x');
$finto->risposta = 503;
$r503 = $invio->invia($sott, 'x');
prova($r410['scaduta'] && !$r410['ok'] && $r404['scaduta'] && !$r503['scaduta'] && !$r503['ok'] && $r503['codice'] === 503, "invio: 404 e 410 = sottoscrizione scaduta da eliminare; 503 = errore passeggero, si riprova");
$finto->risposta = 201;
$senza_chiavi = (new InvioPush(new ChiaviVapid(new Config([]), $orologio_p), $finto))->invia($sott, 'x');
$sott_rotta = $invio->invia(['endpoint' => $sott['endpoint'], 'p256dh' => 'rotta', 'auth' => 'x'], 'x');
prova(!$senza_chiavi['ok'] && str_contains($senza_chiavi['errore'], 'VAPID') && !$sott_rotta['ok'] && !$sott_rotta['scaduta'], "invio: senza chiavi o con una sottoscrizione rovinata non parte nulla e non si cancella niente");
