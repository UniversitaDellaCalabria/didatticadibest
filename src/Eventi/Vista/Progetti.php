<?php

declare(strict_types=1);

namespace App\Eventi\Vista;

/** HTML delle schede dei progetti. */
final class Progetti
{
    /** Pulsante «Vai a OPENLAB» dei progetti con rimando ($stile = colori del pulsante dell'area). */
    public static function pulsanteDestinazione(array $dest, string $stile): string
    {
        $target = $dest['esterno'] ? ' target="_blank" rel="noopener"' : '';

        return '<a href="' . htmlspecialchars((string) $dest['url']) . '"' . $target . ' class="btn fw-bold w-100" style="' . htmlspecialchars($stile) . '">Vai a '
            . htmlspecialchars((string) $dest['nome']) . ' <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>';
    }
}
