<?php
// Le tappe di un incarico di tutorato visto dal tutor («A che punto sei»): le usano la tessera del cruscotto e la pagina il_mio_tutorato.php.
if (!function_exists('area_tappe_tutorato')) {
    /**
     * @param array<string, mixed> $mt  l'incarico (IncaricoRepository::delTutor)
     * @param bool $pagamenti  se i pagamenti passano dal portale (riquadro «Ufficio pagamenti del tutorato» acceso)
     * @return array{tappe: list<array{0: string, 1: bool, 2: string}>, prima: int|null, firmata: bool}
     */
    function area_tappe_tutorato($conn, array $mt, bool $pagamenti): array
    {
        $ps_t = (string) $mt['pagamento_stato'];
        $dst = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioDichiarazione::class)->stato((int) $mt['id']);
        $firmata_t = in_array($mt['stato'], ['firmata', 'protocollata'], true);
        $fine_t = in_array((string) $mt['fine_stato'], ['firmata', 'protocollata'], true);
        $tappe_t = [
            ['Incarico accettato' . ($dst['completa'] ? ' e dichiarazione individuale compilata' : ''), !in_array($mt['stato'], ['bozza', 'inviata', 'annullata'], true), $mt['confermata_il'] ? 'accettato il ' . date('d/m/Y', strtotime($mt['confermata_il'])) : ''],
            ['Lettera firmata dal docente e dal Direttore', $firmata_t, $mt['protocollo'] !== '' ? 'protocollo ' . $mt['protocollo'] : ''],
            ['Registro delle presenze', $mt['fine_stato'] !== '' || $fine_t, $mt['ore_approvate'] !== null ? \App\Tutorato\DatiLettera::oreTesto($mt['ore_approvate']) . ' ore approvate' : ''],
            ['Fine attività firmata dal docente titolare', $fine_t, $mt['fine_firmata_il'] ? date('d/m/Y', strtotime($mt['fine_firmata_il'])) : ''],
            ['Validata dal referente e passata ai pagamenti', in_array($ps_t, ['in_ufficio', 'pagata'], true), $mt['pagamento_passato_il'] && in_array($ps_t, ['in_ufficio', 'pagata'], true) ? date('d/m/Y', strtotime($mt['pagamento_passato_il'])) : ''],
            ['Pagamento disposto', $ps_t === 'pagata', $ps_t === 'pagata' ? date('d/m/Y', strtotime($mt['pagamento_data'])) : 'ti avvisiamo con una email'],
        ];
        // Nell'iter per Uniticket i pagamenti non passano dal portale; lo stesso vale finché l'ufficio pagamenti è spento
        if ((string) ($mt['iter'] ?? 'portale') === 'uniticket' || !$pagamenti) {
            $tappe_t = array_slice($tappe_t, 0, 4);
        }
        $prima = null;
        foreach ($tappe_t as $k => $tp) {
            if (!$tp[1]) {
                $prima = $k;
                break;
            }
        }

        return ['tappe' => $tappe_t, 'prima' => $prima, 'firmata' => $firmata_t];
    }
}
