<?php

declare(strict_types=1);

namespace App\Portale;

use App\Assistenza\Costanti;
use App\Core\Database;

/**
 * Comunicazioni del Dipartimento (tabella comunicazioni): notizie con data (elenco, home, feed) e pagine informative senza data.
 * Si vedono sul sito quando sono pubblicate, la data di pubblicazione è arrivata e non è passato «valida fino al».
 */
final class ComunicazioniRepository
{
    public const NOTIZIA = 'notizia';
    public const PAGINA = 'pagina';

    private const VISIBILE = "pubblicata = 1 AND (data_pubblicazione IS NULL OR data_pubblicazione <= CURDATE()) AND (valida_fino IS NULL OR valida_fino >= CURDATE())";

    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> tutte, per il pannello */
    public function tutte(): array
    {
        return $this->db->righe('SELECT * FROM comunicazioni ORDER BY IFNULL(data_pubblicazione, DATE(creato_il)) DESC, id DESC');
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM comunicazioni WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null una comunicazione che i visitatori possono vedere adesso */
    public function visibilePerId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM comunicazioni WHERE id = ? AND ' . self::VISIBILE, [$id]);
    }

    /**
     * Notizie visibili, dalla più recente; filtrate per pubblico (una voce senza pubblico vale per tutti) e per parole.
     *
     * @return list<array<string, mixed>>
     */
    public function notizie(string $pubblico = '', string $cerca = '', int $limite = 100): array
    {
        $righe = $this->db->righe('SELECT * FROM comunicazioni WHERE tipo = ? AND ' . self::VISIBILE . ' ORDER BY in_evidenza DESC, IFNULL(data_pubblicazione, DATE(creato_il)) DESC, id DESC LIMIT 400', [self::NOTIZIA]);

        return array_slice(array_values(array_filter($righe, fn (array $r): bool => $this->perPubblico($r, $pubblico) && $this->contiene($r, $cerca))), 0, max(1, $limite));
    }

    /** @return list<array<string, mixed>> pagine informative visibili */
    public function pagine(): array
    {
        return $this->db->righe('SELECT * FROM comunicazioni WHERE tipo = ? AND ' . self::VISIBILE . ' ORDER BY titolo', [self::PAGINA]);
    }

    /** @return list<array<string, mixed>> le notizie «in evidenza» per la home */
    public function inEvidenza(int $limite = 3): array
    {
        return $this->db->righe('SELECT * FROM comunicazioni WHERE tipo = ? AND in_evidenza = 1 AND ' . self::VISIBILE . ' ORDER BY IFNULL(data_pubblicazione, DATE(creato_il)) DESC, id DESC LIMIT ' . max(1, min(12, $limite)), [self::NOTIZIA]);
    }

    /** @return list<array<string, mixed>> comunicazioni visibili con una data da ricordare ancora da venire (scadenze, aperture) */
    public function scadenze(): array
    {
        return $this->db->righe('SELECT * FROM comunicazioni WHERE data_utile IS NOT NULL AND data_utile >= CURDATE() AND ' . self::VISIBILE . ' ORDER BY data_utile, titolo');
    }

    /**
     * Crea (id 0) o aggiorna una comunicazione. Ritorna un messaggio d'errore, o '' se è andata bene; $idSalvato riceve l'id.
     *
     * @param array<string, mixed> $d tipo, titolo, sommario, testo, pubblico (lista), data_pubblicazione, data_utile, valida_fino, in_evidenza, pubblicata
     * @param list<array{e: string, u: string}> $link
     * @param list<array{n: string, f: string}>|null $allegati null = lascia quelli che ci sono
     */
    public function salva(int $id, array $d, array $link, ?array $allegati, ?int &$idSalvato = null): string
    {
        $titolo = mb_substr(trim((string) ($d['titolo'] ?? '')), 0, 200);
        $testo = trim(str_replace("\r\n", "\n", (string) ($d['testo'] ?? '')));
        if ($titolo === '' || $testo === '') {
            return 'Scrivi almeno il titolo e il testo.';
        }
        $data = static function (mixed $v): ?string {
            $v = trim((string) $v);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
        };
        $tipo = ($d['tipo'] ?? '') === self::PAGINA ? self::PAGINA : self::NOTIZIA;
        $pub = $data($d['data_pubblicazione'] ?? '') ?? ($tipo === self::NOTIZIA ? date('Y-m-d') : null);
        $dati = [
            $tipo, $titolo, mb_substr(trim((string) ($d['sommario'] ?? '')), 0, 400), $testo,
            implode(',', Costanti::pubblici(implode(',', array_map('strval', (array) ($d['pubblico'] ?? []))))),
            $tipo === self::NOTIZIA ? $pub : null, $data($d['data_utile'] ?? ''), $data($d['valida_fino'] ?? ''),
            !empty($d['in_evidenza']) && $tipo === self::NOTIZIA ? 1 : 0, !empty($d['pubblicata']) ? 1 : 0,
            (string) json_encode($link, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($id > 0) {
            $this->db->esegui('UPDATE comunicazioni SET tipo=?, titolo=?, sommario=?, testo=?, pubblico=?, data_pubblicazione=?, data_utile=?, valida_fino=?, in_evidenza=?, pubblicata=?, link_json=?, aggiornato_il=NOW() WHERE id=?', [...$dati, $id]);
            if ($allegati !== null) {
                $this->db->esegui('UPDATE comunicazioni SET allegati_json = ? WHERE id = ?', [(string) json_encode($allegati, JSON_UNESCAPED_UNICODE), $id]);
            }
            $idSalvato = $id;
        } else {
            $idSalvato = $this->db->inserisci('INSERT INTO comunicazioni (tipo, titolo, sommario, testo, pubblico, data_pubblicazione, data_utile, valida_fino, in_evidenza, pubblicata, link_json, allegati_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [...$dati, (string) json_encode($allegati ?? [], JSON_UNESCAPED_UNICODE)]);
        }

        return '';
    }

    public function pubblica(int $id, bool $si): void
    {
        $this->db->esegui('UPDATE comunicazioni SET pubblicata = ? WHERE id = ?', [$si ? 1 : 0, $id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM comunicazioni WHERE id = ?', [$id]);
    }

    /** Se la comunicazione si rivolge a quel pubblico (o a tutti: nessun pubblico indicato). @param array<string, mixed> $r */
    public function perPubblico(array $r, string $pubblico): bool
    {
        $p = Costanti::pubblici((string) ($r['pubblico'] ?? ''));

        return $pubblico === '' || !$p || in_array($pubblico, $p, true);
    }

    /** @param array<string, mixed> $r */
    private function contiene(array $r, string $cerca): bool
    {
        foreach (array_filter(preg_split('/\s+/u', mb_strtolower(trim($cerca)))) as $w) {
            if (mb_strpos(mb_strtolower($r['titolo'] . ' ' . $r['sommario'] . ' ' . $r['testo']), $w) === false) {
                return false;
            }
        }

        return true;
    }
}
