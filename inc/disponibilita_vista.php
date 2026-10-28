<?php
// Pezzi di disegno comuni alle pagine della disponibilità dei docenti (la pagina del docente e le due pagine pubbliche).
if (!function_exists('disp_semaforo')) {
    /** Pallino del semaforo con il testo (il colore non è mai l'unica informazione: c'è sempre la parola). */
    function disp_semaforo(array $sem, bool $con_dettaglio = true): string {
        $col = ['verde' => '#15803d', 'arancione' => '#d97706', 'rosso' => '#b91c1c', 'grigio' => '#64748b'][$sem['stato']] ?? '#64748b';
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<span class="d-inline-flex align-items-center gap-2"><span class="disp-pallino" style="background:' . $col . ';" aria-hidden="true"></span><span><strong class="fw-semibold">' . $h($sem['etichetta']) . '</strong>'
            . ($con_dettaglio && $sem['dettaglio'] !== '' ? '<span class="text-secondary"> · ' . $h($sem['dettaglio']) . '</span>' : '') . '</span></span>';
    }
}
if (!function_exists('disp_stile')) {
    function disp_stile(): string {
        return '<style>.disp-pallino{width:14px;height:14px;border-radius:50%;display:inline-block;flex-shrink:0;box-shadow:0 0 0 3px rgba(100,116,139,.15)}
.disp-riga{border:1px solid var(--ui-bordo,#e2e8f0);border-radius:12px;background:var(--ui-fondo,#fff);padding:14px 16px}
.disp-chip{display:inline-block;font-size:.78rem;padding:2px 9px;border-radius:999px;background:rgba(100,116,139,.12);margin:2px 4px 2px 0}
.disp-barra{display:flex;gap:4px}.disp-barra span{width:24px;height:9px;border-radius:2px;background:rgba(100,116,139,.25)}
.disp-legenda span{margin-right:16px;white-space:nowrap}</style>';
    }
}
