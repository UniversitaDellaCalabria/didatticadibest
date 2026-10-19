<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Elenco di tutte le email del portale con il testo originale. Ogni modello ha: gruppo, nome, quando parte, a chi, segnaposto
 * (descrizione ed esempio per l'anteprima), oggetto, testo e pulsante (null = senza pulsante). Il formato del testo è spiegato in ModelliEmail.
 * Un'email nuova si aggiunge qui; la pagina «Email» del pannello la mostra da sola.
 */
final class CatalogoEmail
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        return self::$cache ??= self::tutorato();
    }

    /** @return list<string> i gruppi, nell'ordine del catalogo */
    public static function gruppi(): array
    {
        return array_values(array_unique(array_column(self::modelli(), 'gruppo')));
    }

    /** Colore dell'impaginazione dell'email di un gruppo (null = rosso istituzionale). */
    public static function colore(string $gruppo): ?string
    {
        return str_starts_with($gruppo, 'Tutorato') ? '#047857' : null;
    }

    /** Descrizioni ed esempi dei segnaposto più usati. @return array<string, array{d: string, esempio: string}> */
    private static function dizionario(): array
    {
        return [
            'tutor' => ['d' => 'Nome e cognome del tutor', 'esempio' => 'Maria Verdi'],
            'tutor_cn' => ['d' => 'Cognome e nome del tutor', 'esempio' => 'Verdi Maria'],
            'bando' => ['d' => 'Titolo del bando di tutorato', 'esempio' => 'Tutorato I semestre 2026/2027'],
            'docente' => ['d' => 'Nome e cognome del docente responsabile', 'esempio' => 'Anna Rossi'],
            'docente_con_titolo' => ['d' => 'Titolo, nome e cognome del docente responsabile', 'esempio' => 'Prof.ssa Anna Rossi'],
            'ore' => ['d' => 'Ore (approvate o segnate, secondo l\'email)', 'esempio' => '38'],
            'ore_previste' => ['d' => 'Ore previste dalla lettera di incarico', 'esempio' => '40'],
            'data' => ['d' => 'Data', 'esempio' => '15/03/2027'],
            'codice' => ['d' => 'Codice della lettera di incarico', 'esempio' => 'TUT-2026-014'],
        ];
    }

    /**
     * @param array<string, string|array{d: string, esempio: string}> $elenco nome => descrizione ed esempio, oppure solo il nome se è nel dizionario
     * @return array<string, array{d: string, esempio: string}>
     */
    private static function sp(array $elenco): array
    {
        $diz = self::dizionario();
        $r = [];
        foreach ($elenco as $k => $v) {
            if (is_int($k)) {
                $r[$v] = $diz[ltrim($v, '!')];
            } else {
                $r[$k] = $v;
            }
        }

        return $r;
    }

    /**
     * @param array<string, string|array{d: string, esempio: string}> $segnaposto
     * @return array<string, mixed>
     */
    private static function m(string $gruppo, string $nome, string $quando, string $a, array $segnaposto, string $oggetto, string $corpo, ?string $bottone = null): array
    {
        return ['gruppo' => $gruppo, 'nome' => $nome, 'quando' => $quando, 'destinatario' => $a, 'segnaposto' => self::sp($segnaposto), 'oggetto' => $oggetto, 'corpo' => $corpo, 'bottone' => $bottone];
    }

    /** @return array<string, array<string, mixed>> */
    private static function tutorato(): array
    {
        $L = 'Tutorato · Lettera di incarico';
        $R = 'Tutorato · Registro e fine attività';
        $F = 'Tutorato · Firme ferme';
        $P = 'Tutorato · Pagamenti';
        $E = ['tutor', 'tutor_cn', 'bando'];

        return [
            'tutorato_invito_studente' => self::m($L, 'Invito al tutor a confermare la lettera (iter nel portale)', "L'ufficio preme «Invia email allo studente» (o «Invia di nuovo l'email»).", 'Il tutor vincitore', ['tutor', 'bando'],
                'Lettera di incarico di tutorato: controlla e conferma',
                "Gentile {{tutor}},\n\nè pronta la tua **lettera di incarico** per le attività di tutorato ({{bando}}).\n\nAccedi al portale **con SPID o CIE**, controlla i dati e clicca su **Confermo e accetto**: la conferma, con i dati della tua identità digitale, vale come accettazione dell'incarico. Poi la lettera passa alla firma del docente responsabile e del Direttore.\n\nSe trovi un errore nei dati segnalalo dalla stessa pagina.",
                'Controlla e conferma la lettera'),
            'tutorato_invito_studente_uniticket' => self::m($L, 'Invito al tutor a compilare e accettare (iter per Uniticket)', "Come sopra, ma solo dopo «Presa di servizio verificata» (bando o lettera con l'iter per Uniticket).", 'Il tutor vincitore', ['tutor', 'bando'],
                'Lettera di incarico di tutorato: compila e accetta',
                "Gentile {{tutor}},\n\nè pronta la tua **lettera di incarico** per le attività di tutorato ({{bando}}).\n\nAbbiamo verificato la tua presa di servizio su Uniticket. Ora devi **compilare i dati richiesti** (dichiarazione individuale: dati personali, accredito e posizione contributiva) e **accettare l'incarico**.\n\nAccedi al portale **con SPID o CIE**, controlla i dati e clicca su **Confermo e accetto**: la conferma, con i dati della tua identità digitale, vale come accettazione dell'incarico. Poi la lettera passa alla firma del docente responsabile e del Direttore.\n\nSe trovi un errore nei dati segnalalo dalla stessa pagina.",
                'Compila e accetta la lettera'),
            'tutorato_docente_da_firmare' => self::m($L, 'Al docente: lettera da firmare', 'Il tutor ha confermato la lettera.', 'Il docente responsabile', ['tutor', 'tutor_cn', 'bando', 'docente'],
                'Lettera di incarico da firmare: {{tutor_cn}}',
                "Gentile Prof. {{docente}},\n\n{{tutor}} ha accettato l'incarico di tutorato ({{bando}}) di cui è **responsabile dell'attività**.\n\nApra la lettera e la firmi digitalmente **in formato PAdES**: con la firma remota Aruba direttamente dal portale, oppure scaricandola e caricando il PDF firmato. Dopo la sua firma la lettera passa al Direttore.",
                'Apri e firma la lettera'),
            'tutorato_direttore_da_firmare' => self::m($L, 'Al Direttore: lettera da firmare', 'Il docente ha firmato la lettera.', 'Il Direttore del Dipartimento', ['tutor', 'tutor_cn', 'bando', 'docente_chi' => ['d' => 'Titolo, nome e cognome del docente che ha firmato', 'esempio' => 'Prof. Anna Rossi']],
                'Lettera di incarico di tutorato da firmare: {{tutor_cn}}',
                "Gentile Direttore,\n\nla lettera di incarico di tutorato di **{{tutor}}** ({{bando}}) è stata accettata dallo studente e firmata dal responsabile dell'attività, {{docente_chi}}.\n\nLa firmi digitalmente **in formato PAdES** (firma remota Aruba dal portale o caricando il PDF firmato): poi la lettera va all'Ufficio per il protocollo.",
                'Apri e firma la lettera'),
            'tutorato_tutor_registro_aperto' => self::m($L, 'Al tutor: lettera firmata, registro aperto', 'Il Direttore ha firmato la lettera.', 'Il tutor', ['tutor'],
                'Tutorato: lettera di incarico firmata, registro delle attività aperto',
                "Gentile {{tutor}},\n\nla tua lettera di incarico è firmata dal docente responsabile e dal Direttore. Da ora segna nel **registro delle attività** i giorni, le ore e le attività svolte: il docente le approva e a fine incarico dichiari concluse le attività.",
                'Apri il registro'),
            'tutorato_operatore_da_protocollare' => self::m($L, "All'operatore: lettera firmata da protocollare", "Il Direttore ha firmato la lettera.", "L'operatore scelto nel bando, altrimenti chi ha il compito «Bandi», altrimenti gli amministratori", ['codice', 'tutor', 'tutor_cn', 'bando'],
                'Lettera di incarico firmata: da protocollare – {{tutor_cn}}',
                "La lettera di incarico **{{codice}}** di {{tutor}} ({{bando}}) ha tutte le firme: accettazione dello studente con identità digitale, firma PAdES del responsabile dell'attività e del Direttore.\n\nScarica il PDF firmato e protocollalo, poi registra il numero di protocollo nel pannello.",
                'Scarica e protocolla'),
            'tutorato_copia_protocollata' => self::m($L, 'Al tutor: copia della lettera protocollata', "L'ufficio registra il protocollo con la casella «invia allo studente la lettera firmata».", 'Il tutor (con la lettera firmata in PDF allegata)', ['tutor', 'protocollo' => ['d' => 'Numero di protocollo', 'esempio' => '12345'], 'data'],
                'Lettera di incarico di tutorato firmata (prot. {{protocollo}})',
                "Gentile {{tutor}},\n\nin allegato la tua lettera di incarico con tutte le firme, protocollata con il n. {{protocollo}} del {{data}}."),
            'tutorato_segnalazione_errore' => self::m($L, "All'operatore: errore segnalato dal tutor", 'Il tutor preme «segnala un errore nei dati» nella pagina della lettera.', "Gli operatori (come per la lettera da protocollare)", ['codice', 'tutor_cn', 'nota' => ['d' => 'Il testo scritto dal tutor', 'esempio' => 'Il mio indirizzo di residenza è sbagliato.']],
                'Lettera di incarico: segnalazione di {{tutor_cn}}',
                "Lo studente segnala un errore nei dati della lettera **{{codice}}**:\n\n> {{nota}}\n\nAnnulla la lettera e preparane una corretta (puoi duplicarla), poi inviala di nuovo.",
                'Apri nel pannello'),

            'tutorato_promemoria_registro_scadenza' => self::m($R, 'Promemoria al tutor: periodo in scadenza (automatico)', "Ogni giorno il portale controlla; al massimo una volta ogni 7 giorni per tutor, negli ultimi 7 giorni prima della fine del periodo e dopo, finché il tutor non conclude.", 'Il tutor',
                ['tutor', 'periodo' => ['d' => 'Quando finisce il periodo: «termina il 15/03/2027» oppure «è terminato il 15/03/2027»', 'esempio' => 'termina il 15/03/2027'], 'ore_segnate' => ['d' => 'Ore già segnate nel registro', 'esempio' => '30'], 'ore_previste'],
                'Tutorato: registro delle attività',
                "Gentile {{tutor}},\n\nil periodo dell'incarico {{periodo}}: completa il registro delle attività e, quando hai finito, clicca su **«Ho concluso le attività»**. Il docente responsabile confermerà le ore.\n\nOre segnate: {{ore_segnate}} su {{ore_previste}}.",
                'Apri il registro'),
            'tutorato_promemoria_registro_fermo' => self::m($R, 'Promemoria al tutor: registro fermo (automatico)', "Come sopra, quando sono passate più di due settimane dalla firma del Direttore e il registro non ha attività recenti.", 'Il tutor',
                ['tutor', 'ore_segnate' => ['d' => 'Ore già segnate nel registro', 'esempio' => '12'], 'ore_previste'],
                'Tutorato: registro delle attività',
                "Gentile {{tutor}},\n\nnon segni attività nel registro da più di due settimane: aggiornalo con i giorni, le ore e le attività svolte.\n\nOre segnate: {{ore_segnate}} su {{ore_previste}}.",
                'Apri il registro'),
            'tutorato_promemoria_docente_fine' => self::m($R, 'Promemoria al docente: fine attività dichiarata (automatico)', 'Al massimo una volta ogni 7 giorni, se il tutor ha dichiarato concluse le attività da più di 3 giorni.', 'Il docente responsabile', ['tutor', 'tutor_cn'],
                'Tutorato: registro di {{tutor_cn}} da controllare',
                "Nel registro delle attività di tutorato di **{{tutor}}** il tutor ha dichiarato concluse le attività: approvi le ore e confermi la fine.",
                'Apri il registro'),
            'tutorato_promemoria_docente_ore' => self::m($R, 'Promemoria al docente: ore da approvare (automatico)', 'Al massimo una volta ogni 7 giorni, se ci sono ore da approvare da più di 7 giorni.', 'Il docente responsabile', ['tutor', 'tutor_cn'],
                'Tutorato: registro di {{tutor_cn}} da controllare',
                "Nel registro delle attività di tutorato di **{{tutor}}** ci sono ore da approvare.",
                'Apri il registro'),
            'tutorato_fine_dichiarata' => self::m($R, 'Al docente: il tutor ha dichiarato concluse le attività', 'Il tutor preme «Ho concluso le attività».', 'Il docente responsabile', ['docente_con_titolo', 'tutor', 'tutor_cn', 'bando', 'ore', 'ore_previste', 'da_approvare' => ['d' => 'Ore ancora da approvare, tra parentesi (vuoto se non ce ne sono)', 'esempio' => ' (2 da approvare)']],
                'Tutorato: fine attività di {{tutor_cn}}',
                "Gentile {{docente_con_titolo}},\n\n{{tutor}} ha dichiarato concluse le attività di tutorato ({{bando}}) di cui è responsabile.\n\nOre nel registro: **{{ore}}** su {{ore_previste}}{{da_approvare}}.\n\nControlli il registro, approvi le ore e confermi la fine delle attività: il portale prepara la dichiarazione di fine attività da firmare in PAdES.",
                'Apri il registro'),
            'tutorato_fine_firmata_operatore' => self::m($R, "All'operatore: fine attività firmata, da validare", 'Il docente ha firmato la dichiarazione di fine attività (con il PDF firmato allegato).', 'Gli operatori (come per la lettera da protocollare)', ['tutor', 'tutor_cn', 'bando', 'ore', 'ore_previste', '!tabella' => ['d' => 'Tabella delle giornate approvate (la prepara il portale)', 'esempio' => "<table style='border-collapse:collapse;font-size:13px;'><tr><th style='text-align:left;padding:3px 8px;'>Data</th><th style='padding:3px 8px;'>Ore</th><th style='text-align:left;padding:3px 8px;'>Attività</th></tr><tr><td style='padding:3px 8px;'>10/02/2027</td><td style='padding:3px 8px;text-align:center;'>4</td><td style='padding:3px 8px;'>Esercitazioni</td></tr></table>"]],
                'Fine attività firmata: da validare – {{tutor_cn}} – {{ore}} ore',
                "Il tutor **{{tutor}}** ({{bando}}) ha completato le attività: **{{ore}} ore** approvate su {{ore_previste}}.\n\nLa dichiarazione di fine attività è firmata in PAdES dal docente titolare (non serve il protocollo): controlla ore e documenti e passa la pratica all'ufficio pagamenti dalla vista «Da validare» del tutorato.\n\n{{!tabella}}",
                'Apri le pratiche da validare'),
            'tutorato_attivita_completate' => self::m($R, 'Al tutor: attività completate', 'Il docente ha firmato la dichiarazione di fine attività.', 'Il tutor', ['tutor', 'ore'],
                'Tutorato: attività completate',
                "Gentile {{tutor}},\n\nil docente responsabile ha confermato la fine delle tue attività di tutorato: **{{ore}} ore**. L'Ufficio procede con gli adempimenti per il compenso."),
            'tutorato_fine_da_rivedere' => self::m($R, 'Al docente: fine attività da rivedere', 'Il referente non valida la fine attività e la rimanda con un motivo.', 'Il docente responsabile', ['docente_con_titolo', 'tutor', 'tutor_cn', 'bando', 'motivo' => ['d' => 'Il motivo scritto dal referente', 'esempio' => 'Mancano due giornate nel registro.']],
                'Tutorato: fine attività da rivedere – {{tutor_cn}}',
                "Gentile {{docente_con_titolo}},\n\nil referente del tutorato ha controllato la fine attività di **{{tutor}}** ({{bando}}) e la rimanda a lei per questo motivo:\n\n> {{motivo}}\n\nControlli il registro, approvi le ore e confermi di nuovo la fine delle attività: il portale prepara la dichiarazione da firmare.",
                'Apri il registro'),

            'tutorato_sollecito_firma' => self::m($F, 'Sollecito della firma (automatico)', 'La lettera (docente o Direttore) o la dichiarazione di fine attività (docente) aspetta la firma da più di 5 giorni (impostabile nel .env con FIRME_GIORNI_SOLLECITO); fino a 3 solleciti.', 'Il docente o il Direttore, secondo chi deve firmare',
                ['documento' => ['d' => '«la lettera di incarico» oppure «la dichiarazione di fine attività»', 'esempio' => 'la lettera di incarico'], 'tutor', 'tutor_cn', 'bando', 'data' => ['d' => 'Da quando aspetta la firma', 'esempio' => '10/03/2027']],
                'Sollecito: {{documento}} di {{tutor_cn}} è in attesa della sua firma',
                "Gentile,\n\n{{documento}} di tutorato di **{{tutor}}** ({{bando}}) aspetta la sua firma digitale in PAdES dal {{data}}.",
                'Apri e firma'),
            'tutorato_firma_ferma' => self::m($F, "All'operatore: firma ferma dopo tre solleciti", 'Al terzo sollecito senza risposta.', 'Gli operatori (come per la lettera da protocollare)',
                ['documento' => ['d' => '«la lettera di incarico» oppure «la dichiarazione di fine attività»', 'esempio' => 'la lettera di incarico'], 'tutor_cn', 'firmatario' => ['d' => "Indirizzo email di chi deve firmare", 'esempio' => 'anna.rossi@unical.it']],
                'Firma ferma: {{documento}} di {{tutor_cn}}',
                "Dopo tre solleciti {{documento}} non è ancora firmata da {{firmatario}}: serve un contatto diretto.",
                'Apri nel pannello'),

            'tutorato_pagamenti_da_pagare' => self::m($P, "All'ufficio pagamenti: pratiche da pagare", 'Il referente passa una o più pratiche ai pagamenti (una sola email per ufficio, con l\'elenco).', "L'ufficio pagamenti",
                ['ufficio' => ['d' => "Nome dell'ufficio", 'esempio' => 'Ufficio pagamenti'], 'referente' => ['d' => 'Chi ha passato la pratica', 'esempio' => 'Il referente del tutorato'], 'quante' => ['d' => '«una pratica» oppure «3 pratiche»', 'esempio' => '3 pratiche'], 'dettaglio' => ['d' => 'Se la pratica è una sola, « – Cognome Nome» (altrimenti vuoto)', 'esempio' => ''], '!elenco' => ['d' => 'Elenco delle pratiche (lo prepara il portale)', 'esempio' => '<ul><li><strong>Verdi Maria</strong> · Tutorato I semestre · 38 ore</li></ul>']],
                'Tutorato: {{quante}} da pagare{{dettaglio}}',
                "Gentile {{ufficio}},\n\n{{referente}} ha validato e passato **{{quante}}** all'ufficio pagamenti:\n\n{{!elenco}}\n\nNella scheda trovi decreti, UNILAV, lettera di incarico, registro, fine attività e dati per il pagamento.",
                'Apri le pratiche da pagare'),
            'tutorato_pagamenti_rimandata' => self::m($P, "Ai referenti: pratica rimandata dall'ufficio pagamenti", "L'ufficio pagamenti rimanda la pratica con una nota.", 'I referenti del tutorato',
                ['tutor', 'tutor_cn', 'bando', 'da_chi' => ['d' => 'Chi l\'ha rimandata, tra parentesi (vuoto se non si sa)', 'esempio' => ' (Ufficio pagamenti)'], 'nota' => ['d' => "La nota dell'ufficio pagamenti", 'esempio' => 'Manca il codice IBAN.']],
                "Tutorato: pratica rimandata dall'ufficio pagamenti – {{tutor_cn}}",
                "L'ufficio pagamenti ha rimandato la pratica di **{{tutor}}** ({{bando}}){{da_chi}} per questo motivo:\n\n> {{nota}}\n\nCorreggi quanto serve e passala di nuovo ai pagamenti.",
                'Apri le pratiche da validare'),
            'tutorato_pagamento_disposto' => self::m($P, 'Al tutor: pagamento disposto', 'L\'ufficio pagamenti dispone il pagamento.', 'Il tutor', ['tutor', 'bando', 'data', 'riferimento' => ['d' => 'Riferimento del pagamento, tra parentesi (vuoto se non c\'è)', 'esempio' => ' (riferimento: 2027/0123)']],
                'Tutorato: pagamento disposto',
                "Gentile {{tutor}},\n\nil pagamento del tuo incarico di tutorato ({{bando}}) è stato **disposto il {{data}}**{{riferimento}} sull'IBAN che hai indicato.\n\nCon questo la pratica è conclusa. Nell'Area personale trovi tutti i tuoi documenti.",
                "Apri l'Area personale"),
        ];
    }
}
