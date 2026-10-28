<?php
// I permessi di lavoro della persona (gli stessi che usa il pannello, admin/admin_header.php): chi gestisce pratiche, protocollo, sedute, convenzioni, tutorato,
// assistenza o aree. Li usano il riquadro «lavoro» e l'icona «messaggi» dell'area personale; il risultato si calcola una volta sola per richiesta.
if (!function_exists('area_permessi_lavoro')) {
    /** @return array<string, bool> le chiavi di App\Portale\Area\PostazioniLavoro::PERMESSI */
    function area_permessi_lavoro($conn, ?array $user_info, int $uid, bool $admin): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $app = \App\Core\App::per($conn);
        $did_tutto = $admin || ha_modulo($conn, $uid, 'didattica') || utente_operatore_ufficio($conn, $user_info);
        $fsl = $admin || ha_modulo($conn, $uid, 'fsl');

        return $cache = [
            'admin' => $admin,
            'didattica_tutto' => $did_tutto,
            'didattica' => $did_tutto || (bool)consigli_referente($conn, $user_info),
            'conv_tesi' => $did_tutto || $app->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class)->utenteDellUfficio($user_info),
            'tutorato' => $admin || ha_modulo($conn, $uid, 'didattica') || utente_operatore_ufficio($conn, $user_info, 'bandi'),
            'fsl_convenzioni' => $fsl || ha_ambito($conn, $uid, 'fsl_convenzioni') || $app->get(\App\Uffici\ServizioUffici::class)->utenteMembro($user_info, 'convenzioni_fsl'),
            'assistenza' => $app->get(\App\Assistenza\OperatoriAssistenza::class)->eOperatore($user_info),
            'aree' => $admin || utente_ha_abilitazioni($conn, $uid),
        ];
    }
}
