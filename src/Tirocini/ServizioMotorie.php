<?php

declare(strict_types=1);

namespace App\Tirocini;

use App\Core\Database;

/**
 * Tirocini di Scienze motorie: ogni azienda scelta tra le convenzionate ha un numero di posti contemporanei (di partenza 2) e i periodi di tirocinio
 * già assegnati. Un periodo occupa il posto dalla richiesta approvata fino alla data di fine (anche se non è ancora cominciato).
 * Gli studenti vedono solo «libera / ultimo posto / satura» e la data in cui si libera un posto, mai i nomi.
 */
final class ServizioMotorie
{
    public const POSTI_DI_PARTENZA = 2;
    public const CORSO = '[0827]';
    /** I tirocini interni all'Ateneo non occupano i posti delle aziende */
    private const INTERNA = 'università della calabria';

    public function __construct(private Database $db)
    {
    }

    /** Aziende convenzionate non ancora nell'elenco di Scienze motorie, per sceglierle @return list<array<string, mixed>> */
    public function convenzionateLibere(): array
    {
        return $this->db->righe('SELECT a.id, a.nome, a.comune FROM tirocini_aziende a LEFT JOIN tirocini_motorie_posti p ON p.azienda_id = a.id WHERE a.convenzione = 1 AND p.azienda_id IS NULL ORDER BY a.nome');
    }

    public function aggiungiAzienda(int $aziendaId, int $posti = self::POSTI_DI_PARTENZA): ?string
    {
        if (!$this->db->riga('SELECT id FROM tirocini_aziende WHERE id = ? AND convenzione = 1', [$aziendaId])) {
            return 'Scegli un\'azienda dall\'elenco delle convenzionate.';
        }
        if ($posti < 1 || $posti > 50) {
            return 'I posti devono essere da 1 a 50.';
        }
        $this->db->esegui('INSERT IGNORE INTO tirocini_motorie_posti (azienda_id, posti) VALUES (?, ?)', [$aziendaId, $posti]);

        return null;
    }

    public function impostaPosti(int $aziendaId, int $posti, string $note): ?string
    {
        if ($posti < 1 || $posti > 50) {
            return 'I posti devono essere da 1 a 50.';
        }
        $this->db->esegui('UPDATE tirocini_motorie_posti SET posti = ?, note = ? WHERE azienda_id = ?', [$posti, mb_substr(trim($note), 0, 300), $aziendaId]);

        return null;
    }

    /** Toglie l'azienda dall'elenco di Scienze motorie (con i suoi periodi) */
    public function togliAzienda(int $aziendaId): void
    {
        $this->db->transazione(function () use ($aziendaId): void {
            $this->db->esegui('DELETE FROM tirocini_motorie_periodi WHERE azienda_id = ?', [$aziendaId]);
            $this->db->esegui('DELETE FROM tirocini_motorie_posti WHERE azienda_id = ?', [$aziendaId]);
        });
    }

    public function aggiungiPeriodo(int $aziendaId, string $matricola, string $studente, string $inizio, string $fine, string $origine = 'manuale'): ?string
    {
        if (!$this->db->riga('SELECT azienda_id FROM tirocini_motorie_posti WHERE azienda_id = ?', [$aziendaId])) {
            return 'L\'azienda non è nell\'elenco di Scienze motorie.';
        }
        $i = self::data($inizio);
        $f = self::data($fine);
        if ($i === null || $f === null) {
            return 'Scrivi le date di inizio e fine.';
        }
        if ($f < $i) {
            return 'La fine non può venire prima dell\'inizio.';
        }
        $this->db->esegui(
            'INSERT INTO tirocini_motorie_periodi (azienda_id, matricola, studente, inizio, fine, origine) VALUES (?, ?, ?, ?, ?, ?)',
            [$aziendaId, mb_substr(trim($matricola), 0, 20), mb_substr(trim($studente), 0, 150), $i, $f, $origine]
        );

        return null;
    }

    public function annullaPeriodo(int $id): void
    {
        $this->db->esegui('UPDATE tirocini_motorie_periodi SET annullato = 1 WHERE id = ?', [$id]);
    }

    /** Il tirocinio finisce oggi (o alla data indicata): il posto si libera dal giorno dopo */
    public function concludiPeriodo(int $id, ?string $fine = null): void
    {
        $this->db->esegui('UPDATE tirocini_motorie_periodi SET fine = ? WHERE id = ? AND annullato = 0', [self::data((string) $fine) ?? date('Y-m-d'), $id]);
    }

    /**
     * Le aziende di Scienze motorie con posti, occupati e, se sature, il giorno in cui si libera il primo posto.
     *
     * @return list<array{id: int, nome: string, comune: string, provincia: string, settore: string, posti: int, occupati: int, liberi: int, stato: string, libera_dal: ?string, note: string, periodi: list<array<string, mixed>>}>
     */
    public function stato(?string $oggi = null): array
    {
        $oggi ??= date('Y-m-d');
        $periodi = [];
        foreach ($this->db->righe('SELECT * FROM tirocini_motorie_periodi WHERE annullato = 0 AND fine >= ? ORDER BY fine, inizio', [$oggi]) as $p) {
            $periodi[(int) $p['azienda_id']][] = $p;
        }
        $out = [];
        foreach ($this->db->righe('SELECT a.id, a.nome, a.comune, a.provincia, a.settore, p.posti, p.note FROM tirocini_motorie_posti p JOIN tirocini_aziende a ON a.id = p.azienda_id ORDER BY a.nome') as $a) {
            $per = $periodi[(int) $a['id']] ?? [];
            $posti = (int) $a['posti'];
            $occ = count($per);
            $liberaDal = null;
            if ($occ >= $posti) {
                // Ordinati per fine: il posto si libera quando finisce il tirocinio che porta gli occupati sotto il limite
                $ultimo = $per[$occ - $posti];
                $liberaDal = date('Y-m-d', strtotime((string) $ultimo['fine'] . ' +1 day'));
            }
            $out[] = ['id' => (int) $a['id'], 'nome' => (string) $a['nome'], 'comune' => (string) $a['comune'], 'provincia' => (string) $a['provincia'], 'settore' => (string) $a['settore'],
                'posti' => $posti, 'occupati' => $occ, 'liberi' => max(0, $posti - $occ),
                'stato' => $occ >= $posti ? 'satura' : ($posti - $occ === 1 ? 'ultimo' : 'libera'),
                'libera_dal' => $liberaDal, 'note' => (string) $a['note'], 'periodi' => $per];
        }

        return $out;
    }

    /**
     * Legge il report del TSP: tiene solo Scienze motorie e salta i tirocini interni; cerca ogni azienda tra le convenzionate (per nome).
     *
     * @param list<array<string, string>> $righe
     * @return array{righe: list<array<string, mixed>>, saltate: int, interni: int}
     */
    public function leggiReport(array $righe): array
    {
        $conv = $this->db->righe('SELECT id, nome FROM tirocini_aziende WHERE convenzione = 1');
        $chiavi = [];
        foreach ($conv as $c) {
            $chiavi[self::chiave((string) $c['nome'])] = (int) $c['id'];
        }
        $out = [];
        $saltate = 0;
        $interni = 0;
        foreach ($righe as $r) {
            if (!str_contains((string) ($r['corso'] ?? ''), self::CORSO)) {
                $saltate++;
                continue;
            }
            $nomeAz = trim((string) ($r['azienda'] ?? ''));
            if ($nomeAz === '' || mb_strtolower($nomeAz) === self::INTERNA) {
                $interni++;
                continue;
            }
            $inizio = self::data((string) ($r['data inizio tirocinio'] ?? ''));
            $fine = self::data((string) ($r['data fine tirocinio'] ?? ''));
            $out[] = ['azienda_nome' => $nomeAz, 'azienda_id' => $chiavi[self::chiave($nomeAz)] ?? 0, 'matricola' => trim((string) ($r['matricola'] ?? '')),
                'studente' => trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? '')), 'inizio' => $inizio, 'fine' => $fine];
        }

        return ['righe' => $out, 'saltate' => $saltate, 'interni' => $interni];
    }

    /**
     * Salva le righe confermate (azienda scelta e date valide): l'azienda entra nell'elenco con i posti di partenza se non c'era; i doppioni si saltano.
     *
     * @param list<array<string, mixed>> $righe con «azienda_id» già scelto (0 = salta)
     * @return array{nuovi: int, doppioni: int, saltati: int}
     */
    public function applicaReport(array $righe): array
    {
        $r = ['nuovi' => 0, 'doppioni' => 0, 'saltati' => 0];
        $this->db->transazione(function () use ($righe, &$r): void {
            foreach ($righe as $x) {
                $az = (int) ($x['azienda_id'] ?? 0);
                if ($az <= 0 || empty($x['inizio']) || empty($x['fine']) || $this->aggiungiAzienda($az) !== null) {
                    $r['saltati']++;
                    continue;
                }
                if ((int) $this->db->valore('SELECT COUNT(*) FROM tirocini_motorie_periodi WHERE azienda_id = ? AND matricola = ? AND inizio = ? AND annullato = 0', [$az, $x['matricola'], $x['inizio']]) > 0) {
                    $r['doppioni']++;
                    continue;
                }
                if ($this->aggiungiPeriodo($az, (string) $x['matricola'], (string) $x['studente'], (string) $x['inizio'], (string) $x['fine'], 'report') === null) {
                    $r['nuovi']++;
                } else {
                    $r['saltati']++;
                }
            }
        });

        return $r;
    }

    /** «gg/mm/aaaa» o «aaaa-mm-gg» → «aaaa-mm-gg»; null se non è una data */
    public static function data(string $testo): ?string
    {
        $t = trim($testo);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $t, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $t, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        return null;
    }

    /** Nome senza maiuscole, punteggiatura, forma societaria e spazi, per ritrovare la stessa azienda scritta in modo un po' diverso */
    public static function chiave(string $nome): string
    {
        $n = mb_strtolower($nome);
        $n = preg_replace('/\b(s\.?r\.?l\.?s?|s\.?p\.?a\.?|s\.?n\.?c\.?|s\.?a\.?s\.?|a\.?s\.?d\.?|soc\.?|societ[àa]|sportiva|dilettantistica|e|di|il|la)\b/u', ' ', $n) ?? $n;

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $n) ?? '';
    }
}
