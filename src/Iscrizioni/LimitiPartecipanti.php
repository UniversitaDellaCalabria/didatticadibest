<?php

declare(strict_types=1);

namespace App\Iscrizioni;

/** Minimo e massimo di partecipanti per iscrizione (progetti per le scuole, prenotazioni di classe) e loro controllo. */
final class LimitiPartecipanti
{
    /**
     * Minimo e massimo per iscrizione: quelli dell'edizione (turno) se indicati, altrimenti quelli generali del progetto.
     * max = null se non c'è un massimo.
     *
     * @param array<string, mixed>|null $dettagli riga di progetti_dettagli
     * @param array<string, mixed>|null $turno
     * @return array{min: int, max: int|null}
     */
    public static function calcola(?array $dettagli, ?array $turno = null): array
    {
        $min = !empty($turno['min_partecipanti']) ? (int) $turno['min_partecipanti'] : (!empty($dettagli['min_studenti']) ? (int) $dettagli['min_studenti'] : 1);
        $max = !empty($turno['max_partecipanti']) ? (int) $turno['max_partecipanti'] : (!empty($dettagli['max_studenti']) ? (int) $dettagli['max_studenti'] : null);

        return ['min' => $min, 'max' => $max];
    }

    /** «da 15 a 30», «almeno 15», «fino a 30» o '' se non ci sono limiti. */
    public static function testo(?int $min, ?int $max): string
    {
        if ($min > 1 && $max) {
            return $min === $max ? (string) $min : "da $min a $max";
        }
        if ($min > 1) {
            return "almeno $min";
        }

        return $max ? "fino a $max" : '';
    }

    /**
     * Progetti per le scuole: numero di partecipanti obbligatorio, intero, dentro i limiti del progetto.
     * Ritorna null se va bene (o se il progetto non è per le scuole), altrimenti il messaggio di errore.
     *
     * @param array<string, mixed> $custom campi aggiuntivi del modulo
     * @param array<string, mixed>|null $dettagli
     * @param array<string, mixed>|null $turno
     */
    public static function valida(array $custom, ?array $dettagli, ?array $turno = null): ?string
    {
        if ((int) ($dettagli['per_scuole'] ?? 1) !== 1) {
            return null;
        }
        $v = trim((string) ($custom[CAMPO_PARTECIPANTI] ?? ''));
        if ($v === '') {
            return 'Indica il numero di studenti partecipanti.';
        }
        if (!preg_match('/^\d+$/', $v)) {
            return 'Il numero di studenti deve essere un numero intero.';
        }
        $n = (int) $v;
        ['min' => $min, 'max' => $max] = self::calcola($dettagli, $turno);
        if ($n < $min || ($max !== null && $n > $max)) {
            return "Il numero di studenti deve essere compreso tra $min e " . ($max ?? 'il massimo previsto') . '.';
        }

        return null;
    }
}
