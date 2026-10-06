<?php

declare(strict_types=1);

namespace App\Tutorato;

/** Firma digitale PAdES remota di un PDF (servizio del certificatore): nei test c'è un doppio che non chiama nessun servizio. */
interface FirmaRemota
{
    /** La firma remota è configurata sul portale. */
    public function disponibile(): bool;

    /**
     * Firma il PDF con le credenziali di chi firma (non si salvano). $aspetto = riquadro della firma visibile (pagina, x, y, l, a in punti).
     * Ritorna [PDF firmato, null] oppure [null, messaggio di errore].
     *
     * @param array<string, mixed>|null $aspetto
     * @return array{0: string|null, 1: string|null}
     */
    public function firma(string $pdf, string $utente, string $password, string $otp, ?array $aspetto = null, string $motivo = ''): array;
}
