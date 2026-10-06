<?php

declare(strict_types=1);

namespace App\Portale\Vista;

/** Testi comuni delle pagine pubbliche: date in italiano, descrizioni brevi delle card. */
final class Testi
{
    private const GIORNI = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
    private const MESI = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    /** HTML: "Lunedì <span class="text-danger fw-bold">5 ottobre 2026</span>"; 'Date da definire' senza data. */
    public static function dataInItaliano(mixed $data): string
    {
        if (empty($data) || $data == '0000-00-00' || $data == '9999-12-31') {
            return 'Date da definire';
        }
        $t = strtotime((string) $data);
        if ($t === false) {
            $t = 0;
        }

        return self::GIORNI[(int) date('w', $t)] . ' <span class="text-danger fw-bold">' . date('j', $t) . ' ' . self::MESI[(int) date('n', $t)] . ' ' . date('Y', $t) . '</span>';
    }

    /**
     * HTML della descrizione breve: solo grassetto, corsivo, sottolineato e a capo, senza attributi.
     * Oltre 300 caratteri visibili si perde la formattazione e il testo viene troncato.
     */
    public static function pulisciDescrizioneBreve(string $html): string
    {
        $html = (string) preg_replace('#</p>\s*<p[^>]*>#i', '<br>', $html);
        $html = strip_tags($html, '<strong><b><em><i><u><br>');
        $html = (string) preg_replace('#<(/?)(strong|b|em|i|u|br)\b[^>]*>#i', '<$1$2>', $html);
        $html = trim((string) preg_replace('#^(?:\s|&nbsp;|<br>)+|(?:\s|&nbsp;|<br>)+$#iu', '', $html));
        $testo = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        if ($testo === '') {
            return '';
        }
        if (mb_strlen($testo) > 300) {
            return htmlspecialchars(mb_substr($testo, 0, 300));
        }

        return $html;
    }

    /**
     * HTML delle card: la descrizione breve; se manca, l'inizio della descrizione completa senza formattazione.
     *
     * @param array<string, mixed> $evento
     */
    public static function testoCardEvento(array $evento, int $max = 220): string
    {
        $breve = self::pulisciDescrizioneBreve((string) ($evento['descrizione_breve'] ?? ''));
        if ($breve !== '') {
            return $breve;
        }
        $testo = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string) ($evento['descrizione'] ?? ''))), ENT_QUOTES, 'UTF-8')));

        return htmlspecialchars(mb_strimwidth($testo, 0, $max, '…'));
    }
}
