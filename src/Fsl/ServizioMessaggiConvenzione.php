<?php

declare(strict_types=1);

namespace App\Fsl;

/**
 * Comunicazione tra l'ufficio e la scuola sulla convenzione online: il promemoria «carica il PDF firmato», i messaggi liberi dell'ufficio
 * e le risposte della scuola. Ogni messaggio è conservato (si vede dalla pagina della scuola e dalla scheda dell'ufficio) e parte per email.
 */
final class ServizioMessaggiConvenzione
{
    /** Ore da aspettare prima di rimandare lo stesso promemoria. */
    public const ORE_TRA_PROMEMORIA = 24;
    public const MAX_RISPOSTE_AL_GIORNO = 10;
    public const MAX_CARATTERI = 1500;

    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private MessaggiConvenzioneRepository $messaggi,
        private NotificheConvenzioni $notifiche
    ) {
    }

    /** Il promemoria ha senso solo finché la scuola non ha caricato il PDF (o se è stato rifiutato). */
    public static function attendeIlPdf(array $cc): bool
    {
        $stato = (string) ($cc['stato_iter'] ?? '');

        return $stato === Costanti::ITER_RIFIUTATA || ($stato === '' && empty($cc['file_firmato']));
    }

    /** Email della scuola a cui scrivere: quella indicata nel modulo. */
    public static function emailScuola(array $cc): string
    {
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        foreach ([(string) ($cc['email'] ?? ''), (string) ($dati['scuola']['email'] ?? ''), (string) ($dati['scuola']['pec'] ?? '')] as $e) {
            if (filter_var(trim($e), FILTER_VALIDATE_EMAIL)) {
                return trim($e);
            }
        }

        return '';
    }

    public static function nomeScuola(array $cc): string
    {
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];

        return (string) ($dati['scuola']['denominazione'] ?? '') ?: 'la scuola';
    }

    /** @return string|null errore da mostrare, null se il promemoria è partito */
    public function ricordaPdf(int $compilazioneId, string $autore): ?string
    {
        $cc = $this->compilate->perId($compilazioneId);
        if (!$cc) {
            return 'Convenzione non trovata.';
        }
        if (!self::attendeIlPdf($cc)) {
            return 'Questa scuola ha già caricato il PDF: il promemoria non serve.';
        }
        $email = self::emailScuola($cc);
        if ($email === '') {
            return 'Non c\'è un\'email valida a cui scrivere: la scuola non l\'ha indicata.';
        }
        $ultimo = $this->messaggi->ultimoDelTipo($compilazioneId, MessaggiConvenzioneRepository::TIPO_PROMEMORIA);
        if ($ultimo !== null && strtotime($ultimo) > time() - self::ORE_TRA_PROMEMORIA * 3600) {
            return 'Il promemoria è già partito il ' . date('d/m/Y \a\l\l\e H:i', (int) strtotime($ultimo)) . ': riprova domani.';
        }
        if (!$this->notifiche->promemoriaPdf($email, self::nomeScuola($cc), (string) $cc['token'], (string) ($cc['nota_ufficio'] ?? ''), (string) ($cc['stato_iter'] ?? '') === Costanti::ITER_RIFIUTATA)) {
            return 'L\'email non è partita: riprova più tardi.';
        }
        $this->messaggi->aggiungi($compilazioneId, MessaggiConvenzioneRepository::UFFICIO, MessaggiConvenzioneRepository::TIPO_PROMEMORIA, 'Promemoria: carica la convenzione firmata in PDF.', $autore);

        return null;
    }

    /**
     * Ricorda il PDF a tutte le scuole che lo stanno aspettando. Ritorna [partiti, saltati (già avvisati da poco o senza email)].
     *
     * @return array{0: int, 1: int}
     */
    public function ricordaATutte(string $autore): array
    {
        $partiti = 0;
        $saltati = 0;
        foreach ($this->compilate->recenti(500) as $cc) {
            if (!self::attendeIlPdf($cc) || empty($cc['dati_json'])) {
                continue;
            }
            $this->ricordaPdf((int) $cc['id'], $autore) === null ? ++$partiti : ++$saltati;
        }

        return [$partiti, $saltati];
    }

    /** @return string|null errore, null se il messaggio è partito */
    public function scriviAllaScuola(int $compilazioneId, string $testo, string $autore): ?string
    {
        $testo = trim($testo);
        if ($testo === '') {
            return 'Scrivi il messaggio.';
        }
        if (mb_strlen($testo) > self::MAX_CARATTERI) {
            return 'Il messaggio è troppo lungo (al massimo ' . self::MAX_CARATTERI . ' caratteri).';
        }
        $cc = $this->compilate->perId($compilazioneId);
        if (!$cc) {
            return 'Convenzione non trovata.';
        }
        $email = self::emailScuola($cc);
        if ($email === '') {
            return 'Non c\'è un\'email valida a cui scrivere: la scuola non l\'ha indicata.';
        }
        if (!$this->notifiche->messaggioDallUfficio($email, self::nomeScuola($cc), (string) $cc['token'], $testo)) {
            return 'L\'email non è partita: riprova più tardi.';
        }
        $this->messaggi->aggiungi($compilazioneId, MessaggiConvenzioneRepository::UFFICIO, MessaggiConvenzioneRepository::TIPO_MESSAGGIO, $testo, $autore);

        return null;
    }

    /** Risposta della scuola dalla sua pagina: avvisa chi gestisce le convenzioni. @return string|null errore */
    public function rispondi(array $cc, string $testo): ?string
    {
        $testo = trim($testo);
        if ($testo === '') {
            return 'Scrivi il messaggio.';
        }
        if (mb_strlen($testo) > self::MAX_CARATTERI) {
            return 'Il messaggio è troppo lungo (al massimo ' . self::MAX_CARATTERI . ' caratteri).';
        }
        $id = (int) $cc['id'];
        if ($this->messaggi->dellaScuolaOggi($id) >= self::MAX_RISPOSTE_AL_GIORNO) {
            return 'Hai già mandato molti messaggi oggi: riprova domani oppure scrivi all\'ufficio.';
        }
        $this->messaggi->aggiungi($id, MessaggiConvenzioneRepository::SCUOLA, MessaggiConvenzioneRepository::TIPO_MESSAGGIO, $testo, self::nomeScuola($cc));
        $this->notifiche->messaggioDallaScuola($this->compilate->emailAbilitati(), self::nomeScuola($cc), $testo);

        return null;
    }
}
