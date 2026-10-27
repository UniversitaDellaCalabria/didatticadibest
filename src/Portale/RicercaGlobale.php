<?php

declare(strict_types=1);

namespace App\Portale;

use App\Assistenza\Didacticus\VociRepository;
use App\Core\Database;
use App\Eventi\EventoRepository;

/**
 * La ricerca unica del sito (ricerca.php, dalla casella in alto): una domanda sola e i risultati raggruppati per genere — eventi e progetti, notizie e pagine
 * informative, guide «Come si fa», docenti, risposte di Didacticus. Ogni parola scritta deve comparire nel testo. Solo ciò che è pubblico: le risposte di
 * Didacticus riservate a chi accede si vedono solo dopo l'accesso.
 */
final class RicercaGlobale
{
    /** Quanti risultati al massimo per gruppo. */
    private const PER_GRUPPO = 8;

    public function __construct(
        private Database $db,
        private EventoRepository $eventi,
        private ComunicazioniRepository $comunicazioni,
        private VociRepository $didacticus
    ) {
    }

    /**
     * @return list<array{chiave: string, titolo: string, icona: string, colore: string, voci: list<array{titolo: string, testo: string, link: string, meta: string, risposta: string}>}>
     *         solo i gruppi con almeno un risultato
     */
    public function cerca(string $q, bool $conLogin): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        if (mb_strlen($q) < 2) {
            return [];
        }
        $gruppi = [
            ['eventi', 'Eventi e progetti', 'fa-calendar-check', '#0d6efd', $this->eventi($q)],
            ['notizie', 'Notizie e pagine', 'fa-newspaper', '#b91c1c', $this->notizie($q)],
            ['guide', 'Come si fa', 'fa-circle-question', '#475569', $this->guide($q)],
            ['docenti', 'Docenti', 'fa-chalkboard-user', '#7c3aed', $this->docenti($q)],
            ['didacticus', 'Risposte di Didacticus', 'fa-comments', '#0891b2', $this->didacticus($q, $conLogin)],
        ];
        $out = [];
        foreach ($gruppi as [$chiave, $titolo, $icona, $colore, $voci]) {
            if ($voci) {
                $out[] = ['chiave' => $chiave, 'titolo' => $titolo, 'icona' => $icona, 'colore' => $colore, 'voci' => array_slice($voci, 0, self::PER_GRUPPO)];
            }
        }

        return $out;
    }

    /** @param list<array{voci: list<mixed>}> $gruppi */
    public static function totale(array $gruppi): int
    {
        return array_sum(array_map(static fn (array $g): int => count($g['voci']), $gruppi));
    }

    /** Ogni parola della domanda compare nel testo? */
    public static function contiene(string $testo, string $q): bool
    {
        $t = mb_strtolower(html_entity_decode(strip_tags($testo), ENT_QUOTES, 'UTF-8'));
        foreach (array_filter(explode(' ', mb_strtolower($q))) as $parola) {
            if (mb_strpos($t, $parola) === false) {
                return false;
            }
        }

        return true;
    }

    /** Un pezzo di testo attorno alla prima parola trovata, senza HTML. */
    public static function estratto(string $testo, string $q, int $lunghezza = 150): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($testo), ENT_QUOTES, 'UTF-8')) ?? '');
        $pos = 0;
        foreach (array_filter(explode(' ', mb_strtolower($q))) as $parola) {
            $p = mb_stripos($t, $parola);
            if ($p !== false) {
                $pos = $p;
                break;
            }
        }
        $inizio = max(0, $pos - 40);

        return ($inizio > 0 ? '…' : '') . mb_substr($t, $inizio, $lunghezza) . (mb_strlen($t) > $inizio + $lunghezza ? '…' : '');
    }

    /** @return list<array{titolo: string, testo: string, link: string, meta: string, risposta: string}> */
    private function eventi(string $q): array
    {
        $out = [];
        foreach ($this->eventi->cerca($q) as $e) {
            $archiviato = (int) ($e['archiviato'] ?? 0) === 1;
            $link = $archiviato ? $e['slug_area'] . '_archivio.php' : $e['slug_area'] . '.php?' . (($e['tipo'] ?? '') === 'progetto' ? 'progetto=' : 'evento=') . (int) $e['id'];
            $meta = (string) $e['nome_area'] . ($archiviato ? ' · archiviato' : (!empty($e['prossima_data']) ? ' · dal ' . date('d/m/Y', (int) strtotime((string) $e['prossima_data'])) : ''));
            $out[] = ['titolo' => (string) $e['titolo'], 'testo' => self::estratto((string) ($e['descrizione'] ?? ''), $q), 'link' => $link, 'meta' => $meta . (!empty($e['luogo']) ? ' · ' . $e['luogo'] : ''), 'risposta' => ''];
        }

        return $out;
    }

    /** @return list<array{titolo: string, testo: string, link: string, meta: string, risposta: string}> */
    private function notizie(string $q): array
    {
        $out = [];
        foreach ($this->comunicazioni->cerca($q) as $c) {
            $pagina = (string) $c['tipo'] === ComunicazioniRepository::PAGINA;
            $out[] = ['titolo' => (string) $c['titolo'], 'testo' => self::estratto((string) ($c['sommario'] ?: $c['testo']), $q), 'link' => 'comunicazione.php?id=' . (int) $c['id'],
                'meta' => $pagina ? 'Pagina informativa' : 'Notizia' . (!empty($c['data_pubblicazione']) ? ' del ' . date('d/m/Y', (int) strtotime((string) $c['data_pubblicazione'])) : ''), 'risposta' => ''];
        }

        return $out;
    }

    /** @return list<array{titolo: string, testo: string, link: string, meta: string, risposta: string}> */
    private function guide(string $q): array
    {
        $out = [];
        foreach (GuidePerRuolo::tutte() as $chiave => $g) {
            foreach ($g['sezioni'] as $i => $s) {
                if (self::contiene($s['titolo'] . ' ' . implode(' ', $s['passi']) . ' ' . ($s['nota'] ?? ''), $q)) {
                    $out[] = ['titolo' => (string) $s['titolo'], 'testo' => self::estratto(implode(' ', $s['passi']), $q), 'link' => 'guida.php?r=' . $chiave . '#s' . ($i + 1), 'meta' => 'Guida: ' . $g['titolo'], 'risposta' => ''];
                }
            }
        }

        return $out;
    }

    /** @return list<array{titolo: string, testo: string, link: string, meta: string, risposta: string}> */
    private function docenti(string $q): array
    {
        $where = ["docente = 1", "attivo = 1", "gruppo = 'docenti'"];
        $par = [];
        foreach (array_filter(explode(' ', $q)) as $w) {
            $lk = '%' . addcslashes($w, '%_\\') . '%';
            $where[] = '(cognome LIKE ? OR nome LIKE ? OR ssd LIKE ? OR ssd_cod LIKE ?)';
            array_push($par, $lk, $lk, $lk, $lk);
        }
        $out = [];
        foreach ($this->db->righe('SELECT id, cognome, nome, ruolo, ssd FROM personale_ateneo WHERE ' . implode(' AND ', $where) . ' ORDER BY cognome, nome LIMIT ' . self::PER_GRUPPO, $par) as $d) {
            $out[] = ['titolo' => trim($d['cognome'] . ' ' . $d['nome']), 'testo' => trim($d['ruolo'] . ($d['ssd'] !== '' ? ' · ' . $d['ssd'] : ''), ' ·'),
                'link' => 'docenti.php?q=' . rawurlencode(trim($d['cognome'] . ' ' . $d['nome'])), 'meta' => 'Docenti e ricevimento', 'risposta' => ''];
        }

        return $out;
    }

    /** @return list<array{titolo: string, testo: string, link: string, meta: string, risposta: string}> */
    private function didacticus(string $q, bool $conLogin): array
    {
        $out = [];
        foreach ($this->didacticus->attive($conLogin) as $v) {
            if (self::contiene($v['titolo'] . ' ' . $v['domande'] . ' ' . $v['parole_chiave'] . ' ' . $v['risposta'], $q)) {
                $out[] = ['titolo' => (string) $v['titolo'], 'testo' => '', 'link' => 'assistenza.php', 'meta' => 'Didacticus', 'risposta' => (string) $v['risposta']];
            }
        }

        return $out;
    }
}
