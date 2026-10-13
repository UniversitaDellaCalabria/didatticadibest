<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Storage\Upload;

/**
 * Convenzione firmata in PAdES caricata dalla scuola (convenzione_online.php): controlli sul file, salvataggio in una cartella bloccata al
 * web, sostituzione finché l'ufficio non la prende in carico, avvisi a chi gestisce le convenzioni e ricevuta alla scuola.
 * Il file si scarica solo dal pannello (admin/convenzione_file.php) o dalla pagina con il link personale della scuola.
 */
final class ServizioConvenzioneFirmata
{
    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private ServizioConvenzioni $convenzioni,
        private NotificheConvenzioni $notifiche,
        private ServizioFirmaDirettore $direttore,
        private VerificaFirme $firme,
        private Upload $upload,
        private Sito $sito
    ) {
    }

    /** La scuola può caricare (o sostituire) il file finché l'ufficio non lo ha preso in carico; dopo un rifiuto lo ricarica. */
    public static function caricabile(array $cc): bool
    {
        return in_array((string) ($cc['stato_iter'] ?? ''), ['', Costanti::ITER_CARICATA, Costanti::ITER_RIFIUTATA], true);
    }

    /**
     * Carica i file arrivati da un modulo (elementi di $_FILES): la convenzione firmata e l'Allegato A. Ritorna il messaggio di errore da mostrare,
     * null se è andato a buon fine.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     * @param array<string, mixed>|null $file convenzione firmata
     * @param array<string, mixed>|null $fileAllegato Allegato A (PDF, anche senza firma)
     */
    public function caricaDaUpload(array $cc, ?array $file, ?array $fileAllegato = null): ?string
    {
        [$errore, $contenuto] = $this->leggiUpload($file, 'Scegli il file della convenzione firmata.');
        if ($errore !== null) {
            return $errore;
        }
        $allegato = '';
        if (($fileAllegato['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$errore, $allegato] = $this->leggiUpload($fileAllegato, 'Scegli il file dell\'Allegato A.');
            if ($errore !== null) {
                return 'Allegato A: ' . lcfirst($errore);
            }
        }

        return $this->carica($cc, (string) $contenuto, (string) $allegato);
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{0: string|null, 1: string|null} [errore, contenuto]
     */
    private function leggiUpload(?array $file, string $mancante): array
    {
        $errore = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errore === UPLOAD_ERR_NO_FILE || $file === null) {
            return [$mancante, null];
        }
        if ($errore === UPLOAD_ERR_INI_SIZE || $errore === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > Costanti::MAX_FIRMATA) {
            return ['Il file è troppo grande: il limite è ' . (Costanti::MAX_FIRMATA / 1048576) . ' MB.', null];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($errore !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return ['Il caricamento non è riuscito: riprova.', null];
        }
        $c = file_get_contents($tmp);

        return [null, $c === false ? '' : $c];
    }

    /**
     * Controlla e salva il PDF firmato e l'Allegato A. Il primo caricamento richiede entrambi; una sostituzione può cambiare solo la convenzione
     * (l'Allegato A resta quello già caricato). Ritorna il messaggio di errore da mostrare, null se è andato a buon fine.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     */
    public function carica(array $cc, string $pdf, string $allegato = ''): ?string
    {
        if (!self::caricabile($cc)) {
            return "La convenzione è già stata presa in carico dal Dipartimento: non si può più sostituire. Se serve una correzione, scrivi all'ufficio.";
        }
        if ($pdf === '' || strlen($pdf) > Costanti::MAX_FIRMATA) {
            return $pdf === '' ? 'Il file è vuoto.' : 'Il file è troppo grande: il limite è ' . (Costanti::MAX_FIRMATA / 1048576) . ' MB.';
        }
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            return 'Il file non è un PDF. Carica il PDF firmato digitalmente in formato PAdES (non il file .p7m).';
        }
        $firme = $this->firme->firmePades($pdf);
        if (!$firme) {
            return 'Nel PDF non trovo una firma digitale PAdES. Il Dirigente Scolastico deve firmare il PDF in formato PAdES: i file .p7m non sono accettati.';
        }
        foreach ($firme as $f) {
            if (empty($f['valida_struttura'])) {
                return 'La firma digitale del PDF non è integra: ripeti la firma e carica di nuovo il file.';
            }
        }
        if (empty($firme[array_key_last($firme)]['copre_tutto'])) {
            return 'Il PDF è stato modificato dopo la firma: carica il file così come è uscito dalla firma, senza salvarlo di nuovo.';
        }
        if ($allegato === '' && empty($cc['file_allegato_scuola'])) {
            return 'Carica anche l\'Allegato A (PDF): la convenzione e l\'Allegato A si inviano insieme.';
        }
        if ($allegato !== '' && (strncmp($allegato, '%PDF-', 5) !== 0 || strlen($allegato) > Costanti::MAX_FIRMATA)) {
            return 'L\'Allegato A deve essere un PDF (anche senza firma) fino a ' . (Costanti::MAX_FIRMATA / 1048576) . ' MB.';
        }

        $cartella = $this->sito->radice() . '/' . Costanti::DIR_FIRMATE;
        $this->upload->cartellaProtetta($cartella, 'Convenzioni firmate dalle scuole: si scaricano solo dal pannello e dalla pagina con il link personale');
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        $nomeAllegato = $allegato !== '' ? bin2hex(random_bytes(16)) . '.pdf' : null;
        if (file_put_contents($cartella . $nome, $pdf) === false || ($nomeAllegato !== null && file_put_contents($cartella . $nomeAllegato, $allegato) === false)) {
            @unlink($cartella . $nome);

            return 'Non riesco a salvare il file: riprova tra poco.';
        }
        $sostituita = !empty($cc['file_firmato']);
        $vecchio = $sostituita ? $this->percorsoSicuro((string) $cc['file_firmato']) : null;
        $vecchioAllegato = $nomeAllegato !== null && !empty($cc['file_allegato_scuola']) ? $this->percorsoSicuro((string) $cc['file_allegato_scuola']) : null;
        $firmatario = mb_substr(trim((string) ($firme[array_key_last($firme)]['nome'] ?? '')), 0, 255);
        $this->compilate->salvaFirmata((int) $cc['id'], Costanti::DIR_FIRMATE . $nome, $firmatario, $nomeAllegato !== null ? Costanti::DIR_FIRMATE . $nomeAllegato : null);
        foreach ([$vecchio, $vecchioAllegato] as $v) {
            if ($v !== null) {
                @unlink($v);
            }
        }

        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $scuola = (string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola');
        $destinatari = array_values(array_unique(array_map('strtolower', array_merge($this->compilate->emailAbilitati(), [Costanti::EMAIL_UFFICIO]))));
        $this->notifiche->firmataCaricata($scuola, $firmatario, $sostituita, $destinatari);
        $this->notifiche->firmataRicevuta((string) (($dati['scuola']['email'] ?? '') ?: ($cc['email'] ?? '')), $scuola, (string) $cc['token']);

        return null;
    }

    /**
     * L'ufficio registra il protocollo in entrata (solo numero e data: il file non si ricarica): la convenzione è presa in carico, la scuola
     * lo sa e, se il Direttore è indicato, gli arriva subito l'email con il link per firmare.
     * Ritorna ['errore' => ?string, 'direttore' => ?string]: «direttore» è null se il link è partito, altrimenti il perché non è partito.
     *
     * @return array{errore: string|null, direttore: string|null}
     */
    public function protocolloIngresso(int $id, string $numero, string $data, string $autore): array
    {
        $ko = static fn (string $m): array => ['errore' => $m, 'direttore' => null];
        $cc = $this->compilate->perId($id);
        if (!$cc || empty($cc['file_firmato'])) {
            return $ko('Non c\'è nessuna convenzione firmata da protocollare.');
        }
        if ((string) $cc['stato_iter'] !== Costanti::ITER_CARICATA) {
            return $ko('La convenzione non è in attesa del protocollo in entrata.');
        }
        $numero = mb_substr(trim($numero), 0, 80);
        if ($numero === '') {
            return $ko('Indica il numero del protocollo in entrata.');
        }
        $dataOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : null;
        $this->compilate->protocolloIngresso($id, $numero, $dataOk, $autore);
        $testo = $numero . ($dataOk ? ' del ' . date('d/m/Y', (int) strtotime($dataOk)) : '');
        [$scuola, $email] = $this->contatti($cc);
        $this->notifiche->presaInCarico($email, $scuola, (string) $cc['token'], $testo);

        return ['errore' => null, 'direttore' => $this->direttore->invia($id)];
    }
    /** L'ufficio rifiuta il file con un motivo (obbligatorio): la scuola lo riceve per email e può caricare un altro file. */
    public function rifiuta(int $id, string $motivo): ?string
    {
        $cc = $this->compilate->perId($id);
        $motivo = trim($motivo);
        if (!$cc || empty($cc['file_firmato']) || !in_array((string) $cc['stato_iter'], [Costanti::ITER_CARICATA, Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE], true)) {
            return 'Questa convenzione non si può rifiutare in questo momento.';
        }
        if ($motivo === '') {
            return 'Scrivi il motivo del rifiuto: la scuola lo riceve per email.';
        }
        $this->compilate->rifiuta($id, $motivo);
        [$scuola, $email] = $this->contatti($cc);
        $this->notifiche->rifiutata($email, $scuola, mb_substr($motivo, 0, 500), (string) $cc['token']);

        return null;
    }

    /**
     * Il PDF di un modulo (elemento di $_FILES) come testo, solo se è davvero un caricamento riuscito; null altrimenti.
     *
     * @param array<string, mixed>|null $file
     */
    public function contenutoDaUpload(?array $file): ?string
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return null;
        }
        $c = file_get_contents($tmp);

        return $c === false ? null : $c;
    }

    /**
     * Ricarico finale: la convenzione firmata anche dal Direttore, protocollata. Si registra nel registro delle convenzioni (con validità e file),
     * le prenotazioni della scuola in attesa della convenzione si confermano (e le persone sono avvisate) e la scuola riceve l'avviso.
     * $dati: protocollo (numero), protocollo_data, data_stipula (valida dal), scadenza (valida fino al).
     * $pdf vuoto = si usa il PDF già firmato dal Direttore sul portale (stato «firmata_direttore»).
     *
     * @param array<string, mixed> $dati
     * @return array{errore: string|null, prenotazioni: int, convenzione_id: int}
     */
    public function completa(int $id, string $pdf, array $dati, string $autore): array
    {
        $ko = static fn (string $m): array => ['errore' => $m, 'prenotazioni' => 0, 'convenzione_id' => 0];
        $cc = $this->compilate->perId($id);
        if (!$cc) {
            return $ko('Convenzione non trovata.');
        }
        if ((string) $cc['stato_iter'] === Costanti::ITER_PROTOCOLLATA) {
            return $ko('Questa convenzione è già stata protocollata e registrata.');
        }
        $codice = ConvenzioneRepository::codiceValido((string) ($cc['scuola_codice'] ?? ''));
        if ($codice === null) {
            return $ko('La scuola non è collegata all\'anagrafe: registra la convenzione dal modulo «Registra, modifica o rinnova» scegliendo la scuola.');
        }
        $protocollo = mb_substr(trim((string) ($dati['protocollo'] ?? '')), 0, 80);
        if ($protocollo === '') {
            return $ko('Indica il numero di protocollo.');
        }
        $dataOk = static fn ($x): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x) ? (string) $x : null;
        $dataProt = $dataOk($dati['protocollo_data'] ?? null);
        $dal = $dataOk($dati['data_stipula'] ?? null);
        $al = $dataOk($dati['scadenza'] ?? null);
        if (!$dal || !$al || $al < $dal) {
            return $ko('Controlla le date di validità: «valida fino al» non può precedere «valida dal».');
        }
        $cartella = $this->sito->radice() . '/' . Costanti::DIR_CONVENZIONI;
        $nuovo = false;
        if ($pdf === '' && (string) $cc['stato_iter'] === Costanti::ITER_FIRMATA && !empty($cc['file_definitivo']) && is_file($this->sito->radice() . '/' . $cc['file_definitivo'])) {
            $percorso = (string) $cc['file_definitivo'];
        } else {
            if (strncmp($pdf, '%PDF-', 5) !== 0 || strlen($pdf) > Costanti::MAX_FIRMATA) {
                return $ko('Carica il PDF della convenzione firmata (fino a ' . (Costanti::MAX_FIRMATA / 1048576) . ' MB).');
            }
            if (!$this->firme->firmePades($pdf)) {
                return $ko('Nel PDF non trovo una firma digitale PAdES: serve la convenzione firmata in PAdES, i file .p7m non sono accettati.');
            }
            $this->upload->cartellaProtetta($cartella, 'Convenzioni firmate: si scaricano solo dal pannello (admin/convenzione_file.php)');
            $nome = bin2hex(random_bytes(16)) . '.pdf';
            if (file_put_contents($cartella . $nome, $pdf) === false) {
                return $ko('Non riesco a salvare il file: riprova tra poco.');
            }
            $percorso = Costanti::DIR_CONVENZIONI . $nome;
            $nuovo = true;
        }
        $testoProt = $protocollo . ($dataProt ? ' del ' . date('d/m/Y', (int) strtotime($dataProt)) : '');
        // L'Allegato A caricato dalla scuola va nel registro insieme alla convenzione (copia nella cartella delle convenzioni)
        $percorsoAllegato = null;
        $origineAllegato = !empty($cc['file_allegato_scuola']) ? $this->percorsoSicuro((string) $cc['file_allegato_scuola']) : null;
        if ($origineAllegato !== null) {
            $this->upload->cartellaProtetta($cartella, 'Convenzioni firmate: si scaricano solo dal pannello (admin/convenzione_file.php)');
            $nomeAllegato = bin2hex(random_bytes(16)) . '.pdf';
            if (@copy($origineAllegato, $cartella . $nomeAllegato)) {
                $percorsoAllegato = Costanti::DIR_CONVENZIONI . $nomeAllegato;
            }
        }
        $esito = $this->convenzioni->salva([
            'scuola_codice' => $codice, 'data_stipula' => $dal, 'scadenza' => $al, 'protocollo' => $testoProt,
            'note' => 'Da convenzione caricata dalla scuola (compilazione online)', 'file_convenzione' => $percorso, 'file_allegato' => $percorsoAllegato,
        ], 0, $autore);
        if ($esito === null) {
            if ($nuovo) {
                @unlink($this->sito->radice() . '/' . $percorso);
            }
            if ($percorsoAllegato !== null) {
                @unlink($this->sito->radice() . '/' . $percorsoAllegato);
            }

            return $ko('Convenzione non registrata: controlla che la scuola sia nell\'anagrafe e le date.');
        }
        [$convId, $aggiornate] = $esito;
        $this->compilate->segnaProtocollata($id, $convId, $testoProt, $dataProt);
        [$scuola, $email] = $this->contatti($cc);
        $this->notifiche->attiva($email, $scuola, (string) $cc['token'], $testoProt, $dal, $al, $aggiornate);

        return ['errore' => null, 'prenotazioni' => $aggiornate, 'convenzione_id' => $convId];
    }

    /**
     * Nome della scuola ed email a cui scrivere (quella indicata nel modulo, altrimenti quella della prenotazione).
     *
     * @param array<string, mixed> $cc
     * @return array{0: string, 1: string}
     */
    private function contatti(array $cc): array
    {
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];

        return [(string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola'), (string) (($dati['scuola']['email'] ?? '') ?: ($cc['email'] ?? ''))];
    }

    /**
     * Il file firmato di una compilazione da scaricare: percorso reale (solo dentro la cartella delle firmate) e nome del download.
     *
     * @return array{percorso: string, nome: string}|null
     */
    public function fileDaScaricare(int $id, string $quale = 'scuola'): ?array
    {
        $cc = $this->compilate->perId($id);
        $direttore = $quale === 'direttore';
        $colonna = $direttore ? 'file_definitivo' : ($quale === 'allegato' ? 'file_allegato_scuola' : 'file_firmato');
        $percorso = $cc && !empty($cc[$colonna]) ? $this->percorsoSicuro((string) $cc[$colonna], $direttore ? Costanti::DIR_CONVENZIONI : Costanti::DIR_FIRMATE) : null;
        if ($percorso === null) {
            return null;
        }
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $sigla = preg_replace('/[^A-Za-z0-9]+/', '_', (string) (($dati['scuola']['codice'] ?? '') ?: ($dati['scuola']['denominazione'] ?? '') ?: 'scuola'));

        return ['percorso' => $percorso, 'nome' => (($direttore ? 'Convenzione_firmata_dal_Direttore_' : ($quale === 'allegato' ? 'Allegato_A_' : 'Convenzione_firmata_')) . trim((string) $sigla, '_')) . '.pdf'];
    }

    /** Percorso reale del file solo se sta dentro la cartella delle convenzioni firmate. */
    private function percorsoSicuro(string $relativo, string $cartella = Costanti::DIR_FIRMATE): ?string
    {
        $base = realpath($this->sito->radice() . '/' . rtrim($cartella, '/'));
        $percorso = realpath($this->sito->radice() . '/' . $relativo);
        if (!$base || !$percorso || strpos($percorso, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($percorso)) {
            return null;
        }

        return $percorso;
    }
}
