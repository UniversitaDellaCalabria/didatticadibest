<?php

declare(strict_types=1);

namespace App\Email;

/** Le email della Didattica: pratiche degli studenti (studente, uffici, firme, conferme dei docenti) e verbali delle sedute. Formato dei testi: vedi ModelliEmail. */
final class CatalogoDidattica
{
    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        $S = 'Didattica · Pratiche · Allo studente';
        $U = 'Didattica · Pratiche · Agli uffici e agli operatori';
        $F = 'Didattica · Pratiche · Firme e conferme';
        $V = 'Didattica · Sedute e verbali';
        $m = static fn (string $g, string $nome, string $quando, string $a, array $sp, string $oggetto, string $corpo, ?string $bottone = null, ?string $colore = null): array => CatalogoEmail::m($g, $nome, $quando, $a, $sp, $oggetto, $corpo, $bottone, $colore);
        $P = ['modulo', 'codice'];
        $riga = "**{{modulo}}** · pratica **{{codice}}**";

        return [
            // ---- allo studente
            'pratica_ricevuta' => $m($S, 'Pratica ricevuta', 'Lo studente invia una pratica.', 'Lo studente', ['nome', ...$P],
                'Pratica ricevuta: {{modulo}}',
                "Gentile {{nome}},\n\nabbiamo ricevuto la tua richiesta.\n\n$riga\n\nPuoi seguirne lo stato e scrivere all'ufficio dalla tua Area personale.",
                'Vedi la pratica'),
            'pratica_stato' => $m($S, 'La pratica cambia stato', "L'ufficio cambia lo stato della pratica (in lavorazione, accolta, respinta, integrazione richiesta, chiusa…).", 'Lo studente',
                ['nome', ...$P, 'stato' => ['d' => 'Il nuovo stato della pratica', 'esempio' => 'Accolta'], '!nota' => ['d' => "La nota dell'ufficio, in un riquadro (vuota se non c'è)", 'esempio' => "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>Il documento è pronto.</p>"]],
                'Pratica {{codice}}: {{stato}}',
                "Gentile {{nome}},\n\nla tua pratica è ora: **{{stato}}**.\n\n$riga\n\n{{!nota}}",
                'Vedi la pratica'),
            'pratica_messaggio_ufficio' => $m($S, "Messaggio dell'ufficio", "L'ufficio scrive allo studente dalla pratica.", 'Lo studente',
                ['nome', ...$P, '!messaggio' => ['d' => "Il messaggio dell'ufficio, in un riquadro", 'esempio' => "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>Ci serve anche il tuo piano di studi.</p>"]],
                'Nuovo messaggio sulla pratica {{codice}}',
                "Gentile {{nome}}, l'ufficio ti ha scritto:\n\n$riga\n\n{{!messaggio}}",
                'Rispondi'),
            'pratica_passaggio' => $m($S, 'La pratica passa a un altro ufficio o operatore', 'La pratica viene assegnata a un ufficio o a una persona.', 'Lo studente',
                ['nome', ...$P, 'destinazione' => ['d' => 'A chi è passata la pratica', 'esempio' => 'Segreteria studenti']],
                'Pratica {{codice}}: passata a {{destinazione}}',
                "Gentile {{nome}},\n\nla tua pratica è passata a: **{{destinazione}}**.\n\n$riga\n\nSe l'ufficio ti chiede altri documenti li puoi aggiungere dalla pratica.",
                'Vedi la pratica'),
            'pratica_marche_caricate' => $m($S, 'Marche da bollo caricate', "L'ufficio carica le marche da bollo in Esse3.", 'Lo studente', ['nome', ...$P],
                'Puoi pagare le marche da bollo: {{modulo}}',
                "Gentile {{nome}},\n\nper la tua pratica **{{codice}}** ({{modulo}}) l'ufficio ha **caricato le marche da bollo in Esse3**: puoi procedere al pagamento dalla sezione Segreteria > Situazione tasse.\n\nQuando il pagamento risulta, la pratica prosegue e il documento viene firmato dal Direttore.",
                'Apri la pratica'),
            'pratica_documento_pronto' => $m($S, 'Documento firmato pronto', 'Il Direttore ha firmato il documento della pratica.', 'Lo studente', ['nome', ...$P],
                'Il tuo documento è pronto: {{modulo}}',
                "Gentile {{nome}},\n\nil documento richiesto con la pratica **{{codice}}** ({{modulo}}) è stato **firmato digitalmente dal Direttore**: puoi scaricarlo e stamparlo dalla pagina della pratica.",
                'Scarica il documento'),

            // ---- agli uffici e agli operatori
            'pratica_nuova_ufficio' => $m($U, 'Nuova pratica', "Lo studente invia una pratica (o l'ufficio ne apre una).", "I responsabili che smistano, oppure l'ufficio della pratica o del modulo", ['studente', 'studente_cn', ...$P, 'email_studente', 'matricola_testo'],
                'Nuova pratica: {{modulo}} – {{studente_cn}}',
                "È arrivata una nuova pratica.\n\n$riga\n\n{{studente}} · {{email_studente}}{{matricola_testo}}",
                'Apri nel pannello'),
            'pratica_messaggio_studente' => $m($U, 'Messaggio dello studente', 'Lo studente scrive dalla pratica.', "Chi ha la pratica in carico (e chi lo sostituisce se è assente), altrimenti l'ufficio", ['studente_cn', ...$P, '!messaggio' => ['d' => 'Il messaggio dello studente, in un riquadro', 'esempio' => "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>Ho caricato il documento richiesto.</p>"]],
                'Messaggio sulla pratica {{codice}} – {{studente_cn}}',
                "Nuovo messaggio dello studente:\n\n$riga\n\n{{!messaggio}}",
                'Apri nel pannello'),
            'pratica_assegnata' => $m($U, 'Pratica assegnata a una persona', 'Una pratica viene assegnata a un operatore.', "L'operatore",
                ['operatore' => ['d' => "Nome dell'operatore", 'esempio' => 'Anna Protocollo'], 'studente_cn', ...$P, 'passo' => ['d' => 'Il passo o ruolo in cui la riceve', 'esempio' => 'Segreteria'], 'da_chi' => ['d' => "Chi l'ha assegnata, preceduto da « da » (vuoto se non si sa)", 'esempio' => ' da Mario Bianchi'], '!nota' => ['d' => 'La nota di chi assegna, in un riquadro (vuota se non c\'è)', 'esempio' => '']],
                'Pratica assegnata: {{modulo}} – {{studente_cn}}',
                "Gentile {{operatore}},\n\nti è stata assegnata la pratica **{{codice}}** ({{modulo}}) come **{{passo}}**{{da_chi}}.\n\n{{!nota}}",
                'Apri la pratica'),
            'pratica_ufficio_arrivata' => $m($U, "Pratica arrivata a un ufficio", "Una pratica viene passata a un ufficio e l'ufficio ha l'avviso per le sue persone attivo.", "Le persone dell'ufficio",
                ['operatore' => ['d' => 'Nome della persona che riceve', 'esempio' => 'Anna Protocollo'], 'ufficio' => ['d' => "Nome dell'ufficio", 'esempio' => 'Ufficio protocollo'], 'studente_cn', ...$P, 'corso' => ['d' => 'Corso di studio, preceduto da « del corso » (vuoto se non c\'è)', 'esempio' => ' del corso Chimica'], 'assegnazione' => ['d' => 'Chi la ha in carico: «: è assegnata a te.» oppure «: la può prendere in carico chiunque dell\'ufficio.»', 'esempio' => ': è assegnata a te.'], '!nota' => ['d' => 'La nota di chi passa la pratica, in un riquadro (vuota se non c\'è)', 'esempio' => '']],
                'Pratica per {{ufficio}}: {{modulo}} – {{studente_cn}}',
                "Gentile {{operatore}},\n\nè arrivata a **{{ufficio}}** la pratica **{{codice}}** ({{modulo}}){{corso}}{{assegnazione}}\n\n{{!nota}}",
                'Apri la pratica'),
            'pratica_richiesta_integrazione' => $m($U, 'Integrazione richiesta a un operatore', "Chi ha la pratica in carico chiede un'integrazione a un operatore che l'ha avuta prima.", "L'operatore che l'ha avuta prima",
                ['operatore' => ['d' => "Nome dell'operatore", 'esempio' => 'Anna Protocollo'], 'studente_cn', ...$P, 'da' => ['d' => 'Chi chiede', 'esempio' => 'Mario Bianchi'], '!testo' => ['d' => 'Cosa serve, in un riquadro', 'esempio' => "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>Manca il documento di identità.</p>"]],
                'Integrazione richiesta sulla pratica {{codice}} – {{studente_cn}}',
                "Gentile {{operatore}},\n\n{{da}} ti chiede di integrare la pratica **{{codice}}** ({{modulo}}), che hai avuto in carico:\n\n{{!testo}}\n\nAggiungi i documenti come attività o nota nella pratica.",
                'Apri la pratica'),
            'pratica_integrazione_aggiunta' => $m($U, 'Integrazione aggiunta da un operatore precedente', "Un operatore che aveva la pratica prima la integra con una nota o un documento.", 'Chi ha ora la pratica in carico',
                ['autore' => ['d' => "Chi ha aggiunto l'integrazione", 'esempio' => 'Ufficio protocollo (Anna Protocollo)'], 'autore_nome' => ['d' => 'Nome di chi ha aggiunto', 'esempio' => 'Anna Protocollo'], ...$P, '!testo' => ['d' => "Il testo aggiunto (e l'allegato), in un riquadro", 'esempio' => "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>Aggiunto il certificato.</p>"]],
                'Integrazione sulla pratica {{codice}} da {{autore_nome}}',
                "{{autore}}, che ha avuto in carico la pratica **{{codice}}** ({{modulo}}), ha aggiunto:\n\n{{!testo}}",
                'Apri la pratica'),
            'pratica_riepilogo_segreteria' => $m($U, 'Pratiche lavorate trasmesse alla segreteria', 'Dopo il Consiglio, le pratiche esaminate (con convalide, estratto e verbale) vengono trasmesse alla segreteria.', 'Le persone della segreteria (una sola email con la tabella)',
                ['operatore' => ['d' => 'Nome della persona che riceve', 'esempio' => 'Anna Protocollo'], 'quante' => ['d' => 'Quante pratiche', 'esempio' => '3'], 'ufficio' => ['d' => "Nome dell'ufficio", 'esempio' => 'Segreteria studenti'], 'autore' => ['d' => 'Chi le ha trasmesse', 'esempio' => 'Mario Bianchi'], '!tabella' => ['d' => 'Tabella delle pratiche (la prepara il portale)', 'esempio' => "<table style='border-collapse:collapse;font-size:13px;'><tr><th style='padding:4px 8px;border:1px solid #e2e8f0;'>Pratica</th><th style='padding:4px 8px;border:1px solid #e2e8f0;'>Studente</th></tr><tr><td style='padding:4px 8px;border:1px solid #e2e8f0;'>P-2027-001</td><td style='padding:4px 8px;border:1px solid #e2e8f0;'>Verdi Maria</td></tr></table>"]],
                '{{quante}} pratiche lavorate per {{ufficio}}',
                "Gentile {{operatore}},\n\n{{autore}} ha trasmesso a **{{ufficio}}** le pratiche esaminate dal Consiglio, con le convalide, l'estratto e il verbale della seduta:\n\n{{!tabella}}",
                "Apri le pratiche dell'ufficio"),
            'pratica_ferma_in_carico' => $m($U, 'Pratica ferma: in carico a una persona (promemoria automatico)', 'Il cron controlla ogni giorno: la pratica non ha movimenti da più giorni di quelli stabiliti per il passo (e non è sospeso il promemoria).', 'La persona che ha la pratica in carico (e chi la sostituisce)',
                ['studente', 'studente_cn', ...$P, 'giorni' => ['d' => 'Da quanti giorni è ferma', 'esempio' => '12']],
                'Pratica ferma da {{giorni}} giorni: {{modulo}} – {{studente_cn}}',
                "La pratica **{{codice}}** ({{modulo}}) di {{studente}} non ha movimenti da **{{giorni}} giorni** ed è in carico a te.",
                'Apri la pratica'),
            'pratica_ferma_in_ufficio' => $m($U, "Pratica ferma: all'ufficio ma senza nessuno in carico (promemoria automatico)", "Come sopra, quando la pratica è in un ufficio ma nessuno l'ha presa in carico.", "Le persone dell'ufficio",
                ['studente', 'studente_cn', ...$P, 'giorni' => ['d' => 'Da quanti giorni è ferma', 'esempio' => '12']],
                'Pratica ferma da {{giorni}} giorni: {{modulo}} – {{studente_cn}}',
                "La pratica **{{codice}}** ({{modulo}}) di {{studente}} non ha movimenti da **{{giorni}} giorni** ed è all'ufficio, ma nessuno l'ha ancora presa in carico.",
                'Apri la pratica'),
            'pratica_ferma_da_smistare' => $m($U, 'Pratica ferma: non ancora smistata (promemoria automatico)', 'Come sopra, quando la pratica non è stata ancora smistata.', 'Chi smista le pratiche (o l\'ufficio del modulo)',
                ['studente', 'studente_cn', ...$P, 'giorni' => ['d' => 'Da quanti giorni è ferma', 'esempio' => '12']],
                'Pratica ferma da {{giorni}} giorni: {{modulo}} – {{studente_cn}}',
                "La pratica **{{codice}}** ({{modulo}}) di {{studente}} non ha movimenti da **{{giorni}} giorni** e non è ancora stata smistata.",
                'Apri la pratica'),
            'pratica_iter_concluso' => $m($U, 'Iter concluso', "Tutti i passi dell'iter della pratica sono completati.", 'Chi ha in carico la pratica (e chi l\'ha avuta prima)', ['codice'],
                'Iter concluso: pratica {{codice}}',
                "La pratica **{{codice}}** ha concluso tutti i passi dell'iter: puoi chiuderla.",
                'Apri la pratica'),
            'pratica_firma_rifiutata' => $m($U, 'Firma rifiutata', 'Chi doveva firmare la pratica rifiuta e scrive il motivo.', 'Chi ha in carico la pratica (e chi l\'ha avuta prima)',
                ['firmatario' => ['d' => 'Chi ha rifiutato', 'esempio' => 'Prof. Mario Bianchi'], 'codice', 'motivo' => ['d' => 'Il motivo del rifiuto', 'esempio' => 'Manca il timbro.']],
                'Firma rifiutata sulla pratica {{codice}}',
                "{{firmatario}} ha rifiutato di firmare la pratica **{{codice}}**: {{motivo}}\n\nLa pratica è tornata allo studente come «integrazione richiesta».",
                'Apri la pratica'),

            // ---- firme e conferme dei docenti
            'pratica_firma_richiesta' => $m($F, 'Firma richiesta', 'Una pratica aspetta la firma digitale (Direttore o altro firmatario).', 'Chi deve firmare',
                ['firmatario' => ['d' => 'Nome di chi deve firmare', 'esempio' => 'Prof. Mario Bianchi'], 'studente', 'studente_cn', ...$P, 'ruolo' => ['d' => '«Direttore» oppure «firmatario»', 'esempio' => 'Direttore']],
                'Firma richiesta: {{modulo}} – {{studente_cn}}',
                "Gentile {{firmatario}},\n\nè in attesa la tua firma digitale sulla pratica **{{codice}}** ({{modulo}}) di {{studente}} ({{ruolo}}). Accedi con le tue credenziali di Ateneo, controlla il documento e firma (o rifiuta indicando il motivo).",
                'Apri e firma'),
            'pratica_firma_promemoria' => $m($F, 'Firma richiesta: promemoria', 'Il cron (o «Rimanda l\'email») ricorda la firma ancora in attesa, fino a tre volte.', 'Chi deve firmare',
                ['firmatario' => ['d' => 'Nome di chi deve firmare', 'esempio' => 'Prof. Mario Bianchi'], 'studente', 'studente_cn', ...$P, 'ruolo' => ['d' => '«Direttore» oppure «firmatario»', 'esempio' => 'Direttore']],
                'Promemoria · Firma richiesta: {{modulo}} – {{studente_cn}}',
                "Gentile {{firmatario}},\n\nti ricordiamo che è ancora in attesa la tua firma digitale sulla pratica **{{codice}}** ({{modulo}}) di {{studente}} ({{ruolo}}). Accedi con le tue credenziali di Ateneo, controlla il documento e firma (o rifiuta indicando il motivo).",
                'Apri e firma'),
            'pratica_conferma_docente' => $m($F, 'Conferma richiesta al docente', "Lo studente indica un docente in una richiesta (per esempio i crediti in un'attività) e il docente deve confermare.", 'Il docente indicato',
                ['docente' => ['d' => 'Nome del docente', 'esempio' => 'Anna Rossi'], 'studente', 'studente_cn', 'matricola_testo', 'modulo', 'righe_testo' => ['d' => '«una riga che ti riguarda» oppure «3 righe che ti riguardano»', 'esempio' => '2 righe che ti riguardano']],
                'Da confermare: {{modulo}} – {{studente_cn}}',
                "Gentile {{docente}},\n\n{{studente}}{{matricola_testo}} ha presentato una richiesta (**{{modulo}}**) e ti ha indicato come docente: ti chiediamo di confermare {{righe_testo}}.\n\nAccedi con le tue credenziali di Ateneo, controlla i dati e premi «Confermo» (oppure «Non confermo», indicando il motivo: lo studente può correggere).",
                'Apri e conferma'),
            'pratica_conferma_docente_promemoria' => $m($F, 'Conferma richiesta al docente: promemoria', 'Il cron (o «Rimanda l\'email») ricorda al docente la conferma ancora in attesa.', 'Il docente indicato',
                ['docente' => ['d' => 'Nome del docente', 'esempio' => 'Anna Rossi'], 'studente', 'studente_cn', 'matricola_testo', 'modulo', 'righe_testo' => ['d' => '«una riga che ti riguarda» oppure «3 righe che ti riguardano»', 'esempio' => '2 righe che ti riguardano']],
                'Promemoria · Da confermare: {{modulo}} – {{studente_cn}}',
                "Gentile {{docente}},\n\n{{studente}}{{matricola_testo}} ha presentato una richiesta (**{{modulo}}**) e ti ha indicato come docente: è ancora in attesa la tua conferma {{righe_testo}}.\n\nAccedi con le tue credenziali di Ateneo, controlla i dati e premi «Confermo» (oppure «Non confermo», indicando il motivo: lo studente può correggere).",
                'Apri e conferma'),

            // ---- sedute e verbali
            'verbale_da_firmare' => $m($V, 'Verbale da firmare', 'Il verbale della seduta viene inviato alla firma del segretario, poi del coordinatore.', 'Il segretario verbalizzante, poi il coordinatore',
                ['seduta' => ['d' => 'Organo e data della seduta', 'esempio' => 'Consiglio di corso di studio del 12/03/2027'], 'organo' => ['d' => "L'organo", 'esempio' => 'Consiglio di corso di studio'], 'data_testo' => ['d' => 'Data preceduta da « del » (vuota se non c\'è)', 'esempio' => ' del 12/03/2027'], 'ruolo' => ['d' => '«segretario verbalizzante» oppure «coordinatore»', 'esempio' => 'segretario verbalizzante']],
                'Verbale da firmare: {{seduta}}',
                "Gentile,\n\nil verbale della seduta del **{{organo}}**{{data_testo}} aspetta la sua firma digitale in PAdES come **{{ruolo}}**.\n\nSi entra con le credenziali Unical, SPID o CIE. Il link è personale: non inoltrarlo.",
                'Apri e firma', '#0056B3'),
            'verbale_promemoria' => $m($V, 'Verbale da firmare: sollecito', 'Il cron (o il pulsante di sollecito della seduta) ricorda la firma, fino a tre volte.', 'Il segretario verbalizzante o il coordinatore, secondo chi deve firmare',
                ['seduta' => ['d' => 'Organo e data della seduta', 'esempio' => 'Consiglio di corso di studio del 12/03/2027'], 'organo' => ['d' => "L'organo", 'esempio' => 'Consiglio di corso di studio'], 'data_testo' => ['d' => 'Data preceduta da « del » (vuota se non c\'è)', 'esempio' => ' del 12/03/2027'], 'ruolo' => ['d' => '«segretario verbalizzante» oppure «coordinatore»', 'esempio' => 'segretario verbalizzante']],
                'Verbale da firmare: {{seduta}}',
                "Gentile,\n\nil verbale della seduta del **{{organo}}**{{data_testo}} aspetta la sua firma digitale in PAdES come **{{ruolo}} (sollecito)**.\n\nSi entra con le credenziali Unical, SPID o CIE. Il link è personale: non inoltrarlo.",
                'Apri e firma', '#0056B3'),
            'verbale_firmato' => $m($V, 'Verbale firmato', 'Il coordinatore ha firmato: il verbale è completo.', 'Il segretario e i referenti del consiglio (con il PDF firmato allegato)',
                ['seduta' => ['d' => 'Organo e data della seduta', 'esempio' => 'Consiglio di corso di studio del 12/03/2027'], 'organo' => ['d' => "L'organo", 'esempio' => 'Consiglio di corso di studio'], 'data_testo' => ['d' => 'Data preceduta da « del » (vuota se non c\'è)', 'esempio' => ' del 12/03/2027'], 'firmatario_testo' => ['d' => 'Titolare del certificato, tra parentesi (vuoto se non si legge)', 'esempio' => ' (Mario Bianchi)']],
                'Verbale firmato: {{seduta}}',
                "Il verbale della seduta del **{{organo}}**{{data_testo}} è firmato in PAdES dal segretario e dal coordinatore{{firmatario_testo}}.\n\nIl PDF è in allegato e nel pannello Didattica › Sedute.",
                null, '#15803d'),
        ];
    }
}
