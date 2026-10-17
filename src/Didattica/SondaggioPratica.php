<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;

/**
 * Sondaggio facoltativo a fine pratica: a pratica conclusa (accolta, respinta o chiusa) lo studente può dare due voti da 1 a 5 (il servizio nel complesso;
 * chiarezza e tempi) e un commento. Il link personale (token) è nell'email di conclusione e nella pagina della pratica. Le risposte sono per modulo e
 * non mostrano chi le ha date.
 */
final class SondaggioPratica
{
    public const STATI_CONCLUSI = ['accolta', 'respinta', 'chiusa'];

    public function __construct(private Database $db, private Sito $sito, private Orologio $orologio)
    {
    }

    /** @param array<string, mixed> $p riga di pratiche */
    public static function concluse(array $p): bool
    {
        return in_array($p['stato'] ?? '', self::STATI_CONCLUSI, true);
    }

    /** Il token della pratica (creato la prima volta). */
    public function token(int $praticaId): string
    {
        $t = (string) $this->db->valore('SELECT token FROM pratiche_sondaggi WHERE pratica_id = ?', [$praticaId]);
        if ($t === '') {
            $t = bin2hex(random_bytes(16));
            $this->db->esegui('INSERT IGNORE INTO pratiche_sondaggi (pratica_id, token) VALUES (?, ?)', [$praticaId, $t]);
            $t = (string) $this->db->valore('SELECT token FROM pratiche_sondaggi WHERE pratica_id = ?', [$praticaId]);
        }

        return $t;
    }

    /** Il link da dare allo studente, oppure '' se la pratica non è conclusa o ha già risposto. @param array<string, mixed> $p */
    public function link(array $p): string
    {
        if (!self::concluse($p) || $this->compilato((int) $p['id'])) {
            return '';
        }

        return $this->sito->urlBase() . '/sondaggio_pratica.php?t=' . $this->token((int) $p['id']);
    }

    public function compilato(int $praticaId): bool
    {
        return (bool) $this->db->valore('SELECT compilato_il IS NOT NULL FROM pratiche_sondaggi WHERE pratica_id = ?', [$praticaId]);
    }

    /**
     * La pratica a cui si riferisce il link.
     *
     * @return array{pratica_id: int, modulo: string, stato: string, compilato: bool}|null null se il link non è valido o la pratica non è conclusa
     */
    public function perToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $r = $this->db->riga('SELECT s.pratica_id, s.compilato_il, p.stato, m.titolo FROM pratiche_sondaggi s JOIN pratiche p ON p.id = s.pratica_id
            LEFT JOIN didattica_moduli m ON m.id = p.modulo_id WHERE s.token = ?', [$token]);
        if (!$r || !self::concluse($r)) {
            return null;
        }

        return ['pratica_id' => (int) $r['pratica_id'], 'modulo' => (string) $r['titolo'], 'stato' => (string) $r['stato'], 'compilato' => $r['compilato_il'] !== null];
    }

    /** Registra le risposte. Ritorna il messaggio d'errore, o null se è andata bene. */
    public function rispondi(string $token, int $servizio, int $tempi, string $commento): ?string
    {
        $s = $this->perToken($token);
        if (!$s) {
            return 'Il link non è valido.';
        }
        if ($s['compilato']) {
            return 'Hai già risposto: grazie!';
        }
        if ($servizio < 1 || $servizio > 5 || $tempi < 1 || $tempi > 5) {
            return 'Rispondi a tutte e due le domande (da 1 a 5).';
        }
        $this->db->esegui('UPDATE pratiche_sondaggi SET voto_servizio = ?, voto_tempi = ?, commento = ?, compilato_il = ? WHERE token = ? AND compilato_il IS NULL',
            [$servizio, $tempi, mb_substr(trim($commento), 0, 1000), $this->orologio->adesso()->format('Y-m-d H:i:s'), $token]);

        return null;
    }

    /**
     * Riepilogo per le statistiche: voti medi (complessivi e per modulo), quante pratiche concluse hanno risposto e gli ultimi commenti.
     *
     * @return array{conclusi: int, risposte: int, media_servizio: ?float, media_tempi: ?float, per_modulo: array<string, array{n: int, servizio: float, tempi: float}>, commenti: list<array{modulo: string, data: string, commento: string, servizio: int, tempi: int}>}
     */
    public function riepilogo(string $dal, string $al): array
    {
        $conclusi = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche WHERE stato IN ('accolta', 'respinta', 'chiusa') AND DATE(creata_il) BETWEEN ? AND ?", [$dal, $al]);
        $base = 'FROM pratiche_sondaggi s JOIN pratiche p ON p.id = s.pratica_id LEFT JOIN didattica_moduli m ON m.id = p.modulo_id WHERE s.compilato_il IS NOT NULL AND DATE(p.creata_il) BETWEEN ? AND ?';
        $tot = $this->db->riga("SELECT COUNT(*) AS n, AVG(s.voto_servizio) AS a, AVG(s.voto_tempi) AS b $base", [$dal, $al]);
        $per = [];
        foreach ($this->db->righe("SELECT COALESCE(m.titolo, 'Altro') AS modulo, COUNT(*) AS n, AVG(s.voto_servizio) AS a, AVG(s.voto_tempi) AS b $base GROUP BY m.titolo ORDER BY n DESC", [$dal, $al]) as $r) {
            $per[(string) $r['modulo']] = ['n' => (int) $r['n'], 'servizio' => round((float) $r['a'], 1), 'tempi' => round((float) $r['b'], 1)];
        }
        $com = [];
        foreach ($this->db->righe("SELECT COALESCE(m.titolo, 'Altro') AS modulo, s.compilato_il, s.commento, s.voto_servizio, s.voto_tempi $base AND s.commento <> '' ORDER BY s.compilato_il DESC LIMIT 15", [$dal, $al]) as $r) {
            $com[] = ['modulo' => (string) $r['modulo'], 'data' => date('d/m/Y', (int) strtotime((string) $r['compilato_il'])), 'commento' => (string) $r['commento'], 'servizio' => (int) $r['voto_servizio'], 'tempi' => (int) $r['voto_tempi']];
        }
        $n = (int) ($tot['n'] ?? 0);

        return ['conclusi' => $conclusi, 'risposte' => $n, 'media_servizio' => $n ? round((float) $tot['a'], 1) : null, 'media_tempi' => $n ? round((float) $tot['b'], 1) : null, 'per_modulo' => $per, 'commenti' => $com];
    }
}
