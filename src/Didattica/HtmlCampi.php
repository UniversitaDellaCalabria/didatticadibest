<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Anagrafi\Anagrafe;
use App\Core\Orologio;
use App\Core\Sito;

/** HTML dei campi dei moduli online (modulo dello studente e istruttoria dell'ufficio), delle celle delle tabelle e delle risposte. */
final class HtmlCampi
{
    public function __construct(private ScelteAnagrafe $scelte, private Sito $sito, private Orologio $orologio)
    {
    }

    /** Anno accademico in corso (primo anno, es. 2025 per il 2025/2026). */
    public function annoCorrente(): int
    {
        return Anagrafe::annoAccademico($this->orologio->adesso());
    }

    /**
     * «2025/2026» ecc.: dall'anno precedente a quello successivo all'anno in corso.
     *
     * @return list<string>
     */
    public function anniAccademici(): array
    {
        $a = $this->annoCorrente();
        $out = [];
        for ($x = $a + 1; $x >= $a - 3; $x--) {
            $out[] = $x . '/' . ($x + 1);
        }

        return $out;
    }

    /** Righe e logica dei campi (data-cond, data-auto) lette da assets/js/campi-pratica.js: lo script si carica una volta per pagina. */
    public function scriptTabelle(): string
    {
        $base = rtrim((string) parse_url($this->sito->urlBase(), PHP_URL_PATH), '/');
        $v = @filemtime($this->sito->radice() . '/assets/js/campi-pratica.js') ?: 1;

        return '<script src="' . htmlspecialchars($base, ENT_QUOTES) . '/assets/js/campi-pratica.js?v=' . $v . '" data-base="' . htmlspecialchars($base, ENT_QUOTES) . '"></script>';
    }

    /**
     * Valore da rimettere nel campo dopo un errore (le tabelle tornano come righe, le scelte multiple come testo).
     *
     * @param array<string, mixed> $c
     * @param array<string, mixed> $post
     */
    public function valoriPostCampo(array $c, array $post): mixed
    {
        $v = $post['campo_' . $c['nome']] ?? '';
        if ($c['tipo'] === 'multicheck') {
            return implode(', ', array_map('strval', (array) $v));
        }
        if ($c['tipo'] !== 'tabella') {
            return is_array($v) ? '' : (string) $v;
        }
        $righe = [];
        foreach ((is_array($v) ? $v : []) as $j => $col) {
            foreach ((array) $col as $i => $x) {
                $righe[$i][$j] = (string) $x;
            }
        }

        return $righe;
    }

    public function datalist(array $campi): string
    {
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $serve = ['insegnamento' => false, 'docente' => false];
        foreach ($campi as $c) {
            if (isset($serve[$c['tipo']])) {
                $serve[$c['tipo']] = true;
            }
            if ($c['tipo'] === 'tabella') {
                foreach ($c['colonne'] ?: CampiModulo::colonne($c['opzioni']) as $col) {
                    if (in_array($col['tipo'], ['insegnamento', 'insegnamento_dip'], true)) {
                        $serve['insegnamento'] = true;
                    }
                    if ($col['tipo'] === 'docente') {
                        $serve['docente'] = true;
                    }
                }
            }
        }
        $out = '';
        foreach ($serve as $tipo => $si) {
            if (!$si) {
                continue;
            }
            $out .= '<datalist id="dl_' . $tipo . '">';
            foreach ($this->scelte->per($tipo) as $v) {
                $out .= '<option value="' . $h($v) . '">';
            }
            $out .= '</datalist>';
        }
        return $out;
    }

    public function campo(array $c, mixed $valore = '', bool $conAnagrafe = false, bool $griglia = false): string
    {
        $attr = ' data-nome="' . $c['nome'] . '" data-tipo="' . htmlspecialchars($c['tipo'], ENT_QUOTES) . '"';
        foreach (['cond', 'auto'] as $k) {
            if (!empty($c[$k])) {
                $attr .= ' data-' . $k . '="' . htmlspecialchars((string) json_encode($c[$k], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        $breve = in_array($c['tipo'], ['text', 'email', 'tel', 'number', 'date', 'time', 'url', 'codice_fiscale', 'select', 'anno_accademico', 'corso_studio', 'docente', 'insegnamento'], true)
              || ($c['tipo'] === 'radio' && count($c['opzioni']) <= 3);
        return '<div class="campo-pratica' . ($griglia ? ($breve ? ' col-md-6' : ' col-12') : '') . '"' . $attr . '>' . $this->interno($c, $valore, $conAnagrafe) . '</div>';
    }

    public function interno(array $c, mixed $valore, bool $conAnagrafe): string
    {
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $id = 'mc_' . $c['nome'];
        $name = 'campo_' . $c['nome'];
        $req = $c['obbligatorio'] ? ' required' : '';
        $stella = $c['obbligatorio'] ? ' <span class="text-danger">*</span>' : '';
        $et = '<label class="form-label fw-bold" for="' . $id . '">' . $h($c['etichetta']) . $stella . '</label>';
        $aiuto = $c['aiuto'] !== '' ? '<div class="form-text">' . nl2br($h($c['aiuto'])) . '</div>' : '';
        $testo = is_array($valore) ? '' : (string)$valore;
        switch ($c['tipo']) {
            case 'titolo': return '<h2 class="h5 fw-bold mt-4 mb-2 pb-1 border-bottom">' . $h($c['etichetta']) . '</h2>' . ($c['aiuto'] !== '' ? '<p class="small text-secondary">' . nl2br($h($c['aiuto'])) . '</p>' : '');
            case 'info': return '<div class="alert alert-light border small mb-3"><strong>' . $h($c['etichetta']) . '</strong>' . ($c['aiuto'] !== '' ? '<div class="mt-1">' . nl2br($h($c['aiuto'])) . '</div>' : '') . '</div>';
            case 'textarea': return '<div class="mb-3">' . $et . '<textarea class="form-control" id="' . $id . '" name="' . $name . '" rows="4"' . $req . '>' . $h($testo) . '</textarea>' . $aiuto . '</div>';
            case 'select':
                $o = '<option value="">--</option>';
                foreach ($c['opzioni'] as $op) {
                    $o .= '<option' . ($op === $testo ? ' selected' : '') . '>' . $h($op) . '</option>';
                }
                return '<div class="mb-3">' . $et . '<select class="form-select" id="' . $id . '" name="' . $name . '"' . $req . '>' . $o . '</select>' . $aiuto . '</div>';
            case 'multicheck':
                $sel = array_map('trim', explode(',', $testo));
                $o = '';
                foreach ($c['opzioni'] as $i => $op) {
                    $o .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="' . $name . '[]" id="' . $id . '_' . $i . '" value="' . $h($op) . '"' . (in_array($op, $sel, true) ? ' checked' : '') . '><label class="form-check-label" for="' . $id . '_' . $i . '">' . $h($op) . '</label></div>';
                }
                return '<fieldset class="mb-3"' . ($c['obbligatorio'] ? ' data-almeno-uno="1"' : '') . '><legend class="form-label fw-bold fs-6">' . $h($c['etichetta']) . $stella . '</legend>' . $o . $aiuto . '</fieldset>';
            case 'corso_studio':
                $gruppi = $conAnagrafe ? $this->scelte->per('corso_studio') : [];
                if (!$gruppi) {
                    return '<div class="mb-3">' . $et . '<input type="text" class="form-control" id="' . $id . '" name="' . $name . '" value="' . $h($testo) . '"' . $req . '>' . $aiuto . '</div>';
                }
                $o = '<option value="">-- scegli il corso --</option>';
                foreach ($gruppi as $g => $corsi) {
                    $o .= '<optgroup label="' . $h($g) . '">';
                    foreach ($corsi as $n) {
                        $o .= '<option' . ($n === $testo ? ' selected' : '') . '>' . $h($n) . '</option>';
                    }
                    $o .= '</optgroup>';
                }
                return '<div class="mb-3">' . $et . '<select class="form-select" id="' . $id . '" name="' . $name . '"' . $req . '>' . $o . '</select>' . $aiuto . '</div>';
            case 'anno_accademico':
                $o = '<option value="">--</option>';
                $sel = $testo !== '' ? $testo : $this->annoCorrente() . '/' . ($this->annoCorrente() + 1);
                foreach ($this->anniAccademici() as $a) {
                    $o .= '<option' . ($a === $sel ? ' selected' : '') . '>' . $a . '</option>';
                }
                return '<div class="mb-3">' . $et . '<select class="form-select" id="' . $id . '" name="' . $name . '"' . $req . ' style="max-width:220px;">' . $o . '</select>' . $aiuto . '</div>';
            case 'insegnamento':
            case 'docente':
                $ph = $c['tipo'] === 'docente' ? 'Scrivi il cognome e scegli dall\'elenco' : 'Scrivi il nome e scegli dall\'elenco';
                return '<div class="mb-3">' . $et . '<input type="text" class="form-control" id="' . $id . '" name="' . $name . '" value="' . $h($testo) . '" list="dl_' . $c['tipo'] . '" autocomplete="off" placeholder="' . $ph . '"' . $req . '>' . $aiuto . '</div>';
            case 'insegnamento_ateneo':
                // Testo scrivibile a mano + scelta guidata dal catalogo di Ateneo (tipo di corso, corso, a.a. di offerta, insegnamento)
                return '<div class="mb-3">' . $et . '<div class="input-group"><input type="text" class="form-control ins-testo" id="' . $id . '" name="' . $name . '" value="' . $h($testo) . '" placeholder="Scegli dal catalogo o scrivi l\'insegnamento" autocomplete="off"' . $req . '>'
                     . '<button type="button" class="btn btn-outline-primary ins-scegli" data-bersaglio="' . $id . '"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Scegli</button></div>'
                     . '<input type="hidden" name="' . $name . '_meta" class="ins-meta" value="">'
                     . '<div class="form-text">Scegli tipo di corso, anno accademico di offerta e corso di studio; se l\'insegnamento non è in elenco scrivilo a mano.' . ($c['aiuto'] !== '' ? ' ' . $h($c['aiuto']) : '') . '</div></div>';
            case 'tabella':
                $cols = $c['colonne'] ?: CampiModulo::colonne($c['opzioni']);
                $righe = is_array($valore) ? $valore : [];
                $righe = array_merge($righe, array_fill(0, max(0, 3 - count($righe)), []));
                $th = '';
                foreach ($cols as $col) {
                    $th .= '<th scope="col" class="small">' . $h($col['nome']) . '</th>';
                }
                $tr = '';
                foreach ($righe as $riga) {
                    $tr .= '<tr>';
                    foreach ($cols as $j => $col) {
                        $tr .= '<td>' . $this->cella($col, $name . '[' . $j . '][]', (string)($riga[$j] ?? '')) . '</td>';
                    }
                    $tr .= '<td><button type="button" class="btn btn-sm btn-link text-danger p-0 tab-togli" aria-label="Togli la riga"><i class="fa fa-times" aria-hidden="true"></i></button></td></tr>';
                }
                return '<fieldset class="mb-3"><legend class="form-label fw-bold fs-6 mb-1">' . $h($c['etichetta']) . $stella . '</legend>' . $aiuto
                     . '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-1 tab-righe"><thead class="table-light"><tr>' . $th . '<th style="width:28px;"><span class="visually-hidden">Azioni</span></th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
                     . '<button type="button" class="btn btn-sm btn-outline-secondary tab-aggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi riga</button>'
                     . (in_array('insegnamento', array_column($cols, 'tipo'), true) ? ' <span class="small text-secondary ms-1"><i class="fa fa-magnifying-glass" aria-hidden="true"></i> sceglie l\'insegnamento dal catalogo di Ateneo e compila CFU e S.S.D.</span>' : '') . '</fieldset>';
            case 'radio':
                $o = '';
                foreach ($c['opzioni'] as $i => $op) {
                    $o .= '<div class="form-check"><input class="form-check-input" type="radio" name="' . $name . '" id="' . $id . '_' . $i . '" value="' . $h($op) . '"' . ($op === $testo ? ' checked' : '') . ($i === 0 ? $req : '') . '><label class="form-check-label" for="' . $id . '_' . $i . '">' . $h($op) . '</label></div>';
                }
                return '<fieldset class="mb-3"><legend class="form-label fw-bold fs-6">' . $h($c['etichetta']) . $stella . '</legend>' . $o . $aiuto . '</fieldset>';
            case 'checkbox': return '<div class="mb-3 form-check"><input class="form-check-input" type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . ($testo !== '' ? ' checked' : '') . $req . '><label class="form-check-label fw-bold" for="' . $id . '">' . $h($c['etichetta']) . $stella . '</label>' . $aiuto . '</div>';
            case 'dichiarazione':
                return '<div class="mb-3 p-2 border rounded bg-light">' . ($c['aiuto'] !== '' ? '<div class="small mb-2">' . nl2br($h($c['aiuto'])) . '</div>' : '')
                     . '<div class="form-check"><input class="form-check-input" type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . ($testo !== '' ? ' checked' : '') . $req . '><label class="form-check-label fw-bold" for="' . $id . '">' . $h($c['etichetta']) . $stella . '</label></div></div>';
            case 'file': return '<div class="mb-3">' . $et . '<input type="file" class="form-control" id="' . $id . '" name="' . $name . '" accept=".pdf,.jpg,.jpeg,.png,.p7m"' . $req . '><div class="form-text">PDF, JPG o PNG, fino a 10 MB.' . ($c['aiuto'] !== '' ? ' ' . $h($c['aiuto']) : '') . '</div></div>';
            case 'codice_fiscale':
                return '<div class="mb-3">' . $et . '<input type="text" class="form-control text-uppercase font-monospace" id="' . $id . '" name="' . $name . '" value="' . $h($testo) . '" maxlength="16" pattern="[A-Za-z0-9]{16}|[0-9]{11}" autocomplete="off" style="max-width:260px;"' . $req . '>' . $aiuto . '</div>';
            default:
                $tipo = in_array($c['tipo'], ['email', 'tel', 'number', 'date', 'time', 'url'], true) ? $c['tipo'] : 'text';
                return '<div class="mb-3">' . $et . '<input type="' . $tipo . '" class="form-control" id="' . $id . '" name="' . $name . '" value="' . $h($testo) . '"' . ($tipo === 'number' ? ' step="any"' : '') . $req . '>' . $aiuto . '</div>';
        }
    }

    public function cella(array $col, string $name, string $v): string
    {
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $resto = ' aria-label="' . $h($col['nome']) . '" data-col="' . $h($col['tipo']) . '" autocomplete="off"';
        $base = ' name="' . $name . '"' . $resto;
        $nv = fn ($x) => ' name="' . $name . '" value="' . $h($x) . '"' . $resto;
        switch ($col['tipo']) {
            case 'insegnamento':
                return '<div class="input-group input-group-sm flex-nowrap"><input type="text" class="form-control form-control-sm"' . $nv($v) . ' list="dl_insegnamento" style="min-width:260px;">'
                     . '<button type="button" class="btn btn-outline-primary ins-scegli" title="Scegli dal catalogo di Ateneo"><i class="fa fa-magnifying-glass" aria-hidden="true"></i><span class="visually-hidden">Scegli dal catalogo</span></button></div>';
            case 'insegnamento_dip': return '<input type="text" class="form-control form-control-sm"' . $nv($v) . ' list="dl_insegnamento" style="min-width:160px;">';
            case 'docente': return '<input type="text" class="form-control form-control-sm"' . $nv($v) . ' list="dl_docente">';
            case 'cfu': case 'numero': return '<input type="number" step="any" min="0" class="form-control form-control-sm"' . $nv(str_replace(',', '.', $v)) . ' style="min-width:60px;max-width:90px;">';
            case 'voto': return '<input type="text" class="form-control form-control-sm"' . $nv($v) . ' placeholder="es. 28/30" maxlength="20" style="min-width:80px;max-width:110px;">';
            case 'data':
                $iso = preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m) ? "$m[3]-$m[2]-$m[1]" : $v;
                return '<input type="date" class="form-control form-control-sm"' . $nv($iso) . '>';
            case 'anno_accademico':
                $o = '<option value=""></option>';
                foreach ($this->anniAccademici() as $a) {
                    $o .= '<option' . ($a === $v ? ' selected' : '') . '>' . $a . '</option>';
                }
                return '<select class="form-select form-select-sm"' . $base . '>' . $o . '</select>';
            case 'scelta':
                $o = '<option value=""></option>';
                foreach ($col['scelte'] as $s) {
                    $o .= '<option' . ($s === $v ? ' selected' : '') . '>' . $h($s) . '</option>';
                }
                return '<select class="form-select form-select-sm"' . $base . '>' . $o . '</select>';
            case 'piano':
                // Casella «a scelta»: chi la spunta dice anche se c'è un insegnamento del piano da eliminare (assets/js/campi-pratica.js).
                // Il valore sta nel campo nascosto: "A scelta" oppure "A scelta; elimina: <insegnamento>"
                [$si, $elim] = CampiModulo::valorePiano($v);
                return '<div class="piano-cella" style="min-width:150px;"><input type="hidden" class="piano-val"' . $nv($si ? $v : '') . '>'
                     . '<label class="form-check small m-0 text-nowrap"><input type="checkbox" class="form-check-input piano-check" value=""' . ($si ? ' checked' : '') . '> a scelta</label>'
                     . '<div class="piano-elimina small text-secondary"' . ($elim === '' ? ' hidden' : '') . '>elimina: <span>' . $h($elim) . '</span></div></div>';
            case 'codice': return '<input type="text" class="form-control form-control-sm"' . $nv($v) . ' maxlength="30" style="min-width:80px;">';
            case 'denominazione': return '<input type="text" class="form-control form-control-sm"' . $nv($v) . ' style="min-width:240px;">';
            default: return '<input type="text" class="form-control form-control-sm"' . $nv($v) . '' . ($col['tipo'] === 'ssd' ? ' maxlength="20" style="min-width:80px;"' : '') . '>';
        }
    }

    public function risposta(array $r): string
    {
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        if (($r['tipo'] ?? '') === 'tabella') {
            if (empty($r['righe'])) {
                return '—';
            }
            $o = '<div class="table-responsive"><table class="table table-sm table-bordered small mb-0"><thead class="table-light"><tr>';
            foreach ($r['colonne'] ?? [] as $c) {
                $o .= '<th>' . $h($c) . '</th>';
            }
            $o .= '</tr></thead><tbody>';
            foreach ($r['righe'] as $riga) {
                $o .= '<tr>';
                foreach ($riga as $x) {
                    $o .= '<td>' . $h($x) . '</td>';
                } $o .= '</tr>';
            }
            return $o . '</tbody></table></div>';
        }
        $txt = ($r['valore'] ?? '') !== '' ? $r['valore'] : '—';
        if (($r['tipo'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $txt)) {
            $txt = date('d/m/Y', strtotime($txt));
        }
        return nl2br($h($txt)) . (!empty($r['meta']['corso']) ? '<div class="text-secondary small">' . $h($r['meta']['corso'] . ($r['meta']['aa'] !== '' ? ' · a.a. di offerta ' . $r['meta']['aa'] : '')) . '</div>' : '');
    }
}
