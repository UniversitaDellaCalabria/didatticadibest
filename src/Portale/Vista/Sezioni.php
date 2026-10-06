<?php

declare(strict_types=1);

namespace App\Portale\Vista;

/** HTML di tipi di area e ambiti degli eventi (tendine, caselle, etichette colorate). */
final class Sezioni
{
    /** Tendina del tipo di area, raggruppata per macroarea */
    public static function sceltaTipoArea(string $name, string $valore, string $attr = '', string $classi = 'form-select form-select-sm'): string
    {
        $out = '<select name="' . self::h($name) . '" class="' . self::h($classi) . '" ' . $attr . '><option value="">Non assegnata (eventi generici)</option>';
        foreach (SEZIONI_PORTALE as $kS => $s) {
            $out .= '<optgroup label="' . self::h($s['nome']) . '">';
            foreach (TIPI_AREA as $kT => $t) {
                if ($t['sezione'] !== $kS) {
                    continue;
                }
                $out .= '<option value="' . self::h($kT) . '"' . ($valore === $kT ? ' selected' : '') . (!$t['disponibile'] ? ' disabled' : '') . '>'
                    . self::h($t['nome']) . (!$t['disponibile'] ? ' (in arrivo)' : '') . '</option>';
            }
            $out .= '</optgroup>';
        }

        return $out . '</select>';
    }

    /**
     * Caselle degli ambiti per il modulo dell'evento.
     *
     * @param list<string> $selezionati
     */
    public static function sceltaAmbiti(array $selezionati, string $id = 'evAmbiti'): string
    {
        $o = '<div class="d-flex flex-wrap gap-3" id="' . self::h($id) . '" role="group" aria-label="Ambiti">';
        foreach (AMBITI_EVENTO as $k => $a) {
            $o .= '<label class="form-check m-0"><input class="form-check-input" type="checkbox" name="ambiti[]" value="' . self::h($k) . '"'
                . (in_array($k, $selezionati, true) ? ' checked' : '') . '> <i class="fa ' . $a['icona'] . ' me-1" style="color:' . $a['colore'] . ';" aria-hidden="true"></i>'
                . self::h($a['nome']) . '</label>';
        }

        return $o . '</div>';
    }

    /**
     * Etichette colorate degli ambiti (pagine pubbliche).
     *
     * @param array<int|string, mixed> $ambiti
     */
    public static function badgeAmbiti(array $ambiti, string $classe = ''): string
    {
        $o = '';
        foreach ($ambiti as $k) {
            if (!isset(AMBITI_EVENTO[$k])) {
                continue;
            }
            $a = AMBITI_EVENTO[$k];
            $o .= '<span class="badge rounded-pill ' . $classe . '" style="background:' . $a['colore'] . '1a;color:' . $a['colore'] . ';border:1px solid ' . $a['colore'] . '55;">'
                . '<i class="fa ' . $a['icona'] . ' me-1" aria-hidden="true"></i>' . htmlspecialchars($a['nome']) . '</span> ';
        }

        return trim($o);
    }

    private static function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
