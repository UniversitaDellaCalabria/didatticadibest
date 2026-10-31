<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Anagrafi\InsegnamentoRepository;
use App\Anagrafi\ServizioPersone;

/**
 * Collega le righe della graduatoria (nome del corso di laurea e dell'insegnamento, scritti a mano nel decreto) agli insegnamenti dell'anagrafe
 * di Ateneo: propone i candidati migliori con codice dell'insegnamento, corso di laurea e docente titolare. Il referente conferma o cambia la scelta.
 */
final class AbbinamentoInsegnamenti
{
    /** Punteggio minimo perché un insegnamento sia proposto. */
    private const SOGLIA = 55;

    /** @var array<int, list<array<string, mixed>>> insegnamenti letti, per anno */
    private array $cache = [];

    public function __construct(private InsegnamentoRepository $insegnamenti, private ServizioPersone $persone)
    {
    }

    /** Anno accademico di partenza da «2025/2026» (0 se non si capisce). */
    public static function annoDa(string $annoAccademico): int
    {
        return preg_match('/(\d{4})/', $annoAccademico, $m) ? (int) $m[1] : 0;
    }

    /**
     * Insegnamenti dell'anagrafe che assomigliano alla riga, dal migliore. Se l'anno non ha dati si usa il più recente.
     *
     * @return list<array{id: int, codice: string, nome: string, cds_nome: string, cds_cod: string, docente: string, docente_id: string, anno_corso: int|null, punti: int}>
     */
    public function candidati(int $anno, string $corso, string $insegnamento, int $max = 5): array
    {
        $target = self::norma($insegnamento);
        if ($target === '') {
            return [];
        }
        $corsoN = self::norma($corso);
        $out = [];
        foreach ($this->insegnamentiDell($anno) as $i) {
            $n = self::norma((string) $i['nome']);
            if ($n === '') {
                continue;
            }
            $punti = $n === $target ? 100 : (str_contains($n, $target) || str_contains($target, $n) ? 75 : (int) round(60 * self::somiglianza($target, $n)));
            if ($corsoN !== '') {
                $c = self::norma((string) $i['cds_nome']);
                $punti += $c === $corsoN ? 40 : (str_contains($c, $corsoN) || str_contains($corsoN, $c) ? 30 : (int) round(20 * self::somiglianza($corsoN, $c)));
            }
            if ($punti >= self::SOGLIA) {
                $out[] = ['id' => (int) $i['id'], 'codice' => (string) $i['codice'], 'nome' => (string) $i['nome'] . ((string) ($i['partizione'] ?? '') !== '' ? ' (' . $i['partizione'] . ')' : ''),
                    'cds_nome' => (string) $i['cds_nome'], 'cds_cod' => (string) $i['cds_cod'], 'docente' => (string) $i['docente'], 'docente_id' => (string) $i['docente_id'],
                    'anno_corso' => $i['anno_corso'] !== null ? (int) $i['anno_corso'] : null, 'punti' => $punti];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$b['punti'], $a['nome']] <=> [$a['punti'], $b['nome']]);

        return array_slice($out, 0, $max);
    }

    /**
     * L'insegnamento dell'anagrafe per id (con docente e corso).
     *
     * @return array<string, mixed>|null
     */
    public function insegnamento(int $id): ?array
    {
        return $id > 0 ? $this->insegnamenti->perId($id) : null;
    }

    /**
     * Il docente titolare dell'insegnamento come persona dell'anagrafe: sceglie chi ha lo stesso nome (in qualunque ordine); se il nome non basta ma c'è
     * un solo risultato in servizio, quello. Null se non si trova con sicurezza.
     *
     * @return array{persona_id: string, nome: string, cognome: string, email: string}|null
     */
    public function docente(string $nomeDocente): ?array
    {
        $nomeDocente = trim($nomeDocente);
        if (mb_strlen($nomeDocente) < 3) {
            return null;
        }
        $norma = self::norma($nomeDocente);
        $trovate = $this->persone->cerca($nomeDocente, '', '', '', 8);
        $tokens = static function (string $s): array {
            $t = array_filter(explode(' ', $s));
            sort($t);

            return $t;
        };
        $esatte = array_values(array_filter($trovate, static fn (array $p): bool => $tokens(self::norma((string) $p['nome'])) === $tokens($norma)));
        $scelta = count($esatte) === 1 ? $esatte[0] : (count($esatte) === 0 && count($trovate) === 1 ? $trovate[0] : null);
        if ($scelta === null) {
            return null;
        }
        $nome = trim((string) $scelta['nome']);
        $cognome = trim((string) $scelta['cognome']);
        // «nome» dell'anagrafe è il nome per esteso: si toglie il cognome per avere solo il nome proprio
        $proprio = trim(preg_replace('/' . preg_quote($cognome, '/') . '/iu', '', $nome, 1) ?? $nome);

        return ['persona_id' => (string) $scelta['id'], 'nome' => $proprio !== '' ? $proprio : $nome, 'cognome' => $cognome, 'email' => strtolower((string) $scelta['email'])];
    }

    /** @return list<array<string, mixed>> */
    private function insegnamentiDell(int $anno): array
    {
        if (!isset($this->cache[$anno])) {
            $dip = $this->insegnamenti->dipartimentoProprio();
            $el = $anno > 0 ? $this->insegnamenti->dellAnno($anno, $dip) : [];
            if (!$el && ($recente = $this->insegnamenti->annoPiuRecente($dip)) > 0) {
                $el = $this->insegnamenti->dellAnno($recente, $dip);
            }
            $this->cache[$anno] = $el;
        }

        return $this->cache[$anno];
    }

    /** Minuscolo, senza accenti né punteggiatura, spazi singoli. */
    public static function norma(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i', 'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u', '’' => ' ', "'" => ' ']);
        $s = (string) preg_replace('/[^a-z0-9 ]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** Quota di parole in comune (0-1), ignorando articoli e preposizioni. */
    private static function somiglianza(string $a, string $b): float
    {
        $stop = ['di', 'del', 'della', 'delle', 'dei', 'e', 'ed', 'per', 'le', 'la', 'il', 'lo', 'gli', 'i', 'in', 'a', 'al', 'alla', 'cdl', 'corso', 'laurea'];
        $ta = array_diff(array_unique(explode(' ', $a)), $stop);
        $tb = array_diff(array_unique(explode(' ', $b)), $stop);
        if (!$ta || !$tb) {
            return 0.0;
        }

        return count(array_intersect($ta, $tb)) / max(count($ta), count($tb));
    }
}
