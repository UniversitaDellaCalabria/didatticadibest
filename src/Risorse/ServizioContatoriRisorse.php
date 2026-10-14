<?php

declare(strict_types=1);

namespace App\Risorse;

/**
 * I contatori di una risorsa: quantità che si prenotano insieme allo slot (es. i campioni di un laboratorio) e che sono limitate. Due modalità,
 * scelte dall'admin: «periodo» (la capacità vale per ogni giorno, settimana o mese: si ricarica da sola) e «scorta» (una quantità che si consuma
 * e si ricarica con i movimenti del responsabile). Le prenotazioni confermate o da approvare tengono occupate le quantità; se vengono rifiutate o
 * annullate tornano disponibili da sole (il disponibile si calcola sempre dalle prenotazioni attive).
 */
final class ServizioContatoriRisorse
{
    public const MODALITA = ['periodo' => 'Per periodo (si ricarica da solo)', 'scorta' => 'Scorta (si consuma e si ricarica)'];
    public const PERIODI = ['giorno' => 'al giorno', 'settimana' => 'alla settimana', 'mese' => 'al mese'];

    public function __construct(private ContatoreRisorsaRepository $contatori)
    {
    }

    /** @return list<array<string, mixed>> */
    public function elenco(int $risorsaId, bool $soloAttivi = true): array
    {
        return $this->contatori->elenco($risorsaId, $soloAttivi);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->contatori->perId($id);
    }

    /**
     * Il periodo (dal, al) a cui appartiene la data, per i contatori «per periodo»; null per le scorte.
     *
     * @param array<string, mixed> $c
     * @return array{0: string, 1: string}|null
     */
    public function finestra(array $c, string $data): ?array
    {
        if ($c['modalita'] !== 'periodo') {
            return null;
        }
        $t = strtotime($data);

        return match ($c['periodo']) {
            'settimana' => [date('Y-m-d', strtotime('monday this week', $t)), date('Y-m-d', strtotime('sunday this week', $t))],
            'mese' => [date('Y-m-01', $t), date('Y-m-t', $t)],
            default => [date('Y-m-d', $t), date('Y-m-d', $t)],
        };
    }

    /** Quantità ancora disponibili per la data (per le scorte: il saldo attuale). */
    public function disponibile(array $c, string $data, ?int $escludiPrenotazione = null): int
    {
        $id = (int) $c['id'];
        if ($c['modalita'] === 'scorta') {
            return max(0, $this->contatori->totaleMovimenti($id) - $this->contatori->usato($id, null, null, $escludiPrenotazione));
        }
        [$dal, $al] = $this->finestra($c, $data) ?? [$data, $data];

        return max(0, (int) $c['capacita'] - $this->contatori->usato($id, $dal, $al, $escludiPrenotazione));
    }

    /**
     * Per ogni contatore attivo della risorsa e per ogni data: quante quantità restano (per il modulo di prenotazione).
     *
     * @param list<string> $date
     * @return list<array<string, mixed>>
     */
    public function riepilogo(int $risorsaId, array $date): array
    {
        $out = [];
        foreach ($this->elenco($risorsaId) as $c) {
            $resta = [];
            foreach ($date as $d) {
                $resta[$d] = $this->disponibile($c, $d);
            }
            $out[] = ['id' => (int) $c['id'], 'nome' => (string) $c['nome'], 'unita' => (string) $c['unita'], 'modalita' => (string) $c['modalita'], 'periodo' => (string) $c['periodo'],
                'minimo' => (int) $c['minimo'], 'massimo' => $c['massimo'] !== null ? (int) $c['massimo'] : null, 'obbligatorio' => (int) $c['obbligatorio'] === 1, 'resta' => $resta];
        }

        return $out;
    }

    /**
     * Controlla le quantità richieste per la data: dentro minimo e massimo per prenotazione e nel disponibile. Ritorna l'errore, null se vanno bene.
     * Un contatore non obbligatorio può restare a zero.
     *
     * @param list<array<string, mixed>> $contatori
     * @param array<int|string, mixed> $quantita id contatore => quantità
     */
    public function controlla(array $contatori, array $quantita, string $data): ?string
    {
        foreach ($contatori as $c) {
            $q = (int) ($quantita[$c['id']] ?? 0);
            $nome = $c['nome'] . ($c['unita'] !== '' ? ' (' . $c['unita'] . ')' : '');
            if ($q <= 0) {
                if ((int) $c['obbligatorio'] === 1) {
                    return "Indica la quantità: $nome.";
                }
                continue;
            }
            if ($q < (int) $c['minimo']) {
                return "$nome: almeno " . (int) $c['minimo'] . ' per prenotazione.';
            }
            if ($c['massimo'] !== null && $q > (int) $c['massimo']) {
                return "$nome: al massimo " . (int) $c['massimo'] . ' per prenotazione.';
            }
            $resta = $this->disponibile($c, $data);
            if ($q > $resta) {
                return "$nome: " . ($resta > 0 ? "ne restano solo $resta" : 'esaurito') . ($c['modalita'] === 'periodo' ? ' per ' . self::descrizionePeriodo($c, $data) : '') . '.';
            }
        }

        return null;
    }

    /** «il 12/10/2026», «la settimana dal 12/10», «ottobre 2026». */
    private static function descrizionePeriodo(array $c, string $data): string
    {
        $t = strtotime($data);

        return match ($c['periodo']) {
            'settimana' => 'la settimana dal ' . date('d/m', strtotime('monday this week', $t)),
            'mese' => 'il mese di ' . date('m/Y', $t),
            default => 'il ' . date('d/m/Y', $t),
        };
    }

    /**
     * Registra le quantità della prenotazione (solo quelle maggiori di zero).
     *
     * @param list<array<string, mixed>> $contatori
     * @param array<int|string, mixed> $quantita
     */
    public function registra(int $prenotazioneId, array $contatori, array $quantita, string $data): void
    {
        foreach ($contatori as $c) {
            $q = (int) ($quantita[$c['id']] ?? 0);
            if ($q > 0) {
                $this->contatori->registraUso((int) $c['id'], $prenotazioneId, $q, $data);
            }
        }
    }

    /** @return list<array<string, string|null>> */
    public function usi(int $prenotazioneId): array
    {
        return $this->contatori->usiDellaPrenotazione($prenotazioneId);
    }

    /** @param list<int> $prenotazioni @return array<int, list<array<string, string|null>>> */
    public function usiDi(array $prenotazioni): array
    {
        return $this->contatori->usiDi($prenotazioni);
    }

    /** Testo breve delle quantità («2 campioni, 1 kit») per email ed elenchi. */
    public static function testoUsi(array $usi): string
    {
        return implode(', ', array_map(fn ($u) => (int) $u['quantita'] . ' ' . ($u['unita'] !== '' ? $u['unita'] : mb_strtolower((string) $u['nome']))
            . ($u['unita'] !== '' && mb_strtolower((string) $u['unita']) !== mb_strtolower((string) $u['nome']) ? ' (' . $u['nome'] . ')' : ''), $usi));
    }

    /**
     * Crea o aggiorna un contatore dai dati del modulo del pannello. Ritorna [errore, id]. Per le scorte, con $caricoIniziale si registra il primo carico.
     *
     * @param array<string, mixed> $post
     * @return array{0: ?string, 1: int}
     */
    public function salva(int $risorsaId, int $id, array $post, string $autore): array
    {
        $nome = mb_substr(trim((string) ($post['nome'] ?? '')), 0, 100);
        if ($nome === '') {
            return ['Scrivi il nome di quello che si prenota (es. «Campioni»).', $id];
        }
        $modalita = isset(self::MODALITA[$post['modalita'] ?? '']) ? (string) $post['modalita'] : 'periodo';
        $periodo = isset(self::PERIODI[$post['periodo'] ?? '']) ? (string) $post['periodo'] : 'giorno';
        $capacita = max(0, (int) ($post['capacita'] ?? 0));
        $minimo = max(1, (int) ($post['minimo'] ?? 1));
        $massimo = ($post['massimo'] ?? '') !== '' ? max($minimo, (int) $post['massimo']) : null;
        $nuovo = $id === 0;
        $id = $this->contatori->salva($risorsaId, $id, [
            'nome' => $nome, 'unita' => mb_substr(trim((string) ($post['unita'] ?? '')), 0, 30), 'modalita' => $modalita, 'capacita' => $modalita === 'scorta' ? 0 : $capacita,
            'periodo' => $periodo, 'minimo' => $minimo, 'massimo' => $massimo, 'obbligatorio' => !empty($post['obbligatorio']) ? 1 : 0, 'attivo' => !isset($post['attivo']) || !empty($post['attivo']) ? 1 : 0,
        ]);
        if ($nuovo && $modalita === 'scorta' && $capacita > 0) {
            $this->contatori->aggiungiMovimento($id, $capacita, 'Scorta iniziale', $autore);
        }

        return [null, $id];
    }

    public function elimina(int $id): void
    {
        $this->contatori->elimina($id);
    }

    /**
     * Carico (positivo) o rettifica (negativo) di una scorta. Ritorna l'errore, null se registrato.
     */
    public function movimento(int $id, int $quantita, string $nota, string $autore): ?string
    {
        $c = $this->contatori->perId($id);
        if (!$c || $c['modalita'] !== 'scorta') {
            return 'Il carico si registra solo sulle scorte.';
        }
        if ($quantita === 0) {
            return 'Indica la quantità (positiva per un carico, negativa per scalare).';
        }
        if ($quantita < 0 && -$quantita > $this->disponibile($c, date('Y-m-d'))) {
            return 'Non puoi scalare più di quanto resta (' . $this->disponibile($c, date('Y-m-d')) . ').';
        }
        $this->contatori->aggiungiMovimento($id, $quantita, $nota !== '' ? $nota : ($quantita > 0 ? 'Carico' : 'Rettifica'), $autore);

        return null;
    }

    /** @return list<array<string, string|null>> */
    public function movimenti(int $id): array
    {
        return $this->contatori->movimenti($id);
    }
}
