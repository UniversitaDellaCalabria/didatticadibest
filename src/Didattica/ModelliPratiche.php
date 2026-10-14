<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;

/**
 * Le pratiche degli studenti preparate dal portale (come l'elenco delle pratiche del Dipartimento): ogni pratica nasce come modulo online inattivo,
 * con i campi che c'erano su Uniticket, l'iter e la domanda in PDF/A. L'amministratore la rivede e la attiva dal costruttore dei moduli
 * (Didattica → Moduli e documenti): da lì si può cambiare qualunque campo o passo.
 */
final class ModelliPratiche
{
    /** Certificati che si possono chiedere (Uniticket: «Richiesta Certificato di Iscrizione»). */
    public const CERTIFICATI_ISCRIZIONE = [
        'Iscrizione', 'Iscrizione con esami', 'Iscrizione con anni accademici', 'Iscrizione con esami da superare', 'Iscrizione con anni accademici ed esami',
        'Iscrizione con piano', 'Iscrizione con esami e date', 'Iscrizione corsi singoli con esami e date',
        'Carriera completa (da richiedere solo in caso di passaggi di corso di laurea con la stessa matricola)',
    ];

    public function __construct(private Database $db)
    {
    }

    /** Crea le pratiche che mancano (si chiama a ogni aggiornamento dello schema: non tocca quelle già presenti). */
    public function prepara(): void
    {
        if (!$this->db->valore("SELECT id FROM didattica_moduli WHERE chiave = 'certificato_iscrizione'")) {
            $this->certificatoIscrizione();
        }
    }

    /** I campi di una richiesta di certificato: dati dello studente, iscrizione, certificati richiesti, note e dichiarazione. */
    private function campiCertificato(string $chiede, array $opzioni, string $noteOperative): array
    {
        return [
            ['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => $noteOperative],
            ['tipo' => 'titolo', 'etichetta' => 'Dati del richiedente'],
            ['tipo' => 'codice_fiscale', 'etichetta' => 'Codice fiscale', 'obbligatorio' => 1],
            ['tipo' => 'text', 'etichetta' => 'Luogo di nascita', 'obbligatorio' => 1, 'aiuto' => 'Comune (provincia) o Stato estero'],
            ['tipo' => 'date', 'etichetta' => 'Data di nascita', 'obbligatorio' => 1],
            ['tipo' => 'tel', 'etichetta' => 'Cellulare', 'obbligatorio' => 1],
            ['tipo' => 'email', 'etichetta' => 'Email personale', 'obbligatorio' => 1],
            ['tipo' => 'titolo', 'etichetta' => 'Iscrizione'],
            ['tipo' => 'anno_accademico', 'etichetta' => 'Iscritto per l\'A.A.', 'obbligatorio' => 1],
            ['tipo' => 'text', 'etichetta' => 'Matricola', 'obbligatorio' => 1],
            ['tipo' => 'corso_studio', 'etichetta' => 'Al Corso di Laurea in', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
            ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi'],
            ['tipo' => 'multicheck', 'etichetta' => $chiede, 'obbligatorio' => 1, 'opzioni' => $opzioni],
            ['tipo' => 'textarea', 'etichetta' => 'Note'],
            ['tipo' => 'dichiarazione', 'etichetta' => 'Dichiaro di aver letto e compreso quanto scritto sopra e di assumermi ogni responsabilità su quanto dichiarato', 'obbligatorio' => 1,
             'aiuto' => 'Dichiaro di essere a conoscenza delle responsabilità penali cui vado incontro in caso di dichiarazione mendace, reticente o contenente dati non rispondenti a verità (art. 483 c.p., art. 495 c.p., art. 640 c.p. e art. 76 D.P.R. n. 445/2000).'],
        ];
    }

    /**
     * «Richiesta Certificato di Iscrizione»: protocollo → ufficio del corso di studio, che carica le 2 marche da bollo in Esse3 e aspetta il pagamento →
     * firma del Direttore. Non va in verbale. Modulo inattivo: lo si attiva dal costruttore dei moduli.
     */
    public function certificatoIscrizione(): int
    {
        $campi = $this->campiCertificato(
            'Chiede il rilascio del/i seguente/i certificato/i',
            self::CERTIFICATI_ISCRIZIONE,
            "È previsto il pagamento di n. 2 marche da bollo, che verranno caricate in Esse3 (Segreteria > Situazione tasse).\nL'operatore che prenderà in carico la richiesta ti informerà sul caricamento delle marche da bollo.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso. Puoi salvare la richiesta come bozza e modificarla finché l'ufficio non la prende in carico."
        );
        $domanda = ['pdf' => 1, 'bollo' => 0, 'oggetto' => 'Richiesta certificato di iscrizione', 'destinatario' => "Al Direttore del DiBEST\nUNICAL\nSEDE",
                    'chiede' => 'il rilascio del/i seguente/i certificato/i: {Chiede il rilascio del/i seguente/i certificato/i}.'];
        $verbale = ['sezione' => 'Richiesta Certificato di Iscrizione', 'stile' => 'scheda', 'testo' => '', 'delibera' => ''];
        $iter = ['protocollo', ['t' => 'ufficio', 'u' => '@cdl', 'pagamento' => 1], ['t' => 'direttore']];
        $this->db->esegui(
            "INSERT INTO didattica_moduli (titolo, descrizione, categoria, tipo, campi_json, destinatari, attivo, ordine, verbale_json, iter_json, giorni_promemoria, chiave, domanda_json, in_verbale, aggiornato_il)
                          VALUES (?, ?, ?, 'online', ?, 'studenti', 0, 10, ?, ?, 7, 'certificato_iscrizione', ?, 0, NOW())",
            [
                'Richiesta Certificato di Iscrizione',
                '<p>Per chiedere il certificato di iscrizione (anche con esami, anni accademici o piano). La richiesta arriva all\'Ufficio protocollo; l\'ufficio del tuo corso di studio carica le marche da bollo in Esse3 e ti avvisa; dopo il pagamento il Direttore firma il certificato e lo scarichi da qui.</p>',
                'Certificati e attestati', json_encode($campi, JSON_UNESCAPED_UNICODE), json_encode($verbale, JSON_UNESCAPED_UNICODE), json_encode($iter), json_encode($domanda, JSON_UNESCAPED_UNICODE),
            ]
        );

        return (int) $this->db->valore("SELECT id FROM didattica_moduli WHERE chiave = 'certificato_iscrizione'");
    }
}
