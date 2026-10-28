<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Le pagine di unical.it e dei suoi sottodomini scoperte dal crawler (didacticus_sito), i loro brani con indice di ricerca (didacticus_sito_brani, FULLTEXT) e le
 * impostazioni della lettura automatica (didacticus_sito_impostazioni). Una pagina nasce «in coda», diventa «letta» (con i suoi brani), «vuota» (senza testo utile),
 * «errore» o «esclusa».
 */
final class SitoRepository
{
    public const STATO_CODA = 'coda';
    public const STATO_LETTA = 'letta';
    public const STATO_VUOTA = 'vuota';
    public const STATO_ERRORE = 'errore';
    public const STATO_ESCLUSA = 'esclusa';

    public const PARTENZA_PREDEFINITA = "https://www.unical.it/\nhttps://dibest.unical.it/";
    public const ESCLUDI_PREDEFINITO = "/albo\n/wp-admin\n/wp-login\n/login\n/search\n/ricerca\n/calendar\n/feed\n/tag/";
    public const MAX_PAGINE_PREDEFINITO = 5000;
    public const MAX_PAGINE_LIMITE = 20000;

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{attivo: bool, partenza: list<string>, escludi: list<string>, max_pagine: int, profondita: int, minuti: int, ultima_passata: ?string, ultimo_esito: string}
     */
    public function impostazioni(): array
    {
        $r = $this->db->riga('SELECT * FROM didacticus_sito_impostazioni WHERE id = 1');
        $righe = static fn (?string $t): array => array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) $t) ?: []), static fn (string $x): bool => $x !== ''));

        return [
            'attivo' => $r !== null && (int) $r['attivo'] === 1,
            'partenza' => $righe($r['partenza'] ?? self::PARTENZA_PREDEFINITA),
            'escludi' => $righe($r['escludi'] ?? self::ESCLUDI_PREDEFINITO),
            'max_pagine' => max(100, min(self::MAX_PAGINE_LIMITE, (int) ($r['max_pagine'] ?? self::MAX_PAGINE_PREDEFINITO))),
            'profondita' => max(1, min(8, (int) ($r['profondita'] ?? 4))),
            'minuti' => max(1, min(60, (int) ($r['minuti'] ?? 20))),
            'ultima_passata' => $r['ultima_passata'] ?? null,
            'ultimo_esito' => (string) ($r['ultimo_esito'] ?? ''),
        ];
    }

    public function salvaImpostazioni(bool $attivo, string $partenza, string $escludi, int $maxPagine, int $profondita, int $minuti): void
    {
        $this->db->esegui(
            'INSERT INTO didacticus_sito_impostazioni (id, attivo, partenza, escludi, max_pagine, profondita, minuti) VALUES (1, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE attivo = VALUES(attivo), partenza = VALUES(partenza), escludi = VALUES(escludi), max_pagine = VALUES(max_pagine), profondita = VALUES(profondita), minuti = VALUES(minuti)',
            [$attivo ? 1 : 0, mb_substr($partenza, 0, 3000), mb_substr($escludi, 0, 3000), max(100, min(self::MAX_PAGINE_LIMITE, $maxPagine)), max(1, min(8, $profondita)), max(1, min(60, $minuti))]
        );
    }

    public function registraPassata(string $quando, string $esito): void
    {
        $this->db->esegui(
            'INSERT INTO didacticus_sito_impostazioni (id, ultima_passata, ultimo_esito) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE ultima_passata = VALUES(ultima_passata), ultimo_esito = VALUES(ultimo_esito)',
            [$quando, mb_substr($esito, 0, 250)]
        );
    }

    public function totale(): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM didacticus_sito');
    }

    /** Mette una pagina in coda se non c'è già e se non si è al massimo. Ritorna true se l'ha aggiunta. */
    public function accoda(string $url, int $profondita, string $quando, int $massimo): bool
    {
        if (mb_strlen($url) > 300 || $this->totale() >= $massimo) {
            return false;
        }

        return $this->db->esegui('INSERT IGNORE INTO didacticus_sito (url, profondita, stato, scoperta_il) VALUES (?, ?, ?, ?)', [$url, $profondita, self::STATO_CODA, $quando]) > 0;
    }

    /** La prossima pagina da leggere: prima quelle in coda (le più vicine alla partenza), poi quelle lette da più di N giorni fa. @return array<string, mixed>|null */
    public function prossima(int $giorni): ?array
    {
        $r = $this->db->riga('SELECT * FROM didacticus_sito WHERE stato = ? ORDER BY profondita, id LIMIT 1', [self::STATO_CODA]);
        if ($r === null) {
            $r = $this->db->riga(
                'SELECT * FROM didacticus_sito WHERE stato IN (?, ?, ?) AND aggiornata_il < ? ORDER BY aggiornata_il LIMIT 1',
                [self::STATO_LETTA, self::STATO_VUOTA, self::STATO_ERRORE, date('Y-m-d H:i:s', time() - $giorni * 86400)]
            );
        }

        return $r === null ? null : Righe::testo([$r])[0];
    }

    /**
     * Salva la lettura di una pagina. Con i brani (stato «letta») sostituisce quelli di prima solo se il testo è cambiato.
     *
     * @param list<string> $brani
     * @return bool true se il testo è cambiato (o è la prima lettura)
     */
    public function salvaLettura(int $id, string $stato, string $titolo, string $esito, ?string $impronta, array $brani, string $quando): bool
    {
        $precedente = (string) ($this->db->valore('SELECT impronta FROM didacticus_sito WHERE id = ?', [$id]) ?? '');
        $cambiata = $stato === self::STATO_LETTA && $impronta !== null && $impronta !== $precedente;
        if ($cambiata) {
            $this->db->esegui('DELETE FROM didacticus_sito_brani WHERE pagina_id = ?', [$id]);
            foreach ($brani as $b) {
                $this->db->esegui('INSERT INTO didacticus_sito_brani (pagina_id, testo) VALUES (?, ?)', [$id, $b]);
            }
        } elseif ($stato === self::STATO_VUOTA) {
            $this->db->esegui('DELETE FROM didacticus_sito_brani WHERE pagina_id = ?', [$id]);
        }
        if ($stato === self::STATO_LETTA && $impronta !== null) {
            $this->db->esegui('UPDATE didacticus_sito SET stato = ?, titolo = ?, esito = ?, impronta = ?, aggiornata_il = ? WHERE id = ?', [$stato, mb_substr($titolo, 0, 200), mb_substr($esito, 0, 200), $impronta, $quando, $id]);
        } else {
            // una pagina rimasta senza testo perde anche l'impronta: se il testo ritorna uguale a prima, i brani vanno rifatti
            $this->db->esegui('UPDATE didacticus_sito SET stato = ?, esito = ?, aggiornata_il = ?' . ($stato === self::STATO_VUOTA ? ', impronta = NULL' : '') . ' WHERE id = ?', [$stato, mb_substr($esito, 0, 200), $quando, $id]);
        }

        return $cambiata;
    }

    /** Segna le pagine il cui indirizzo contiene una delle parti indicate come escluse e toglie i loro brani. Ritorna quante. */
    public function applicaEsclusioni(array $parti): int
    {
        $n = 0;
        foreach ($parti as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $p) . '%';
            $this->db->esegui('DELETE FROM didacticus_sito_brani WHERE pagina_id IN (SELECT id FROM didacticus_sito WHERE url LIKE ? AND stato <> ?)', [$like, self::STATO_ESCLUSA]);
            $n += max(0, $this->db->esegui('UPDATE didacticus_sito SET stato = ?, esito = ? WHERE url LIKE ? AND stato <> ?', [self::STATO_ESCLUSA, 'Esclusa dalle impostazioni', $like, self::STATO_ESCLUSA]));
        }

        return $n;
    }

    /** Svuota tutto (pagine, brani); le impostazioni restano. */
    public function svuota(): void
    {
        $this->db->esegui('DELETE FROM didacticus_sito_brani');
        $this->db->esegui('DELETE FROM didacticus_sito');
    }

    /** @return array{totale: int, coda: int, letta: int, vuota: int, errore: int, esclusa: int, brani: int} */
    public function conteggi(): array
    {
        $out = ['totale' => 0, 'coda' => 0, 'letta' => 0, 'vuota' => 0, 'errore' => 0, 'esclusa' => 0, 'brani' => (int) $this->db->valore('SELECT COUNT(*) FROM didacticus_sito_brani')];
        foreach ($this->db->righe('SELECT stato, COUNT(*) AS n FROM didacticus_sito GROUP BY stato') as $r) {
            if (isset($out[$r['stato']])) {
                $out[$r['stato']] = (int) $r['n'];
            }
            $out['totale'] += (int) $r['n'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> le ultime pagine con errore */
    public function ultimiErrori(int $quanti = 10): array
    {
        return Righe::testo($this->db->righe('SELECT url, esito, aggiornata_il FROM didacticus_sito WHERE stato = ? ORDER BY aggiornata_il DESC LIMIT ' . max(1, $quanti), [self::STATO_ERRORE]));
    }

    /**
     * I brani che contengono almeno una delle radici (ricerca per prefisso nell'indice FULLTEXT), dai più pertinenti, con titolo e indirizzo della pagina.
     *
     * @param list<string> $radici
     * @return list<array{titolo: string, url: string, testo: string}>
     */
    public function braniCandidati(array $radici, int $limite = 60): array
    {
        $termini = [];
        foreach ($radici as $r) {
            if (strlen($r) >= 3 && preg_match('/^[a-z0-9]+$/', $r)) {
                $termini[] = $r . '*';
            }
        }
        if (!$termini) {
            return [];
        }
        $q = implode(' ', $termini);

        return Righe::testo($this->db->righe(
            'SELECT p.titolo, p.url, b.testo FROM didacticus_sito_brani b JOIN didacticus_sito p ON p.id = b.pagina_id
             WHERE p.stato = ? AND MATCH(b.testo) AGAINST (? IN BOOLEAN MODE) ORDER BY MATCH(b.testo) AGAINST (? IN BOOLEAN MODE) DESC LIMIT ' . max(1, $limite),
            [self::STATO_LETTA, $q, $q]
        ));
    }
}
