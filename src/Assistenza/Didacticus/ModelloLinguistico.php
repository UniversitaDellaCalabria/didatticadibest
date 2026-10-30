<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/**
 * Il collegamento facoltativo di Didacticus a un modello linguistico: qualunque servizio con l'interfaccia «chat completions» di OpenAI. Due casi:
 *  - locale (Ollama, llama.cpp, vLLM su una macchina dell'Ateneo): niente esce dall'Ateneo, un calcolo alla volta;
 *  - esterno (per esempio Google Gemini, https://generativelanguage.googleapis.com/v1beta/openai): c'è LLM_CHIAVE, che si manda come «Bearer». Alla chiamata vanno solo la
 *    domanda (ripulita da email, codici fiscali e numeri lunghi) e i brani delle fonti; c'è un tetto di chiamate al giorno (LLM_MAX_GIORNO).
 * Spento di partenza: si accende con LLM_URL nel file .env.
 *
 * Il modello NON risponde dalla sua conoscenza: riceve la domanda e le voci della base di conoscenza più vicine, e deve rispondere solo con quelle; se non bastano
 * risponde NON_SO e Didacticus torna al suo comportamento a regole. Se il servizio non risponde in tempo, dà errore o il tetto del giorno è finito, lo stesso:
 * nessuna chat si rompe.
 *
 * Variabili del file .env: LLM_URL, LLM_MODELLO, LLM_TIMEOUT (secondi, 45), LLM_MODALITA («incerto» = solo quando nessuna voce è abbastanza sicura, «sempre» =
 * anche per riformulare le voci trovate), LLM_CHIAVE (solo servizio esterno), LLM_MAX_GIORNO (chiamate al giorno, 300; 0 = nessun tetto),
 * LLM_RAGIONAMENTO (reasoning_effort: none | minimal | low…; vuoto = non si manda; i token di ragionamento si pagano).
 */
final class ModelloLinguistico
{
    public const NON_SO = 'NON_SO';
    public const MODALITA_INCERTO = 'incerto';
    public const MODALITA_SEMPRE = 'sempre';
    public const MAX_GIORNO_PREDEFINITO = 300;
    /** Quanti giorni di conteggi si tengono. */
    private const GIORNI_STORICO = 62;

    private const ISTRUZIONI = "Sei Didacticus, l'assistente virtuale del Dipartimento di Biologia, Ecologia e Scienze della Terra (DiBEST) dell'Università della Calabria. "
        . "Rispondi in italiano, con gentilezza, in poche frasi (al massimo cinque righe). "
        . "Usa SOLO le informazioni contenute nelle FONTI che ti vengono date. "
        . "Non inventare mai date, scadenze, importi, nomi, indirizzi email, numeri di telefono o link che non siano nelle FONTI. "
        . "Se le FONTI non bastano per rispondere alla domanda, rispondi esattamente con la sola parola NON_SO. "
        . "Non chiedere password, codice fiscale o altri dati personali. Non scrivere i numeri delle fonti nella risposta.";

    private string $url;
    private string $modello;
    private int $timeout;
    private string $modalita;
    private string $chiave;
    private int $maxGiorno;
    private string $ragionamento;
    private string $fileUso;
    /** Perché è fallita l'ultima chiamata (vuoto se è andata bene). */
    private string $erroreChiamata = '';

    /** Senza argomenti legge le variabili del file .env. $fileUso = dove si contano le chiamate (di partenza cache/didacticus_llm_uso.json). */
    public function __construct(?string $url = null, ?string $modello = null, ?int $timeout = null, ?string $modalita = null, ?string $chiave = null, ?int $maxGiorno = null, ?string $ragionamento = null, ?string $fileUso = null)
    {
        $env = static fn (string $k): string => function_exists('env_valore') ? trim((string) env_valore($k)) : '';
        $this->url = rtrim($url ?? $env('LLM_URL'), '/');
        $this->modello = ($modello ?? $env('LLM_MODELLO')) ?: 'qwen2.5:3b';
        $this->timeout = max(5, min(180, $timeout ?? (((int) $env('LLM_TIMEOUT')) ?: 45)));
        $m = strtolower($modalita ?? $env('LLM_MODALITA'));
        $this->modalita = $m === self::MODALITA_SEMPRE ? self::MODALITA_SEMPRE : self::MODALITA_INCERTO;
        $this->chiave = trim($chiave ?? $env('LLM_CHIAVE'));
        $tetto = $maxGiorno ?? ($env('LLM_MAX_GIORNO') !== '' ? (int) $env('LLM_MAX_GIORNO') : self::MAX_GIORNO_PREDEFINITO);
        $this->maxGiorno = max(0, $tetto);
        $this->ragionamento = preg_replace('/[^a-z]/', '', strtolower($ragionamento ?? $env('LLM_RAGIONAMENTO'))) ?? '';
        $this->fileUso = $fileUso ?? dirname(__DIR__, 3) . '/cache/didacticus_llm_uso.json';
    }

    public function attivo(): bool
    {
        return $this->url !== '';
    }

    /** Servizio esterno (c'è una chiave): i dati escono dall'Ateneo, quindi tetto e pulizia della domanda. */
    public function esterno(): bool
    {
        return $this->chiave !== '';
    }

    public function modalita(): string
    {
        return $this->modalita;
    }

    public function nome(): string
    {
        return $this->modello;
    }

    public function maxGiorno(): int
    {
        return $this->maxGiorno;
    }

    /**
     * Il testo con cui costruire il messaggio per il modello: le fonti numerate e la domanda.
     *
     * @param list<array{titolo: string, testo: string}> $fonti
     */
    public static function messaggio(string $domanda, array $fonti): string
    {
        $t = "FONTI:\n";
        foreach (array_values($fonti) as $i => $f) {
            $t .= '[' . ($i + 1) . '] ' . $f['titolo'] . ': ' . trim(strip_tags($f['testo'])) . "\n";
        }

        return $t . "\nDOMANDA: " . trim($domanda) . "\n\nRISPOSTA:";
    }

    /**
     * La domanda senza i dati che non devono uscire dall'Ateneo: indirizzi email, codici fiscali, numeri di telefono, matricole e altri numeri lunghi, indirizzi web
     * con credenziali. Chi scrive li mette a volte nel testo senza pensarci; al modello non servono.
     */
    public static function anonimizza(string $domanda): string
    {
        $t = (string) preg_replace('/[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+/u', '[email]', $domanda);
        $t = (string) preg_replace('/\b[A-Za-z]{6}\d{2}[A-Za-z]\d{2}[A-Za-z]\d{3}[A-Za-z]\b/u', '[codice]', $t);
        $t = (string) preg_replace('/\bIT\d{2}[A-Za-z]\d{10}[A-Za-z0-9]{12}\b/u', '[codice]', $t);
        $t = (string) preg_replace('~https?://[^\s/@]+:[^\s/@]+@\S+~u', '[indirizzo]', $t);
        // numeri di almeno 7 cifre (telefoni, matricole lunghe, carte), ma non le date scritte con punti o trattini
        $t = (string) preg_replace_callback('/(?<!\d)\+?\d(?:[\s.\-]?\d){6,}(?!\d)/u', static fn (array $m): string => preg_match('/^(?:\d{1,2}[.\-]\d{1,2}[.\-]\d{2,4}|\d{4}-\d{1,2}-\d{1,2})$/', $m[0]) ? $m[0] : '[numero]', $t);

        return trim($t);
    }

    /**
     * La risposta del modello alla domanda, usando solo le fonti. Null se il servizio è spento o non risponde, se il tetto del giorno è finito, se il modello dice di
     * non sapere o se la risposta è vuota.
     *
     * @param list<array{titolo: string, testo: string}> $fonti
     */
    public function rispondi(string $domanda, array $fonti): ?string
    {
        if (!$this->attivo() || !$fonti) {
            return null;
        }
        if ($this->esterno() && $this->tettoFinito()) {
            error_log('[Didacticus] tetto di chiamate al modello raggiunto per oggi: risposta a regole');

            return null;
        }
        $corpo = (string) json_encode($this->corpo($this->esterno() ? self::anonimizza($domanda) : $domanda, $fonti), JSON_UNESCAPED_UNICODE);
        if ($this->esterno()) {
            return $this->chiama($corpo);
        }
        // Servizio locale: un calcolo alla volta (blocco su un file); se è occupato si risponde subito a regole invece di mettersi in coda
        $blocco = @fopen(sys_get_temp_dir() . '/didacticus_modello.lock', 'c');
        if ($blocco === false || !flock($blocco, LOCK_EX | LOCK_NB)) {
            if ($blocco !== false) {
                fclose($blocco);
            }
            error_log('[Didacticus] modello occupato: risposta a regole');

            return null;
        }
        try {
            return $this->chiama($corpo);
        } finally {
            flock($blocco, LOCK_UN);
            fclose($blocco);
        }
    }

    /**
     * Una chiamata di prova (pannello): dice se il servizio risponde, con quanto tempo e un eventuale errore. Conta nelle chiamate del giorno.
     *
     * @return array{ok: bool, messaggio: string, ms: int}
     */
    public function prova(): array
    {
        if (!$this->attivo()) {
            return ['ok' => false, 'messaggio' => 'Il modello non è collegato (LLM_URL vuoto).', 'ms' => 0];
        }
        $inizio = microtime(true);
        $corpo = (string) json_encode($this->corpo('Quando scade la domanda?', [['titolo' => 'Prova', 'testo' => 'La domanda scade il 15 del mese.']]), JSON_UNESCAPED_UNICODE);
        $this->chiama($corpo);
        $ms = (int) round((microtime(true) - $inizio) * 1000);
        if ($this->erroreChiamata !== '') {
            return ['ok' => false, 'messaggio' => $this->erroreChiamata, 'ms' => $ms];
        }

        return ['ok' => true, 'messaggio' => 'Il servizio ha risposto.', 'ms' => $ms];
    }

    /**
     * Le chiamate fatte oggi e questo mese e l'ultimo errore.
     *
     * @return array{oggi: int, mese: int, errori_oggi: int, non_so_oggi: int, ultimo_errore: string, ultimo_errore_ora: string}
     */
    public function uso(): array
    {
        $d = $this->leggiUso();
        $oggi = $d['giorni'][date('Y-m-d')] ?? ['ok' => 0, 'ko' => 0, 'nonso' => 0];
        $mese = 0;
        foreach ($d['giorni'] as $giorno => $c) {
            if (str_starts_with((string) $giorno, date('Y-m'))) {
                $mese += (int) $c['ok'] + (int) $c['ko'] + (int) $c['nonso'];
            }
        }

        return [
            'oggi' => (int) $oggi['ok'] + (int) $oggi['ko'] + (int) $oggi['nonso'], 'mese' => $mese, 'errori_oggi' => (int) $oggi['ko'], 'non_so_oggi' => (int) $oggi['nonso'],
            'ultimo_errore' => (string) $d['ultimo_errore'], 'ultimo_errore_ora' => (string) $d['ultimo_errore_ora'],
        ];
    }

    private function tettoFinito(): bool
    {
        return $this->maxGiorno > 0 && $this->uso()['oggi'] >= $this->maxGiorno;
    }

    /**
     * @param list<array{titolo: string, testo: string}> $fonti
     * @return array<string, mixed>
     */
    private function corpo(string $domanda, array $fonti): array
    {
        $c = [
            'model' => $this->modello,
            'messages' => [['role' => 'system', 'content' => self::ISTRUZIONI], ['role' => 'user', 'content' => self::messaggio($domanda, $fonti)]],
            'temperature' => 0.1,
            'max_tokens' => $this->esterno() ? 600 : 300,
            'stream' => false,
        ];
        if ($this->ragionamento !== '') {
            $c['reasoning_effort'] = $this->ragionamento;
        }

        return $c;
    }

    private function chiama(string $corpo): ?string
    {
        $intestazioni = ['Content-Type: application/json'];
        if ($this->chiave !== '') {
            $intestazioni[] = 'Authorization: Bearer ' . $this->chiave;
        }
        $this->erroreChiamata = '';
        $ch = curl_init($this->url . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $intestazioni, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $r = curl_exec($ch);
        $codice = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($r === false || $codice !== 200) {
            $motivo = $r === false ? curl_error($ch) : 'HTTP ' . $codice . ' ' . $this->messaggioErrore((string) $r);
            error_log('[Didacticus] modello non disponibile: ' . $motivo);
            curl_close($ch);
            $this->erroreChiamata = $motivo;
            $this->registra('ko', $motivo);

            return null;
        }
        curl_close($ch);
        $testo = self::pulisci((string) (json_decode((string) $r, true)['choices'][0]['message']['content'] ?? ''));
        $this->registra($testo === null ? 'nonso' : 'ok');

        return $testo;
    }

    /** Il messaggio d'errore del servizio, breve e senza la chiave (non deve finire nei log né nel pannello). */
    private function messaggioErrore(string $risposta): string
    {
        $j = json_decode($risposta, true);
        $m = is_array($j) ? (string) ($j['error']['message'] ?? ($j[0]['error']['message'] ?? '')) : '';
        if ($this->chiave !== '') {
            $m = str_replace($this->chiave, '[chiave]', $m);
        }

        return mb_substr(trim($m), 0, 200);
    }

    /** @return array{giorni: array<string, array{ok: int, ko: int, nonso: int}>, ultimo_errore: string, ultimo_errore_ora: string} */
    private function leggiUso(): array
    {
        $vuoto = ['giorni' => [], 'ultimo_errore' => '', 'ultimo_errore_ora' => ''];
        $j = is_file($this->fileUso) ? json_decode((string) @file_get_contents($this->fileUso), true) : null;

        return is_array($j) && isset($j['giorni']) && is_array($j['giorni']) ? $j + $vuoto : $vuoto;
    }

    /** Conta una chiamata al servizio esterno (ok | ko | nonso); con file bloccato per non perdere i conteggi di chiamate in contemporanea. */
    private function registra(string $esito, string $errore = ''): void
    {
        if (!$this->esterno()) {
            return;
        }
        $dir = dirname($this->fileUso);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return;
        }
        $f = @fopen($this->fileUso, 'c+');
        if ($f === false) {
            return;
        }
        try {
            if (!flock($f, LOCK_EX)) {
                return;
            }
            $j = json_decode((string) stream_get_contents($f), true);
            $d = is_array($j) && isset($j['giorni']) && is_array($j['giorni']) ? $j : ['giorni' => []];
            $g = date('Y-m-d');
            $d['giorni'][$g] = ($d['giorni'][$g] ?? ['ok' => 0, 'ko' => 0, 'nonso' => 0]);
            $d['giorni'][$g][$esito] = (int) ($d['giorni'][$g][$esito] ?? 0) + 1;
            ksort($d['giorni']);
            $d['giorni'] = array_slice($d['giorni'], -self::GIORNI_STORICO, null, true);
            if ($esito === 'ko') {
                $d['ultimo_errore'] = $errore;
                $d['ultimo_errore_ora'] = date('Y-m-d H:i:s');
            }
            ftruncate($f, 0);
            rewind($f);
            fwrite($f, (string) json_encode($d));
            fflush($f);
            flock($f, LOCK_UN);
        } finally {
            fclose($f);
        }
    }

    /** Toglie spazi e rimandi alle fonti; null se vuota o se il modello ha detto che non sa. */
    public static function pulisci(string $testo): ?string
    {
        $t = trim((string) preg_replace('/\s*\[\d+\]/u', '', $testo));
        if ($t === '' || stripos($t, self::NON_SO) !== false) {
            return null;
        }

        return mb_substr($t, 0, 1500);
    }
}
