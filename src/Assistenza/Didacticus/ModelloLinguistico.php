<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/**
 * Il collegamento facoltativo di Didacticus a un modello linguistico aperto che gira su una macchina dell'Ateneo (Ollama, llama.cpp, vLLM: qualunque servizio con
 * l'interfaccia «chat completions» di OpenAI). Niente va a servizi esterni. Spento di partenza: si accende con LLM_URL nel file .env.
 *
 * Il modello NON risponde dalla sua conoscenza: riceve la domanda e le voci della base di conoscenza più vicine, e deve rispondere solo con quelle; se non bastano
 * risponde NON_SO e Didacticus torna al suo comportamento a regole. Se il servizio non risponde in tempo o dà errore, lo stesso: nessuna chat si rompe.
 *
 * Variabili del file .env: LLM_URL (es. http://127.0.0.1:11434/v1), LLM_MODELLO (es. qwen2.5:3b), LLM_TIMEOUT (secondi, 60),
 * LLM_MODALITA («incerto» = solo quando nessuna voce è abbastanza sicura, «sempre» = anche per riformulare le voci trovate).
 */
final class ModelloLinguistico
{
    public const NON_SO = 'NON_SO';
    public const MODALITA_INCERTO = 'incerto';
    public const MODALITA_SEMPRE = 'sempre';

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

    /** Senza argomenti legge le variabili del file .env. */
    public function __construct(?string $url = null, ?string $modello = null, ?int $timeout = null, ?string $modalita = null)
    {
        $env = static fn (string $k): string => function_exists('env_valore') ? trim((string) env_valore($k)) : '';
        $this->url = rtrim($url ?? $env('LLM_URL'), '/');
        $this->modello = ($modello ?? $env('LLM_MODELLO')) ?: 'qwen2.5:3b';
        $this->timeout = max(5, min(180, $timeout ?? (((int) $env('LLM_TIMEOUT')) ?: 60)));
        $m = strtolower($modalita ?? $env('LLM_MODALITA'));
        $this->modalita = $m === self::MODALITA_SEMPRE ? self::MODALITA_SEMPRE : self::MODALITA_INCERTO;
    }

    public function attivo(): bool
    {
        return $this->url !== '';
    }

    public function modalita(): string
    {
        return $this->modalita;
    }

    public function nome(): string
    {
        return $this->modello;
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
     * La risposta del modello alla domanda, usando solo le fonti. Null se il servizio è spento o non risponde, se il modello dice di non sapere o se la risposta è vuota.
     *
     * @param list<array{titolo: string, testo: string}> $fonti
     */
    public function rispondi(string $domanda, array $fonti): ?string
    {
        if (!$this->attivo() || !$fonti) {
            return null;
        }
        $corpo = json_encode([
            'model' => $this->modello,
            'messages' => [['role' => 'system', 'content' => self::ISTRUZIONI], ['role' => 'user', 'content' => self::messaggio($domanda, $fonti)]],
            'temperature' => 0.1,
            'max_tokens' => 300,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($this->url . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $r = curl_exec($ch);
        $codice = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($r === false || $codice !== 200) {
            error_log('[Didacticus] modello non disponibile: ' . ($r === false ? curl_error($ch) : 'HTTP ' . $codice));
            curl_close($ch);

            return null;
        }
        curl_close($ch);

        return self::pulisci((string) (json_decode((string) $r, true)['choices'][0]['message']['content'] ?? ''));
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
