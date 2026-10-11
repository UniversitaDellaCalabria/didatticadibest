<?php

declare(strict_types=1);

namespace App\Portale\Vista;

/**
 * Componenti comuni delle pagine del pannello (aspetto in assets/css/pannello.css): intestazione della pagina,
 * schede, riquadro «prossimo passo», linea dell'iter, filtri per fase, etichette di stato, elenco vuoto.
 * Il colore del modulo corrente arriva dalla variabile CSS --pn-mod impostata da admin_header.php.
 * I testi sono sempre ripuliti; i parametri $azioni sono HTML già pronto (pulsanti, form).
 */
final class Pannello
{
    /** Toni delle etichette di stato: [classe, icona predefinita]. */
    public const TONI = [
        'ok'      => ['pn-tono-ok', 'fa-circle-check'],
        'attesa'  => ['pn-tono-attesa', 'fa-hourglass-half'],
        'blocco'  => ['pn-tono-blocco', 'fa-circle-exclamation'],
        'info'    => ['pn-tono-info', 'fa-circle-info'],
        'lavoro'  => ['pn-tono-lavoro', 'fa-gears'],
        'spento'  => ['pn-tono-spento', 'fa-box-archive'],
    ];

    /** Intestazione: icona, titolo, una frase «a cosa serve» e le azioni principali (a destra). */
    public static function testata(string $titolo, string $icona = '', string $aiuto = '', string $azioni = '', string $sopra = ''): string
    {
        return '<header class="pn-testata">'
            . ($sopra !== '' ? '<div class="pn-sopra">' . $sopra . '</div>' : '')
            . '<div class="pn-testata-riga">'
            . ($icona !== '' ? '<span class="pn-testata-ico" aria-hidden="true"><i class="fa ' . self::h($icona) . '"></i></span>' : '')
            . '<div class="pn-testata-testi"><h1 class="pn-titolo">' . self::h($titolo) . '</h1>'
            . ($aiuto !== '' ? '<p class="pn-aiuto">' . self::h($aiuto) . '</p>' : '') . '</div>'
            . ($azioni !== '' ? '<div class="pn-testata-azioni">' . $azioni . '</div>' : '')
            . '</div></header>';
    }

    /**
     * Schede della pagina (un solo aspetto in tutto il pannello).
     *
     * @param array<string, array{href: string, testo: string, icona?: string, numero?: int|null, avviso?: bool, titolo?: string}> $schede
     */
    public static function schede(array $schede, string $attiva, string $etichetta = 'Sezioni della pagina'): string
    {
        $o = '<nav class="pn-schede" aria-label="' . self::h($etichetta) . '">';
        foreach ($schede as $k => $s) {
            $on = (string) $k === $attiva;
            $n = $s['numero'] ?? null;
            $o .= '<a class="pn-scheda' . ($on ? ' attiva' : '') . '" href="' . self::h($s['href']) . '"' . ($on ? ' aria-current="page"' : '')
                . (!empty($s['titolo']) ? ' title="' . self::h($s['titolo']) . '"' : '') . '>'
                . (!empty($s['icona']) ? '<i class="fa ' . self::h($s['icona']) . '" aria-hidden="true"></i>' : '')
                . '<span>' . self::h($s['testo']) . '</span>'
                . ($n !== null && $n > 0 ? '<span class="pn-num' . (!empty($s['avviso']) ? ' avviso' : '') . '">' . (int) $n . '</span>' : '')
                . '</a>';
        }

        return $o . '</nav>';
    }

    /** Riquadro «prossimo passo»: cosa fare adesso e il pulsante per farlo. */
    public static function prossima(string $titolo, string $testo = '', string $azioni = '', string $tono = ''): string
    {
        return '<div class="pn-prossima' . ($tono !== '' ? ' pn-prossima-' . self::h($tono) : '') . '"><div class="pn-prossima-testo"><strong>' . self::h($titolo) . '</strong>'
            . ($testo !== '' ? '<span>' . self::h($testo) . '</span>' : '') . '</div>'
            . ($azioni !== '' ? '<div class="pn-prossima-azioni">' . $azioni . '</div>' : '') . '</div>';
    }

    /**
     * Linea dell'iter: passi fatti (verdi), il passo corrente evidenziato, quelli che mancano in grigio.
     *
     * @param list<array{nome: string, stato?: string, nota?: string, href?: string}> $passi stato = fatto | qui | ''
     */
    public static function iter(array $passi, string $etichetta = 'Iter'): string
    {
        $o = '<ol class="pn-iter" aria-label="' . self::h($etichetta) . '">';
        foreach ($passi as $p) {
            $st = in_array($p['stato'] ?? '', ['fatto', 'qui'], true) ? $p['stato'] : '';
            $testo = self::h($p['nome']) . (!empty($p['nota']) ? '<small>' . self::h($p['nota']) . '</small>' : '');
            $o .= '<li class="' . $st . '"' . ($st === 'qui' ? ' aria-current="step"' : '') . '>'
                . (!empty($p['href']) ? '<a href="' . self::h($p['href']) . '">' . $testo . '</a>' : '<span>' . $testo . '</span>')
                . ($st === 'fatto' ? '<span class="visually-hidden"> (fatto)</span>' : '') . '</li>';
        }

        return $o . '</ol>';
    }

    /**
     * Filtri per fase con il numero (es. Da protocollare 2 · Da assegnare 8): una panoramica che è anche un filtro.
     *
     * @param array<string, array{href: string, testo: string, numero?: int|null, colore?: string}> $fasi
     */
    public static function fasi(array $fasi, string $attiva, string $etichetta = 'Fasi'): string
    {
        $o = '<nav class="pn-fasi" aria-label="' . self::h($etichetta) . '">';
        foreach ($fasi as $k => $f) {
            $on = (string) $k === $attiva;
            $col = isset($f['colore']) && preg_match('/^#[0-9a-fA-F]{6}$/', $f['colore']) ? $f['colore'] : '';
            $o .= '<a class="pn-fase' . ($on ? ' attiva' : '') . '" href="' . self::h($f['href']) . '"' . ($on ? ' aria-current="true"' : '')
                . ($col !== '' ? ' style="--pn-fase:' . $col . ';"' : '') . '>'
                . (isset($f['numero']) && $f['numero'] !== null ? '<b>' . (int) $f['numero'] . '</b>' : '')
                . '<span>' . self::h($f['testo']) . '</span></a>';
        }

        return $o . '</nav>';
    }

    /** Etichetta di stato: sempre colore + icona + parola. */
    public static function stato(string $testo, string $tono = 'info', string $icona = ''): string
    {
        [$classe, $ico] = self::TONI[$tono] ?? self::TONI['info'];

        return '<span class="pn-stato ' . $classe . '"><i class="fa ' . self::h($icona !== '' ? $icona : $ico) . '" aria-hidden="true"></i>' . self::h($testo) . '</span>';
    }

    /** Una cosa da fare: il numero nel colore del modulo, che cosa, di quale modulo, e dove si va. */
    public static function cosa(int $numero, string $titolo, string $dettaglio, string $href, string $colore = '', bool $avviso = false): string
    {
        $col = preg_match('/^#[0-9a-fA-F]{6}$/', $colore) ? $colore : '';

        return '<a class="pn-cosa' . ($avviso ? ' avviso' : '') . '" href="' . self::h($href) . '"' . ($col !== '' ? ' style="--pn-cosa:' . $col . ';"' : '') . '>'
            . '<span class="pn-cosa-num">' . $numero . '</span>'
            . '<span class="pn-cosa-testo"><strong>' . self::h($titolo) . '</strong>' . ($dettaglio !== '' ? '<small>' . self::h($dettaglio) . '</small>' : '') . '</span>'
            . '<span class="pn-cosa-vai">Apri <i class="fa fa-arrow-right" aria-hidden="true"></i></span></a>';
    }

    /** Elenco vuoto: una frase chiara e, se serve, il pulsante per cominciare. */
    public static function vuoto(string $testo, string $icona = 'fa-inbox', string $azioni = ''): string
    {
        return '<div class="pn-vuoto"><i class="fa ' . self::h($icona) . '" aria-hidden="true"></i><p>' . self::h($testo) . '</p>'
            . ($azioni !== '' ? '<div>' . $azioni . '</div>' : '') . '</div>';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
