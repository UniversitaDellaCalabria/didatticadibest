<?php

declare(strict_types=1);

namespace App\Iscrizioni\Vista;

use App\Iscrizioni\CampiAnagrafe;
use App\Iscrizioni\RegoleFsl;

/**
 * Campi del Form Builder per l'amministratore (prenotazione manuale e modifica degli iscritti).
 * Le condizioni «mostra se» sono ignorate: in admin si vede tutto, niente campi obbligatori.
 * Gli allegati non si caricano da qui: si mostrano i link a quelli esistenti.
 */
final class CampiFormAdmin
{
    public function __construct(private RegoleFsl $fsl, private CampiAnagrafe $anagrafe)
    {
    }

    /**
     * @param list<array<string, string|null>> $campi righe di campi_form, nell'ordine del modulo
     * @param array<string, mixed>|null $dettagli scheda del progetto
     * @param array{min: int, max: int|null} $limiti limiti dei partecipanti del turno o del progetto
     * @param array<string, mixed> $valori dati_custom_json già salvati
     */
    public function html(array $campi, bool $eProgetto, ?array $dettagli, array $limiti, array $valori, string $prefisso): string
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $out = '';
        foreach ($campi as $cf) {
            if (!$this->fsl->campoFormVisibile($cf, $eProgetto, $dettagli)) {
                continue;
            }
            $tipo = $cf['tipo_campo'];
            $nome = (string) $cf['nome_campo'];
            $name = 'custom_' . $nome;
            $val = (string) ($valori[$nome] ?? '');
            $id = $prefisso . '_' . (int) $cf['id'];
            $etichetta = $nome === CAMPO_PARTECIPANTI ? 'Numero di studenti partecipanti' : $cf['etichetta'];
            $opts = !empty($cf['opzioni_select']) ? array_map('trim', explode(',', $cf['opzioni_select'])) : [];
            if ($tipo === 'hidden') {
                $out .= '<input type="hidden" name="' . $h($name) . '" value="' . $h($val !== '' ? $val : ($opts[0] ?? '')) . '">';
                continue;
            }
            if ($tipo === 'separator') {
                $out .= '<div class="col-12"><hr class="my-1">' . ($etichetta !== '-' ? '<div class="small fw-bold text-uppercase text-secondary">' . $h($etichetta) . '</div>' : '') . '</div>';
                continue;
            }
            $col = in_array($tipo, ['textarea', 'checkboxes', 'radio'], true) ? 'col-12' : 'col-md-6';
            $out .= '<div class="' . $col . '"><label class="form-label small fw-bold mb-1" for="' . $id . '">' . $h($etichetta) . '</label>';
            if ($tipo === 'file') {
                if ($val !== '') {
                    $link = [];
                    foreach (array_filter(array_map('trim', explode(',', $val))) as $i => $path) {
                        $link[] = '<a href="../' . $h($path) . '" target="_blank">Allegato ' . ($i + 1) . '</a>';
                    }
                    $out .= '<div class="small p-2 border rounded bg-light">' . implode(' · ', $link) . '</div>';
                } else {
                    $out .= '<div class="small text-muted p-2 border rounded bg-light">Nessun allegato (si carica solo dal modulo pubblico)</div>';
                }
            } elseif ($tipo === 'select' || $tipo === 'radio') {
                $out .= '<select name="' . $h($name) . '" id="' . $id . '" class="form-select form-select-sm"><option value="">--</option>';
                foreach ($opts as $o) {
                    $out .= '<option value="' . $h($o) . '"' . ($o === $val ? ' selected' : '') . '>' . $h($o) . '</option>';
                }
                $out .= '</select>';
            } elseif ($tipo === 'checkboxes') {
                $sel = array_map('trim', explode(',', $val));
                foreach ($opts as $k => $o) {
                    $out .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="' . $h($name) . '[]" id="' . $id . '_' . $k . '" value="' . $h($o) . '"' . (in_array($o, $sel, true) ? ' checked' : '') . '><label class="form-check-label small" for="' . $id . '_' . $k . '">' . $h($o) . '</label></div>';
                }
            } elseif ($tipo === 'checkbox') {
                $out .= '<div class="form-check"><input type="hidden" name="' . $h($name) . '" value=""><input class="form-check-input" type="checkbox" name="' . $h($name) . '" id="' . $id . '" value="Sì"' . ($val !== '' ? ' checked' : '') . '><label class="form-check-label small" for="' . $id . '">Sì</label></div>';
            } elseif ($tipo === 'textarea') {
                $out .= '<textarea name="' . $h($name) . '" id="' . $id . '" class="form-control form-control-sm" rows="2">' . $h($val) . '</textarea>';
            } elseif ($tipo === 'scuola') {
                $out .= $this->anagrafe->campoScuola($nome, $val, (string) ($valori['__scuola_codice'] ?? ''));
            } elseif ($tipo === 'corso_studio') {
                $out .= $this->anagrafe->campoCorso($nome, $val, '', 'form-select form-select-sm', $id);
            } else {
                $htmlTipo = in_array($tipo, ['number', 'email', 'tel', 'url', 'date', 'time'], true) ? $tipo : 'text';
                $extra = '';
                if ($nome === CAMPO_PARTECIPANTI) {
                    $extra = ' min="' . $limiti['min'] . '"' . ($limiti['max'] ? ' max="' . $limiti['max'] . '"' : '') . ' required';
                }
                if ($tipo === 'rating') {
                    $htmlTipo = 'number';
                    $extra = ' min="1" max="' . max(5, count($opts)) . '"';
                }
                $out .= '<input type="' . $htmlTipo . '" name="' . $h($name) . '" id="' . $id . '" class="form-control form-control-sm" value="' . $h($val) . '"' . $extra . '>';
            }
            $out .= '</div>';
        }

        return $out;
    }
}
