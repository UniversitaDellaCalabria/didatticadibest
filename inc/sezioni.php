<?php
// inc/sezioni.php - Macroaree del portale (Orientamento, Didattica, Calendari e risorse) e tipi di area.
// Ogni area (pagine_eventi) ha un tipo (pagine_eventi.tipo_area) che la colloca in una macroarea e decide le impostazioni
// proposte e ciò che si vede. Tipo vuoto = area non ancora assegnata: si comporta come sempre (eventi generici).
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('SEZIONI_PORTALE')) define('SEZIONI_PORTALE', [
    'orientamento' => ['nome' => 'Orientamento', 'icona' => 'fa-compass',
                       'descr' => 'Formazione Scuola Lavoro, laboratori per le scuole, eventi e seminari'],
    'didattica'    => ['nome' => 'Didattica', 'icona' => 'fa-graduation-cap',
                       'descr' => 'Gruppi e attività degli insegnamenti dei corsi del Dipartimento'],
    'calendari'    => ['nome' => 'Calendari e risorse', 'icona' => 'fa-calendar-days',
                       'descr' => 'Aule, laboratori e appuntamenti con gli uffici'],
]);

// disponibile = false: tipo previsto ma non ancora utilizzabile
if (!defined('TIPI_AREA')) define('TIPI_AREA', [
    'fsl'        => ['nome' => 'Formazione Scuola Lavoro', 'sezione' => 'orientamento', 'disponibile' => true,
                     'descr' => 'Progetti ed eventi per le scuole con convenzioni, elenco degli studenti e attestati: nuovi eventi e progetti con "Attività di Formazione Scuola Lavoro" già acceso.'],
    'eventi'     => ['nome' => 'Eventi e seminari', 'sezione' => 'orientamento', 'disponibile' => true,
                     'descr' => 'Eventi aperti a scuole, studenti ed esterni (es. Welcome Week).'],
    'gruppi'     => ['nome' => 'Gruppi degli insegnamenti', 'sezione' => 'didattica', 'disponibile' => true,
                     'descr' => 'Attività create a partire da un insegnamento dell\'anagrafe, con i gruppi come turni (es. Scienze Motorie).'],
    'calendario' => ['nome' => 'Calendari e risorse', 'sezione' => 'calendari', 'disponibile' => true,
                     'descr' => 'Aule, laboratori e sportelli (appuntamenti con gli uffici) prenotabili a calendario, a slot.'],
]);

if (!function_exists('tipo_area')) {
    // Tipo dell'area ('' se non assegnato)
    function tipo_area(?array $pagina): string {
        $t = (string)($pagina['tipo_area'] ?? '');
        return isset(TIPI_AREA[$t]) ? $t : '';
    }
}

if (!function_exists('sezione_area')) {
    // Macroarea dell'area ('' se il tipo non è assegnato)
    function sezione_area(?array $pagina): string {
        $t = tipo_area($pagina);
        return $t !== '' ? TIPI_AREA[$t]['sezione'] : '';
    }
}

if (!function_exists('raggruppa_aree_per_sezione')) {
    // Aree divise per macroarea, nell'ordine di SEZIONI_PORTALE; in fondo ('') quelle non assegnate.
    // L'ordine delle aree dentro ogni macroarea resta quello ricevuto.
    function raggruppa_aree_per_sezione(array $aree): array {
        $gruppi = array_fill_keys(array_keys(SEZIONI_PORTALE), []);
        $gruppi[''] = [];
        foreach ($aree as $a) $gruppi[sezione_area($a)][] = $a;
        return array_filter($gruppi);
    }
}

if (!function_exists('html_scelta_tipo_area')) {
    // Tendina del tipo di area, raggruppata per macroarea
    function html_scelta_tipo_area(string $name, string $valore, string $attr = '', string $classi = 'form-select form-select-sm'): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $out = '<select name="' . $h($name) . '" class="' . $h($classi) . '" ' . $attr . '><option value="">Non assegnata (eventi generici)</option>';
        foreach (SEZIONI_PORTALE as $k_s => $s) {
            $out .= '<optgroup label="' . $h($s['nome']) . '">';
            foreach (TIPI_AREA as $k_t => $t) {
                if ($t['sezione'] !== $k_s) continue;
                $out .= '<option value="' . $h($k_t) . '"' . ($valore === $k_t ? ' selected' : '') . (!$t['disponibile'] ? ' disabled' : '') . '>' . $h($t['nome']) . (!$t['disponibile'] ? ' (in arrivo)' : '') . '</option>';
            }
            $out .= '</optgroup>';
        }
        return $out . '</select>';
    }
}
