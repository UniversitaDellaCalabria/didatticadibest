<?php

declare(strict_types=1);

namespace App\Email;

/** Le email dell'assistenza: agli operatori (nuova richiesta, nuovo messaggio) e a chi chiede aiuto (ricevuta, risposta). Formato dei testi: vedi ModelliEmail. */
final class CatalogoAssistenza
{
    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        $O = 'Assistenza · Agli operatori';
        $U = 'Assistenza · A chi chiede aiuto';
        $m = static fn (string $g, string $nome, string $quando, string $a, array $sp, string $oggetto, string $corpo, ?string $bottone = null): array => CatalogoEmail::m($g, $nome, $quando, $a, $sp, $oggetto, $corpo, $bottone);
        $argomento = ['d' => "Argomento scelto da chi chiede aiuto", 'esempio' => 'Certificati e attestati'];
        $testo = ['d' => 'Il messaggio (testo semplice, il portale lo ripulisce)', 'esempio' => "Buongiorno, come posso avere il certificato di iscrizione?\nGrazie."];
        $persona = ['d' => 'Nome e cognome di chi scrive', 'esempio' => 'Maria Verdi'];
        $nome = ['d' => 'Nome e cognome della persona', 'esempio' => 'Maria Verdi'];

        return [
            'assistenza_operatori_nuova' => $m($O, 'Nuova richiesta di assistenza', 'Qualcuno apre una nuova chat e nessuno l\'ha ancora presa in carico.', 'Le persone dell\'ufficio Assistenza (o gli amministratori se l\'ufficio è vuoto)',
                ['persona' => $persona, 'email' => ['d' => 'Email di chi scrive', 'esempio' => 'maria.verdi@studenti.unical.it'], 'argomento' => $argomento,
                    'contesto' => ['d' => 'Se la richiesta riguarda una pratica o una prenotazione: «, riguarda …» (vuoto altrimenti)', 'esempio' => ', riguarda la pratica P-2026-0123'], 'testo' => $testo],
                'Nuova richiesta di assistenza: {{argomento}}',
                "**{{persona}}** ({{email}}) chiede assistenza su **{{argomento}}**{{contesto}}.\n\nScrive:\n\n{{testo}}",
                'Apri la conversazione'),
            'assistenza_operatori_messaggio' => $m($O, 'Nuovo messaggio in una chat', 'Chi chiede aiuto scrive in una chat già in carico e l\'operatore non ha la chat aperta (non più di una email ogni quarto d\'ora).', 'L\'operatore che ha preso in carico la chat (o tutti, se nessuno l\'ha presa)',
                ['persona' => $persona, 'argomento' => $argomento, 'testo' => $testo],
                'Nuovo messaggio da {{persona}}',
                "**{{persona}}** ha scritto nella chat su **{{argomento}}**:\n\n{{testo}}",
                'Apri la conversazione'),
            'assistenza_utente_ricevuta' => $m($U, 'Richiesta ricevuta (utenti esterni)', 'Una persona senza accesso al portale apre una chat: riceve il link personale per riprenderla.', 'La persona esterna che ha aperto la chat',
                ['nome' => $nome, 'argomento' => $argomento],
                'Abbiamo ricevuto la tua richiesta di assistenza',
                "Gentile **{{nome}}**,\n\nabbiamo ricevuto la tua richiesta su **{{argomento}}** e ti risponderemo il prima possibile.\n\nIl pulsante qui sotto è il tuo link personale per riprendere la conversazione e leggere le risposte: non condividerlo con altri.",
                'Riprendi la conversazione'),
            'assistenza_utente_risposta' => $m($U, 'Risposta dell\'assistenza', 'Un operatore risponde e la persona non ha la chat aperta (non più di una email ogni dieci minuti).', 'La persona che ha chiesto aiuto',
                ['nome' => $nome, 'argomento' => $argomento, 'testo' => ['d' => 'La risposta dell\'operatore (testo semplice)', 'esempio' => 'Il certificato si richiede dalla tua Area personale, voce Pratiche.']],
                'Hai una risposta dall\'assistenza',
                "Gentile **{{nome}}**,\n\nl'assistenza ha risposto alla tua richiesta su **{{argomento}}**:\n\n{{testo}}\n\nPuoi continuare la conversazione dal pulsante qui sotto.",
                'Continua la conversazione'),
        ];
    }
}
