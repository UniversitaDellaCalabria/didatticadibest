<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/**
 * Il collegamento facoltativo di Didacticus a un modello che capisce il SIGNIFICATO delle frasi (embedding, per esempio bge-m3 su Ollama), in esecuzione su una
 * macchina dell'Ateneo: niente va a servizi esterni. Non scrive risposte: trasforma la domanda e le voci in elenchi di numeri e dice quali voci le somigliano
 * di più, così «stage fuori dall'Italia» trova «Tirocinio all'estero» anche senza parole in comune. La risposta mostrata resta sempre il testo scritto dal Dipartimento.
 * Spento di partenza: si accende con EMB_URL nel file .env.
 *
 * Variabili del file .env: EMB_URL (es. http://127.0.0.1:11434), EMB_MODELLO (bge-m3), EMB_TIMEOUT (secondi, 8),
 * EMB_SOGLIA (somiglianza da cui si risponde direttamente, 0.62), EMB_SOGLIA_SUGGERISCI (da cui si propone «forse intendevi…», 0.50).
 */
final class Significato
{
    public const SOGLIA_RISPOSTA = 0.62;
    public const SOGLIA_SUGGERIMENTO = 0.50;

    private string $url;
    private string $modello;
    private int $timeout;
    private float $sogliaRisposta;
    private float $sogliaSuggerimento;

    /** Senza argomenti legge le variabili del file .env. */
    public function __construct(?string $url = null, ?string $modello = null, ?int $timeout = null)
    {
        $env = static fn (string $k): string => function_exists('env_valore') ? trim((string) env_valore($k)) : '';
        $this->url = rtrim($url ?? $env('EMB_URL'), '/');
        $this->modello = ($modello ?? $env('EMB_MODELLO')) ?: 'bge-m3';
        $this->timeout = max(2, min(60, $timeout ?? (((int) $env('EMB_TIMEOUT')) ?: 8)));
        $s = (float) str_replace(',', '.', $env('EMB_SOGLIA'));
        $this->sogliaRisposta = $s > 0.3 && $s < 0.95 ? $s : self::SOGLIA_RISPOSTA;
        $g = (float) str_replace(',', '.', $env('EMB_SOGLIA_SUGGERISCI'));
        $this->sogliaSuggerimento = $g > 0.2 && $g < $this->sogliaRisposta ? $g : min(self::SOGLIA_SUGGERIMENTO, $this->sogliaRisposta - 0.05);
    }

    public function attivo(): bool
    {
        return $this->url !== '';
    }

    public function nome(): string
    {
        return $this->modello;
    }

    public function sogliaRisposta(): float
    {
        return $this->sogliaRisposta;
    }

    public function sogliaSuggerimento(): float
    {
        return $this->sogliaSuggerimento;
    }

    /** Il testo di una voce che si confronta con le domande: titolo e domande d'esempio. */
    public static function testoVoce(array $voce): string
    {
        return trim((string) $voce['titolo'] . "\n" . (string) ($voce['domande'] ?? ''));
    }

    /** Cambia se cambia il testo o il modello: serve a sapere quali vettori sono da ricalcolare. */
    public function impronta(array $voce): string
    {
        return md5($this->modello . "\n" . self::testoVoce($voce));
    }

    /**
     * I vettori dei testi, nello stesso ordine; null se il servizio è spento o non risponde (Didacticus continua a regole).
     *
     * @param list<string> $testi
     * @return list<list<float>>|null
     */
    public function incorpora(array $testi): ?array
    {
        $testi = array_values($testi);
        if (!$this->attivo() || !$testi) {
            return null;
        }
        $ch = curl_init($this->url . '/api/embed');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => $this->modello, 'input' => array_map(static fn (string $t): string => mb_substr($t, 0, 2000), $testi)], JSON_UNESCAPED_UNICODE),
        ]);
        $r = curl_exec($ch);
        $codice = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($r === false || $codice !== 200) {
            error_log('[Didacticus] significato non disponibile: ' . ($r === false ? curl_error($ch) : 'HTTP ' . $codice));
            curl_close($ch);

            return null;
        }
        curl_close($ch);
        $v = json_decode((string) $r, true)['embeddings'] ?? null;
        if (!is_array($v) || count($v) !== count($testi) || !is_array($v[0] ?? null) || !$v[0]) {
            return null;
        }

        return array_map(static fn (array $e): array => array_map('floatval', $e), array_values($v));
    }

    /** Somiglianza di significato tra due vettori, da -1 a 1 (di solito 0,4-0,7 per frasi dello stesso tema). */
    public static function coseno(array $a, array $b): float
    {
        $p = $na = $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i] ?? 0.0;
            $p += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }
        $d = sqrt($na * $nb);

        return $d > 0 ? $p / $d : 0.0;
    }

    /** Il vettore come testo (numeri a 32 bit in base64) per tenerlo nel database. */
    public static function comprimi(array $v): string
    {
        return base64_encode(pack('g*', ...array_map('floatval', $v)));
    }

    /** @return list<float> */
    public static function espandi(string $testo): array
    {
        $b = base64_decode($testo, true);
        if ($b === false || $b === '') {
            return [];
        }

        return array_values(unpack('g*', $b) ?: []);
    }
}
