<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;

/** Modelli e PEC della convenzione: quelli dell'area (Impostazioni area) se validi, altrimenti i predefiniti del Dipartimento. */
final class ModelliConvenzione
{
    public function __construct(private Sito $sito)
    {
    }

    /**
     * $cfg = riga di pagine_eventi dell'area: campi vuoti o non validi → valori predefiniti.
     * Indirizzi sempre completi (servono anche nelle email): i file del portale diventano https://…/eventi/…
     *
     * @param array<string, mixed> $cfg
     * @return array{modello: string, allegato: string, pec: string}
     */
    public function dati(array $cfg): array
    {
        $pec = trim((string) ($cfg['conv_pec'] ?? ''));

        return [
            'modello' => $this->indirizzo($cfg['conv_url_modello'] ?? '', Costanti::URL_MODELLO),
            'allegato' => $this->indirizzo($cfg['conv_url_allegato'] ?? '', Costanti::URL_ALLEGATO),
            'pec' => filter_var($pec, FILTER_VALIDATE_EMAIL) ? $pec : Costanti::PEC,
        ];
    }

    /** Il modello precompilabile del Dipartimento (Convenzione o Allegato A) è nel portale: si può compilare online. */
    public function haPrecompilabile(string $doc): bool
    {
        return is_file($this->sito->radice() . '/modelli_documenti/' . ($doc === 'allegato' ? 'allegato_a' : 'convenzione') . '_precompilabile.docx');
    }

    private function indirizzo(mixed $valore, string $predefinito): string
    {
        $v = trim((string) $valore);
        $ok = preg_match('#^https?://#i', $v) || preg_match('#^(uploads/modelli_convenzione|assets/modelli)/[A-Za-z0-9._-]+$#', $v);

        return $this->assoluto($ok ? $v : $predefinito);
    }

    private function assoluto(string $v): string
    {
        return preg_match('#^https?://#i', $v) ? $v : rtrim($this->sito->urlBase(), '/') . '/' . ltrim($v, '/');
    }
}
