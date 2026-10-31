<?php

declare(strict_types=1);

namespace App\Email;

/** Le email delle convenzioni: FSL con le scuole (richiesta, firme, messaggi, documenti della classe, valutazioni) e di tesi con le aziende. Formato dei testi: vedi ModelliEmail. */
final class CatalogoConvenzioni
{
    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        return self::fsl() + self::tesi();
    }

    /** @return array<string, array{d: string, esempio: string}> */
    private static function sp(string ...$nomi): array
    {
        $diz = [
            'scuola' => ['d' => 'Nome della scuola', 'esempio' => 'Liceo Scientifico «G. Galilei»'],
            'nome' => ['d' => 'Nome e cognome del docente della scuola', 'esempio' => 'Anna Rossi'],
            'attivita' => ['d' => "Titolo dell'attività (con il turno, se c'è)", 'esempio' => 'Laboratorio di botanica (turno del mattino)'],
            'attivita_titolo' => ['d' => "Titolo dell'attività", 'esempio' => 'Laboratorio di botanica'],
            'titolo' => ['d' => "Titolo dell'attività", 'esempio' => 'Laboratorio di botanica'],
            'periodo' => ['d' => "Periodo dell'attività: «il 12/03/2027» oppure «dal 12/03/2027 al 14/03/2027»", 'esempio' => 'il 12/03/2027'],
            'codice' => ['d' => 'Codice della prenotazione', 'esempio' => 'FSL-2027-0123'],
            'protocollo' => ['d' => 'Numero di protocollo', 'esempio' => '12345'],
            'azienda' => ['d' => "Nome dell'azienda o ente", 'esempio' => 'Acme S.r.l.'],
            'testo' => ['d' => 'Il messaggio scritto da una persona', 'esempio' => 'Abbiamo corretto il documento.'],
            'motivo' => ['d' => 'Il motivo scritto da chi ha rifiutato il file', 'esempio' => 'Il PDF non ha la firma digitale.'],
        ];
        $r = [];
        foreach ($nomi as $n) {
            $r[$n] = $diz[ltrim($n, '!')] ?? ['d' => $n, 'esempio' => '…'];
        }

        return $r;
    }

    /** @param array<string, mixed> ...$extra segnaposto particolari (nome => [d, esempio]) @return array<string, array{d: string, esempio: string}> */
    private static function con(array $base, array $extra): array
    {
        return $base + $extra;
    }

    /** @return array<string, array<string, mixed>> */
    private static function fsl(): array
    {
        $S = 'Convenzioni FSL · Alle scuole';
        $U = "Convenzioni FSL · All'ufficio e al Direttore";
        $D = 'Convenzioni FSL · Prenotazioni e documenti della classe';
        $m = static fn (string $g, string $nome, string $quando, string $a, array $sp, string $oggetto, string $corpo, ?string $bottone = null): array => CatalogoEmail::m($g, $nome, $quando, $a, $sp, $oggetto, $corpo, $bottone);
        $raw = static fn (string $d, string $esempio = ''): array => ['d' => $d, 'esempio' => $esempio];
        $cod = '🎟️ Codice della prenotazione: **{{codice}}**';
        $istr = $raw("Le istruzioni per la convenzione, con i modelli e l'indirizzo PEC (le prepara il portale)", '<p>Scarica i modelli e invia la convenzione firmata via PEC.</p>');
        $nota = $raw("Avviso quando la convenzione registrata non copre il periodo (vuoto se non serve)", '');

        return [
            'fsl_convenzione_richiesta' => $m($S, 'Richiesta della convenzione', "L'ufficio preme il pulsante di richiesta della convenzione nelle Iscrizioni (con i modelli e l'indirizzo PEC).", 'Il docente che ha prenotato',
                self::con(self::sp('nome', 'attivita', 'attivita_titolo', 'periodo', 'codice'), ['!nota' => $nota, '!istruzioni' => $istr]),
                'Convenzione con il Dipartimento - {{attivita_titolo}}',
                "Gentile **{{nome}}**,\n\nper la prenotazione di **{{attivita}}** ({{periodo}}) la scuola deve avere una convenzione con il Dipartimento per la Formazione Scuola Lavoro valida per tutto il periodo dell'attività.\n\n{{!nota}}\n\n$cod\n\n{{!istruzioni}}"),
            'fsl_convenzione_promemoria' => $m($S, 'Promemoria della convenzione da inviare (automatico)', 'Il cron ricorda la convenzione mancante di una prenotazione.', 'Il docente che ha prenotato',
                self::con(self::sp('nome', 'attivita', 'attivita_titolo', 'periodo', 'codice'), ['!nota' => $nota, '!istruzioni' => $istr]),
                'Promemoria: convenzione da inviare - {{attivita_titolo}}',
                "Gentile **{{nome}}**,\n\nti ricordiamo che per la prenotazione di **{{attivita}}** non abbiamo ancora ricevuto la convenzione della scuola con il Dipartimento.\n\n{{!nota}}\n\n$cod\n\n{{!istruzioni}}"),
            'fsl_convenzione_pronta' => $m($S, 'Convenzione e Allegato A pronti', 'La scuola ha compilato i dati online: la convenzione e l\'Allegato A sono pronti da scaricare.', 'Il docente della scuola',
                self::con(self::sp('scuola'), ['@pec' => ['d' => 'Indirizzo PEC del Dipartimento (diventa un collegamento)', 'esempio' => 'dibest@pec.unical.it']]),
                'Convenzione Formazione Scuola Lavoro – documenti da firmare',
                "Gentile docente,\n\nla Convenzione e l'Allegato A per **{{scuola}}** sono pronti. Poi il Dirigente Scolastico li **firma digitalmente in PAdES** (PDF firmato, non .p7m) e la scuola li invia via PEC a {{@pec}}. Appena riceviamo la convenzione confermiamo le prenotazioni.\n\nDa questo link puoi scaricarli o correggerli:",
                'Apri la convenzione'),
            'fsl_riepilogo_programma' => $m($S, 'Riepilogo delle attività richieste', 'La scuola ha chiesto un programma di attività FSL: una sola email con le attività, i loro stati e i documenti.', 'Il docente della scuola',
                self::con(self::sp('scuola'), ['!tabella' => $raw('Tabella delle attività con stato e codice (la prepara il portale)', '<table style="border-collapse:collapse;width:100%;font-size:14px;"><tr><th align="left">Attività</th><th align="left">Stato</th><th align="left">Codice</th></tr><tr><td>Laboratorio di botanica</td><td>Confermata</td><td>FSL-2027-0123</td></tr></table>'), '!avvisi' => $raw("Avvisi su lista d'attesa e attività non prenotate (vuoti se non servono)", ''), '!documenti' => $raw("Spiegazione dei documenti da scaricare (vuota se non ci sono)", '<p>Abbiamo preparato l\'Allegato A con i dati che hai inserito.</p>')]),
                'Formazione Scuola Lavoro – riepilogo delle attività richieste',
                "Gentile docente,\n\necco il riepilogo delle attività di Formazione Scuola Lavoro richieste per **{{scuola}}**:\n\n{{!tabella}}\n\n{{!avvisi}}\n\n{{!documenti}}",
                'Scarica i documenti'),
            'fsl_link_ripreso' => $m($S, 'Link della convenzione rimandato', 'Una scuola ha perso il link e lo richiede con la sua email.', 'La scuola',
                self::sp('scuola'),
                'Convenzione Formazione Scuola Lavoro: il tuo link',
                "Gentile docente,

hai chiesto di nuovo il link per la convenzione di **{{scuola}}**: da questa pagina riprendi dove eri rimasto, scarichi i documenti e carichi la convenzione firmata.

Se non sei stato tu a chiederlo, ignora questa email: nessun dato è stato modificato.",
                'Apri la pagina della convenzione'),
            'fsl_firmata_ricevuta' => $m($S, 'Convenzione firmata ricevuta', 'La scuola carica la convenzione firmata.', 'La scuola',
                self::sp('scuola'),
                'Convenzione ricevuta: {{scuola}}',
                "Gentile **{{scuola}}**,\n\nabbiamo ricevuto la **convenzione firmata** per la Formazione Scuola Lavoro. Il Dipartimento la prende in carico, la fa firmare dal Direttore e la protocolla: appena è pronta ti scriviamo e la trovi nell'Area personale. Le prenotazioni in attesa si confermano dopo la protocollazione.\n\nHai caricato il file sbagliato? Fino alla presa in carico puoi sostituirlo da questa pagina.",
                'Apri la pagina della convenzione'),
            'fsl_promemoria_pdf' => $m($S, 'Promemoria: carica la convenzione firmata (automatico)', 'Il cron ricorda alla scuola di caricare la convenzione firmata.', 'La scuola',
                self::sp('scuola'),
                'Promemoria: carica la convenzione firmata - {{scuola}}',
                "Gentile **{{scuola}}**,\n\nti ricordiamo di **caricare la convenzione firmata digitalmente dal Dirigente Scolastico** (PDF in formato PAdES) insieme all'Allegato A: senza la convenzione le prenotazioni della scuola restano in attesa.\n\nLa carichi dalla pagina della convenzione, riquadro «Convenzione firmata». Se hai bisogno di aiuto scrivici dalla stessa pagina.",
                'Apri la pagina della convenzione'),
            'fsl_promemoria_pdf_rifiutata' => $m($S, 'Promemoria: ricarica la convenzione rifiutata (automatico)', 'Il cron ricorda di ricaricare una convenzione che era stata rifiutata.', 'La scuola',
                self::con(self::sp('scuola'), ['motivo_intro' => ['d' => '« per questo motivo:» se c\'è un motivo, altrimenti «.»', 'esempio' => ' per questo motivo:'], '!motivo' => $raw('Il motivo del rifiuto in un riquadro (vuoto se non c\'è)', '')]),
                'Promemoria: carica la convenzione firmata - {{scuola}}',
                "Gentile **{{scuola}}**,\n\nti ricordiamo che la convenzione che avevi caricato **non è stata accettata**{{motivo_intro}}\n\n{{!motivo}}\n\nCorreggi il documento e **caricalo di nuovo** dalla pagina della convenzione.",
                'Apri la pagina della convenzione'),
            'fsl_messaggio_ufficio' => $m($S, "Messaggio dell'ufficio alla scuola", "L'ufficio scrive alla scuola dalla pagina della convenzione.", 'La scuola',
                self::sp('scuola', 'testo'),
                'Messaggio dal Dipartimento sulla convenzione: {{scuola}}',
                "Gentile **{{scuola}}**,\n\nil Dipartimento ti scrive a proposito della convenzione per la Formazione Scuola Lavoro:\n\n> {{testo}}\n\nPuoi rispondere dalla pagina della convenzione.",
                'Apri la pagina e rispondi'),
            'fsl_presa_in_carico' => $m($S, 'Convenzione presa in carico', "L'ufficio prende in carico la convenzione firmata.", 'La scuola',
                self::con(self::sp('scuola'), ['protocollo_testo' => ['d' => "Protocollo in entrata, preceduto da « e l'ha protocollata in entrata » (vuoto se non c'è)", 'esempio' => " e l'ha protocollata in entrata (12345)"]]),
                'Convenzione presa in carico: {{scuola}}',
                "Gentile **{{scuola}}**,\n\nil Dipartimento ha **preso in carico** la convenzione firmata{{protocollo_testo}}: ora la fa firmare dal Direttore e la protocolla in via definitiva. Ti scriviamo appena è pronta; le prenotazioni in attesa si confermano in quel momento.",
                'Apri la pagina della convenzione'),
            'fsl_rifiutata' => $m($S, 'Convenzione da correggere', "L'ufficio rifiuta il file caricato dalla scuola, con il motivo.", 'La scuola',
                self::sp('scuola', 'motivo'),
                'Convenzione da correggere: {{scuola}}',
                "Gentile **{{scuola}}**,\n\nla convenzione che hai caricato **non può essere accettata** per questo motivo:\n\n> {{motivo}}\n\nCorreggi il documento e **caricalo di nuovo** dalla stessa pagina.",
                'Carica di nuovo la convenzione'),
            'fsl_attiva' => $m($S, 'Convenzione attiva', 'La convenzione è firmata dal Direttore, protocollata e registrata.', 'La scuola',
                self::con(self::sp('scuola'), ['protocollo_testo' => ['d' => 'Protocollo tra parentesi (vuoto se non c\'è)', 'esempio' => ' (12345)'], 'validita_testo' => ['d' => 'Validità preceduta da una virgola (vuota se non c\'è)', 'esempio' => ', valida dal 01/09/2026 al 31/08/2029'], '!prenotazioni' => $raw('Paragrafo sulle prenotazioni confermate (vuoto se non ce n\'erano)', '<p>Le tue <strong>2 prenotazioni erano in attesa</strong>: sono state confermate.</p>')]),
                'Convenzione attiva: {{scuola}}',
                "Gentile **{{scuola}}**,\n\nla convenzione è stata **firmata dal Direttore e protocollata**{{protocollo_testo}}{{validita_testo}}.\n\n{{!prenotazioni}}\n\nTrovi la convenzione e l'Allegato A nell'Area personale, nella scheda «Programma FSL».",
                'Apri la pagina della convenzione'),

            'fsl_firmata_caricata' => $m($U, 'Convenzione firmata caricata dalla scuola', 'La scuola carica la convenzione firmata (o una nuova versione).', 'Chi gestisce le convenzioni',
                self::con(self::sp('scuola'), ['azione' => ['d' => '«caricato la» oppure «caricato una nuova versione della»', 'esempio' => 'caricato la'], 'firmatario_testo' => ['d' => 'Firmatario tra parentesi (vuoto se non si legge)', 'esempio' => ' (firma di Mario Bianchi)']]),
                'Convenzione FSL da prendere in carico: {{scuola}}',
                "La scuola **{{scuola}}** ha {{azione}} convenzione firmata{{firmatario_testo}}.\n\nVa **protocollata in entrata**: scarica il PDF, protocollalo e registra solo il numero nel portale (senza ricaricare il file). Subito dopo la convenzione va in automatico al Direttore per la firma.",
                'Apri le convenzioni'),
            'fsl_messaggio_scuola' => $m($U, 'Messaggio della scuola', 'La scuola scrive dalla pagina della convenzione.', 'Chi gestisce le convenzioni',
                self::sp('scuola', 'testo'),
                'Messaggio della scuola sulla convenzione: {{scuola}}',
                "La scuola **{{scuola}}** ha scritto a proposito della convenzione:\n\n> {{testo}}",
                'Apri le convenzioni e rispondi'),
            'fsl_firma_richiesta' => $m($U, 'Convenzione da firmare', 'La convenzione è pronta per la firma del Direttore.', 'Il Direttore',
                self::con(self::sp('scuola'), ['direttore' => ['d' => 'Nome del Direttore (o «Direttore»)', 'esempio' => 'Prof. Mario Bianchi']]),
                'Convenzione FSL da firmare: {{scuola}}',
                "Gentile {{direttore}},\n\nla convenzione per la Formazione Scuola Lavoro con **{{scuola}}** è già firmata dal Dirigente Scolastico e attende la **tua firma**.\n\nAccedi al portale e firmala in PAdES: con la firma remota Aruba direttamente dal portale oppure scaricando il PDF, firmandolo e ricaricandolo.",
                'Apri e firma la convenzione'),
            'fsl_firmata_direttore' => $m($U, 'Convenzione firmata dal Direttore', 'Il Direttore ha firmato: la convenzione è da protocollare.', 'Chi gestisce le convenzioni',
                self::sp('scuola'),
                'Convenzione FSL firmata dal Direttore: da protocollare - {{scuola}}',
                "Il Direttore ha **firmato** la convenzione con **{{scuola}}**.\n\nOra va **protocollata in via definitiva**: scarica il PDF firmato, protocollalo, poi nel portale registra numero e data e carica il PDF protocollato. Le prenotazioni in attesa della scuola si confermano alla registrazione.",
                'Apri le convenzioni'),

            'fsl_convenzioni_mancanti' => $m($U, 'Convenzioni mancanti prima di un\'attività', "Il cron avvisa quando un'attività inizia entro 7 giorni e ci sono scuole senza convenzione (una volta).", 'I gestori dell\'evento (o gli amministratori)',
                ['titolo' => ['d' => "Titolo dell'attività", 'esempio' => 'Laboratorio di botanica'], 'data' => ['d' => "Data d'inizio", 'esempio' => '15/03/2027'], '!tabella' => $raw('Tabella delle scuole senza convenzione (la prepara il portale)', "<table style='border-collapse:collapse;font-size:13px;width:100%;'><tr><th>Scuola</th><th>Docente</th><th>Turno</th><th>Codice</th></tr><tr><td>Liceo Galilei</td><td>Anna Rossi</td><td>Mattina</td><td>FSL-123</td></tr></table>")],
                'Convenzioni mancanti: {{titolo}}',
                "L'attività **{{titolo}}** inizia il **{{data}}** e queste scuole non hanno ancora inviato la **convenzione**:\n\n{{!tabella}}\n\nQuando arriva la convenzione segnala come ricevuta da Iscrizioni: la prenotazione si conferma e la scuola riceve l'email.",
                'Apri Iscrizioni'),
            'fsl_convenzioni_in_scadenza' => $m($U, 'Convenzioni con le scuole in scadenza', 'Il cron avvisa (una volta) le convenzioni del registro che scadono entro 60 giorni.', 'Gli amministratori',
                ['quante' => ['d' => 'Quante convenzioni', 'esempio' => '3'], '!elenco' => $raw('Elenco delle convenzioni in scadenza (lo prepara il portale)', '<ul><li><strong>Liceo Galilei</strong> (CSPS00001A): scade il <strong>30/06/2027</strong></li></ul>')],
                'Convenzioni con le scuole in scadenza ({{quante}})',
                "Queste convenzioni con le scuole scadono nei prossimi 60 giorni:\n\n{{!elenco}}\n\nDopo la scadenza, alle nuove iscrizioni di queste scuole verrà chiesta di nuovo la convenzione. Il rinnovo si registra in Formazione Scuola Lavoro → Convenzioni.",
                'Apri le convenzioni'),
            'fsl_ricevuta_convenzione' => $m($D, 'Convenzione ricevuta: avviso a chi ha prenotato', 'La convenzione della scuola è registrata: chi aveva prenotato riceve la ricevuta.', 'Il docente che ha prenotato',
                self::con(self::sp('nome', 'attivita', 'attivita_titolo'), ['!stato' => $raw('Frase sullo stato della prenotazione (confermata, in valutazione, in lista d\'attesa)', '<p>La prenotazione è <strong>CONFERMATA</strong>.</p>')]),
                'Convenzione ricevuta: {{attivita_titolo}}',
                "Gentile **{{nome}}**,\n\nabbiamo ricevuto la **convenzione** della scuola con il Dipartimento.\n\n{{!stato}}",
                '📄 Scarica Ricevuta PDF'),
            'fsl_documenti_promemoria' => $m($D, 'Documenti della classe mancanti: promemoria (automatico)', "Il cron ricorda l'elenco degli studenti e l'autorizzazione della scuola che mancano.", 'Il docente che ha prenotato',
                self::con(self::sp('nome', 'titolo', 'codice'), ['inizio_testo' => ['d' => '«inizia il 12/03/2027» oppure «sta per iniziare»', 'esempio' => 'inizia il 12/03/2027'], 'manca' => ['d' => 'Cosa manca', 'esempio' => "l'elenco degli studenti e l'autorizzazione della scuola (PDF)"], '!scadenza' => $raw('Frase sulla scadenza (vuota se non c\'è)', '<p>Puoi caricarli entro il <strong>05/03/2027</strong>.</p>')]),
                'Promemoria: elenco degli studenti e autorizzazione della scuola - {{titolo}}',
                "Gentile **{{nome}}**,\n\nper la prenotazione di **{{titolo}}** (l'attività {{inizio_testo}}) non abbiamo ancora ricevuto: {{manca}}.\n\n{{!scadenza}}\n\n$cod",
                'Carica i documenti'),
            'fsl_documenti_ultimo_avviso' => $m($D, 'Documenti della classe mancanti: ultimo avviso (automatico)', "Ultimo avviso del cron quando l'attività sta per iniziare.", 'Il docente che ha prenotato',
                self::con(self::sp('nome', 'titolo', 'codice'), ['inizio_testo' => ['d' => '«inizia il 12/03/2027» oppure «sta per iniziare»', 'esempio' => 'inizia il 12/03/2027'], 'manca' => ['d' => 'Cosa manca', 'esempio' => "l'elenco degli studenti e l'autorizzazione della scuola (PDF)"], '!consegna' => $raw('Frase sulla consegna (con la scadenza se c\'è)', '<p>I documenti andavano consegnati entro il <strong>05/03/2027</strong>: caricali al più presto.</p>')]),
                'Ultimo avviso: elenco degli studenti e autorizzazione della scuola - {{titolo}}',
                "Gentile **{{nome}}**,\n\n**Ultimo avviso.** L'attività **{{titolo}}** {{inizio_testo}} e non risultano ancora caricati: {{manca}}.\n\n{{!consegna}}\n\n$cod",
                'Carica i documenti'),
            'fsl_valutazione_invito' => $m($D, 'Scheda di valutazione: invito', "Dopo l'attività la scuola riceve l'invito a compilare la scheda di valutazione.", 'Il docente della classe',
                self::con(self::sp('nome', 'attivita_titolo'), ['scuola_testo' => ['d' => 'La scuola tra parentesi (vuota se non c\'è)', 'esempio' => ' (Liceo Galilei, Cosenza)']]),
                'Scheda di valutazione - {{attivita_titolo}}',
                "Gentile **{{nome}}**,\n\ngrazie per aver partecipato con la tua classe all'attività di Formazione Scuola Lavoro **{{attivita_titolo}}**{{scuola_testo}}.\n\nCome previsto dalla convenzione, ti chiediamo di compilare la breve **scheda di valutazione della struttura ospitante**: bastano 3 minuti e ci aiuta a migliorare i percorsi.",
                'Compila la scheda di valutazione'),
            'fsl_valutazione_promemoria' => $m($D, 'Scheda di valutazione: promemoria', 'Secondo invito se la scheda non è stata compilata.', 'Il docente della classe',
                self::sp('nome', 'attivita_titolo'),
                'Promemoria: Scheda di valutazione - {{attivita_titolo}}',
                "Gentile **{{nome}}**,\n\nti ricordiamo che la scheda di valutazione non è ancora stata compilata.\n\nCome previsto dalla convenzione, ti chiediamo di compilare la breve **scheda di valutazione della struttura ospitante**: bastano 3 minuti e ci aiuta a migliorare i percorsi.",
                'Compila la scheda di valutazione'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function tesi(): array
    {
        $A = 'Convenzioni di tesi · Alle aziende';
        $U = "Convenzioni di tesi · All'ufficio e al Direttore";
        $m = static fn (string $g, string $nome, string $quando, string $a, array $sp, string $oggetto, string $corpo, ?string $bottone = null): array => CatalogoEmail::m($g, $nome, $quando, $a, $sp, $oggetto, $corpo, $bottone);
        $az = self::sp('azienda');
        $promemoria = static fn (string $cosaMod, string $quando, string $cosa): array => $m($A, 'Promemoria: ' . $cosaMod . ' (automatico)', $quando, "L'azienda", $az,
            'Promemoria: convenzione per la tesi con il DiBEST',
            "Gentile **{{azienda}}**,\n\nti ricordiamo che per completare la convenzione per la tesi con il Dipartimento DiBEST resta da **$cosa**.",
            'Apri la convenzione');

        return [
            'tesi_invito' => $m($A, 'Invito a compilare la convenzione', "L'ufficio invia il link, o l'azienda lo richiede dalla pagina pubblica.", "L'azienda", $az,
                'Convenzione per la tesi con il Dipartimento DiBEST',
                "Gentile **{{azienda}}**,\n\nil Dipartimento di Biologia, Ecologia e Scienze della Terra (DiBEST) dell'Università della Calabria stipula con gli enti e le aziende che accolgono i propri studenti una **convenzione per lo svolgimento della tesi**.\n\nSi fa online, in pochi minuti e **senza registrarsi**: dal link qui sotto si inseriscono i dati dell'azienda e del rappresentante legale, si scarica la convenzione già compilata (Word e PDF), la si firma digitalmente (PAdES) e la si ricarica. Al resto pensiamo noi.\n\nSe vuoi cambiare qualche articolo puoi proporlo nella stessa pagina: lo valutiamo prima della firma.\n\nIl link è personale: puoi chiuderlo e riprendere più tardi.",
                'Compila la convenzione'),
            'tesi_invito_ripreso' => $m($A, 'Convenzione già aperta: link rimandato', "L'azienda richiede di nuovo la convenzione che ha già in corso.", "L'azienda", $az,
                'Convenzione per la tesi: riprendi dove eri rimasto',
                "Gentile **{{azienda}}**,\n\nhai già una convenzione per la tesi in corso con il Dipartimento DiBEST: la riprendi da questo link.",
                'Riprendi la convenzione'),
            'tesi_modifiche_accettate' => $m($A, 'Modifiche accettate', "L'ufficio accetta tutte le modifiche proposte dall'azienda.", "L'azienda", $az,
                'Convenzione per la tesi: risposta alle modifiche',
                "Gentile **{{azienda}}**,\n\nil Dipartimento ha **accettato le modifiche** che hai proposto alla convenzione. Ora puoi scaricarla, firmarla in PAdES e ricaricarla.",
                'Apri la convenzione'),
            'tesi_modifiche_risposta' => $m($A, 'Risposta alle modifiche proposte', "L'ufficio risponde alle modifiche (non tutte accettate).", "L'azienda", $az,
                'Convenzione per la tesi: risposta alle modifiche',
                "Gentile **{{azienda}}**,\n\nil Dipartimento ha risposto alle modifiche che hai proposto alla convenzione. Apri la pagina per vedere le risposte: puoi confermare il testo proposto dal Dipartimento oppure scrivere una nuova proposta.",
                'Apri la convenzione'),
            'tesi_firmata_ricevuta' => $m($A, 'Convenzione firmata ricevuta', "L'azienda carica la convenzione firmata.", "L'azienda", $az,
                'Convenzione ricevuta: {{azienda}}',
                "Gentile **{{azienda}}**,\n\nabbiamo ricevuto la **convenzione firmata**. Il Dipartimento la protocolla e la fa firmare dal Direttore: appena è pronta ti scriviamo e potrai scaricarla dalla stessa pagina.\n\nHai caricato il file sbagliato? Fino alla presa in carico puoi sostituirlo da questa pagina.",
                'Apri la pagina della convenzione'),
            'tesi_presa_in_carico' => $m($A, 'Convenzione presa in carico', "L'ufficio prende in carico la convenzione.", "L'azienda", self::con($az, ['protocollo' => ['d' => 'Protocollo in entrata', 'esempio' => '12345']]),
                'Convenzione presa in carico: {{azienda}}',
                "Gentile **{{azienda}}**,\n\nla convenzione è stata presa in carico dal Dipartimento (protocollo in entrata **{{protocollo}}**) e passa alla firma del Direttore. Ti scriviamo appena è stipulata.",
                'Segui la convenzione'),
            'tesi_rifiutata' => $m($A, 'File della convenzione rifiutato', "L'ufficio rifiuta il file caricato, con il motivo.", "L'azienda", self::con($az, ['motivo' => ['d' => 'Il motivo del rifiuto', 'esempio' => 'La firma digitale non è valida.']]),
                'Convenzione da ricaricare: {{azienda}}',
                "Gentile **{{azienda}}**,\n\nil file della convenzione che hai caricato **non è stato accettato** per questo motivo:\n\n> {{motivo}}\n\nCorreggi il documento e caricalo di nuovo dalla pagina della convenzione.",
                'Apri la convenzione'),
            'tesi_attiva' => $m($A, 'Convenzione stipulata', 'La convenzione è firmata, protocollata e valida.', "L'azienda", self::con($az, ['protocollo' => ['d' => 'Protocollo', 'esempio' => '12345'], 'dal' => ['d' => 'Valida dal', 'esempio' => '01/09/2026'], 'al' => ['d' => 'Valida fino al', 'esempio' => '31/08/2029']]),
                'Convenzione stipulata: {{azienda}}',
                "Gentile **{{azienda}}**,\n\nla convenzione per la tesi è stata **stipulata** (protocollo {{protocollo}}) ed è valida dal {{dal}} al {{al}}.\n\nDa questa pagina puoi scaricare la convenzione firmata. Grazie per la collaborazione!",
                'Scarica la convenzione firmata'),
            'tesi_studente_stipulata' => $m('Convenzioni di tesi · Allo studente', 'Convenzione dell\'azienda stipulata', 'La convenzione che lo studente ha chiesto per la sua azienda è stipulata.', 'Lo studente', $az,
                'Convenzione stipulata: {{azienda}}',
                "Ciao,

la convenzione con **{{azienda}}**, che hai chiesto dalla tua pratica di tesi, è stata **stipulata**: puoi proseguire con la tesi.",
                'Apri la tua pratica'),
            'tesi_promemoria_invitata' => $promemoria('inserire i dati', "Il cron ricorda all'azienda invitata di inserire i dati.", "inserire i dati dell'azienda"),
            'tesi_promemoria_risposta' => $promemoria('leggere la risposta', "Il cron ricorda all'azienda di leggere la risposta alle modifiche.", 'leggere la risposta del Dipartimento alle modifiche proposte'),
            'tesi_promemoria_rifiutata' => $promemoria('ricaricare il file', "Il cron ricorda all'azienda di ricaricare il file rifiutato.", 'ricaricare la convenzione corretta'),
            'tesi_promemoria_firma' => $promemoria('firmare e ricaricare', "Il cron ricorda all'azienda di firmare e ricaricare la convenzione.", 'firmare digitalmente la convenzione (PAdES) e ricaricarla'),
            'tesi_in_scadenza' => $m($A, 'Convenzione in scadenza', "Il cron avvisa l'azienda prima della scadenza.", "L'azienda", self::con($az, ['al' => ['d' => 'Data di scadenza', 'esempio' => '31/08/2029']]),
                'Convenzione per la tesi in scadenza: {{azienda}}',
                "Gentile **{{azienda}}**,\n\nla convenzione per la tesi con il Dipartimento DiBEST scade il **{{al}}**. Se vuoi continuare ad accogliere i nostri studenti puoi rinnovarla dal link qui sotto: con i dati già inseriti ci vogliono pochi minuti.",
                'Rinnova la convenzione'),

            'tesi_modifiche_proposte' => $m($U, "Modifiche proposte dall'azienda", "L'azienda propone modifiche agli articoli.", 'Chi gestisce le convenzioni di tesi', self::con($az, ['modifiche' => ['d' => '«1 modifica» oppure «3 modifiche»', 'esempio' => '3 modifiche']]),
                'Convenzione di tesi: modifiche da valutare - {{azienda}}',
                "L'azienda **{{azienda}}** propone **{{modifiche}}** agli articoli della convenzione per la tesi.\n\nVanno valutate prima che l'azienda possa firmare: puoi accettarle, rifiutarle o rispondere con un testo diverso.",
                'Apri le convenzioni di tesi'),
            'tesi_firmata_caricata' => $m($U, "Convenzione firmata caricata dall'azienda", "L'azienda carica la convenzione firmata (o una nuova versione).", 'Chi gestisce le convenzioni di tesi', self::con($az, ['azione' => ['d' => '«caricato la» oppure «caricato una nuova versione della»', 'esempio' => 'caricato la'], 'firmatario_testo' => ['d' => 'Firmatario tra parentesi (vuoto se non si legge)', 'esempio' => ' (firma di Mario Bianchi)']]),
                'Convenzione di tesi da prendere in carico: {{azienda}}',
                "L'azienda **{{azienda}}** ha {{azione}} convenzione per la tesi firmata{{firmatario_testo}}.\n\nVa **protocollata in entrata**: scarica il PDF, protocollalo e registra il numero nel portale. Subito dopo la convenzione va al Direttore per la firma.",
                'Apri le convenzioni di tesi'),
            'tesi_firma_richiesta' => $m($U, 'Convenzione di tesi da firmare', 'La convenzione è pronta per la firma del Direttore.', 'Il Direttore', self::con($az, ['direttore' => ['d' => 'Nome del Direttore', 'esempio' => 'Prof. Mario Bianchi']]),
                'Convenzione di tesi da firmare: {{azienda}}',
                "Gentile **{{direttore}}**,\n\nla convenzione per la tesi con **{{azienda}}** è già firmata dall'azienda e protocollata in entrata: attende la **firma del Direttore**.\n\nSi firma dal portale con la firma remota oppure scaricando il PDF, firmandolo in PAdES e ricaricandolo. Per accedere servono le credenziali di Ateneo.",
                'Firma la convenzione'),
            'tesi_firmata_direttore' => $m($U, 'Convenzione di tesi firmata dal Direttore', 'Il Direttore ha firmato: la convenzione è da protocollare in uscita.', 'Chi gestisce le convenzioni di tesi', $az,
                'Convenzione di tesi firmata dal Direttore: {{azienda}}',
                "Il Direttore ha **firmato** la convenzione per la tesi con **{{azienda}}**.\n\nOra va **protocollata in uscita**: scarica il PDF firmato, protocollalo e nel portale registra numero, data e validità (poi puoi caricare anche il PDF con la segnatura di protocollo).",
                'Apri le convenzioni di tesi'),
            'tesi_scadenze_ufficio' => $m($U, 'Convenzioni di tesi in scadenza', 'Il cron elenca le convenzioni che scadono a breve.', 'Chi gestisce le convenzioni di tesi',
                ['quante' => ['d' => 'Quante convenzioni', 'esempio' => '2'], '!elenco' => ['d' => 'Elenco delle convenzioni in scadenza (lo prepara il portale)', 'esempio' => '<ul><li><strong>Acme S.r.l.</strong>: scade il 31/08/2027</li></ul>']],
                'Convenzioni di tesi in scadenza ({{quante}})',
                "Queste convenzioni per la tesi scadono a breve; le aziende hanno ricevuto il link per rinnovarle:\n\n{{!elenco}}",
                'Apri le convenzioni di tesi'),
        ];
    }
}
