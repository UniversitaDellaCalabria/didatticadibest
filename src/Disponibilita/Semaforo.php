<?php

declare(strict_types=1);

namespace App\Disponibilita;

/**
 * Il semaforo della disponibilità di un docente per tesi o tirocinio, ricavato da posti, posti occupati, interruttore «accetto» e data dell'ultimo
 * aggiornamento. Solo regole, niente database: l'ora arriva da chi chiama. Il docente non sceglie il colore: lo decidono i posti liberi.
 *
 *   verde      ci sono posti liberi (almeno due)
 *   arancione  resta un solo posto
 *   rosso      al completo
 *   grigio     non accetta per ora, oppure l'ultimo aggiornamento è troppo vecchio («da riconfermare»: un semaforo fermo non dice la verità)
 */
final class Semaforo
{
    public const VERDE = 'verde';
    public const ARANCIONE = 'arancione';
    public const ROSSO = 'rosso';
    public const GRIGIO = 'grigio';

    /** Dopo quanti giorni senza aggiornamenti la disponibilità si considera da riconfermare. */
    public const GIORNI_VALIDITA = 90;

    /**
     * @param array<string, mixed> $riga accetta, posti, occupati, aggiornato_il
     * @return array{stato: string, liberi: int, etichetta: string, dettaglio: string, scaduta: bool, giorni: int}
     */
    public static function di(array $riga, \DateTimeInterface $ora): array
    {
        $posti = max(0, (int) ($riga['posti'] ?? 0));
        $liberi = max(0, $posti - max(0, (int) ($riga['occupati'] ?? 0)));
        $quando = !empty($riga['aggiornato_il']) ? date_create((string) $riga['aggiornato_il']) : false;
        $giorni = $quando ? max(0, (int) $quando->diff($ora)->format('%r%a')) : 9999;
        $scaduta = $giorni > self::GIORNI_VALIDITA;
        $out = ['liberi' => $liberi, 'scaduta' => $scaduta, 'giorni' => $giorni];

        if (empty($riga['accetta'])) {
            return $out + ['stato' => self::GRIGIO, 'etichetta' => 'Non accetta per ora', 'dettaglio' => ''];
        }
        if ($scaduta) {
            return $out + ['stato' => self::GRIGIO, 'etichetta' => 'Da riconfermare', 'dettaglio' => 'Non aggiornata da più di ' . self::GIORNI_VALIDITA . ' giorni'];
        }
        $dettaglio = $liberi . ($liberi === 1 ? ' posto libero' : ' posti liberi') . ' su ' . $posti;
        if ($liberi === 0) {
            return $out + ['stato' => self::ROSSO, 'etichetta' => 'Al completo', 'dettaglio' => ''];
        }

        return $out + ['stato' => $liberi === 1 ? self::ARANCIONE : self::VERDE, 'etichetta' => $liberi === 1 ? 'Ultimo posto' : 'Disponibile', 'dettaglio' => $dettaglio];
    }

    /** «oggi», «ieri», «3 giorni fa», «2 mesi fa» per la riga dell'elenco. */
    public static function quandoTesto(int $giorni): string
    {
        return match (true) {
            $giorni >= 9999 => 'mai',
            $giorni === 0 => 'oggi',
            $giorni === 1 => 'ieri',
            $giorni < 60 => $giorni . ' giorni fa',
            default => intdiv($giorni, 30) . ' mesi fa',
        };
    }
}
