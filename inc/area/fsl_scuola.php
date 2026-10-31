<?php
// Tessera «fsl_scuola» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id, $u_email_sql…).
// «La mia scuola»: per ogni prenotazione di Formazione Scuola Lavoro i passi (programma, convenzione, elenco studenti e autorizzazione, attività, attestati) e un solo
// pulsante per quello che c'è da fare. Le regole stanno in App\Fsl\PercorsoScuola, i dati in ServizioPercorsiScuola. Compare solo a chi ha prenotazioni FSL.
$percorsi_fs = [];
try {
    if (!empty($user_info['id'])) $percorsi_fs = \App\Core\App::per($conn)->get(\App\Fsl\ServizioPercorsiScuola::class)->perUtente((int)$user_info['id'], $u_email_sql);
} catch (\Throwable $e_fs) { error_log('[Area personale] percorso FSL non disponibile: ' . $e_fs->getMessage()); }
if ($percorsi_fs) {
    $h_fs = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $da_fare_fs = count(array_filter($percorsi_fs, static fn (array $p): bool => $p['percorso']['azione'] !== null && !$p['percorso']['fatto']));
    echo '<div class="col-12"><div class="ui-fsl" style="--tc:#15803d;">';
    echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-1"><span class="ui-tessera-icona" aria-hidden="true"><i class="fa fa-school"></i></span>';
    echo '<div class="flex-grow-1"><div class="ui-tessera-titolo">La mia scuola: Formazione Scuola Lavoro</div>';
    echo '<div class="ui-tessera-testo">' . ($da_fare_fs > 0 ? ($da_fare_fs === 1 ? 'Una prenotazione aspetta qualcosa da te' : $da_fare_fs . ' prenotazioni aspettano qualcosa da te') : 'Niente da fare per ora') . '</div></div>';
    echo '<a class="btn btn-sm btn-outline-secondary fw-bold" href="miei_eventi.php#programma-fsl">Tutti i documenti</a></div>';
    foreach ($percorsi_fs as $p_fs) {
        $pc_fs = $p_fs['percorso'];
        echo '<div class="ui-fsl-pr"><div class="fw-semibold">' . $h_fs($p_fs['titolo'])
            . ($p_fs['turno'] !== '' ? ' <span class="text-secondary fw-normal">· ' . $h_fs($p_fs['turno']) . '</span>' : '')
            . ($p_fs['data'] !== '' ? ' <span class="text-secondary fw-normal small">· ' . $h_fs(date('d/m/Y', (int)strtotime($p_fs['data']))) . '</span>' : '') . '</div>';
        echo '<ol class="ui-passi">';
        foreach ($pc_fs['passi'] as $pas_fs) {
            echo '<li class="' . $h_fs($pas_fs['stato']) . '"' . ($pas_fs['stato'] === 'qui' ? ' aria-current="step"' : '') . '><span>' . $h_fs($pas_fs['nome'])
                . ($pas_fs['nota'] !== '' ? '<span class="d-block fw-normal text-secondary">' . $h_fs($pas_fs['nota']) . '</span>' : '') . '</span></li>';
        }
        echo '</ol>';
        if ($pc_fs['azione'] !== null) {
            echo '<a class="btn btn-sm fw-bold ' . ($pc_fs['fatto'] ? 'btn-success' : 'btn-danger') . '" href="' . $h_fs($pc_fs['azione']['link']) . '">' . $h_fs($pc_fs['azione']['testo']) . '</a>';
        } elseif ($pc_fs['attesa'] !== '') {
            echo '<div class="small text-secondary"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>' . $h_fs($pc_fs['attesa']) . '</div>';
        }
        echo '</div>';
    }
    echo '</div></div>';
}
