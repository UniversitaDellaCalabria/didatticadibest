<?php

declare(strict_types=1);

namespace App\Tutorato\Vista;

use App\Tutorato\Costanti;

/** Etichette dello stato della lettera di incarico per le pagine. */
final class StatiIncarico
{
    public static function badge(string $stato): string
    {
        [$n, $col, $ico] = Costanti::STATI_INCARICO[$stato] ?? [$stato, '#64748b', 'fa-circle'];

        return '<span class="badge" style="background:' . $col . ';"><i class="fa ' . $ico . ' me-1" aria-hidden="true"></i>' . htmlspecialchars($n) . '</span>';
    }

    /**
     * Passi della lettera per le pagine: [nome, fatto?, attuale?].
     *
     * @param array<string, mixed> $i
     * @return list<array{0: string, 1: bool, 2: bool}>
     */
    public static function passi(array $i): array
    {
        $ordine = ['bozza', 'inviata', 'confermata', 'firmata_docente', 'firmata', 'protocollata'];
        $nomi = ['Preparata', 'Conferma dello studente (SPID/CIE)', 'Firma del docente (PAdES)', 'Firma del direttore (PAdES)', 'Protocollo', 'Protocollata'];
        $pos = array_search($i['stato'], $ordine, true);
        $out = [];
        foreach ($nomi as $k => $n) {
            $out[] = [$n, $pos !== false && $k < $pos, $pos !== false && $k === $pos];
        }

        return $out;
    }
}
