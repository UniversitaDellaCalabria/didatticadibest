<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Core\Database;
use App\Core\Orologio;
use DateTimeImmutable;

/**
 * Se il servizio è acceso e quando risponde una persona. Gli orari sono uno per giorno della settimana, scritti come «09:00-13:00, 14:00-16:00»
 * (giorno vuoto = nessuna risposta quel giorno). Senza nessun orario scritto il servizio si considera sempre aperto. Fuori orario la chat si può
 * usare lo stesso: si lascia il messaggio e la risposta arriva per email.
 */
final class OrariAssistenza
{
    /** Giorni della settimana come li dà DateTime::format('N'). */
    public const GIORNI = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];

    public function __construct(private Database $db, private Orologio $orologio)
    {
    }

    /** @return array{attiva: bool, orari: array<int, string>} */
    public function impostazioni(): array
    {
        $r = $this->db->riga('SELECT assistenza_attiva, assistenza_orari FROM impostazioni_sistema WHERE id = 1') ?? [];
        $orari = json_decode((string) ($r['assistenza_orari'] ?? ''), true);
        $out = [];
        foreach (self::GIORNI as $n => $_) {
            $out[$n] = is_array($orari) ? trim((string) ($orari[$n] ?? '')) : '';
        }

        return ['attiva' => !isset($r['assistenza_attiva']) || (int) $r['assistenza_attiva'] === 1, 'orari' => $out];
    }

    public function attiva(): bool
    {
        return $this->impostazioni()['attiva'];
    }

    /**
     * Le fasce di un giorno come minuti dalla mezzanotte, oppure null se il testo non è scritto bene.
     *
     * @return list<array{0: int, 1: int}>|null
     */
    public static function fasce(string $testo): ?array
    {
        $testo = trim($testo);
        if ($testo === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\s*[,;]\s*/', $testo) ?: [] as $f) {
            if (!preg_match('/^(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})$/', $f, $m)) {
                return null;
            }
            [$da, $a] = [(int) $m[1] * 60 + (int) $m[2], (int) $m[3] * 60 + (int) $m[4]];
            if ((int) $m[1] > 23 || (int) $m[3] > 24 || (int) $m[2] > 59 || (int) $m[4] > 59 || $da >= $a || $a > 24 * 60) {
                return null;
            }
            $out[] = [$da, $a];
        }

        return $out;
    }

    /**
     * Salva il servizio acceso/spento e gli orari. Ritorna l'errore da mostrare o null.
     *
     * @param array<int, string> $orari giorno (1-7) => testo
     */
    public function salva(bool $attiva, array $orari): ?string
    {
        $pulito = [];
        foreach (self::GIORNI as $n => $nome) {
            $t = trim((string) ($orari[$n] ?? ''));
            if (self::fasce($t) === null) {
                return "$nome: scrivi gli orari come 09:00-13:00, 14:00-16:00 (oppure lascia vuoto se non rispondete).";
            }
            $pulito[$n] = $t;
        }
        $this->db->esegui('UPDATE impostazioni_sistema SET assistenza_attiva = ?, assistenza_orari = ? WHERE id = 1', [$attiva ? 1 : 0, (string) json_encode($pulito, JSON_UNESCAPED_UNICODE)]);

        return null;
    }

    /** C'è qualcuno che risponde adesso? Senza orari scritti: sempre sì. */
    public function inOrario(?DateTimeImmutable $quando = null): bool
    {
        $quando ??= $this->orologio->adesso();
        $orari = $this->impostazioni()['orari'];
        if (!array_filter($orari)) {
            return true;
        }
        $minuti = (int) $quando->format('G') * 60 + (int) $quando->format('i');
        foreach (self::fasce($orari[(int) $quando->format('N')]) ?? [] as [$da, $a]) {
            if ($minuti >= $da && $minuti < $a) {
                return true;
            }
        }

        return false;
    }

    /** Gli orari a parole, raggruppando i giorni uguali: «Lunedì–Venerdì 09:00-13:00, 14:00-16:00». Vuoto se non ce ne sono. */
    public function descrizione(): string
    {
        $gruppi = [];
        foreach ($this->impostazioni()['orari'] as $n => $t) {
            if ($t === '') {
                continue;
            }
            $ultimo = array_key_last($gruppi);
            if ($ultimo !== null && $gruppi[$ultimo]['testo'] === $t && $gruppi[$ultimo]['fine'] === $n - 1) {
                $gruppi[$ultimo]['fine'] = $n;
            } else {
                $gruppi[] = ['inizio' => $n, 'fine' => $n, 'testo' => $t];
            }
        }

        return implode('; ', array_map(static fn (array $g): string => (
            $g['inizio'] === $g['fine'] ? self::GIORNI[$g['inizio']] : self::GIORNI[$g['inizio']] . '–' . self::GIORNI[$g['fine']]
        ) . ' ' . $g['testo'], $gruppi));
    }
}
