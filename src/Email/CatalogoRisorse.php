<?php

declare(strict_types=1);

namespace App\Email;

/** Le email delle prenotazioni di risorse (aule, laboratori, auto): a chi prenota, ai gestori e ai responsabili. Formato dei testi: vedi ModelliEmail. */
final class CatalogoRisorse
{
    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        $P = 'Risorse · A chi prenota';
        $G = 'Risorse · Ai gestori e ai responsabili';
        $m = static fn (string $g, string $nome, string $quando, string $a, array $sp, string $oggetto, string $corpo, ?string $bottone = null): array => CatalogoEmail::m($g, $nome, $quando, $a, $sp, $oggetto, $corpo, $bottone);
        $raw = static fn (string $d, string $esempio = ''): array => ['d' => $d, 'esempio' => $esempio];
        $nome = ['d' => 'Nome e cognome di chi ha prenotato', 'esempio' => 'Maria Verdi'];
        $risorsa = ['d' => 'Nome della risorsa', 'esempio' => 'Aula Magna'];
        $quando = ['d' => 'Data e ora della prenotazione', 'esempio' => 'lunedì 15/03/2027, 10:00–12:00'];
        $pren = [
            'nome' => $nome, 'risorsa' => $risorsa,
            'luogo_testo' => ['d' => 'Il luogo tra parentesi (vuoto se non c\'è)', 'esempio' => ' (Cubo 15B)'],
            '!elenco' => $raw('Elenco delle date prenotate con il codice (lo prepara il portale)', '<ul><li>lunedì 15/03/2027, 10:00–12:00 · codice <strong>R-123</strong></li></ul>'),
            '!motivo' => $raw('Il motivo, se è stato scritto (vuoto altrimenti)', '<p>Motivo: riunione di corso.</p>'),
            '!dettagli' => $raw('Quantità e risposte ai campi della risorsa (vuoto se non ce ne sono)', ''),
            '!saltate' => $raw('Date non prenotate perché non disponibili (vuoto se non ce ne sono)', ''),
            '!ics' => $raw('Pulsante «Aggiungi al calendario» (.ics)', "<p><a href='#' style='background:#334155;color:#fff;padding:8px 14px;text-decoration:none;border-radius:4px;font-weight:bold;'>📥 Aggiungi al calendario (.ics)</a></p>"),
        ];
        $senzaIcs = array_diff_key($pren, ['!ics' => 1]);
        $coda = "Le tue prenotazioni sono nella tua Area personale, dove puoi anche annullarle.";
        $corpo = static fn (string $titolo, string $extra = '', bool $ics = true): string => "Gentile **{{nome}}**,\n\n**$titolo**: {{risorsa}}{{luogo_testo}}.\n\n{{!elenco}}\n\n{{!motivo}}\n\n{{!dettagli}}\n\n{{!saltate}}\n\n" . ($extra !== '' ? "$extra\n\n" : '') . ($ics ? "{{!ics}}\n\n" : '') . $coda;
        $btn = "Apri l'Area personale";
        $gest = [
            'persona' => ['d' => 'Nome e cognome di chi ha prenotato', 'esempio' => 'Maria Verdi'], 'email' => ['d' => 'Email di chi ha prenotato', 'esempio' => 'maria.verdi@studenti.unical.it'],
            'risorsa' => $risorsa, 'quando' => $quando, 'quando_breve' => ['d' => 'Data e ora in breve', 'esempio' => '15/03 10:00'],
            'altre_date' => ['d' => 'Se la serie ha più date: « e altre N date (ogni settimana)» (vuoto altrimenti)', 'esempio' => ' e altre 3 date (ogni settimana)'],
            '!motivo' => $raw('Il motivo, se è stato scritto (vuoto altrimenti)', ''), '!dettagli' => $raw('Quantità e risposte ai campi della risorsa (vuoto se non ce ne sono)', ''),
        ];

        return [
            'risorsa_registrata' => $m($P, 'Prenotazione confermata', 'Chi prenota una risorsa con conferma immediata.', 'Chi ha prenotato', $pren,
                'Prenotazione confermata: {{risorsa}}', $corpo('Prenotazione confermata'), $btn),
            'risorsa_in_attesa' => $m($P, 'Richiesta ricevuta, in attesa di approvazione', 'Chi prenota una risorsa che richiede l\'approvazione del gestore.', 'Chi ha prenotato', $pren,
                'Richiesta ricevuta, in attesa di approvazione: {{risorsa}}', $corpo('Richiesta ricevuta, in attesa di approvazione', "Riceverai un'email quando la richiesta sarà approvata."), $btn),
            'risorsa_approvata' => $m($P, 'Prenotazione approvata', 'Il gestore approva la richiesta.', 'Chi ha prenotato', $pren,
                'Prenotazione approvata: {{risorsa}}', $corpo('Prenotazione approvata'), $btn),
            'risorsa_rifiutata' => $m($P, 'Prenotazione non approvata', 'Il gestore rifiuta la richiesta.', 'Chi ha prenotato', $senzaIcs,
                'Prenotazione non approvata: {{risorsa}}', $corpo('Prenotazione non approvata', '', false), $btn),
            'risorsa_annullata_gestore' => $m($P, 'Prenotazione annullata dal gestore', 'Il gestore annulla una prenotazione.', 'Chi ha prenotato', $senzaIcs,
                'Prenotazione annullata: {{risorsa}}', $corpo('Prenotazione annullata', '', false), $btn),
            'risorsa_promemoria' => $m($P, 'Promemoria della prenotazione (automatico)', 'Il cron avvisa il giorno prima della prenotazione.', 'Chi ha prenotato', $pren,
                'Promemoria: prenotazione di domani: {{risorsa}}', $corpo('Promemoria: prenotazione di domani'), $btn),
            'risorsa_slot_libero' => $m($P, "Si è liberato uno slot (lista d'attesa)", "Una prenotazione viene annullata e la persona in coda riceve l'avviso.", "Chi è in lista d'attesa",
                ['nome' => $nome, 'risorsa' => $risorsa, 'quando' => $quando, 'quando_breve' => ['d' => 'Data e ora in breve', 'esempio' => '15/03 10:00'], 'ore' => ['d' => "Ore a disposizione per prenotare", 'esempio' => '24']],
                'Si è liberato uno slot: {{risorsa}} – {{quando_breve}}',
                "Gentile **{{nome}}**,\n\nsi è liberato lo slot per cui eri in lista d'attesa: **{{risorsa}}**, {{quando}}.\n\nHai tempo fino a circa {{ore}} ore per prenotarlo: poi l'avviso passa alla persona successiva in coda. Lo slot non resta riservato, quindi conviene non aspettare.",
                'Prenota ora'),
            'risorsa_diario' => $m($P, 'Diario di bordo da compilare', 'Dopo il viaggio, a chi ha prenotato e agli altri conducenti indicati.', 'Chi ha prenotato e i co-conducenti',
                ['nome' => $nome, 'risorsa' => $risorsa, 'quando' => $quando],
                'Diario di bordo da compilare: {{risorsa}}',
                "Gentile **{{nome}}**,\n\nhai usato **{{risorsa}}** ({{quando}}): ti chiediamo di compilare il **diario di bordo** del viaggio (km, carburante, segnalazioni). Chi ha indicato altri conducenti può compilarlo anche da loro.",
                'Compila il diario di bordo'),

            'risorsa_gestori_nuova' => $m($G, 'Nuova prenotazione', 'Qualcuno prenota una risorsa (conferma immediata).', 'I gestori e i responsabili della risorsa', $gest,
                'Nuova prenotazione: {{risorsa}} – {{quando_breve}}',
                "**{{persona}}** ({{email}}) ha prenotato **{{risorsa}}**: {{quando}}{{altre_date}}.\n\n{{!motivo}}\n\n{{!dettagli}}", 'Apri le prenotazioni'),
            'risorsa_gestori_da_approvare' => $m($G, 'Richiesta da approvare', 'Qualcuno chiede di prenotare una risorsa che richiede approvazione.', 'I gestori e i responsabili della risorsa', $gest,
                'Da approvare: {{risorsa}} – {{quando_breve}}',
                "**{{persona}}** ({{email}}) chiede di prenotare **{{risorsa}}**: {{quando}}{{altre_date}}.\n\n{{!motivo}}\n\n{{!dettagli}}", 'Approva o rifiuta'),
            'risorsa_gestori_annullata' => $m($G, 'Prenotazione annullata da chi aveva prenotato', 'Chi aveva prenotato annulla.', 'I gestori e i responsabili della risorsa', $gest,
                'Annullata: {{risorsa}} – {{quando_breve}}',
                "**{{persona}}** ({{email}}) ha annullato la prenotazione **{{risorsa}}**: {{quando}}{{altre_date}}.\n\n{{!motivo}}\n\n{{!dettagli}}", 'Apri le prenotazioni'),
            'risorsa_scadenza_in_arrivo' => $m($G, 'Scadenza in arrivo', 'Il cron avvisa prima della scadenza di una voce della risorsa (assicurazione, revisione…).', 'I responsabili e gli indirizzi di notifica della risorsa',
                ['voce' => ['d' => 'Nome della scadenza', 'esempio' => 'Assicurazione'], 'risorsa' => $risorsa, 'data' => ['d' => 'Data di scadenza', 'esempio' => '30/06/2027'], '!note' => $raw('Le note della scadenza (vuote se non ci sono)', '')],
                'In scadenza: {{voce}} – {{risorsa}}',
                "**{{voce}}** di **{{risorsa}}** scade il {{data}}.\n\n{{!note}}\n\nQuando è stata rinnovata inserisci la nuova data dal cruscotto della risorsa, così l'avviso si azzera.", 'Apri il cruscotto'),
            'risorsa_scadenza_scaduta' => $m($G, 'Scadenza superata', 'Il cron avvisa quando la scadenza è passata.', 'I responsabili e gli indirizzi di notifica della risorsa',
                ['voce' => ['d' => 'Nome della scadenza', 'esempio' => 'Assicurazione'], 'risorsa' => $risorsa, 'data' => ['d' => 'Data di scadenza', 'esempio' => '30/06/2027'], '!note' => $raw('Le note della scadenza (vuote se non ci sono)', '')],
                'Scaduta: {{voce}} – {{risorsa}}',
                "**{{voce}}** di **{{risorsa}}** è scaduta il {{data}}.\n\n{{!note}}\n\nQuando è stata rinnovata inserisci la nuova data dal cruscotto della risorsa, così l'avviso si azzera.", 'Apri il cruscotto'),
        ];
    }
}
