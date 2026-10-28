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
     * @return list<array{chiave: string, titolo: string, testo: string, icona: string, colore: string, href: string, novita: int, novita_et: string, altri: list<array{0: string, 1: string, 2?: bool, 3?: bool}>}>
     */
    public static function elenco(array $p, array $n): array
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
                    ['Tutte', 'admin/didattica.php?tab=pratiche&stato=tutte'],
                ]);
            if ($n['protocollare'] > 0) {
                $out[] = self::voce('protocollo', 'Ufficio protocollo', 'Pratiche arrivate all\'ufficio protocollo: registra il numero e la data e la pratica prosegue.', 'fa-stamp', '#b45309',
                    'admin/didattica.php?tab=pratiche&carico=ufficio', $n['protocollare'], 'da protocollare');
            }
        }
        if ($p['didattica']) {
            $out[] = self::voce('sedute', $p['didattica_tutto'] ? 'Sedute e verbali' : 'Le sedute dei miei consigli', 'Consigli dei corsi di studio: ordine del giorno, presenze, decisioni sulle pratiche e verbali da firmare.', 'fa-gavel', '#7c3aed',
                'admin/didattica.php?tab=sedute', $n['verbali'], $n['verbali'] === 1 ? 'verbale in firma' : 'verbali in firma');
        }
        if ($p['conv_tesi']) {
            $out[] = self::voce('convenzioni_tesi', 'Convenzioni di tesi', 'Convenzioni con enti e aziende per svolgere la tesi: modifiche, protocollo, firma del Direttore.', 'fa-handshake', '#0e7490',
                'admin/convenzioni_tesi.php', $n['conv_tesi'], 'da seguire');
        }
        if ($p['tutorato']) {
            $out[] = self::voce('tutorato', 'Tutorato · lettere di incarico', 'Bandi e lettere dei vincitori, firme del docente e del Direttore, protocollo e validazione.', 'fa-user-graduate', '#0f766e',
                'admin/tutorato.php', $n['tutorato'], 'da validare');
        }
        if ($p['fsl_convenzioni']) {
            $out[] = self::voce('fsl_convenzioni', 'Convenzioni FSL con le scuole', 'Convenzioni caricate dalle scuole: da prendere in carico, far firmare e registrare.', 'fa-school', '#0369a1',
                'admin/fsl.php', $n['conv_fsl'], 'da seguire');
        }
        if ($p['assistenza']) {
            $pre = $n['assistenza_prendere'];
            $out[] = self::voce('assistenza', 'Chat di assistenza da gestire', 'Le chat di studenti e personale: da prendere, in carico a te, chiuse.', 'fa-headset', '#B30000',
                'admin/assistenza.php', $pre + $n['assistenza_mie'], $pre > 0 ? 'da prendere o aperte' : 'aperte in carico a te', [
                    ['Da prendere', 'admin/assistenza.php?vista=da_prendere', false, $pre > 0],
                    ['Le mie', 'admin/assistenza.php?vista=mie'],
                ]);
        }
        if ($p['aree']) {
            $out[] = self::voce('aree', $p['admin'] ? 'Gestione del portale' : 'Eventi e aree che gestisci', $p['admin']
                ? 'Aree, eventi, iscritti, utenti, sistema e tutto il resto della gestione.'
                : 'Gli eventi, i progetti e le aree per cui sei abilitato: iscritti, presenze, messaggi.', 'fa-screwdriver-wrench', '#475569', 'admin/inizio.php', 0, '');
        }

        return $out;
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
