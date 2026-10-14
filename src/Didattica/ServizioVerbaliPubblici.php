<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Auth\LimiteRichieste;
use App\Auth\Sessione;
use App\Core\IndirizzoClient;
use App\Core\Sito;

/**
 * La pagina pubblica dei verbali con password. Gli accessi li crea l'Ufficio didattico (una password per gruppo di esterni, con i consigli che
 * possono vedere e la scadenza); si entra con la sola password, in una sessione separata dal login di Ateneo. Si scaricano solo i verbali
 * segnati «pubblicabili» e firmati (o importati dall'archivio), nella versione con omissis se è stata caricata. Ogni accesso e scaricamento
 * si registra (IP con l'ultimo numero azzerato). Dati personali: la decisione di pubblicare un verbale tocca all'Ufficio didattico, mai in automatico.
 */
final class ServizioVerbaliPubblici
{
    public const CHIAVE = 'verbali_pubblici_accesso';
    public const INATTIVITA = 3600;
    public const MAX_TENTATIVI = 8;
    public const FINESTRA = 900;
    public const MESI_REGISTRO = 12;
    public const MIN_PASSWORD = 10;
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(
        private AccessoVerbaliRepository $accessi,
        private SedutaRepository $sedute,
        private ConsiglioRepository $consigli,
        private Sessione $sessione,
        private LimiteRichieste $limite,
        private IndirizzoClient $client,
        private Sito $sito,
        private string $cartella = Costanti::DIR_VERBALI
    ) {
    }

    /** Una password facile da dettare: 12 caratteri senza lettere ambigue, a gruppi di quattro («Kp3x-9Hnm-Tz4c»). */
    public static function generaPassword(): string
    {
        $p = '';
        for ($i = 0; $i < 12; $i++) {
            $p .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return implode('-', str_split($p, 4));
    }

    /** L'IP con l'ultimo numero azzerato (IPv4) o gli ultimi quattro gruppi (IPv6): basta per capire da dove, senza identificare. */
    public static function ipAnonimo(?string $ip): string
    {
        $ip = (string) $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string) preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 4)) . '::';
        }

        return '';
    }

    /**
     * I consigli scelti come testo JSON: «["*"]» = tutti, altrimenti gli id (solo quelli che esistono).
     *
     * @param list<int|string> $ids
     * @param array<int, mixed> $consigliNoti id => riga
     */
    public static function ambiti(array $ids, bool $tutti, array $consigliNoti): string
    {
        if ($tutti) {
            return '["*"]';
        }

        return (string) json_encode(array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => isset($consigliNoti[$i])))));
    }

    /**
     * Crea un accesso. Ritorna [errore, id, password]: password vuota = ne viene generata una; la password in chiaro si vede solo ora.
     *
     * @param list<int|string> $consigliIds
     * @param array<int, mixed> $consigliNoti
     * @return array{0: ?string, 1: int, 2: string}
     */
    public function crea(string $etichetta, string $password, ?string $scade, array $consigliIds, bool $tutti, array $consigliNoti, string $creatoDa): array
    {
        $etichetta = mb_substr(trim($etichetta), 0, 150);
        $ambiti = self::ambiti($consigliIds, $tutti, $consigliNoti);
        if ($etichetta === '') {
            return ['Scrivi a chi è destinato l\'accesso (per esempio «Rappresentanti degli studenti»).', 0, ''];
        }
        if ($ambiti === '[]') {
            return ['Scegli almeno un consiglio, oppure «tutti i consigli».', 0, ''];
        }
        $password = trim($password);
        if ($password !== '' && mb_strlen($password) < self::MIN_PASSWORD) {
            return ['La password deve avere almeno ' . self::MIN_PASSWORD . ' caratteri (lasciala vuota per generarne una).', 0, ''];
        }
        $password = $password !== '' ? $password : self::generaPassword();
        $id = $this->accessi->crea($etichetta, password_hash($password, PASSWORD_DEFAULT), $ambiti, self::dataValida($scade), mb_substr($creatoDa, 0, 150));

        return [null, $id, $password];
    }

    private static function dataValida(?string $d): ?string
    {
        return $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    /**
     * Cambia etichetta, consigli e scadenza di un accesso. Ritorna l'errore, null se va bene.
     *
     * @param list<int|string> $consigliIds
     * @param array<int, mixed> $consigliNoti
     */
    public function aggiorna(int $id, string $etichetta, ?string $scade, array $consigliIds, bool $tutti, array $consigliNoti): ?string
    {
        $etichetta = mb_substr(trim($etichetta), 0, 150);
        $ambiti = self::ambiti($consigliIds, $tutti, $consigliNoti);
        if (!$this->accessi->perId($id) || $etichetta === '' || $ambiti === '[]') {
            return 'Scrivi a chi è destinato l\'accesso e scegli almeno un consiglio.';
        }
        $this->accessi->aggiorna($id, $etichetta, $ambiti, self::dataValida($scade));

        return null;
    }

    /** Nuova password per l'accesso (la vecchia smette di funzionare). Ritorna la password in chiaro, vuota se l'accesso non esiste. */
    public function nuovaPassword(int $id): string
    {
        if (!$this->accessi->perId($id)) {
            return '';
        }
        $p = self::generaPassword();
        $this->accessi->nuovaPassword($id, password_hash($p, PASSWORD_DEFAULT));

        return $p;
    }

    /** Entra con la password. Ritorna l'errore (sempre lo stesso messaggio generico), null se va bene. */
    public function accedi(string $password): ?string
    {
        $ip = (string) $this->client->ip();
        if (!$this->limite->consenti($ip, 'verbali_pubblici', self::MAX_TENTATIVI, self::FINESTRA)) {
            return 'Troppi tentativi: riprova tra qualche minuto.';
        }
        $trovato = null;
        foreach ($this->accessi->validi(date('Y-m-d')) as $a) {
            // si controllano tutti, anche dopo aver trovato quello giusto: il tempo di risposta non dice quanti accessi ci sono
            if (password_verify($password, (string) $a['password_hash']) && $trovato === null) {
                $trovato = $a;
            }
        }
        $this->accessi->pulisci(self::MESI_REGISTRO);
        if ($trovato === null) {
            $this->accessi->registra(null, 'rifiutato', null, self::ipAnonimo($ip));

            return 'Password non valida.';
        }
        $this->sessione->rigenera();
        $this->sessione->scrivi(self::CHIAVE, ['id' => (int) $trovato['id'], 'ultimo' => time()]);
        $this->accessi->segnaAccesso((int) $trovato['id']);
        $this->accessi->registra((int) $trovato['id'], 'accesso', null, self::ipAnonimo($ip));

        return null;
    }

    /** L'accesso della sessione, se è ancora valido (attivo, non scaduto, non fermo da più di un'ora). */
    public function corrente(): ?array
    {
        $s = $this->sessione->leggi(self::CHIAVE);
        if (!is_array($s) || empty($s['id'])) {
            return null;
        }
        $a = $this->accessi->perId((int) $s['id']);
        $scaduto = $a && $a['scade_il'] !== null && $a['scade_il'] < date('Y-m-d');
        if (!$a || !(int) $a['attivo'] || $scaduto || time() - (int) ($s['ultimo'] ?? 0) > self::INATTIVITA) {
            $this->sessione->togli(self::CHIAVE);

            return null;
        }
        $this->sessione->scrivi(self::CHIAVE, ['id' => (int) $a['id'], 'ultimo' => time()]);

        return $a;
    }

    public function esci(): void
    {
        $this->sessione->togli(self::CHIAVE);
    }

    /**
     * L'accesso può vedere questo verbale: pubblicabile, firmato (o importato), con un PDF, di un consiglio compreso negli ambiti.
     *
     * @param array<string, mixed> $accesso
     * @param array<string, mixed> $s
     */
    public static function visibile(array $accesso, array $s): bool
    {
        if (!(int) ($s['pubblicabile'] ?? 0) || !($s['verbale_stato'] === 'firmato' || $s['origine'] === 'importato') || empty($s['verbale_pdf'])) {
            return false;
        }
        $ambiti = json_decode((string) $accesso['ambiti_json'], true);
        if (!is_array($ambiti)) {
            return false;
        }

        return in_array('*', $ambiti, true) || ($s['consiglio_id'] !== null && in_array((int) $s['consiglio_id'], array_map('intval', $ambiti), true));
    }

    /**
     * I verbali che l'accesso può vedere, dal più recente.
     *
     * @param array<string, mixed> $accesso
     * @return list<array<string, string|null>>
     */
    public function verbali(array $accesso): array
    {
        return array_values(array_filter($this->sedute->pubblici(), fn ($s) => self::visibile($accesso, $s)));
    }

    /**
     * Il PDF da scaricare (la versione con omissis se c'è) e il nome, dopo aver controllato che l'accesso possa vederlo; registra lo scaricamento.
     *
     * @param array<string, mixed> $accesso
     * @return array{percorso: string, nome: string}|null
     */
    public function file(array $accesso, int $sedutaId): ?array
    {
        $s = $this->sedute->perId($sedutaId);
        if (!$s || !self::visibile($accesso, $s)) {
            return null;
        }
        $rel = !empty($s['verbale_pubblico_pdf']) ? (string) $s['verbale_pubblico_pdf'] : (string) $s['verbale_pdf'];
        $base = realpath($this->sito->radice() . '/' . $this->cartella);
        $p = realpath($this->sito->radice() . '/' . $rel);
        if (!$base || !$p || strpos($p, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($p)) {
            return null;
        }
        $this->accessi->registra((int) $accesso['id'], 'scarica', $sedutaId, self::ipAnonimo($this->client->ip()));

        return ['percorso' => $p, 'nome' => 'Verbale_' . ($s['data'] ? date('d_m_Y', strtotime((string) $s['data'])) : 'seduta') . '.pdf'];
    }

    /**
     * Rende pubblicabile un verbale firmato (o importato). «integrale» = il PDF così com'è, solo se chi pubblica conferma che non ha dati
     * personali degli studenti; «omissis» = si pubblica il PDF caricato al suo posto. Ritorna l'errore, null se va bene.
     *
     * @param array<string, mixed> $s
     */
    public function pubblica(array $s, string $modo, bool $conferma, ?string $tmpOmissis, string $da): ?string
    {
        if (!($s['verbale_stato'] === 'firmato' || $s['origine'] === 'importato') || empty($s['verbale_pdf'])) {
            return 'Si pubblicano solo i verbali firmati o importati dall\'archivio, con il loro PDF.';
        }
        $file = null;
        if ($modo === 'omissis') {
            $pdf = $tmpOmissis !== null && is_file($tmpOmissis) ? (string) file_get_contents($tmpOmissis) : '';
            if (!str_starts_with(ltrim(substr($pdf, 0, 16)), '%PDF') || strlen($pdf) > ArchivioVerbali::MAX_BYTE) {
                return 'Carica il PDF con gli omissis (fino a 30 MB).';
            }
            $dir = $this->sito->radice() . '/' . $this->cartella;
            $nome = 'pubblico_' . (int) $s['id'] . '_' . bin2hex(random_bytes(6)) . '.pdf';
            if (@file_put_contents($dir . $nome, $pdf) === false) {
                return 'Salvataggio non riuscito.';
            }
            $file = $this->cartella . $nome;
        } elseif (!$conferma) {
            return 'Conferma di aver verificato che il verbale non contiene dati personali degli studenti, oppure carica la versione con gli omissis.';
        }
        $vecchio = !empty($s['verbale_pubblico_pdf']) ? $this->sito->radice() . '/' . $s['verbale_pubblico_pdf'] : null;
        $this->sedute->pubblica((int) $s['id'], $file, mb_substr($da, 0, 150));
        if ($vecchio && $vecchio !== $this->sito->radice() . '/' . $file && is_file($vecchio)) {
            @unlink($vecchio);
        }

        return null;
    }

    /** @param array<string, mixed> $s */
    public function ritira(array $s): void
    {
        if (!empty($s['verbale_pubblico_pdf']) && is_file($f = $this->sito->radice() . '/' . $s['verbale_pubblico_pdf'])) {
            @unlink($f);
        }
        $this->sedute->ritira((int) $s['id']);
    }
}
