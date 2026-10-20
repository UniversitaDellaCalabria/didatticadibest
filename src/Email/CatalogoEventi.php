<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Le email degli eventi e delle iscrizioni: conferme, liste d'attesa, cancellazioni, promemoria, attestati, sondaggi, messaggi, avvisi.
 * Quelle in formato «html» (testo con l'editor e segnaposto {NOME}) sono quelle che prima si modificavano in Sistema; le altre sono in formato
 * semplice come tutte le altre (vedi ModelliEmail).
 */
final class CatalogoEventi
{
    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        return self::htmlIscritti() + self::iscritti() + self::operatori();
    }

    /** @return array<string, array{d: string, esempio: string}> */
    private static function token(string ...$nomi): array
    {
        $diz = [
            '{NOME}' => ['d' => 'Nome', 'esempio' => 'Maria'],
            '{COGNOME}' => ['d' => 'Cognome', 'esempio' => 'Verdi'],
            '{MATRICOLA}' => ['d' => 'Matricola', 'esempio' => '223456'],
            '{TITOLO_EVENTO}' => ['d' => "Titolo dell'evento", 'esempio' => 'Giornata della biodiversità'],
            '{DATA_TURNO}' => ['d' => 'Data (e nome del turno)', 'esempio' => 'Mattina · 15/03/2027'],
            '{ORARIO_TURNO}' => ['d' => 'Orario', 'esempio' => '10:00 - 12:00'],
            '{LUOGO}' => ['d' => 'Luogo', 'esempio' => 'Aula Magna'],
            '{CODICE_PRENOTAZIONE}' => ['d' => 'Codice della prenotazione', 'esempio' => 'GB-1A2B3C4D'],
            '{LINK_RICEVUTA}' => ['d' => 'Pulsante «Scarica ricevuta PDF» (lo prepara il portale)', 'esempio' => "<p style='margin-top:15px;'><a href='#' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>"],
            '{LINK_AREA_PERSONALE}' => ['d' => "Collegamento all'Area Personale", 'esempio' => "<a href='#'>Area Personale</a>"],
            '{LINK_SONDAGGIO}' => ['d' => 'Pulsante per compilare il questionario (lo prepara il portale)', 'esempio' => "<a href='#' style='background-color:#17a2b8;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:bold;'>📝 Compila il Questionario</a>"],
            '{NUM_POSTI}' => ['d' => 'Numero di posti richiesti', 'esempio' => '2'],
            '{AVVISO_CONVENZIONE}' => ['d' => 'Avviso e istruzioni sulla convenzione della scuola (li prepara il portale; vuoto se non servono)', 'esempio' => '<p><strong>La convenzione della scuola non è ancora registrata.</strong></p>'],
        ];
        $r = [];
        foreach ($nomi as $n) {
            $r[$n] = $diz[$n];
        }

        return $r;
    }

    /** @return array<string, array<string, mixed>> */
    private static function htmlIscritti(): array
    {
        $G = 'Eventi e iscrizioni · A chi prenota';
        $base = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}'];
        $dettagli = "<p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>";
        $saluto = '<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p>';

        return [
            'evento_conferma' => CatalogoEmail::h($G, 'Prenotazione confermata', "L'iscritto prenota e il posto è subito confermato.", "L'iscritto (dopo il testo partono i pulsanti del calendario)",
                self::token(...$base, ...['{LINK_RICEVUTA}']),
                'Conferma Prenotazione Eventi',
                $saluto . '<p>Prenotazione confermata per <strong>{TITOLO_EVENTO}</strong>.</p>' . $dettagli . '{LINK_RICEVUTA}',
                ['oggetto' => 'email_conferma_oggetto', 'corpo' => 'email_conferma_corpo']),
            'evento_attesa_convenzione' => CatalogoEmail::h($G, 'Prenotazione in attesa della convenzione', "Una scuola prenota un'attività FSL e non ha ancora la convenzione.", 'Il docente della scuola',
                self::token(...$base, ...['{AVVISO_CONVENZIONE}', '{LINK_RICEVUTA}']),
                'Prenotazione in attesa della convenzione: {TITOLO_EVENTO}',
                $saluto . '<p>abbiamo ricevuto la prenotazione per <strong>{TITOLO_EVENTO}</strong>.</p>' . $dettagli . '{AVVISO_CONVENZIONE}{LINK_RICEVUTA}'),
            'evento_in_valutazione' => CatalogoEmail::h($G, 'Richiesta in valutazione', "La prenotazione richiede l'approvazione degli organizzatori.", "L'iscritto",
                self::token(...$base, ...['{NUM_POSTI}', '{LINK_RICEVUTA}']),
                'Richiesta Ricevuta (In valutazione): {TITOLO_EVENTO}',
                $saluto . "<p>La tua richiesta per <strong>{NUM_POSTI} posti</strong> all'evento <strong>{TITOLO_EVENTO}</strong> è in fase di valutazione.</p>" . $dettagli . '{LINK_RICEVUTA}'),
            'evento_lista_attesa' => CatalogoEmail::h($G, "Inserito in lista d'attesa", "I posti sono esauriti e la prenotazione va in lista d'attesa.", "L'iscritto (dopo il testo partono i pulsanti del calendario)",
                self::token(...$base, ...['{LINK_RICEVUTA}']),
                "Lista d'Attesa: {TITOLO_EVENTO}",
                $saluto . "<p>Sei stato inserito in <strong>lista d'attesa</strong> per l'evento <strong>{TITOLO_EVENTO}</strong>.</p>" . $dettagli . '{LINK_RICEVUTA}'),
            'evento_cancellazione_utente' => CatalogoEmail::h($G, 'Prenotazione cancellata dall\'iscritto', "L'iscritto annulla la prenotazione dall'Area personale.", "L'iscritto",
                self::token(...$base, ...['{LINK_RICEVUTA}']),
                'Cancellazione Prenotazione Confermata',
                $saluto . "<p>La tua prenotazione per l'evento <strong>{TITOLO_EVENTO}</strong> è stata cancellata con successo.</p>",
                ['oggetto' => 'email_canc_utente_oggetto', 'corpo' => 'email_canc_utente_corpo']),
            'evento_promemoria' => CatalogoEmail::h($G, 'Promemoria prima dell\'evento (automatico)', "Il cron avvisa gli iscritti confermati degli eventi che iniziano nelle prossime 72 ore.", "L'iscritto",
                self::token(...$base),
                'Promemoria Evento Imminente - DiBEST',
                "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Ti ricordiamo che l'evento <strong>{TITOLO_EVENTO}</strong> si terrà a breve.</p><p><strong>Dettagli:</strong><br>📅 Data: {DATA_TURNO}<br>🕒 Orario: {ORARIO_TURNO}<br>📍 Luogo: {LUOGO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p><p style='color:red;'>Se non potrai più partecipare, ti preghiamo di accedere alla tua Area Personale e <strong>annullare la prenotazione</strong>, così da cedere il posto a chi è in lista d'attesa.</p>",
                ['oggetto' => 'email_reminder_oggetto', 'corpo' => 'email_reminder_corpo']),
            'evento_attestato' => CatalogoEmail::h($G, 'Attestato disponibile (dopo l\'evento)', "Il cron avvisa i presenti che l'attestato è pronto. Facoltativa: senza testo non parte.", 'I presenti (se l\'evento ha attestati personali)',
                self::token(...$base, ...['{LINK_AREA_PERSONALE}']),
                'Il tuo Attestato di Partecipazione è pronto',
                "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Grazie per aver partecipato all'evento <strong>{TITOLO_EVENTO}</strong>.</p><p>Ti informiamo che il tuo Attestato di Partecipazione è stato generato ed è ora disponibile per il download.</p><p>Puoi scaricarlo accedendo alla tua {LINK_AREA_PERSONALE}.</p>",
                ['oggetto' => 'email_attestato_oggetto', 'corpo' => 'email_attestato_corpo'], true),
            'evento_sondaggio' => CatalogoEmail::h($G, 'Richiesta di feedback / sondaggio', "Dopo l'evento il cron manda l'invito al questionario; dal pannello si può anche mandare il link personale. Facoltativa per il cron: senza testo non parte (il link personale usa il testo qui sotto come suggerimento).", 'I presenti',
                self::token(...$base, ...['{LINK_AREA_PERSONALE}', '{LINK_SONDAGGIO}']),
                'La tua opinione è importante! Sondaggio Evento: {TITOLO_EVENTO}',
                '<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Ti ringraziamo per aver partecipato all\'evento <strong>{TITOLO_EVENTO}</strong>.</p><p>La tua opinione per noi è fondamentale. Ti invitiamo a compilare il questionario di gradimento in forma <strong>totalmente anonima</strong>.</p><div style="text-align:center;margin:35px 0;">{LINK_SONDAGGIO}</div>',
                ['oggetto' => 'email_sondaggio_oggetto', 'corpo' => 'email_sondaggio_corpo'], true),
            'evento_promosso_cambio_turno' => CatalogoEmail::h($G, "Posto liberato da un cambio turno: confermata dalla lista d'attesa", "Qualcuno cambia turno e il primo in lista d'attesa passa a confermato.", "Il primo in lista d'attesa",
                self::token('{NOME}', '{LINK_RICEVUTA}'),
                'Posto Disponibile! Prenotazione CONFERMATA',
                "<p>Ottime notizie <strong>{NOME}</strong>!</p><p>Si è appena liberato un posto e la tua prenotazione in lista d'attesa è passata a <strong>CONFERMATA UFFICIALMENTE</strong>.</p>{LINK_RICEVUTA}"),
            'evento_promosso_annullo' => CatalogoEmail::h($G, "Posto liberato da un annullamento: confermata dalla lista d'attesa", "Qualcuno annulla e il primo in lista d'attesa passa a confermato.", "Il primo in lista d'attesa",
                self::token(...$base, ...['{LINK_RICEVUTA}']),
                'Posto Disponibile! Prenotazione CONFERMATA: {TITOLO_EVENTO}',
                "<p>Ottime notizie <strong>{NOME} {COGNOME}</strong>!</p><p>Si è appena liberato un posto e la tua prenotazione in lista d'attesa per l'evento <strong>{TITOLO_EVENTO}</strong> è passata a <strong>CONFERMATA UFFICIALMENTE</strong>.</p>{LINK_RICEVUTA}"),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function iscritti(): array
    {
        $G = 'Eventi e iscrizioni · A chi prenota';
        $m = static fn (string $nome, string $quando, string $a, array $sp, string $oggetto, string $corpo, ?string $bottone = null, ?string $colore = null): array => CatalogoEmail::m($G, $nome, $quando, $a, $sp, $oggetto, $corpo, $bottone, $colore);
        $raw = static fn (string $d, string $esempio = ''): array => ['d' => $d, 'esempio' => $esempio];
        $nome = ['d' => 'Nome e cognome', 'esempio' => 'Maria Verdi'];
        $ev = ['d' => "Titolo dell'evento", 'esempio' => 'Giornata della biodiversità'];
        $turno = ['d' => 'Turno tra parentesi (vuoto se non c\'è)', 'esempio' => ' (Mattina · 15/03/2027)'];
        $codice = ['d' => 'Codice della prenotazione', 'esempio' => 'GB-1A2B3C4D'];

        return [
            'evento_posto_offerto' => $m("Posto liberato: serve la conferma entro 24 ore", "Si libera un posto e la persona in lista d'attesa ha 24 ore per confermare.", "Il primo in lista d'attesa",
                ['nome' => ['d' => 'Nome', 'esempio' => 'Maria'], 'evento' => $ev, 'scadenza' => ['d' => 'Entro quando confermare', 'esempio' => '16/03/2027 10:00']],
                'Azione Richiesta: Si è liberato un posto per {{evento}}',
                "Ottime notizie **{{nome}}**!\n\nSi è appena liberato un posto per l'evento **{{evento}}**.\n\n**ATTENZIONE:** Hai esattamente **24 ore** di tempo per confermare la tua presenza. Se non confermi entro il {{scadenza}}, il posto verrà riassegnato allo studente successivo.",
                'CONFERMA IL MIO POSTO', '#198754'),
            'evento_posto_confermato_attesa' => $m('Posto confermato dalla lista d\'attesa', "L'organizzatore libera un posto e la prenotazione in lista d'attesa si conferma.", "Chi era in lista d'attesa",
                ['nome' => ['d' => 'Nome', 'esempio' => 'Maria'], 'evento' => $ev],
                'Posto Confermato: {{evento}}',
                "Gentile {{nome}}, la tua prenotazione in lista d'attesa è stata **confermata** per l'evento **{{evento}}**.",
                'Scarica la ricevuta'),
            'evento_posto_accettato_convenzione' => $m('Posto accettato, in attesa della convenzione', "Chi era in lista d'attesa accetta il posto ma la scuola non ha la convenzione.", 'Il docente della scuola',
                ['nome' => $nome, 'evento' => $ev, 'turno' => $turno, '!istruzioni' => $raw('Le istruzioni per la convenzione (le prepara il portale)', '<p>Scarica i modelli e invia la convenzione firmata via PEC.</p>')],
                'Posto accettato, in attesa della convenzione: {{evento}}',
                "Gentile **{{nome}}**,\n\nhai accettato il posto per **{{evento}}**{{turno}}.\n\n{{!istruzioni}}"),
            'evento_posto_confermato_risposta' => $m('Posto confermato dall\'iscritto', "Chi era in lista d'attesa conferma il posto dal link.", "L'iscritto",
                ['nome' => $nome, 'evento' => $ev, 'turno' => $turno],
                'Prenotazione CONFERMATA: {{evento}}',
                "Gentile **{{nome}}**,\n\nhai confermato il tuo posto per **{{evento}}**{{turno}}. La prenotazione è **CONFERMATA**.",
                '📄 Scarica / Stampa Ricevuta PDF', '#B80000'),
            'evento_liste_attesa_annullate' => $m("Liste d'attesa annullate dopo una conferma", "In un'area con una sola iscrizione, la conferma annulla le altre richieste in lista d'attesa.", "L'iscritto",
                ['nome' => ['d' => 'Nome', 'esempio' => 'Maria'], 'evento' => $ev, '!elenco' => $raw('Elenco delle richieste annullate (lo prepara il portale)', '<ul><li><strong>Convegno</strong> — Mattina</li></ul>')],
                "Liste d'attesa annullate: iscrizione confermata a {{evento}}",
                "Gentile **{{nome}}**,\n\nla tua prenotazione per **{{evento}}** è **confermata**.\n\nPoiché in quest'area è consentita una sola iscrizione, le tue altre richieste in lista d'attesa sono state annullate automaticamente:\n\n{{!elenco}}"),
            'evento_approvata' => $m('Prenotazione approvata', 'Il gestore approva la richiesta (anche quando è la convenzione a sbloccarla).', "L'iscritto",
                ['evento' => $ev, 'turno' => $turno],
                'Prenotazione Approvata: {{evento}}',
                "La tua richiesta per l'evento **{{evento}}**{{turno}} è stata **APPROVATA**!",
                '📄 Scarica / Stampa Ricevuta PDF', '#B80000'),
            'evento_promossa_admin' => $m("Confermata dalla lista d'attesa (dal gestore)", "Il gestore promuove una richiesta dalla lista d'attesa.", "L'iscritto",
                ['evento' => $ev, 'turno' => $turno],
                'Posto Confermato: {{evento}}',
                "Si è liberato un posto: la tua prenotazione in lista d'attesa per **{{evento}}**{{turno}} è stata **CONFERMATA**.",
                '📄 Scarica / Stampa Ricevuta PDF', '#B80000'),
            'evento_rifiutata' => $m('Richiesta non accolta', 'Il gestore rifiuta la richiesta.', "L'iscritto", ['evento' => $ev],
                'Aggiornamento Prenotazione: {{evento}}',
                "Siamo spiacenti di informarti che la tua richiesta per l'evento **{{evento}}** non è stata accolta."),
            'evento_annullata_admin' => $m('Prenotazione annullata dall\'amministrazione', "Il gestore annulla una prenotazione dall'elenco iscritti.", "L'iscritto", ['evento' => $ev],
                'Prenotazione Annullata: {{evento}}',
                "La tua prenotazione per **{{evento}}** è stata annullata dall'amministrazione."),
            'evento_annullata_singola' => $m('Prenotazione annullata (singola)', "Il gestore annulla una singola prenotazione.", "L'iscritto", ['evento' => $ev],
                'Prenotazione Annullata: {{evento}}',
                "La tua prenotazione è stata annullata dall'amministrazione."),
            'evento_cancellata_admin' => $m('Prenotazione cancellata', 'Il gestore elimina una prenotazione.', "L'iscritto", ['evento' => $ev],
                'Cancellazione Prenotazione',
                "La tua prenotazione per **{{evento}}** è stata cancellata."),
            'evento_inserita_segreteria_confermata' => $m('Prenotazione inserita dalla segreteria', 'La segreteria inserisce a mano una prenotazione confermata.', "L'iscritto",
                ['nome' => $nome, 'evento' => $ev, 'codice' => $codice],
                'Conferma Prenotazione: {{evento}}',
                "Gentile **{{nome}}**,\n\nLa tua prenotazione per **{{evento}}** è stata inserita dalla segreteria.\n\n**Codice prenotazione:** {{codice}}\n\nConserva questo codice: ti servirà per il check-in.\n\nCordiali saluti,\nSegreteria DiBEST"),
            'evento_inserita_segreteria_attesa' => $m("Richiesta inserita dalla segreteria in lista d'attesa", "La segreteria inserisce a mano una richiesta e l'edizione è al completo.", "L'iscritto",
                ['nome' => $nome, 'evento' => $ev, 'codice' => $codice],
                "Lista d'attesa: {{evento}}",
                "Gentile **{{nome}}**,\n\nLa tua richiesta, in **lista d'attesa**, per **{{evento}}** è stata inserita dalla segreteria.\n\n**Codice prenotazione:** {{codice}}\n\nConserva questo codice: ti servirà per il check-in.\n\nCordiali saluti,\nSegreteria DiBEST"),
            'evento_messaggio_segreteria' => $m('Nuovo messaggio dalla segreteria', "La segreteria scrive all'iscritto dalla chat della prenotazione.", "L'iscritto",
                ['operatore' => ['d' => "Nome di chi scrive", 'esempio' => 'Anna Protocollo'], 'evento' => $ev, 'ora' => ['d' => "Data e ora d'invio", 'esempio' => '15/03 10:30'], '!messaggio' => $raw('Il messaggio, in un riquadro (lo prepara il portale)', "<div style='background:#f8fafc; padding:15px; border-left:4px solid #B80000; margin:15px 0; font-style:italic;'>Ci serve la tua matricola.</div>")],
                'Nuovo messaggio da {{operatore}} – {{evento}} [{{ora}}]',
                "Hai ricevuto un nuovo messaggio da **{{operatore}}** riguardante l'evento **{{evento}}**:\n\n{{!messaggio}}\n\nAccedi alla tua Area Personale per leggere il messaggio completo e rispondere:\n\nCordiali saluti,\n{{operatore}}\nSegreteria DiBEST",
                "Vai all'Area Personale per rispondere"),
            'evento_elenco_promemoria_progetto' => $m("Promemoria dell'elenco degli studenti (progetto)", "Il cron ricorda al docente di inserire l'elenco degli studenti per gli attestati (una volta).", 'Il docente della classe',
                ['nome' => $nome, 'titolo' => $ev, 'data' => ['d' => 'Data di fine del progetto', 'esempio' => '30/06/2027']],
                'Promemoria: elenco degli studenti per gli attestati - {{titolo}}',
                "Gentile **{{nome}}**,\n\nil progetto **{{titolo}}** si conclude il **{{data}}**.\n\nPer ricevere gli **attestati di partecipazione** dei tuoi studenti inserisci il loro elenco (cognome e nome): puoi scriverlo, incollarlo da Excel o caricare il modello compilato.",
                "Inserisci l'elenco degli studenti", '#198754'),
            'evento_elenco_promemoria_attivita' => $m("Promemoria dell'elenco degli studenti (attività)", "Il cron ricorda al docente di inserire l'elenco degli studenti per gli attestati (una volta).", 'Il docente della classe',
                ['nome' => $nome, 'titolo' => $ev, 'quando' => ['d' => '«si svolge» oppure «si è svolta»', 'esempio' => 'si svolge'], 'data' => ['d' => "Data dell'attività", 'esempio' => '15/03/2027']],
                'Promemoria: elenco degli studenti per gli attestati - {{titolo}}',
                "Gentile **{{nome}}**,\n\nl'attività **{{titolo}}** {{quando}} il **{{data}}**.\n\nPer ricevere gli **attestati di partecipazione** dei tuoi studenti inserisci il loro elenco (cognome e nome): puoi scriverlo, incollarlo da Excel o caricare il modello compilato.",
                "Inserisci l'elenco degli studenti", '#198754'),
            'evento_attestati_classe' => $m('Attestati degli studenti pronti', "L'attività è conclusa e gli attestati della classe sono pronti.", 'Il docente della classe',
                ['nome' => $nome, 'tipo' => ['d' => '«al progetto» oppure «all\'attività»', 'esempio' => "all'attività"], 'titolo' => $ev, 'studenti' => ['d' => '«1 studente» oppure «25 studenti»', 'esempio' => '25 studenti'], 'area_personale' => ['d' => "Indirizzo dell'Area Personale", 'esempio' => 'https://portale.unical.it/area_personale.php']],
                'Attestati degli studenti: {{titolo}}',
                "Gentile **{{nome}}**,\n\ngrazie per aver partecipato con la tua classe {{tipo}} **{{titolo}}**.\n\nSono pronti gli **attestati di partecipazione di {{studenti}}**: li trovi tutti in un'unica pagina, uno per foglio, pronti da stampare o salvare in PDF. Ogni attestato ha un codice e un QR per verificarne l'autenticità.\n\nPer aprirli accedi con le stesse credenziali usate per l'iscrizione. Li ritrovi anche nella tua Area Personale.",
                '📄 Apri gli attestati', '#198754'),
            'evento_attestato_presenza' => $m("Attestato pronto (alla registrazione della presenza)", "La presenza viene registrata dopo l'evento e l'attestato è pronto.", 'Il partecipante',
                ['nome' => $nome, 'titolo' => $ev, 'data_testo' => ['d' => 'Data preceduta da « del » (vuota se non c\'è)', 'esempio' => ' del 15/03/2027']],
                'Il tuo Attestato è pronto: {{titolo}}',
                "Gentile **{{nome}}**,\n\nGrazie per aver partecipato all'evento **{{titolo}}**{{data_testo}}.\n\nIl tuo **Attestato di Partecipazione** è disponibile per il download.\n\nIn alternativa puoi recuperarlo dalla tua Area Personale.\n\nCordiali saluti,\nIl team Didattica DiBEST",
                '📄 Scarica il tuo Attestato', '#198754'),
            'evento_attestato_cron' => $m("Attestato pronto (dopo l'evento, automatico)", "Il cron degli attestati li genera dopo l'evento.", 'Il partecipante',
                ['nome' => $nome, 'titolo' => $ev, 'data_testo' => ['d' => 'Data preceduta da « del » (vuota se non c\'è)', 'esempio' => ' del 15/03/2027']],
                'Il tuo Attestato è pronto: {{titolo}}',
                "Gentile **{{nome}}**,\n\nGrazie per aver partecipato all'evento **{{titolo}}**{{data_testo}}.\n\nIl tuo **Attestato di Partecipazione** è stato generato ed è ora disponibile per il download.\n\nIn alternativa, puoi sempre recuperarlo accedendo alla tua Area Personale.\n\nCordiali saluti,\nIl team Didattica DiBEST",
                '📄 Scarica il tuo Attestato', '#198754'),
            'avvisi_conferma_iscrizione' => $m('Avvisi sui nuovi eventi: conferma dell\'iscrizione', "Qualcuno si iscrive agli avvisi sui nuovi eventi dal sito.", "Chi si iscrive",
                ['argomenti' => ['d' => 'Gli argomenti scelti', 'esempio' => 'Ricerca, Didattica']],
                "Conferma l'iscrizione agli avvisi degli eventi",
                "Hai chiesto di ricevere un'email quando il Dipartimento pubblica nuovi eventi di: **{{argomenti}}**.\n\nSe non sei stato tu, ignora questa email: senza conferma non riceverai nulla e i dati si cancellano dopo 30 giorni.",
                "Conferma l'iscrizione", '#0056B3'),
            'avvisi_nuovi_eventi' => $m('Avvisi sui nuovi eventi', 'Il cron avvisa gli iscritti quando escono nuovi eventi dei loro argomenti.', 'Chi si è iscritto agli avvisi',
                ['argomenti' => ['d' => 'Gli argomenti dell\'iscritto', 'esempio' => 'Ricerca, Didattica'], '!eventi' => $raw('Tabella dei nuovi eventi (la prepara il portale)', "<table style='width:100%;border-collapse:collapse;font-size:14px;'><tr><td>Giornata della biodiversità</td></tr></table>"), 'quanti_testo' => ['d' => 'Oggetto: titolo se è un solo evento, altrimenti «N nuovi eventi del Dipartimento»', 'esempio' => '3 nuovi eventi del Dipartimento'], '!gestisci' => $raw('Frase con il link per cambiare argomenti o cancellarsi (la prepara il portale)', "<p style='font-size:12px;color:#64748b;margin-top:18px;'>Ricevi questa email perché ti sei iscritto agli avvisi. <a href='#'>Cambia argomenti o cancellati</a>.</p>")],
                '{{quanti_testo}}',
                "Nuovi appuntamenti su: **{{argomenti}}**.\n\n{{!eventi}}\n\n{{!gestisci}}",
                "Tutta l'agenda", '#0056B3'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function operatori(): array
    {
        $G = 'Eventi e iscrizioni · Ai gestori';
        $m = static fn (string $nome, string $quando, string $a, array $sp, string $oggetto, string $corpo, ?string $bottone = null): array => CatalogoEmail::m($G, $nome, $quando, $a, $sp, $oggetto, $corpo, $bottone);
        $raw = static fn (string $d, string $esempio = ''): array => ['d' => $d, 'esempio' => $esempio];
        $riep = $raw('Riepilogo della prenotazione con tutti i campi (lo prepara il portale)', '<table><tr><td>Maria Verdi</td><td>2 posti</td></tr></table>');

        return [
            'evento_gestori_nuova' => $m('Nuova prenotazione', 'Qualcuno prenota un evento.', "Gli indirizzi di notifica dell'evento e i referenti del progetto",
                ['posti' => ['d' => 'Posti richiesti, con «posto» o «posti»', 'esempio' => '2 posti'], 'evento' => ['d' => "Titolo dell'evento", 'esempio' => 'Giornata della biodiversità'], '!riepilogo' => $riep],
                'Nuova prenotazione ({{posti}}): {{evento}}',
                "È stata registrata una nuova prenotazione per l'evento **{{evento}}**.\n\n{{!riepilogo}}"),
            'evento_gestori_disdetta' => $m('Disdetta', "Un iscritto annulla la prenotazione.", "Gli indirizzi di notifica dell'evento e i referenti del progetto",
                ['persona' => ['d' => 'Nome e cognome', 'esempio' => 'Maria Verdi'], 'evento' => ['d' => "Titolo dell'evento", 'esempio' => 'Giornata della biodiversità'], '!riepilogo' => $riep],
                'Disdetta: {{evento}}',
                "**{{persona}}** ha appena **annullato** la prenotazione per l'evento **{{evento}}**.\n\n{{!riepilogo}}"),
            'evento_gestori_messaggio' => $m('Nuovo messaggio di assistenza', "Un iscritto scrive alla segreteria dalla sua Area personale.", "I gestori dell'evento",
                ['persona' => ['d' => 'Nome e cognome di chi scrive', 'esempio' => 'Maria Verdi'], 'evento' => ['d' => "Titolo dell'evento", 'esempio' => 'Giornata della biodiversità']],
                'Nuovo messaggio assistenza – {{evento}}',
                "Gentile Gestore,\n\n**{{persona}}** ha inviato un messaggio riguardante l'evento **{{evento}}**.\n\nAccedi al pannello di amministrazione > Messaggi per rispondere.\n\nCordiali saluti,\nSistema Didattica DiBEST"),
        ];
    }
}
