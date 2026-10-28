<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/**
 * La lettura automatica di unical.it e dei suoi sottodomini: parte dalle pagine indicate nel pannello, segue i collegamenti interni (senza uscire dal dominio),
 * rispetta robots.txt, legge una pagina al secondo, ne conserva i brani nell'indice di ricerca e rilegge solo quello che è cambiato. Lavora a piccole dosi dentro un
 * tempo massimo (il cron di notte): ogni passata riprende da dove era arrivata la precedente.
 *
 * Non si seguono: indirizzi con parametri (?…), file che non sono pagine (PDF, immagini, documenti…), pagine escluse dalle impostazioni o da robots.txt.
 */
final class CrawlerSito
{
    /** Dopo quanti giorni una pagina già letta si rilegge. */
    public const GIORNI_RILETTURA = 7;
    /** Pausa fra due richieste, in microsecondi. */
    public const PAUSA = 1000000;
    private const ESTENSIONI_NO = 'pdf|doc|docx|xls|xlsx|ppt|pptx|odt|ods|zip|rar|7z|gz|tar|jpg|jpeg|png|gif|svg|webp|bmp|ico|mp3|mp4|avi|mov|wmv|css|js|json|xml|rss|txt|csv|exe|dmg|apk';
    private const MAX_BRANI_PAGINA = 40;

    /** @var array<string, list<string>> regole di robots.txt già lette in questa passata, per sito */
    private array $robots = [];
    /** @var callable(int): void */
    private $pausa;

    public function __construct(private SitoRepository $sito, private LettorePagine $lettore)
    {
        $this->pausa = static function (int $micro): void {
            usleep($micro);
        };
    }

    /** Le prove sostituiscono la pausa fra le richieste. @param callable(int): void $pausa */
    public function conPausa(callable $pausa): self
    {
        $this->pausa = $pausa;

        return $this;
    }

    /**
     * Una passata: al massimo $secondi di lavoro (anche meno se non c'è altro da fare) e al massimo $richieste pagine lette.
     *
     * @return array{lette: int, cambiate: int, errori: int, scoperte: int, finita: bool, nota: string}
     */
    public function passata(int $secondi, int $richieste = 100000): array
    {
        $imp = $this->sito->impostazioni();
        $out = ['lette' => 0, 'cambiate' => 0, 'errori' => 0, 'scoperte' => 0, 'finita' => false, 'nota' => ''];
        if (!$imp['attivo']) {
            $out['nota'] = 'La lettura automatica è spenta.';

            return $out;
        }
        @set_time_limit($secondi + 120);
        $inizio = time();
        $quando = static fn (): string => date('Y-m-d H:i:s');
        foreach ($imp['partenza'] as $u) {
            $n = self::normalizza($u);
            if ($n !== null && !self::escluso($n, $imp['escludi'])) {
                $this->sito->accoda($n, 0, $quando(), $imp['max_pagine']);
            }
        }
        $this->robots = [];
        while ($out['lette'] + $out['errori'] < $richieste && time() - $inizio < $secondi) {
            $p = $this->sito->prossima(self::GIORNI_RILETTURA);
            if ($p === null) {
                $out['finita'] = true;
                break;
            }
            $t0 = microtime(true);
            $url = (string) $p['url'];
            $id = (int) $p['id'];
            if (self::escluso($url, $imp['escludi'])) {
                $this->sito->salvaLettura($id, SitoRepository::STATO_ESCLUSA, '', 'Esclusa dalle impostazioni', null, [], $quando());
                continue;
            }
            if (!$this->robotsPermette($url)) {
                $this->sito->salvaLettura($id, SitoRepository::STATO_ESCLUSA, '', 'Non ammessa da robots.txt', null, [], $quando());
                continue;
            }
            try {
                $r = $this->lettore->leggiConCollegamenti($url);
                $testo = $r['testo'];
                if (mb_strlen($testo) < 80) {
                    $this->sito->salvaLettura($id, SitoRepository::STATO_VUOTA, $r['titolo'], 'Senza testo utile (forse si compone con JavaScript)', null, [], $quando());
                } else {
                    $brani = array_slice(RicercaPagine::brani($testo), 0, self::MAX_BRANI_PAGINA);
                    $cambiata = $this->sito->salvaLettura($id, SitoRepository::STATO_LETTA, $r['titolo'], 'Letta: ' . mb_strlen($testo) . ' caratteri', md5($testo), $brani, $quando());
                    $out['cambiate'] += $cambiata ? 1 : 0;
                }
                ++$out['lette'];
                if ((int) $p['profondita'] < $imp['profondita']) {
                    foreach ($r['link'] as $l) {
                        $n = self::normalizza($l);
                        if ($n !== null && !self::escluso($n, $imp['escludi']) && $this->sito->accoda($n, (int) $p['profondita'] + 1, $quando(), $imp['max_pagine'])) {
                            ++$out['scoperte'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->sito->salvaLettura($id, SitoRepository::STATO_ERRORE, '', $e->getMessage(), null, [], $quando());
                ++$out['errori'];
            }
            $resto = (int) round(self::PAUSA - (microtime(true) - $t0) * 1000000);
            if ($resto > 0) {
                ($this->pausa)($resto);
            }
        }
        $out['nota'] = $out['finita'] ? 'Niente altro da leggere per ora.' : 'Tempo finito: si riprende alla prossima passata.';
        $this->sito->registraPassata($quando(), $out['lette'] . ' pagine lette (' . $out['cambiate'] . ' cambiate), ' . $out['scoperte'] . ' scoperte, ' . $out['errori'] . ' con errore. ' . $out['nota']);

        return $out;
    }

    /**
     * L'indirizzo in forma canonica da seguire: https, dominio di Ateneo, senza ancora né parametri, senza porte né credenziali, senza file che non sono pagine.
     * Null se non va seguito.
     */
    public static function normalizza(string $url): ?string
    {
        $url = trim((string) preg_replace('/#.*$/', '', trim($url)));
        $p = parse_url($url);
        if (!is_array($p) || isset($p['query']) || str_contains($url, '?') || !PagineRepository::urlAmmesso($url)) {
            return null;
        }
        $percorso = (string) ($p['path'] ?? '/');
        if ($percorso === '') {
            $percorso = '/';
        }
        if (preg_match('/\.(' . self::ESTENSIONI_NO . ')$/i', $percorso) || preg_match('#//#', $percorso) || substr_count($percorso, '/') > 12) {
            return null;
        }

        return 'https://' . strtolower((string) $p['host']) . $percorso;
    }

    /** L'indirizzo contiene una delle parti escluse (senza badare alle maiuscole)? @param list<string> $escludi */
    public static function escluso(string $url, array $escludi): bool
    {
        $u = strtolower($url);
        foreach ($escludi as $e) {
            $e = strtolower(trim($e));
            if ($e !== '' && str_contains($u, $e)) {
                return true;
            }
        }

        return false;
    }

    /**
     * I percorsi vietati a tutti i robot (User-agent: *) in un file robots.txt. Gli «Allow» più specifici non si considerano: nel dubbio si salta la pagina.
     *
     * @return list<string>
     */
    public static function analizzaRobots(string $testo): array
    {
        $vietati = [];
        $valido = false;
        $gruppoPrecedenteAgente = false;
        foreach (preg_split('/\R/', $testo) ?: [] as $riga) {
            $riga = trim((string) preg_replace('/#.*$/', '', $riga));
            if ($riga === '' || !str_contains($riga, ':')) {
                continue;
            }
            [$campo, $valore] = array_map('trim', explode(':', $riga, 2));
            $campo = strtolower($campo);
            if ($campo === 'user-agent') {
                $v = strtolower($valore);
                // più «User-agent» di fila formano un gruppo solo
                $valido = ($gruppoPrecedenteAgente ? $valido : false) || $v === '*' || str_contains($v, 'didacticus');
                $gruppoPrecedenteAgente = true;
                continue;
            }
            $gruppoPrecedenteAgente = false;
            if ($valido && $campo === 'disallow' && $valore !== '') {
                $vietati[] = $valore;
            }
        }

        return $vietati;
    }

    /** Un percorso è vietato da una delle regole? (prefisso; «*» e «$» di Google supportati in modo semplice) @param list<string> $regole */
    public static function vietato(string $percorso, array $regole): bool
    {
        foreach ($regole as $r) {
            $fine = str_ends_with($r, '$');
            $modello = '#^' . str_replace('\*', '.*', preg_quote($fine ? substr($r, 0, -1) : $r, '#')) . ($fine ? '$' : '') . '#i';
            if (preg_match($modello, $percorso)) {
                return true;
            }
        }

        return false;
    }

    private function robotsPermette(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!isset($this->robots[$host])) {
            try {
                [$corpo] = $this->lettore->scarica('https://' . $host . '/robots.txt', false);
                $this->robots[$host] = self::analizzaRobots($corpo);
            } catch (\Throwable $e) {
                // robots.txt assente o non raggiungibile: nessun divieto
                $this->robots[$host] = [];
            }
        }
        $percorso = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        return !self::vietato($percorso, $this->robots[$host]);
    }
}
