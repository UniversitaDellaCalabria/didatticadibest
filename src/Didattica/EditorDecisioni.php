<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Anagrafi\Testi;
use App\Core\Sito;

/** Tabella delle decisioni (convalide o piano di studi) da compilare, nella pratica (referente) e in seduta. */
final class EditorDecisioni
{
    public function __construct(private Sito $sito)
    {
    }

    public function editor(string $tipo_d, array $dec, array $ins_dip): string
    {
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $piano = $tipo_d === 'piano';
        $o = '<div class="table-responsive"><table class="table table-sm table-bordered align-middle small mb-1 bg-white" style="min-width:' . ($piano ? 680 : 1220) . 'px;"><thead class="table-light"><tr>'
           . ($piano ? '<th>Insegnamento richiesto</th><th style="width:80px;">CFU</th><th style="width:110px;">Data sostenimento</th><th style="width:200px;">Decisione</th>'
                     : '<th>Sostenuto dallo studente</th><th style="width:64px;">CFU</th><th style="width:70px;">Voto</th><th style="width:105px;">Data sostenimento</th><th style="min-width:220px;">Convalidato con (anagrafe)</th><th style="width:150px;">Convalida</th><th style="width:70px;">CFU ric.</th><th style="width:70px;">Da integrare</th><th style="width:170px;">Piano di studi</th>')
           . '<th style="width:28px;"></th></tr></thead><tbody>';
        foreach (array_merge($dec['righe'], [[]]) as $r) {
            $o .= '<tr><td><input class="form-control form-control-sm" name="d_richiesto[]" value="' . $h($r['richiesto'] ?? '') . '" aria-label="Insegnamento richiesto">'
                . '<input type="hidden" name="d_ssd[]" value="' . $h($r['ssd'] ?? '') . '">'
                . (!empty($r['ssd']) ? '<div class="text-secondary" style="font-size:.7rem;">' . $h($r['ssd']) . '</div>' : '') . '</td>'
                . '<td><input class="form-control form-control-sm d-cfu" name="d_cfu[]" value="' . $h($r['cfu'] ?? '') . '" aria-label="CFU"></td>';
            $cella_data = '<td><input class="form-control form-control-sm" name="d_data[]" value="' . $h($r['data'] ?? '') . '" maxlength="20" placeholder="gg/mm/aaaa" aria-label="Data sostenimento"></td>';
            if ($piano) {
                $o .= $cella_data;
            }
            if ($piano) {
                $op = '';
                foreach (Costanti::ESITI_PIANO as $k => $n) {
                    $op .= '<option value="' . $k . '"' . (($r['esito'] ?? 'in_piano') === $k ? ' selected' : '') . '>' . $h($n) . '</option>';
                }
                $o .= '<td><select class="form-select form-select-sm" name="d_esito[]" aria-label="Decisione">' . $op . '</select></td>';
            } else {
                $op = '';
                foreach (Costanti::ESITI_CONVALIDA as $k => $n) {
                    $op .= '<option value="' . $k . '"' . (($r['esito'] ?? 'totale') === $k ? ' selected' : '') . '>' . $h($n) . '</option>';
                }
                $ins = !empty($r['ins_id']) && isset($ins_dip[(int)$r['ins_id']]) ? Testi::etichettaInsegnamentoScelta($ins_dip[(int)$r['ins_id']]) : ($r['ins'] ?? '');
                $o .= '<td><input class="form-control form-control-sm" name="d_voto[]" value="' . $h($r['voto'] ?? '') . '" aria-label="Voto"></td>' . $cella_data
                    . '<td><input class="form-control form-control-sm d-ins" name="d_ins[]" value="' . $h($ins) . '" list="dlInsDip" placeholder="Scrivi e scegli" aria-label="Insegnamento convalidato">'
                    . '<input type="hidden" class="d-ins-cfu" name="d_ins_cfu[]" value="' . $h($r['ins_cfu'] ?? '') . '"></td>'
                    . '<td><select class="form-select form-select-sm d-esito" name="d_esito[]" aria-label="Convalida">' . $op . '</select></td>'
                    . '<td><input class="form-control form-control-sm d-ric" name="d_cfu_ric[]" value="' . $h($r['cfu_ric'] ?? '') . '" aria-label="CFU riconosciuti"></td>'
                    . '<td><input class="form-control form-control-sm d-int" name="d_cfu_int[]" value="' . $h($r['cfu_int'] ?? '') . '" aria-label="CFU da integrare"></td>'
                    . '<td><select class="form-select form-select-sm d-piano mb-1" name="d_piano[]" aria-label="Nel piano come a scelta"><option value="">Non richiesto</option><option value="1"' . (!empty($r['piano']) ? ' selected' : '') . '>A scelta nel piano</option></select>'
                    . '<input class="form-control form-control-sm d-elimina" name="d_elimina[]" value="' . $h($r['elimina'] ?? '') . '" list="dlInsDip" placeholder="da eliminare (facolt.)" aria-label="Insegnamento del piano da eliminare"' . (empty($r['piano']) ? ' hidden' : '') . '></td>';
            }
            $o .= '<td><button type="button" class="btn btn-sm btn-link text-danger p-0 d-togli" aria-label="Togli la riga"><i class="fa fa-times" aria-hidden="true"></i></button></td></tr>';
        }
        $o .= '</tbody></table></div><button type="button" class="btn btn-sm btn-outline-secondary py-0 mb-2 d-aggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Riga</button>';
        if (!$piano) {
            $o .= '<div class="form-text mb-2">Convalida totale: CFU riconosciuti = CFU dell\'insegnamento del Dipartimento. Parziale: indica i CFU riconosciuti, quelli da integrare si calcolano. Lasciando vuoti i CFU li completa il portale. «Piano di studi»: richiesta dello studente di inserire l\'insegnamento convalidato come a scelta (ed eventualmente eliminarne uno): va nel verbale.</div>';
        }
        return $o;
    }

    public function supporto(array $ins_dip): string
    {
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $dl = '<datalist id="dlInsDip">';
        $mappa = [];
        foreach ($ins_dip as $i) {
            $e = Testi::etichettaInsegnamentoScelta($i);
            $dl .= '<option value="' . $h($e) . '">';
            $mappa[$e] = $i['cfu'];
        }
        $base = rtrim((string)parse_url($this->sito->urlBase(), PHP_URL_PATH), '/');
        $v = @filemtime($this->sito->radice() . '/assets/js/decisioni-pratica.js') ?: 1;
        return $dl . '</datalist><script type="application/json" id="cfuInsDip">' . json_encode($mappa, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
             . '<script src="' . $h($base) . '/assets/js/decisioni-pratica.js?v=' . $v . '"></script>';
    }

    public static function valoreIniziale(array $c, ?array $u): string
    {
        if (!$u) {
            return '';
        }
        $e = mb_strtolower($c['etichetta']);
        if ($c['tipo'] === 'codice_fiscale') {
            return strtoupper((string)($u['codice_fiscale'] ?? ''));
        }
        if (preg_match('/^matricola/u', $e)) {
            return (string)(($u['matricola_studente'] ?? '') ?: ($u['matricola'] ?? ''));
        }
        if ($c['tipo'] === 'tel' && preg_match('/cellulare|telefono/u', $e)) {
            return (string)($u['telefono'] ?? '');
        }
        return '';
    }
}
