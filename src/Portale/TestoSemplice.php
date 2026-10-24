<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * Testo scritto «a mano» dal pannello (comunicazioni) reso in HTML sicuro: riga vuota = nuovo paragrafo, righe che iniziano con «- » = elenco puntato,
 * **testo** = grassetto, gli indirizzi web diventano link. Tutto il resto è mostrato com'è (niente HTML).
 */
final class TestoSemplice
{
    public static function html(string $testo): string
    {
        $out = '';
        foreach (preg_split('/\n{2,}/', trim(str_replace("\r\n", "\n", $testo))) ?: [] as $blocco) {
            $righe = array_values(array_filter(array_map('trim', explode("\n", $blocco)), static fn (string $r): bool => $r !== ''));
            if (!$righe) {
                continue;
            }
            if (count(array_filter($righe, static fn (string $r): bool => str_starts_with($r, '- '))) === count($righe)) {
                $out .= '<ul>' . implode('', array_map(static fn (string $r): string => '<li>' . self::riga(substr($r, 2)) . '</li>', $righe)) . '</ul>';
            } else {
                $out .= '<p>' . implode('<br>', array_map([self::class, 'riga'], $righe)) . '</p>';
            }
        }

        return $out;
    }

    /** Un'estratto in testo semplice (senza formattazione) per elenchi e feed. */
    public static function estratto(string $testo, int $max = 220): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', str_replace(['**', "\n- "], ['', ' '], $testo)));

        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
    }

    private static function riga(string $r): string
    {
        $s = htmlspecialchars($r, ENT_QUOTES, 'UTF-8');
        $s = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);

        return (string) preg_replace_callback('#https?://[^\s<]+#i', static function (array $m): string {
            $u = rtrim($m[0], '.,;:)');
            $resto = substr($m[0], strlen($u));

            return '<a href="' . $u . '" target="_blank" rel="noopener noreferrer">' . $u . '</a>' . $resto;
        }, $s);
    }
}
