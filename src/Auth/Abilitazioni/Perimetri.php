<?php

declare(strict_types=1);

namespace App\Auth\Abilitazioni;

/**
 * Perimetro di un'abilitazione scelto nel pannello Utenti (scheda dell'utente o "Abilita una persona"):
 * ['area' => nessuno|area|parziale, 'tipi' => [progetti, eventi], 'attivita' => [id], 'fsl' => nessuno|tutto|parziale,
 *  'fsl_parti' => [fsl_convenzioni, fsl_scuole], 'moduli' => [orientamento, calendari, didattica]].
 * Spostato dalle funzioni di admin/utenti.php (leggi_perimetro_post, ambiti_da_perimetro, testo_perimetro).
 */
final class Perimetri
{
    public const VUOTO = ['area' => 'nessuno', 'tipi' => [], 'attivita' => [], 'fsl' => 'nessuno', 'fsl_parti' => [], 'moduli' => []];

    /**
     * Perimetro dai campi del modulo inviato.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function daModulo(array $post): array
    {
        $area = in_array($post['area_perimetro'] ?? '', ['area', 'parziale'], true) ? $post['area_perimetro'] : 'nessuno';
        $tipi = $area === 'parziale' ? array_values(array_intersect((array) ($post['area_tipi'] ?? []), ['progetti', 'eventi'])) : [];
        $att = $area === 'parziale' ? array_values(array_unique(array_filter(array_map('intval', (array) ($post['attivita'] ?? []))))) : [];
        if ($area === 'parziale' && !$tipi && !$att) {
            $area = 'nessuno';
        }
        $fsl = in_array($post['fsl_perimetro'] ?? '', ['tutto', 'parziale'], true) ? $post['fsl_perimetro'] : 'nessuno';
        $parti = $fsl === 'parziale' ? array_values(array_intersect((array) ($post['fsl_parti'] ?? []), ['fsl_convenzioni', 'fsl_scuole'])) : [];
        if ($fsl === 'parziale' && !$parti) {
            $fsl = 'nessuno';
        }
        // Moduli interi: tutte le aree del modulo (Orientamento comprende la FSL)
        $moduli = array_values(array_intersect((array) ($post['moduli'] ?? []), ['orientamento', 'calendari', 'didattica']));

        return ['area' => $area, 'tipi' => $tipi, 'attivita' => $att, 'fsl' => $fsl, 'fsl_parti' => $parti, 'moduli' => $moduli];
    }

    /**
     * [[ambito, pagina_id, eventi_ids], …] corrispondenti al perimetro.
     *
     * @param array<string, mixed> $p
     * @return list<array{0: string, 1: int, 2: list<int>}>
     */
    public static function ambiti(array $p, int $paginaId): array
    {
        $out = [];
        if ($p['area'] === 'area') {
            $out[] = ['area', $paginaId, []];
        }
        foreach ($p['tipi'] as $t) {
            $out[] = [$t, $paginaId, []];
        }
        if ($p['attivita']) {
            $out[] = ['attivita', $paginaId, $p['attivita']];
        }
        if ($p['fsl'] === 'tutto') {
            $out[] = ['fsl', 0, []];
        }
        foreach ($p['fsl_parti'] as $t) {
            $out[] = [$t, 0, []];
        }
        foreach ($p['moduli'] ?? [] as $m) {
            $out[] = ['modulo_' . $m, 0, []];
        }

        return $out;
    }

    /**
     * Descrizione breve per riepiloghi e messaggi.
     *
     * @param array<string, mixed> $p
     * @param array<int|string, mixed> $titoliAttivita titoli delle attività per id
     * @param array<string, array<string, mixed>> $moduliPortale MODULI_PORTALE (nomi dei moduli)
     */
    public static function testo(array $p, array $titoliAttivita = [], array $moduliPortale = []): string
    {
        $parti = [];
        if ($p['area'] === 'area') {
            $parti[] = "tutta l'area";
        }
        if (in_array('progetti', $p['tipi'], true)) {
            $parti[] = 'tutti i progetti';
        }
        if (in_array('eventi', $p['tipi'], true)) {
            $parti[] = 'tutti gli eventi';
        }
        if ($p['attivita']) {
            $parti[] = count($p['attivita']) === 1 ? '1 attività' . (isset($titoliAttivita[$p['attivita'][0]]) ? ' (' . $titoliAttivita[$p['attivita'][0]] . ')' : '') : count($p['attivita']) . ' attività';
        }
        if ($p['fsl'] === 'tutto') {
            $parti[] = 'Formazione Scuola Lavoro';
        }
        if (in_array('fsl_convenzioni', $p['fsl_parti'], true)) {
            $parti[] = 'convenzioni FSL';
        }
        if (in_array('fsl_scuole', $p['fsl_parti'], true)) {
            $parti[] = 'anagrafe scuole';
        }
        foreach ($p['moduli'] ?? [] as $m) {
            $parti[] = 'modulo ' . ($moduliPortale[$m]['nome'] ?? $m);
        }

        return $parti ? implode(', ', $parti) : 'nessuna abilitazione';
    }
}
