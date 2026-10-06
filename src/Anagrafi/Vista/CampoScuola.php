<?php

declare(strict_types=1);

namespace App\Anagrafi\Vista;

/** Campo «Scuola» dei moduli con ricerca nell'anagrafe del Ministero (stesso output di prima). */
final class CampoScuola
{
    /**
     * Testo visibile (name=custom_<campo>) + codice nascosto (name=scuola_codice[<campo>]). Se non si sceglie dall'elenco resta
     * il testo scritto a mano. Il comportamento è in assets/js/campo-scuola.js; $endpoint è l'indirizzo di cerca_scuole.php.
     */
    public static function html(string $endpoint, string $campo, string $valore, string $codice, string $attr, string $classi, string $id): string
    {
        $h = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        return '<div class="scuola-campo position-relative" data-endpoint="' . $h($endpoint) . '">'
             . '<input type="text" name="custom_' . $h($campo) . '" id="' . $id . '" class="' . $h($classi) . ' scuola-testo" value="' . $h($valore) . '" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . $id . '_lista" placeholder="Clicca per scegliere la scuola" ' . $attr . '>'
             . '<input type="hidden" name="scuola_codice[' . $h($campo) . ']" class="scuola-codice" value="' . $h($codice) . '">'
             . '<ul class="scuola-lista list-group position-absolute w-100 shadow" id="' . $id . '_lista" role="listbox" style="z-index:1080;display:none;max-height:260px;overflow-y:auto;"></ul>'
             . '<div class="form-text scuola-stato">' . ($codice !== '' ? '<i class="fa fa-circle-check text-success me-1"></i>Scuola dall\'anagrafe del Ministero' : 'Clicca nel campo: scegli regione, provincia e comune, poi la scuola. Se non è in elenco potrai scriverla a mano.') . '</div>'
             . '</div>';
    }
}
