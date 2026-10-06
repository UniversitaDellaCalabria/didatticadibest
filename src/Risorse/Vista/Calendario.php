<?php

declare(strict_types=1);

namespace App\Risorse\Vista;

use App\Core\Orologio;
use App\Portale\Colori;
use App\Risorse\CalendarioRisorse;
use App\Risorse\Costanti;
use App\Risorse\RisorsaRepository;

/**
 * Vista a calendario delle risorse: giorno e settimana come griglia risorse × ore con le prenotazioni a blocchi colorati,
 * mese come calendario con il numero di prenotazioni per giorno. I nomi di chi ha prenotato si vedono solo ai gestori;
 * agli altri «Prenotato» (e «La tua prenotazione» per le proprie).
 */
final class Calendario
{
    public function __construct(private RisorsaRepository $risorse, private Orologio $orologio)
    {
    }

    /**
     * $risorse: righe di risorse (già filtrate). $o: vista (giorno|settimana|mese), data (Y-m-d), uid (utente collegato o 0),
     * gestore (vede i nomi), url (callable fn(array $cambia): string per navigazione e filtri), url_risorsa (callable fn(int $id, string $data): string),
     * filtri (['tipo' => '', 'capienza' => 0]).
     *
     * @param list<array<string, mixed>> $risorse
     * @param array<string, mixed> $o
     */
    public function html(array $risorse, array $o): string
    {
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $vista = in_array($o['vista'] ?? '', ['giorno', 'settimana', 'mese'], true) ? $o['vista'] : 'settimana';
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($o['data'] ?? '')) ? $o['data'] : $oggi;
        $uid = (int)($o['uid'] ?? 0);
        $gestore = !empty($o['gestore']);
        $url = $o['url'];
        $url_ris = $o['url_risorsa'];
        [$dal, $al] = CalendarioRisorse::periodo($vista, $data);
        $pren = $this->risorse->nelPeriodo(array_map('intval', array_column($risorse, 'id')), $dal, $al);
        $leg = Costanti::LEGENDA_CALENDARIO;
        $mesi = [1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

        // Navigazione: precedente / oggi / successivo e titolo del periodo
        $passo = ['giorno' => '1 day', 'settimana' => '7 days', 'mese' => '1 month'][$vista];
        $prec = date('Y-m-d', strtotime("-$passo", strtotime($vista === 'mese' ? $dal : $data)));
        $succ = date('Y-m-d', strtotime("+$passo", strtotime($vista === 'mese' ? $dal : $data)));
        $titolo = $vista === 'mese' ? ucfirst($mesi[(int)date('n', (int) strtotime($dal))]) . ' ' . date('Y', (int) strtotime($dal))
                : ($dal === $al ? Costanti::GIORNI[(int)date('N', (int) strtotime($dal))] . ' ' . date('d/m/Y', (int) strtotime($dal)) : date('d/m/Y', (int) strtotime($dal)) . ' – ' . date('d/m/Y', (int) strtotime($al)));

        $out = '<div class="cal-ris">'
             . '<div class="cal-ris-barra d-flex flex-wrap align-items-center gap-2 mb-2">'
             . '<div class="btn-group btn-group-sm" role="group" aria-label="Vista">';
        foreach (['giorno' => 'Giorno', 'settimana' => 'Settimana', 'mese' => 'Mese'] as $k => $n) {
            $out .= '<a class="btn ' . ($vista === $k ? 'btn-dark' : 'btn-outline-dark') . '" href="' . $h($url(['vista' => $k, 'data' => $data])) . '"' . ($vista === $k ? ' aria-current="true"' : '') . '>' . $n . '</a>';
        }
        $out .= '</div><div class="d-flex align-items-center gap-1 mx-auto">'
              . '<a class="btn btn-sm btn-light" href="' . $h($url(['vista' => $vista, 'data' => $oggi])) . '" title="Oggi" aria-label="Vai a oggi"><i class="fa fa-house" aria-hidden="true"></i></a>'
              . '<a class="btn btn-sm btn-light" href="' . $h($url(['vista' => $vista, 'data' => $prec])) . '" aria-label="Periodo precedente"><i class="fa fa-arrow-left" aria-hidden="true"></i></a>'
              . '<span class="fw-bold px-2 cal-ris-titolo">' . $h($titolo) . '</span>'
              . '<a class="btn btn-sm btn-light" href="' . $h($url(['vista' => $vista, 'data' => $succ])) . '" aria-label="Periodo successivo"><i class="fa fa-arrow-right" aria-hidden="true"></i></a>'
              . '<form method="GET" class="d-inline m-0 ms-1" onsubmit="return false;"><label class="visually-hidden" for="calRisData">Vai al giorno</label>'
              . '<input type="date" id="calRisData" class="form-control form-control-sm" value="' . $h($data) . '" onchange="location.href=' . $h(json_encode($url(['vista' => $vista, 'data' => '__D__']))) . '.replace(\'__D__\', this.value)"></form>'
              . '</div><button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print me-1" aria-hidden="true"></i>Stampa</button></div>';

        // Filtri: tipo di risorsa e capienza minima
        $f = $o['filtri'] ?? [];
        $out .= '<form method="GET" class="cal-ris-filtri d-flex flex-wrap gap-2 align-items-end mb-2">';
        foreach ($o['campi_nascosti'] ?? [] as $k => $v) {
            $out .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
        }
        $out .= '<input type="hidden" name="vista" value="' . $h($vista) . '"><input type="hidden" name="data" value="' . $h($data) . '">'
              . '<div><label class="form-label small fw-bold mb-0" for="calRisTipo">Tipo di risorsa</label><select class="form-select form-select-sm" id="calRisTipo" name="tipo_ris"><option value="">Tutte</option>';
        foreach (Costanti::TIPI as $k => [$n, $_]) {
            $out .= '<option value="' . $k . '"' . (($f['tipo'] ?? '') === $k ? ' selected' : '') . '>' . $h($n) . '</option>';
        }
        $out .= '</select></div><div><label class="form-label small fw-bold mb-0" for="calRisCap">Capienza minima</label><input type="number" min="0" class="form-control form-control-sm" style="width:110px;" id="calRisCap" name="capienza" value="' . ((int)($f['capienza'] ?? 0) ?: '') . '"></div>'
              . '<button class="btn btn-sm btn-primary fw-bold"><i class="fa fa-filter me-1" aria-hidden="true"></i>Filtra</button>'
              . (!empty($f['tipo']) || !empty($f['capienza']) ? '<a class="btn btn-sm btn-outline-secondary" href="' . $h($url(['vista' => $vista, 'data' => $data, 'tipo_ris' => '', 'capienza' => ''])) . '">Togli i filtri</a>' : '')
              . '</form>';

        // Legenda
        $out .= '<div class="cal-ris-legenda d-flex flex-wrap gap-2 mb-2" aria-label="Legenda">';
        foreach ($leg as $k => [$n, $bg, $fg]) {
            if ($k === 'mia' && !$uid) {
                continue;
            }
            $out .= '<span class="cal-ris-leg" style="background:' . $bg . ';color:' . $fg . ';">' . $n . '</span>';
        }
        $out .= '</div>';

        if (!$risorse) {
            return $out . '<div class="alert alert-light border">Nessuna risorsa con questi filtri.</div></div>';
        }

        if ($vista === 'mese') {
            // Calendario del mese: per ogni giorno quante prenotazioni ci sono (in sospeso a parte)
            $per_giorno = [];
            foreach ($pren as $lista) {
                foreach ($lista as $p) {
                    $g = substr($p['inizio'], 0, 10);
                    $per_giorno[$g]['n'] = ($per_giorno[$g]['n'] ?? 0) + 1;
                    if ($p['stato'] === 'da_approvare') {
                        $per_giorno[$g]['sospese'] = ($per_giorno[$g]['sospese'] ?? 0) + 1;
                    }
                    if ($uid && (int)$p['utente_id'] === $uid) {
                        $per_giorno[$g]['mie'] = ($per_giorno[$g]['mie'] ?? 0) + 1;
                    }
                }
            }
            $out .= '<div class="table-responsive"><table class="table table-bordered cal-ris-mese mb-0"><thead><tr>';
            foreach (Costanti::GIORNI as $n) {
                $out .= '<th scope="col" class="text-center small">' . mb_substr($n, 0, 3) . '</th>';
            }
            $out .= '</tr></thead><tbody><tr>';
            $primo = (int) strtotime($dal);
            $vuoti = (int)date('N', $primo) - 1;
            for ($i = 0; $i < $vuoti; $i++) {
                $out .= '<td class="bg-light"></td>';
            }
            for ($t = $primo, $col = $vuoti; $t <= (int) strtotime($al); $t = (int) strtotime('+1 day', $t), $col++) {
                if ($col > 0 && $col % 7 === 0) {
                    $out .= '</tr><tr>';
                }
                $g = date('Y-m-d', $t);
                $x = $per_giorno[$g] ?? [];
                $out .= '<td class="' . ($g === $oggi ? 'cal-ris-oggi' : '') . '"><a class="d-block text-decoration-none" href="' . $h($url(['vista' => 'giorno', 'data' => $g])) . '">'
                      . '<span class="fw-bold">' . (int)date('j', $t) . '</span>'
                      . (!empty($x['n']) ? '<span class="d-block small text-dark">' . $x['n'] . ' ' . ($x['n'] === 1 ? 'prenotazione' : 'prenotazioni') . '</span>' : '')
                      . (!empty($x['sospese']) ? '<span class="badge" style="background:' . $leg['sospesa'][1] . ';">' . $x['sospese'] . ' in sospeso</span> ' : '')
                      . (!empty($x['mie']) ? '<span class="badge" style="background:' . $leg['mia'][1] . ';">' . $x['mie'] . ' tue</span>' : '')
                      . '</a></td>';
            }
            while ($col % 7 !== 0) {
                $out .= '<td class="bg-light"></td>';
                $col++;
            }
            return $out . '</tr></tbody></table></div></div>';
        }

        // Giorno e settimana: per ogni giorno una griglia risorse × ore
        $adesso = $this->orologio->adesso()->getTimestamp();
        for ($t = (int) strtotime($dal); $t <= (int) strtotime($al); $t = (int) strtotime('+1 day', $t)) {
            $g = date('Y-m-d', $t);
            $gs = (int)date('N', $t);
            // Ore mostrate: dalla prima apertura all'ultima chiusura delle risorse in quel giorno (minimo 8–20)
            $min_h = 8;
            $max_h = 20;
            foreach ($risorse as $r) {
                foreach ($this->risorse->orari((int)$r['id'])[$gs] ?? [] as [$da, $a]) {
                    $min_h = min($min_h, (int)substr($da, 0, 2));
                    $max_h = max($max_h, (int)ceil((strtotime("$g $a") - strtotime("$g 00:00:00")) / 3600));
                }
            }
            $t0 = strtotime("$g " . sprintf('%02d', $min_h) . ':00:00');
            $t1 = strtotime("$g 00:00:00") + $max_h * 3600;
            $span = max(1, $t1 - $t0);
            $pos = fn ($ts) => max(0, min(100, ($ts - $t0) / $span * 100));
            $out .= '<div class="cal-ris-giorno mb-3"><div class="cal-ris-riga cal-ris-testa"><div class="cal-ris-nome' . ($g === $oggi ? ' cal-ris-oggi' : '') . '">'
                  . '<a class="text-reset text-decoration-none" href="' . $h($url(['vista' => 'giorno', 'data' => $g])) . '">' . Costanti::GIORNI[$gs] . ', ' . date('d/m/Y', $t) . '</a></div><div class="cal-ris-ore">';
            for ($o_h = $min_h; $o_h < $max_h; $o_h++) {
                $out .= '<span style="left:' . round($pos(strtotime("$g " . sprintf('%02d', $o_h) . ':00:00')), 3) . '%;">' . sprintf('%02d', $o_h) . ':00</span>';
            }
            $out .= '</div></div>';
            foreach ($risorse as $r) {
                $rid = (int)$r['id'];
                $col_r = Colori::valido($r['colore'] ?? '', '#e2e8f0');
                $out .= '<div class="cal-ris-riga"><div class="cal-ris-nome" style="border-left:5px solid ' . $h($col_r) . ';"><a class="text-reset text-decoration-none" href="' . $h($url_ris($rid, $g)) . '">' . $h($r['nome']) . '</a>'
                      . (!empty($r['capienza']) ? '<span class="d-block text-secondary" style="font-size:.7rem;">' . (int)$r['capienza'] . ' posti</span>' : '') . '</div>';
                $out .= '<div class="cal-ris-linea" data-href="' . $h($url_ris($rid, $g)) . '" title="Prenota ' . $h($r['nome']) . '">';
                // Ore non prenotabili: fuori dagli orari, oppure risorsa chiusa / non attiva
                $chiusa = !(int)($r['attiva'] ?? 1) ? 'Non prenotabile' : $this->risorse->chiusura((int)$r['id'], (int)$r['pagina_id'], $g);
                $fasce = $this->risorse->orari($rid)[$gs] ?? [];
                if ($chiusa !== null || !$fasce) {
                    $out .= '<span class="cal-ris-blocco cal-ris-chiuso" style="left:0;width:100%;" title="' . $h($chiusa ?? 'Chiuso') . '">' . $h($chiusa ?? 'Chiuso') . '</span>';
                } else {
                    $cur = $t0;
                    foreach ($fasce as [$da, $a]) {
                        $ta = strtotime("$g $da");
                        if ($ta > $cur) {
                            $out .= '<span class="cal-ris-blocco cal-ris-chiuso" style="left:' . round($pos($cur), 3) . '%;width:' . round($pos($ta) - $pos($cur), 3) . '%;" aria-hidden="true"></span>';
                        }
                        $cur = max($cur, strtotime("$g $a"));
                    }
                    if ($cur < $t1) {
                        $out .= '<span class="cal-ris-blocco cal-ris-chiuso" style="left:' . round($pos($cur), 3) . '%;width:' . round(100 - $pos($cur), 3) . '%;" aria-hidden="true"></span>';
                    }
                }
                foreach ($pren[$rid] ?? [] as $p) {
                    $pi = max(strtotime($p['inizio']), $t0);
                    $pf = min(strtotime($p['fine']), $t1);
                    if ($pf <= $pi || substr($p['inizio'], 0, 10) > $g || substr($p['fine'], 0, 10) < $g) {
                        continue;
                    }
                    $mia = $uid && (int)$p['utente_id'] === $uid;
                    $tipo = strtotime($p['fine']) < $adesso ? 'passato' : ($mia ? 'mia' : ($p['stato'] === 'da_approvare' ? 'sospesa' : 'occupato'));
                    $chi = $gestore ? mb_strtoupper(trim($p['cognome'] . ' ' . $p['nome'])) : ($mia ? 'La tua prenotazione' : ($p['stato'] === 'da_approvare' ? 'In sospeso' : 'Prenotato'));
                    $ora = date('H:i', strtotime($p['inizio'])) . '–' . date('H:i', strtotime($p['fine']));
                    $tit = $chi . ' · ' . $ora . ($gestore && $p['motivo'] !== '' ? ' · ' . $p['motivo'] : '') . ($p['stato'] === 'da_approvare' ? ' (da approvare)' : '');
                    $out .= '<span class="cal-ris-blocco" style="left:' . round($pos($pi), 3) . '%;width:' . round($pos($pf) - $pos($pi), 3) . '%;background:' . $leg[$tipo][1] . ';color:' . $leg[$tipo][2] . ';" title="' . $h($tit) . '">'
                          . '<span class="visually-hidden">' . $h($r['nome']) . ': </span>' . $h($chi) . '</span>';
                }
                $out .= '</div></div>';
            }
            $out .= '</div>';
        }
        return $out . '</div>';
    }

    /** Stile della vista a calendario (una volta per pagina). */
    public function css(): string
    {
        return '<style>
.cal-ris-leg { display:inline-block; padding:4px 14px; border-radius:6px; border:1px solid #cbd5e1; font-size:.8rem; font-weight:600; }
.cal-ris-giorno { border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; background:#fff; }
.cal-ris-riga { display:grid; grid-template-columns: 200px 1fr; border-top:1px solid #e2e8f0; min-height:42px; }
.cal-ris-testa { border-top:0; background:#e2e8f0; min-height:34px; }
.cal-ris-testa .cal-ris-nome { background:#4f8fd6; color:#fff; font-weight:700; }
.cal-ris-nome { padding:6px 10px; font-size:.82rem; background:#f8fafc; border-right:1px solid #cbd5e1; line-height:1.2; display:flex; flex-direction:column; justify-content:center; }
.cal-ris-nome.cal-ris-oggi { background:#1e3a8a; }
.cal-ris-ore { position:relative; }
.cal-ris-ore span { position:absolute; top:8px; font-size:.72rem; color:#334155; padding-left:3px; border-left:1px solid #94a3b8; height:100%; }
.cal-ris-linea { position:relative; cursor:pointer; background-image: repeating-linear-gradient(90deg, transparent 0, transparent calc(100%/12 - 1px), #e2e8f0 calc(100%/12 - 1px), #e2e8f0 calc(100%/12)); }
.cal-ris-linea:hover { background-color:#f0f9ff; }
.cal-ris-blocco { position:absolute; top:3px; bottom:3px; border-radius:4px; font-size:.72rem; font-weight:600; padding:2px 6px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
.cal-ris-chiuso { background:#dc2626; color:#fff; opacity:.85; top:0; bottom:0; border-radius:0; }
.cal-ris-mese td { height:86px; vertical-align:top; width:14.28%; }
.cal-ris-mese td.cal-ris-oggi { background:#eff6ff; }
@media (max-width: 767.98px) { .cal-ris-riga { grid-template-columns: 110px 1fr; } .cal-ris-ore span:nth-child(odd) { display:none; } }
@media print { .cal-ris-barra, .cal-ris-filtri, nav, header, footer, #sidebar, .navbar { display:none !important; } .cal-ris-giorno { break-inside:avoid; } }
</style>
<script>
document.addEventListener("click", function (e) {
    var l = e.target.closest(".cal-ris-linea"); if (!l || e.target.closest("a")) return;
    if (l.dataset.href) location.href = l.dataset.href;
});
</script>';
    }
}
