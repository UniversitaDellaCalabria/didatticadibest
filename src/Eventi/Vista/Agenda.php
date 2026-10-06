<?php

declare(strict_types=1);

namespace App\Eventi\Vista;

use App\Eventi\PresentazioneComune;
use App\Portale\Colori;
use App\Portale\Vista\Sezioni as VistaSezioni;

/** HTML dell'agenda: una voce per evento e lo stile comune (home, agenda.php, orientamento.php). */
final class Agenda
{
    public function __construct(private PresentazioneComune $presentazione)
    {
    }

    /** Una riga dell'agenda: data, titolo, area e ambiti, luogo e ora, posti. $posti = riga di get_riepilogo_posti() o null. */
    public function voce(array $e, ?array $posti = null): string
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $mm = [1 => 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
        $gg = ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab'];
        $col = Colori::valido($e['area']['colore_primario'] ?? '', '#0056B3');
        $ts = $e['prossima_data'] ? strtotime($e['prossima_data']) : null;
        $data = $ts ? '<span class="ag-g">' . $gg[(int) date('w', $ts)] . '</span><strong>' . date('j', $ts) . '</strong><span class="ag-m">' . $mm[(int) date('n', $ts)] . '</span>' : '<span class="ag-m">da<br>definire</span>';
        $postiTxt = '';
        if ($posti && $posti['capienza'] > 0) {
            $postiTxt = $posti['liberi'] > 0 ? '<span class="text-success fw-bold">' . $h($this->presentazione->testoPostiLiberi((int) $posti['liberi'])) . '</span>' : '<span class="text-danger fw-bold">Completo</span>';
        }
        $altre = (int) $e['n_turni'] > 1 ? ' · ' . ((int) $e['n_turni'] === 2 ? 'un\'altra data' : ((int) $e['n_turni'] - 1) . ' altre date') : '';

        return '<a class="ag-voce" href="' . $h($e['url']) . '">'
            . '<span class="ag-data" style="--c:' . $col . ';">' . $data . '</span>'
            . '<span class="ag-testo"><span class="ag-titolo">' . $h($e['titolo']) . '</span>'
            . (trim((string) ($e['relatore'] ?? '')) !== '' ? '<span class="ag-info"><i class="fa fa-chalkboard-user me-1" aria-hidden="true"></i>' . $h($e['relatore']) . (trim((string) $e['relatore_ente']) !== '' ? ' (' . $h($e['relatore_ente']) . ')' : '') . '</span>' : '')
            . '<span class="ag-info"><span class="ag-area" style="color:' . $col . ';">' . $h($e['area']['titolo']) . '</span>'
            . ($e['prossimo_orario'] ? ' · ore ' . $h($e['prossimo_orario']) : '') . ($e['luogo'] ? ' · ' . $h($e['luogo']) : '') . $altre . '</span>'
            . '<span class="ag-badge">' . VistaSezioni::badgeAmbiti($e['ambiti']) . ($e['per_scuole'] ? ' <span class="badge rounded-pill text-bg-light border"><i class="fa fa-school me-1" aria-hidden="true"></i>Per le scuole</span>' : '') . ($postiTxt ? ' <span class="small ms-1">' . $postiTxt . '</span>' : '') . '</span></span>'
            . '<i class="fa fa-chevron-right ag-freccia" aria-hidden="true"></i></a>';
    }

    /** Stile comune delle voci dell'agenda (home, agenda.php, orientamento.php). */
    public function css(): string
    {
        return '<style>
.ag-voce { display: flex; gap: 14px; align-items: center; padding: 12px 14px; border-bottom: 1px solid #e5e7eb; text-decoration: none; color: inherit; background: #fff; transition: background .15s; }
.ag-voce:hover, .ag-voce:focus { background: #f8fafc; }
.ag-voce:last-child { border-bottom: 0; }
.ag-data { width: 58px; flex-shrink: 0; text-align: center; border-radius: 10px; padding: 6px 0; background: color-mix(in srgb, var(--c) 10%, white); color: var(--c); line-height: 1.1; }
.ag-data strong { display: block; font-size: 1.45rem; }
.ag-g, .ag-m { display: block; font-size: .7rem; text-transform: uppercase; font-weight: 700; letter-spacing: .03em; }
.ag-testo { flex-grow: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.ag-titolo { font-weight: 700; color: #0f172a; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.ag-info { font-size: .82rem; color: #475569; }
.ag-area { font-weight: 700; }
.ag-badge .badge { font-size: .68rem; font-weight: 600; }
.ag-freccia { color: #94a3b8; }
.ag-filtri { display: flex; flex-wrap: wrap; gap: 6px; }
.ag-filtri a { border: 1px solid #cbd5e1; border-radius: 999px; padding: 5px 14px; font-size: .85rem; font-weight: 600; color: #334155; text-decoration: none; background: #fff; }
.ag-filtri a.attivo { background: #0f172a; color: #fff; border-color: #0f172a; }
.ag-filtri a .n { opacity: .7; font-weight: 400; margin-left: 4px; }
/* Bootstrap Italia aggiunge 48px sotto ogni .card con ::after: nelle liste non serve */
#main-content .card::after { display: none !important; }
</style>';
    }
}
