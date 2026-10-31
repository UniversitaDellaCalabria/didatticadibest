<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;
use App\Core\Orologio;

/**
 * Bozza dei moduli lunghi delle convenzioni (scuole e aziende): i campi compilati si salvano ogni 30 secondi, così non si perde il lavoro se la sessione
 * scade o si chiude la pagina; quando i dati vengono salvati davvero la bozza si cancella. La chiave lega la bozza al link personale («tipo:token»);
 * non si salvano file, password né il codice di sicurezza della pagina.
 */
final class BozzaModulo
{
    public const TIPI = ['tesi', 'fsl'];
    public const MAX_BYTE = 60000;
    public const GIORNI_CONSERVAZIONE = 30;

    public function __construct(private Database $db, private Orologio $orologio)
    {
    }

    public static function chiave(string $tipo, string $token): ?string
    {
        return in_array($tipo, self::TIPI, true) && preg_match('/^[A-Za-z0-9]{20,64}$/', $token) ? $tipo . ':' . $token : null;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string> i campi da tenere (testo, senza il codice di sicurezza e i pulsanti di invio)
     */
    public static function pulisci(array $post): array
    {
        $out = [];
        foreach ($post as $k => $v) {
            $k = (string) $k;
            if (!is_string($v) || !preg_match('/^[A-Za-z0-9_\-\[\]]{1,60}$/', $k) || $k === 'csrf_token' || $k === 'tipo' || $k === 'token'
                || str_starts_with($k, 'salva') || str_contains($k, 'password') || str_contains($k, 'pwd')) {
                continue;
            }
            $out[$k] = mb_substr($v, 0, 2000);
        }

        return $out;
    }

    /** @param array<string, mixed> $post @return bool false se è troppo grande o vuota */
    public function salva(string $chiave, array $post): bool
    {
        $campi = self::pulisci($post);
        $json = (string) json_encode($campi, JSON_UNESCAPED_UNICODE);
        if (!$campi || strlen($json) > self::MAX_BYTE) {
            return false;
        }
        $this->db->esegui('REPLACE INTO bozze_moduli (chiave, dati, aggiornata_il) VALUES (?, ?, ?)', [$chiave, $json, $this->orologio->adesso()->format('Y-m-d H:i:s')]);
        $limite = $this->orologio->adesso()->modify('-' . self::GIORNI_CONSERVAZIONE . ' days')->format('Y-m-d H:i:s');
        $this->db->esegui('DELETE FROM bozze_moduli WHERE aggiornata_il < ?', [$limite]);

        return true;
    }

    /** @return array{campi: array<string, string>, quando: string}|null */
    public function leggi(string $chiave): ?array
    {
        $r = $this->db->riga('SELECT dati, aggiornata_il FROM bozze_moduli WHERE chiave = ?', [$chiave]);
        $campi = $r ? json_decode((string) $r['dati'], true) : null;

        return is_array($campi) && $campi ? ['campi' => array_map('strval', $campi), 'quando' => (string) $r['aggiornata_il']] : null;
    }

    public function elimina(string $chiave): void
    {
        $this->db->esegui('DELETE FROM bozze_moduli WHERE chiave = ?', [$chiave]);
    }
}
