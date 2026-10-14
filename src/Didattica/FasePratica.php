<?php

declare(strict_types=1);

namespace App\Didattica;

/**
 * A che punto è una pratica e che cosa tocca fare: la «fase» che si legge nell'elenco e nel riquadro «Prossima azione».
 * La fase si ricava da campi che ci sono già (stato, ufficio, persona in carico, seduta, esito); non si salva nulla.
 * Solo regole, niente database: il tipo dell'ufficio in cui si trova la pratica arriva da chi chiama.
 */
final class FasePratica
{
    /** Fase => [etichetta, tono (vedi Pannello::TONI), colore, icona], nell'ordine dell'iter. */
    public const FASI = [
        'protocollare' => ['Da protocollare', 'attesa', '#b45309', 'fa-stamp'],
        'smistare'     => ['Da assegnare', 'info', '#0056B3', 'fa-shuffle'],
        'prendere'     => ['Da prendere in carico', 'info', '#0369a1', 'fa-hand'],
        'lavorazione'  => ['In lavorazione', 'lavoro', '#7c3aed', 'fa-gears'],
        'firma'        => ['In attesa di firma', 'attesa', '#b45309', 'fa-signature'],
        'integrazione' => ['Attesa dello studente', 'attesa', '#b45309', 'fa-hourglass-half'],
        'consiglio'    => ['Pronta per il consiglio', 'ok', '#047857', 'fa-gavel'],
        'decisa'       => ['Decisa in seduta', 'ok', '#15803d', 'fa-circle-check'],
        'segreteria'   => ['Alla segreteria', 'lavoro', '#5b21b6', 'fa-building-columns'],
        'conclusa'     => ['Conclusa', 'spento', '#475569', 'fa-box-archive'],
    ];

    /**
     * Fase di una pratica.
     *
     * @param array<string, mixed> $p riga di pratiche (stato, assegnata_a, ufficio_id, seduta_id, esito_seduta)
     * @param string $tipoUfficio tipo dell'ufficio in cui si trova (protocollo, cdl, segreteria o vuoto)
     */
    public static function di(array $p, string $tipoUfficio = ''): string
    {
        if (in_array($p['stato'] ?? '', ['accolta', 'respinta', 'chiusa'], true)) {
            return 'conclusa';
        }
        if (($p['stato'] ?? '') === 'integrazione') {
            return 'integrazione';
        }
        if (!empty($p['firme_attesa'])) {
            return 'firma';
        }
        if ($tipoUfficio === 'protocollo') {
            return 'protocollare';
        }
        if ($tipoUfficio === 'segreteria') {
            return 'segreteria';
        }
        if (!empty($p['seduta_id'])) {
            return (string) ($p['esito_seduta'] ?? '') === '' ? 'consiglio' : 'decisa';
        }
        if (empty($p['assegnata_a'])) {
            return empty($p['ufficio_id']) ? 'smistare' : 'prendere';
        }

        return 'lavorazione';
    }

    /**
     * Che cosa fare adesso: testo, tipo («azione» = c'è qualcosa da fare, «attesa» = si aspetta qualcun altro, «nessuna») e dove
     * si va («pratica» = la scheda della pratica, «seduta» = la seduta a cui è portata).
     *
     * @param array<string, mixed> $p
     * @return array{testo: string, tipo: string, dove: string}
     */
    public static function cosaFare(string $fase, array $p): array
    {
        return match ($fase) {
            'protocollare' => ['testo' => (string) ($p['protocollo'] ?? '') === '' ? 'Protocolla' : 'Aspetta il bollo', 'tipo' => (string) ($p['protocollo'] ?? '') === '' ? 'azione' : 'attesa', 'dove' => 'pratica'],
            'smistare'     => ['testo' => 'Assegna a una persona', 'tipo' => 'azione', 'dove' => 'pratica'],
            'prendere'     => ['testo' => 'Prendi in carico', 'tipo' => 'azione', 'dove' => 'pratica'],
            'lavorazione'  => ['testo' => (string) ($p['in_verbale'] ?? '1') === '0' ? 'Lavora e concludi la pratica' : (empty($p['seduta_id']) ? 'Istruisci e porta in consiglio' : 'Istruisci'), 'tipo' => 'azione', 'dove' => 'pratica'],
            'firma'        => ['testo' => 'Aspetta la firma', 'tipo' => 'attesa', 'dove' => 'pratica'],
            'integrazione' => ['testo' => 'Aspetta lo studente', 'tipo' => 'attesa', 'dove' => 'pratica'],
            'consiglio'    => ['testo' => 'Decidi in seduta', 'tipo' => 'azione', 'dove' => 'seduta'],
            'decisa'       => ['testo' => 'Comunica l\'esito', 'tipo' => 'azione', 'dove' => 'seduta'],
            'segreteria'   => ['testo' => 'In lavorazione alla segreteria', 'tipo' => 'attesa', 'dove' => 'pratica'],
            default        => ['testo' => '', 'tipo' => 'nessuna', 'dove' => 'pratica'],
        };
    }

    /**
     * Conta le pratiche per fase.
     *
     * @param list<array<string, mixed>> $pratiche
     * @param callable(array<string, mixed>): string $tipoUfficio dato la pratica, il tipo dell'ufficio in cui si trova
     * @return array<string, int>
     */
    public static function conta(array $pratiche, callable $tipoUfficio): array
    {
        $out = [];
        foreach ($pratiche as $p) {
            $f = self::di($p, $tipoUfficio($p));
            $out[$f] = ($out[$f] ?? 0) + 1;
        }

        return $out;
    }
}
