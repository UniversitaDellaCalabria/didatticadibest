<?php

declare(strict_types=1);

namespace App\Email;

/** Le email di sistema e della Direzione: riepilogo «Da fare per te», riepilogo settimanale delle email e controllo del sito. Formato dei testi: vedi ModelliEmail. */
final class CatalogoSistema
{
    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        $D = 'Direzione e sistema · Riepiloghi e controlli';
        $m = static fn (string $nome, string $quando, string $a, array $sp, string $oggetto, string $corpo, ?string $bottone = null, ?string $colore = null): array => CatalogoEmail::m($D, $nome, $quando, $a, $sp, $oggetto, $corpo, $bottone, $colore);
        $raw = static fn (string $d, string $esempio = ''): array => ['d' => $d, 'esempio' => $esempio];

        return [
            'riepilogo_persone' => $m('«Da fare per te» (riepilogo settimanale)', 'Una volta a settimana (dal lunedì al venerdì, dalle 7), solo a chi ha qualcosa da fare; al Direttore anche il suo cruscotto.', 'Chi lavora nel portale (docenti, uffici, Direttore…)',
                ['quante' => ['d' => 'Quante cose, per l\'oggetto: «1 cosa» oppure «5 cose»', 'esempio' => '5 cose'], 'quante_testo' => ['d' => 'Quante cose per il testo: «1 cosa» oppure «4 cose»', 'esempio' => '4 cose'],
                    '!elenco' => $raw('Gli elenchi delle cose da fare per modulo (li prepara il portale)', '<h3>Pratiche (2)</h3><ul><li>Richiesta tesi di Maria Verdi</li></ul>'),
                    '!disiscrizione' => $raw('Frase con il link per non riceverlo più (la prepara il portale)', "<p style='font-size:12px;color:#64748b;'>Ricevi questa email una volta a settimana, solo quando c'è qualcosa da fare. <a href='#'>Non voglio più riceverla</a>.</p>")],
                'Da fare questa settimana: {{quante}}',
                "Buongiorno,\n\nquesta settimana ti aspettano **{{quante_testo}}** nel portale Didattica DiBEST.\n\n{{!elenco}}\n\n{{!disiscrizione}}",
                'Apri la tua Area personale', '#047857'),
            'riepilogo_agenda' => $m('«La tua settimana» (agenda del riepilogo settimanale)', 'Una volta a settimana, solo se l\'amministratore ha acceso il riepilogo con l\'agenda, a chi ha qualcosa in agenda ma nulla da fare.', 'Chi ha eventi, aule o appuntamenti nei sette giorni a venire (studenti compresi)',
                ['quante' => ['d' => 'Quanti appuntamenti: «1 appuntamento» oppure «3 appuntamenti»', 'esempio' => '3 appuntamenti'],
                    '!elenco' => $raw('L\'agenda dei sette giorni (la prepara il portale)', '<h3>Questa settimana in agenda (3)</h3><ul><li>Open day DiBEST</li></ul>'),
                    '!disiscrizione' => $raw('Frase con il link per non riceverlo più (la prepara il portale)', "<p style='font-size:12px;color:#64748b;'>Ricevi questa email una volta a settimana. <a href='#'>Non voglio più riceverla</a>.</p>")],
                'La tua settimana: {{quante}} in agenda',
                "Buongiorno,\n\necco cosa hai in agenda nei prossimi sette giorni nel portale Didattica DiBEST.\n\n{{!elenco}}\n\n{{!disiscrizione}}",
                'Apri il tuo calendario', '#0d6efd'),
            'sistema_report_settimanale' => $m('Riepilogo settimanale delle email del portale', 'Una volta a settimana (primo cron del lunedì) o dal pulsante in Sistema.', 'Gli amministratori',
                ['simbolo' => ['d' => '⚠️ se ci sono problemi, altrimenti ✅', 'esempio' => '✅'], 'ok' => ['d' => 'Email accettate dal server', 'esempio' => '120'], 'ko' => ['d' => 'Email fallite', 'esempio' => '0'], 'data' => ['d' => 'Data e ora del riepilogo', 'esempio' => '15/03/2027 07:00'],
                    '!dettaglio' => $raw('Tabella delle email, errori raggruppati e stato del backup (li prepara il portale)', '<table><tr><td>✅ Accettate dal server</td><td><strong>120</strong></td></tr></table><p>Nessun invio fallito. 👍</p>')],
                '{{simbolo}} Riepilogo settimanale email Didattica DiBEST: {{ok}} inviate, {{ko}} fallite',
                "Riepilogo delle email inviate dal portale negli ultimi 7 giorni (fino al {{data}}).\n\n{{!dettaglio}}",
                'Apri il registro completo nel pannello'),
            'sistema_backup' => $m('Copia del database via email', 'Il cron del backup manda ogni giorno (o quando serve) il database cifrato agli amministratori; se pesa troppo non è allegato.', 'Gli amministratori (con il database cifrato allegato)',
                ['data' => ['d' => 'Data del backup', 'esempio' => '15/03/2027'], 'data_ora' => ['d' => 'Data e ora del backup', 'esempio' => '15/03/2027 03:00'],
                    '!istruzioni' => $raw('Cosa c\'è in allegato e come si apre, oppure l\'avviso che il file è troppo grande (lo prepara il portale)', '<p>In allegato il database compresso, dentro uno ZIP cifrato AES-256.</p>'),
                    '!esito' => $raw('Esito del backup di oggi, riga per riga (lo prepara il portale)', "<p style='color:#64748b;font-size:13px;'>Esito del backup di oggi:<br>✅ Database salvato</p>")],
                'Backup database Didattica DiBEST - {{data}}',
                "Backup del database del portale Didattica DiBEST del **{{data_ora}}**.\n\n{{!istruzioni}}\n\n{{!esito}}\n\nConserva questa email in una casella sicura: contiene dati personali (cifrati)."),
            'sistema_backup_problemi' => $m('Backup con problemi', 'Il backup ha avuto problemi.', 'Gli amministratori',
                ['data_ora' => ['d' => 'Data e ora del backup', 'esempio' => '15/03/2027 03:00'], '!elenco' => $raw('Elenco dei problemi (lo prepara il portale)', '<ul><li>NAS non raggiungibile</li></ul>')],
                '⚠️ Backup Didattica DiBEST: problemi da controllare',
                "Il backup del portale Didattica DiBEST del **{{data_ora}}** ha avuto dei problemi:\n\n{{!elenco}}\n\nDettagli e stato nel pannello, in Sistema Email & Backup.",
                'Apri Sistema Email & Backup'),
            'sistema_email_prova' => $m('Email di prova della configurazione', 'Si preme «Invia email di prova» in Sistema.', "L'indirizzo scelto da chi prova", ['data_ora' => ['d' => "Data e ora della prova", 'esempio' => '15/03/2027 10:30']],
                'Email di prova - Didattica DiBEST',
                "Questa è un'email di prova inviata dal pannello **Sistema Email** il {{data_ora}}.\n\nSe la ricevi, la configurazione SMTP funziona."),
            'sistema_controllo_problemi' => $m('Controllo del sito: problemi rilevati', 'Il controllo automatico trova problemi (al massimo una volta al giorno per gli stessi problemi).', 'Gli amministratori',
                ['quanti' => ['d' => 'Quanti problemi', 'esempio' => '2'], '!elenco' => $raw('Elenco dei problemi (lo prepara il portale)', '<ul><li>Spazio su disco quasi esaurito</li></ul>')],
                'Portale Didattica DiBEST: {{quanti}} problemi rilevati',
                "Il controllo automatico del portale ha trovato **{{quanti}} problemi**:\n\n{{!elenco}}\n\nDettagli e nuovo controllo in Sistema → Controllo del sito.",
                'Apri il controllo del sito'),
            'sistema_controllo_ok' => $m('Controllo del sito: tutto a posto', 'Il controllo automatico non trova più i problemi segnalati prima.', 'Gli amministratori', [],
                'Portale Didattica DiBEST: tutto di nuovo a posto',
                'Il controllo automatico non trova più problemi. Dettagli in Sistema → Controllo del sito.',
                'Apri il controllo del sito'),
        ];
    }
}
