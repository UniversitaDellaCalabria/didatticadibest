<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Le pagine del sito di Ateneo e del Dipartimento che Didacticus legge come fonti per il modello linguistico (tabella didacticus_pagine): l'indirizzo lo sceglie chi
 * istruisce Didacticus, il testo lo scarica LettorePagine ogni settimana. Sono ammessi solo indirizzi https del dominio unical.it.
 */
final class PagineRepository
{
    /** Domini ammessi: unical.it e tutti i suoi sottodomini. */
    public const DOMINIO = 'unical.it';
    public const MAX_PAGINE = 60;

    public function __construct(private Database $db)
    {
    }

    /** L'indirizzo è https e del dominio di Ateneo? */
    public static function urlAmmesso(string $url): bool
    {
        $p = parse_url(trim($url));
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['port'])) {
            return false;
        }
        $h = strtolower((string) $p['host']);

        return $h === self::DOMINIO || str_ends_with($h, '.' . self::DOMINIO);
    }

    /** @return list<array<string, mixed>> */
    public function tutte(): array
    {
        return Righe::testo($this->db->righe('SELECT id, url, titolo, attiva, esito, aggiornata_il, creata_il, CHAR_LENGTH(COALESCE(testo, \'\')) AS lunghezza FROM didacticus_pagine ORDER BY titolo = \'\', titolo, id'));
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        $r = $this->db->riga('SELECT * FROM didacticus_pagine WHERE id = ?', [$id]);

        return $r === null ? null : Righe::testo([$r])[0];
    }

    /** Le pagine attive con un testo, per la ricerca. @return list<array<string, mixed>> */
    public function conTesto(): array
    {
        return Righe::testo($this->db->righe('SELECT id, url, titolo, testo FROM didacticus_pagine WHERE attiva = 1 AND testo IS NOT NULL AND testo <> \'\''));
    }

    /** Aggiunge una pagina; ritorna l'id, o 0 se l'indirizzo non è ammesso, c'è già o si è al massimo. */
    public function aggiungi(string $url, string $quando): int
    {
        $url = trim($url);
        if (!self::urlAmmesso($url) || mb_strlen($url) > 300 || (int) $this->db->valore('SELECT COUNT(*) FROM didacticus_pagine') >= self::MAX_PAGINE) {
            return 0;
        }
        if ((int) $this->db->valore('SELECT COUNT(*) FROM didacticus_pagine WHERE url = ?', [$url]) > 0) {
            return 0;
        }

        return $this->db->inserisci('INSERT INTO didacticus_pagine (url, creata_il) VALUES (?, ?)', [$url, $quando]);
    }

    public function attiva(int $id, bool $attiva): void
    {
        $this->db->esegui('UPDATE didacticus_pagine SET attiva = ? WHERE id = ?', [$attiva ? 1 : 0, $id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM didacticus_pagine WHERE id = ?', [$id]);
    }

    /** Il risultato dell'ultima lettura: titolo e testo se è andata bene (altrimenti si tengono quelli di prima e si annota l'errore). */
    public function salvaLettura(int $id, ?string $titolo, ?string $testo, string $esito, string $quando): void
    {
        if ($testo !== null) {
            $this->db->esegui('UPDATE didacticus_pagine SET titolo = ?, testo = ?, esito = ?, aggiornata_il = ? WHERE id = ?', [mb_substr((string) $titolo, 0, 200), $testo, mb_substr($esito, 0, 200), $quando, $id]);
        } else {
            $this->db->esegui('UPDATE didacticus_pagine SET esito = ? WHERE id = ?', [mb_substr($esito, 0, 200), $id]);
        }
    }

    /** Gli id delle pagine attive mai lette o lette prima di N giorni fa. @return list<int> */
    public function daAggiornare(int $giorni): array
    {
        return array_map('intval', array_column($this->db->righe(
            'SELECT id FROM didacticus_pagine WHERE attiva = 1 AND (aggiornata_il IS NULL OR aggiornata_il < ?) ORDER BY id',
            [date('Y-m-d H:i:s', time() - $giorni * 86400)]
        ), 'id'));
    }
}
