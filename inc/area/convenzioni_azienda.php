<?php
// Tessera «convenzioni_azienda» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info…).
// «Le convenzioni della tua azienda»: per ogni convenzione di tesi legata all'email della persona, i passi (dati, firma e caricamento, protocollo e firma del
// Direttore, stipulata) e un solo pulsante che porta alla pagina con il link personale. Le regole stanno in App\ConvenzioniTesi\PercorsoAzienda.
// Compare solo a chi ha una convenzione. Gli stili della barra a passi sono gli stessi della tessera delle scuole (.ui-fsl, .ui-passi).
$conv_az = [];
try {
    $conv_az = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ServizioConvenzioniAzienda::class)->perUtente($user_info ?? null);
} catch (\Throwable $e_az) { error_log('[Area personale] convenzioni azienda non disponibili: ' . $e_az->getMessage()); }
if ($conv_az) {
    $h_az = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $da_fare_az = count(array_filter($conv_az, static fn (array $c): bool => $c['percorso']['azione'] !== null));
    echo '<div class="col-12"><div class="ui-fsl" style="--tc:#0e7490;">';
    echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-1"><span class="ui-tessera-icona" aria-hidden="true"><i class="fa fa-handshake"></i></span>';
    echo '<div class="flex-grow-1"><div class="ui-tessera-titolo">Le convenzioni della tua azienda</div>';
    echo '<div class="ui-tessera-testo">' . ($da_fare_az > 0 ? ($da_fare_az === 1 ? 'Una convenzione aspetta qualcosa da te' : $da_fare_az . ' convenzioni aspettano qualcosa da te') : 'Niente da fare per ora') . '</div></div>';
    echo '<a class="btn btn-sm btn-outline-secondary fw-bold" href="percorso_tesi.php">Come funziona</a></div>';
    foreach ($conv_az as $c_az) {
        $pc_az = $c_az['percorso'];
        echo '<div class="ui-fsl-pr"><div class="fw-semibold">' . $h_az($c_az['azienda']) . ' <span class="text-secondary fw-normal small">· convenzione per le tesi</span></div>';
        echo '<ol class="ui-passi">';
        foreach ($pc_az['passi'] as $pas_az) {
            echo '<li class="' . $h_az($pas_az['stato']) . '"' . ($pas_az['stato'] === 'qui' ? ' aria-current="step"' : '') . '><span>' . $h_az($pas_az['nome'])
                . ($pas_az['nota'] !== '' ? '<span class="d-block fw-normal text-secondary">' . $h_az($pas_az['nota']) . '</span>' : '') . '</span></li>';
        }
        echo '</ol>';
        if ($pc_az['azione'] !== null) {
            echo '<a class="btn btn-sm btn-danger fw-bold" href="' . $h_az($pc_az['azione']['link']) . '">' . $h_az($pc_az['azione']['testo']) . '</a>';
        } elseif ($pc_az['attesa'] !== '') {
            echo '<div class="small text-secondary"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>' . $h_az($pc_az['attesa']) . '</div>';
        }
        echo '</div>';
    }
    echo '</div></div>';
}
