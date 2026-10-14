<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Infrastructure\Documenti\Excel;

/**
 * Il cruscotto del responsabile di una risorsa: prenotazioni e utilizzo in un periodo, fasce più richieste, chi la usa di più, quantità consumate dei
 * contatori, km e consumi del diario di bordo e scadenze. Lo vedono i responsabili di quella risorsa e gli amministratori.
 */
final class CruscottoRisorsa
{
    public const PERIODI = ['30' => 'Ultimi 30 giorni', '90' => 'Ultimi 90 giorni', 'anno' => 'Quest\'anno', '365' => 'Ultimi 12 mesi', 'altro' => 'Dal… al…'];

    public function __construct(
        private RisorsaRepository $risorse,
        private ContatoreRisorsaRepository $contatori,
        private DiarioBordoRepository $diario,
        private ServizioScadenzeRisorsa $scadenze,
        private ResponsabileRisorsaRepository $responsabili
    ) {
    }

    /**
     * Può vedere il cruscotto: responsabile della risorsa o amministratore.
     *
     * @param array<string, mixed>|null $utente
     */
    public function puoVedere(?array $utente, int $risorsaId): bool
    {
        if (!$utente || empty($utente['id'])) {
            return false;
        }
        if ((int) ($utente['ruolo_id'] ?? 5) === 1 || in_array('1', explode(',', (string) ($utente['ruoli_secondari'] ?? '')), true)) {
            return true;
        }
        $email = strtolower(trim((string) ($utente['email'] ?? '')));

        return $email !== '' && $this->responsabili->perEmail($risorsaId, $email) !== null;
    }

    /**
     * Il periodo scelto (dal, al, etichetta). «altro» usa le date indicate; un periodo non valido torna agli ultimi 30 giorni.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function periodo(string $scelta, ?string $dal = null, ?string $al = null, ?string $oggi = null): array
    {
        $oggi ??= date('Y-m-d');
        if ($scelta === 'altro' && $dal && $al && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dal) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $al) && $dal <= $al) {
            return [$dal, min($al, date('Y-m-d', strtotime($dal . ' +366 days'))), 'Dal ' . date('d/m/Y', (int) strtotime($dal)) . ' al ' . date('d/m/Y', (int) strtotime($al))];
        }
        if ($scelta === 'anno') {
            return [date('Y-01-01', (int) strtotime($oggi)), $oggi, 'Quest\'anno'];
        }
        $n = in_array($scelta, ['90', '365'], true) ? (int) $scelta : 30;

        return [date('Y-m-d', strtotime($oggi . " -$n days")), $oggi, self::PERIODI[(string) $n]];
    }

    /**
     * Tutti i numeri del cruscotto per la risorsa nel periodo.
     *
     * @param array<string, mixed> $risorsa
     * @return array<string, mixed>
     */
    public function dati(array $risorsa, string $dal, string $al): array
    {
        $rid = (int) $risorsa['id'];
        $pren = $this->risorse->prenotazioniDellaRisorsa($rid, $dal, $al);
        $n = ['totale' => count($pren), 'confermata' => 0, 'da_approvare' => 0, 'annullata' => 0, 'rifiutata' => 0];
        $ore = 0.0;
        $fasce = [];
        $utenti = [];
        $mesi = [];
        foreach ($pren as $p) {
            $n[$p['stato']] = ($n[$p['stato']] ?? 0) + 1;
            if ($p['stato'] !== 'confermata') {
                continue;
            }
            $i = (int) strtotime((string) $p['inizio']);
            $f = (int) strtotime((string) $p['fine']);
            $h = max(0, ($f - $i) / 3600);
            $ore += $h;
            for ($t = $i; $t < $f; $t += 3600) {
                $fasce[(int) date('N', $t)][(int) date('G', $t)] = ($fasce[(int) date('N', $t)][(int) date('G', $t)] ?? 0) + 1;
            }
            $chi = strtolower((string) ($p['email'] ?? '')) ?: (string) $p['nome'];
            $utenti[$chi] ??= ['nome' => trim($p['cognome'] . ' ' . $p['nome']) ?: $chi, 'email' => (string) $p['email'], 'prenotazioni' => 0, 'ore' => 0.0];
            ++$utenti[$chi]['prenotazioni'];
            $utenti[$chi]['ore'] += $h;
            $m = date('Y-m', $i);
            $mesi[$m]['prenotazioni'] = ($mesi[$m]['prenotazioni'] ?? 0) + 1;
            $mesi[$m]['ore'] = ($mesi[$m]['ore'] ?? 0) + $h;
        }
        // Ore di apertura nel periodo secondo gli orari settimanali
        $orari = $this->risorse->oreDiAperturaPerGiorno([$rid])[$rid] ?? [];
        $aperte = 0.0;
        for ($t = (int) strtotime($dal), $fine = (int) strtotime($al); $t <= $fine; $t += 86400) {
            $aperte += $orari[(int) date('N', $t)] ?? 0;
        }
        usort($utenti, fn ($a, $b) => $b['ore'] <=> $a['ore'] ?: $b['prenotazioni'] <=> $a['prenotazioni']);
        ksort($mesi);
        $decise = $n['confermata'] + $n['rifiutata'];
        $contatori = [];
        foreach ($this->contatori->elenco($rid, false) as $c) {
            $usato = $this->contatori->usato((int) $c['id'], $dal, $al);
            $contatori[] = ['nome' => $c['nome'], 'unita' => $c['unita'], 'modalita' => $c['modalita'], 'usato' => $usato, 'capacita' => (int) $c['capacita'], 'periodo' => $c['periodo'],
                'saldo' => $c['modalita'] === 'scorta' ? max(0, $this->contatori->totaleMovimenti((int) $c['id']) - $this->contatori->usato((int) $c['id'], null, null)) : null];
        }
        $diario = null;
        if ((int) ($risorsa['diario_attivo'] ?? 0) === 1) {
            $t = $this->diario->totali($rid, $dal, $al);
            $diario = $t + ['consumo' => $t['km'] > 0 && $t['litri'] > 0 ? round($t['litri'] * 100 / $t['km'], 1) : null, 'km_medi' => $t['viaggi'] > 0 ? (int) round($t['km'] / $t['viaggi']) : null,
                'senza_diario' => count($this->diario->senzaDiario(null, null, $rid))];
        }

        return [
            'conteggi' => $n, 'ore' => round($ore, 1), 'ore_aperte' => round($aperte, 1), 'utilizzo' => $aperte > 0 ? (int) round($ore * 100 / $aperte) : null,
            'rifiutate_pct' => (int) $risorsa['approvazione'] === 1 && $decise > 0 ? (int) round($n['rifiutata'] * 100 / $decise) : null,
            'fasce' => $fasce, 'utenti' => array_slice(array_values($utenti), 0, 8), 'mesi' => $mesi, 'contatori' => $contatori, 'diario' => $diario,
            'scadenze' => $this->scadenze->elenco($rid), 'prenotazioni' => $pren,
        ];
    }

    /** File .xlsx con riepilogo, prenotazioni e utilizzo per persona del periodo. Percorso temporaneo o null. */
    public function esporta(array $risorsa, string $dal, string $al): ?string
    {
        $d = $this->dati($risorsa, $dal, $al);
        $riep = [['Risorsa', (string) $risorsa['nome']], ['Periodo', date('d/m/Y', (int) strtotime($dal)) . ' – ' . date('d/m/Y', (int) strtotime($al))], ['Prenotazioni', $d['conteggi']['totale']],
            ['Confermate', $d['conteggi']['confermata']], ['Da approvare', $d['conteggi']['da_approvare']], ['Annullate', $d['conteggi']['annullata']], ['Rifiutate', $d['conteggi']['rifiutata']],
            ['Ore prenotate', $d['ore']], ['Ore di apertura', $d['ore_aperte']], ['Utilizzo %', $d['utilizzo'] ?? '']];
        foreach ($d['contatori'] as $c) {
            $riep[] = [$c['nome'] . ' usati', $c['usato'] . ($c['unita'] !== '' ? ' ' . $c['unita'] : '')];
        }
        if ($d['diario']) {
            array_push($riep, ['Viaggi nel diario', $d['diario']['viaggi']], ['Km percorsi', $d['diario']['km']], ['Litri', $d['diario']['litri']], ['Consumo (l/100 km)', $d['diario']['consumo'] ?? '']);
        }
        $righe = [];
        foreach ($d['prenotazioni'] as $p) {
            $righe[] = [$p['codice'], date('d/m/Y', (int) strtotime((string) $p['inizio'])), date('H:i', (int) strtotime((string) $p['inizio'])), date('H:i', (int) strtotime((string) $p['fine'])),
                trim($p['cognome'] . ' ' . $p['nome']), $p['email'], $p['motivo'], $p['stato']];
        }
        $per = array_map(fn ($u) => [$u['nome'], $u['email'], $u['prenotazioni'], round($u['ore'], 1)], $d['utenti']);

        return Excel::crea([
            'Riepilogo' => ['intestazioni' => ['Indicatore', 'Valore'], 'righe' => $riep, 'larghezze' => [28, 30]],
            'Prenotazioni' => ['intestazioni' => ['Codice', 'Data', 'Dalle', 'Alle', 'Persona', 'Email', 'Motivo', 'Stato'], 'righe' => $righe, 'larghezze' => [14, 12, 8, 8, 24, 28, 36, 14]],
            'Per persona' => ['intestazioni' => ['Persona', 'Email', 'Prenotazioni', 'Ore'], 'righe' => $per, 'larghezze' => [26, 30, 14, 10]],
        ]);
    }
}
