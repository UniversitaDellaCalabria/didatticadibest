<?php

declare(strict_types=1);

namespace App\Portale\Area;

/**
 * Le «postazioni di lavoro» che una persona vede nel riquadro «Il mio lavoro» dell'area personale: una tessera per ogni cosa che può gestire (pratiche degli
 * studenti, protocollo, sedute e verbali, convenzioni, tutorato, assistenza, aree), con il numero di cose che aspettano e i pulsanti sulla vista giusta.
 * Solo regole, niente database: i permessi (gli stessi che usa il pannello, admin/admin_header.php) e i numeri (CoseDaFare) arrivano da chi chiama.
 * Le azioni vere si fanno nelle pagine del pannello, con gli stessi controlli di sempre.
 */
final class PostazioniLavoro
{
    /** Giorni dopo i quali una pratica ferma si segnala in evidenza. */
    public const GIORNI_FERMA = 7;

    /** Permessi e numeri che il chiamante deve passare (le chiavi mancanti valgono falso e zero). */
    public const PERMESSI = ['admin', 'didattica_tutto', 'didattica', 'conv_tesi', 'tutorato', 'fsl_convenzioni', 'assistenza', 'aree'];
    public const NUMERI = ['smistare', 'smistare_giorni', 'protocollare', 'verbali', 'conv_tesi', 'tutorato', 'conv_fsl', 'assistenza_prendere', 'assistenza_mie'];

    /**
     * Le postazioni della persona, nell'ordine in cui compaiono.
     *
     * @param array<string, bool> $p permessi (vedi PERMESSI)
     * @param array<string, int> $n numeri (vedi NUMERI)
     * @param list<array<string, mixed>> $aree le aree che la persona gestisce (vedi area())
     * @return list<array{chiave: string, titolo: string, testo: string, icona: string, colore: string, href: string, novita: int, novita_et: string, altri: list<array{0: string, 1: string, 2?: bool, 3?: bool}>}>
     */
    public static function elenco(array $p, array $n, array $aree = []): array
    {
        $p += array_fill_keys(self::PERMESSI, false);
        $n += array_fill_keys(self::NUMERI, 0);
        $out = [];

        if ($p['didattica_tutto']) {
            $ferma = $n['smistare_giorni'] >= self::GIORNI_FERMA;
            $out[] = self::voce('pratiche', 'Pratiche degli studenti', 'Le domande arrivate con i moduli online: da assegnare, da lavorare, da portare in consiglio.', 'fa-inbox', '#047857',
                'admin/didattica.php?tab=pratiche', $n['smistare'], 'da assegnare', [
                    ['Da assegnare', 'admin/didattica.php?tab=pratiche&carico=smistare', false, $ferma],
                    ['Assegnate a me', 'admin/didattica.php?tab=pratiche&carico=me'],
                    ['Seguite da me', 'admin/didattica.php?tab=pratiche&carico=seguite'],
                    ['Tutte', 'admin/didattica.php?tab=pratiche&stato=tutte'],
                    ['Moduli e documenti', 'admin/didattica.php?tab=moduli'],
                    ['Ricevimento', 'admin/didattica.php?tab=ufficio'],
                    ['Statistiche', 'admin/didattica.php?tab=statistiche'],
                ]);
            if ($n['protocollare'] > 0) {
                $out[] = self::voce('protocollo', 'Ufficio protocollo', 'Pratiche arrivate all\'ufficio protocollo: registra il numero e la data e la pratica prosegue.', 'fa-stamp', '#b45309',
                    'admin/didattica.php?tab=pratiche&carico=ufficio', $n['protocollare'], 'da protocollare');
            }
        }
        if ($p['didattica']) {
            $out[] = self::voce('sedute', $p['didattica_tutto'] ? 'Sedute e verbali' : 'Le sedute dei miei consigli', 'Consigli dei corsi di studio: ordine del giorno, presenze, decisioni sulle pratiche e verbali da firmare.', 'fa-gavel', '#7c3aed',
                'admin/didattica.php?tab=sedute', $n['verbali'], $n['verbali'] === 1 ? 'verbale in firma' : 'verbali in firma', array_merge([
                    ['Nuova seduta', 'admin/didattica.php?tab=sedute&nuova=1'],
                ], $p['didattica_tutto'] ? [['Archivio verbali', 'admin/didattica.php?tab=sedute&archivio=1']] : []));
        }
        if ($p['conv_tesi']) {
            $out[] = self::voce('convenzioni_tesi', 'Convenzioni di tesi', 'Convenzioni con enti e aziende per svolgere la tesi: modifiche, protocollo, firma del Direttore.', 'fa-handshake', '#0e7490',
                'admin/convenzioni_tesi.php', $n['conv_tesi'], 'da seguire', [
                    ['In vigore', 'admin/convenzioni_tesi.php?vista=valide'],
                    ['Scadute', 'admin/convenzioni_tesi.php?vista=scadute'],
                ]);
        }
        if ($p['tutorato']) {
            $out[] = self::voce('tutorato', 'Tutorato · lettere di incarico', 'Bandi e lettere dei vincitori, firme del docente e del Direttore, protocollo e validazione.', 'fa-user-graduate', '#0f766e',
                'admin/tutorato.php', $n['tutorato'], 'da validare', [
                    ['Da validare', 'admin/tutorato.php?vista=validare', false, $n['tutorato'] > 0],
                    ['Nuovo bando', 'admin/tutorato.php?nuovo_bando=1'],
                ]);
        }
        if ($p['fsl_convenzioni']) {
            $out[] = self::voce('fsl_convenzioni', 'Convenzioni FSL con le scuole', 'Convenzioni caricate dalle scuole: da prendere in carico, far firmare e registrare.', 'fa-school', '#0369a1',
                'admin/fsl.php', $n['conv_fsl'], 'da seguire', [
                    ['Da stipulare', 'admin/fsl.php?tab=stipulare'],
                    ['Convenzioni', 'admin/fsl.php?tab=convenzioni'],
                    ['Verifica iscrizioni', 'admin/fsl.php?tab=verifica'],
                    ['Riepilogo', 'admin/fsl.php?tab=riepilogo'],
                ]);
        }
        if ($p['assistenza']) {
            $pre = $n['assistenza_prendere'];
            $out[] = self::voce('assistenza', 'Chat di assistenza da gestire', 'Le chat di studenti e personale: da prendere, in carico a te, chiuse.', 'fa-headset', '#B30000',
                'admin/assistenza.php', $pre + $n['assistenza_mie'], $pre > 0 ? 'da prendere o aperte' : 'aperte in carico a te', [
                    ['Da prendere', 'admin/assistenza.php?vista=da_prendere', false, $pre > 0],
                    ['Le mie', 'admin/assistenza.php?vista=mie'],
                ]);
        }
        if ($p['aree'] && ($p['admin'] || !$aree)) {
            $out[] = self::voce('aree', $p['admin'] ? 'Gestione del portale' : 'Eventi e aree che gestisci', $p['admin']
                ? 'Aree, eventi, iscritti, utenti, sistema e tutto il resto della gestione.'
                : 'Gli eventi, i progetti e le aree per cui sei abilitato: iscritti, presenze, messaggi.', 'fa-screwdriver-wrench', '#475569', 'admin/inizio.php', 0, '');
        }

        foreach ($aree as $a) {
            $v = self::area($a);
            if ($v !== null) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * Una tessera per un'area che la persona gestisce: eventi, progetti, iscritti, presenze, messaggi, sondaggi, statistiche e, a chi può, «Nuovo evento» e
     * «Nuovo progetto» (il modulo di creazione resta nel pannello: è lungo e si usa di rado). Per un'area di aule e laboratori: prenotazioni e risorse.
     *
     * @param array<string, mixed> $a id, titolo, colore, calendario, intera, crea_eventi, crea_progetti, eventi, progetti, iscritti, messaggi, da_approvare
     * @return array<string, mixed>|null
     */
    public static function area(array $a): ?array
    {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $q = '?p_id=' . $id;
        $mess = (int) ($a['messaggi'] ?? 0);
        $appr = (int) ($a['da_approvare'] ?? 0);
        $colore = (string) ($a['colore'] ?? '#0056B3');
        if (!empty($a['calendario'])) {
            return self::voce('area_' . $id, (string) $a['titolo'], (int) ($a['risorse'] ?? 0) . ' risorse · ' . (int) ($a['prossime'] ?? 0) . ' prenotazioni in arrivo', 'fa-door-open', $colore,
                'admin/prenotazioni_risorse.php' . $q, $appr, 'da approvare', [
                    ['Prenotazioni', 'admin/prenotazioni_risorse.php' . $q, false, $appr > 0],
                    ['Risorse e orari', 'admin/risorse.php' . $q],
                ]);
        }
        $altri = [
            ['Iscritti e presenze', 'admin/iscritti.php' . $q, false, $appr > 0],
            ['Scanner presenze', 'admin/scanner.php' . $q],
            ['Messaggi' . ($mess > 0 ? ' (' . $mess . ')' : ''), 'admin/messaggi.php' . $q, false, $mess > 0],
            ['Eventi e turni', 'admin/eventi.php' . $q],
            ['Sondaggi', 'admin/sondaggi.php' . $q],
            ['Statistiche', 'admin/statistiche.php' . $q],
        ];
        if (!empty($a['crea_eventi'])) {
            $altri[] = ['Nuovo evento', 'admin/eventi.php' . $q . '&azione=nuovo'];
        }
        if (!empty($a['crea_progetti'])) {
            $altri[] = ['Nuovo progetto', 'admin/progetti.php' . $q . '&azione=nuovo'];
        }
        $testo = (int) ($a['eventi'] ?? 0) . ' eventi · ' . (int) ($a['progetti'] ?? 0) . ' progetti · ' . (int) ($a['iscritti'] ?? 0) . ' iscritti';

        return self::voce('area_' . $id, (string) $a['titolo'], $testo, 'fa-calendar-day', $colore, 'admin/dashboard.php' . $q, $mess + $appr, 'da guardare', $altri);
    }

    /**
     * @param list<array{0: string, 1: string, 2?: bool, 3?: bool}> $altri
     * @return array{chiave: string, titolo: string, testo: string, icona: string, colore: string, href: string, novita: int, novita_et: string, altri: list<array{0: string, 1: string, 2?: bool, 3?: bool}>}
     */
    private static function voce(string $chiave, string $titolo, string $testo, string $icona, string $colore, string $href, int $novita, string $novitaEt, array $altri = []): array
    {
        return ['chiave' => $chiave, 'titolo' => $titolo, 'testo' => $testo, 'icona' => $icona, 'colore' => $colore, 'href' => $href, 'novita' => max(0, $novita), 'novita_et' => $novitaEt, 'altri' => $altri];
    }
}
