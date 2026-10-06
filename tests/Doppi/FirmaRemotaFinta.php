<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Tutorato\FirmaRemota;

/** Firma remota dei test: aggiunge «%%FIRMA» al PDF se le credenziali sono utente/pin/123456. */
final class FirmaRemotaFinta implements FirmaRemota
{
    public bool $attiva = true;
    /** @var list<array{utente: string, aspetto: array<string, mixed>|null, motivo: string}> */
    public array $richieste = [];

    public function disponibile(): bool
    {
        return $this->attiva;
    }

    public function firma(string $pdf, string $utente, string $password, string $otp, ?array $aspetto = null, string $motivo = ''): array
    {
        $this->richieste[] = ['utente' => $utente, 'aspetto' => $aspetto, 'motivo' => $motivo];
        if ($utente !== 'utente' || $password !== 'pin' || $otp !== '123456') {
            return [null, 'Firma non riuscita: credenziali non valide.'];
        }

        return [$pdf . "\n%%FIRMA\n", null];
    }
}
