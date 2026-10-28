<?php
// Una tessera del cruscotto dell'area personale: l'argomento, una riga di stato, le novità (numero in rosso) e il collegamento alla pagina dell'argomento.
// Si usa dentro la griglia di area_personale.php (<div class="row">): ogni tessera è una colonna.
if (!function_exists('area_tessera')) {
    /**
     * @param string $colore  colore #rrggbb dell'argomento
     * @param int    $novita  quante cose aspettano la persona (0 = niente da segnalare)
     * @param string $novita_et  come si chiamano («da firmare», «da approvare»…)
     * @param list<array{0: string, 1: string, 2?: bool, 3?: bool}> $altri  pulsanti sotto il testo: etichetta, indirizzo, esterno, evidenziato
     */
    function area_tessera(string $icona, string $colore, string $titolo, string $testo, string $href, int $novita = 0, string $novita_et = '', array $altri = []): void
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $esterno = static fn (string $u): bool => (bool) preg_match('~^https?://~i', $u);
        echo '<div class="col-md-6 col-xl-4"><div class="ui-tessera' . ($novita > 0 ? ' ha-novita' : '') . '" style="--tc:' . $h($colore) . ';">';
        echo '<span class="ui-tessera-icona" aria-hidden="true"><i class="fa ' . $h($icona) . '"></i></span><div class="ui-tessera-corpo">';
        echo $href !== ''
            ? '<a href="' . $h($href) . '" class="ui-tessera-titolo stretched-link">' . $h($titolo) . '</a>'
            : '<div class="ui-tessera-titolo">' . $h($titolo) . '</div>';
        if ($testo !== '') {
            echo '<div class="ui-tessera-testo">' . nl2br($h($testo)) . '</div>';
        }
        if ($novita > 0) {
            echo '<span class="ui-novita"><i class="fa fa-bell" aria-hidden="true"></i>' . $novita . ($novita_et !== '' ? ' ' . $h($novita_et) : '') . '</span>';
        }
        if ($altri) {
            echo '<div class="ui-tessera-extra d-flex flex-wrap gap-2 mt-2">';
            foreach ($altri as $a) {
                $est = $a[2] ?? $esterno($a[1]);
                echo '<a href="' . $h($a[1]) . '" class="btn btn-sm fw-bold ' . (!empty($a[3]) ? 'btn-danger' : 'btn-outline-secondary') . '"' . ($est ? ' target="_blank" rel="noopener"' : '') . '>' . $h($a[0])
                    . ($est ? ' <i class="fa fa-arrow-up-right-from-square ms-1" aria-hidden="true"></i>' : '') . '</a>';
            }
            echo '</div>';
        }
        echo '</div></div></div>';
    }
}

if (!function_exists('area_icona')) {
    /**
     * Una piccola tessera «icona» (tre per riga): il simbolo in alto, il nome sotto in piccolo e, se serve, il numero in rosso.
     * Per i servizi che si usano spesso (assistenza, messaggi, calendario, profilo, guide…): la riga di stato sta nel suggerimento al passaggio del mouse.
     */
    function area_icona(string $icona, string $colore, string $titolo, string $href, int $novita = 0, string $nota = ''): void
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        echo '<div class="col-4"><a href="' . $h($href) . '" class="ui-icona' . ($novita > 0 ? ' ha-novita' : '') . '" style="--tc:' . $h($colore) . ';"'
            . ($nota !== '' ? ' title="' . $h($nota) . '"' : '') . '>';
        echo '<span class="ui-icona-simbolo" aria-hidden="true"><i class="fa ' . $h($icona) . '"></i></span><span class="ui-icona-testo">' . $h($titolo) . '</span>';
        if ($novita > 0) {
            echo '<span class="ui-badge-rosso" aria-label="' . $novita . ' da vedere">' . $novita . '</span>';
        }
        echo '</a></div>';
    }
}

if (!function_exists('area_riga')) {
    /**
     * Una riga a tutta larghezza per una postazione di lavoro: icona, titolo e stato a sinistra, i pulsanti d'azione in fila a destra, ognuno con il suo
     * numero in rosso se c'è qualcosa da fare. Con qualcosa da fare il bordo è rosso.
     *
     * @param list<array{0: string, 1: string, 2?: bool, 3?: bool, 4?: int}> $azioni etichetta, indirizzo, esterno, evidenziato, numero
     */
    function area_riga(string $icona, string $colore, string $titolo, string $testo, string $href, int $novita = 0, array $azioni = []): void
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        echo '<div class="col-12"><div class="ui-riga' . ($novita > 0 ? ' ha-novita' : '') . '" style="--tc:' . $h($colore) . ';">';
        echo '<span class="ui-tessera-icona" aria-hidden="true"><i class="fa ' . $h($icona) . '"></i></span>';
        echo '<div class="ui-riga-titolo">' . ($href !== '' ? '<a href="' . $h($href) . '" class="ui-tessera-titolo">' . $h($titolo) . '</a>' : '<span class="ui-tessera-titolo">' . $h($titolo) . '</span>');
        if ($testo !== '') {
            echo '<div class="ui-tessera-testo">' . $h($testo) . '</div>';
        }
        echo '</div>';
        if ($azioni) {
            echo '<div class="ui-riga-azioni">';
            foreach ($azioni as $a) {
                $n = (int) ($a[4] ?? 0);
                echo '<a href="' . $h($a[1]) . '" class="btn btn-sm fw-bold ' . ($n > 0 || !empty($a[3]) ? 'btn-outline-danger' : 'btn-outline-secondary') . '">' . $h($a[0])
                    . ($n > 0 ? ' <span class="ui-badge-rosso ms-1">' . $n . '</span>' : '') . '</a>';
            }
            echo '</div>';
        }
        echo '</div></div>';
    }
}
