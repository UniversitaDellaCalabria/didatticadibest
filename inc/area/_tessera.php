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
