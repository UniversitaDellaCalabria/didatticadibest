<?php

declare(strict_types=1);

namespace App\Eventi;

/** Referenti letti dal modulo di progetti ed eventi (ref_ruolo[], ref_nome[], ref_email[], ref_tel[], ref_link[], ref_notifiche[], ref_persona[]). */
final class ReferentiForm
{
    /**
     * Righe con almeno nome o email; email, telefono e link non validi vengono scartati (le email scartate finiscono in $emailScartate).
     * Senza $anagrafe le persone scelte dall'anagrafe di Ateneo non vengono collegate.
     *
     * @param list<string> $emailScartate
     * @return list<array<string, mixed>>
     */
    public static function daPost(array $post, ?Anagrafe $anagrafe, array &$emailScartate): array
    {
        $referenti = [];
        $emailScartate = [];
        foreach ((array) ($post['ref_nome'] ?? []) as $i => $nomeR) {
            $r = [
                'ruolo' => mb_substr(trim((string) ($post['ref_ruolo'][$i] ?? '')), 0, 60),
                'nome' => mb_substr(trim((string) $nomeR), 0, 120),
                'email' => mb_substr(strtolower(trim((string) ($post['ref_email'][$i] ?? ''))), 0, 150),
                'telefono' => mb_substr(trim((string) ($post['ref_tel'][$i] ?? '')), 0, 40),
                'link' => mb_substr(trim((string) ($post['ref_link'][$i] ?? '')), 0, 300),
                // Riceve il riepilogo di ogni iscrizione e disdetta (come i gestori)
                'notifiche' => (($post['ref_notifiche'][$i] ?? '0') === '1') ? 1 : 0,
            ];
            // Persona scelta dall'anagrafe di Ateneo: il nome porta alla sua pagina nel portale eventi
            $pid = trim((string) ($post['ref_persona'][$i] ?? ''));
            if ($pid !== '' && $anagrafe !== null && ($pers = $anagrafe->persona($pid))) {
                $r['persona_id'] = $pers['id'];
                if (empty($pers['dettaglio_il'])) {
                    $anagrafe->completaPersona($pers); // foto e scheda pronte per le pagine pubbliche
                }
            }
            if ($r['email'] !== '' && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                $emailScartate[] = $r['email'];
                $r['email'] = '';
            }
            if ($r['telefono'] !== '' && !preg_match('/^[0-9 +().\/-]{5,40}$/', $r['telefono'])) {
                $r['telefono'] = '';
            }
            // Pagina personale: solo indirizzi http(s), es. https://www.unical.it/... («www.…» senza schema diventa https://)
            if ($r['link'] !== '' && !preg_match('#^https?://#i', $r['link'])) {
                $r['link'] = 'https://' . $r['link'];
            }
            if ($r['link'] !== '' && !filter_var($r['link'], FILTER_VALIDATE_URL)) {
                $r['link'] = '';
            }
            if ($r['email'] === '') {
                $r['notifiche'] = 0;
            }
            if ($r['nome'] === '' && $r['email'] === '') {
                continue;
            }
            $referenti[] = $r;
            if (count($referenti) >= 10) {
                break;
            }
        }

        return $referenti;
    }
}
