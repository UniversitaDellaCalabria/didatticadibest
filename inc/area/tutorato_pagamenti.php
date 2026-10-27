<?php
// Tessera «tutorato_pagamenti» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Ufficio pagamenti del tutorato (persone degli uffici di Ateneo); spento finché i pagamenti non passano dal portale
$uff_pag = []; $n_da_pagare = 0;
try {
    $pag_sv = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioPagamenti::class);
    $uff_pag = \App\Core\App::per($conn)->get(\App\Ateneo\ServizioUfficiAteneo::class)->ufficiDellUtente($user_info ?? null);
    $n_da_pagare = $uff_pag ? $pag_sv->daPagare($user_info ?? null) : 0;
} catch (\Throwable $e_tut) { error_log('[Area personale] pagamenti del tutorato non disponibili: ' . $e_tut->getMessage()); }
if ($uff_pag) {
    area_tessera('fa-euro-sign', '#15803d', 'Ufficio pagamenti del tutorato', implode(' · ', array_column($uff_pag, 'nome')) . ($n_da_pagare ? '' : ' · niente da pagare'), 'pagamenti_tutorato.php', (int)$n_da_pagare, 'da pagare');
}
