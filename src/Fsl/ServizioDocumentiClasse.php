<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Esito;
use App\Core\Sito;
use App\Fsl\Vista\AvvisoDocumenti;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Storage\Upload;
use App\Portale\ColoriAree;

/**
 * Elenco degli studenti e autorizzazione della scuola (PDF) da consegnare dopo la prenotazione e prima dell'inizio di
 * un'attività FSL: caricamento e scaricamento dell'autorizzazione, avviso dopo la prenotazione e promemoria del cron.
 */
final class ServizioDocumentiClasse
{
    public function __construct(
        private DocumentiClasse $regole,
        private DocumentiClasseRepository $repo,
        private AvvisoDocumenti $avvisi,
        private Upload $upload,
        private Mailer $mailer,
        private ColoriAree $colori,
        private Sito $sito
    ) {
    }

    /** @param array<string, mixed> $p prenotazione con fsl, evento_tipo, per_scuole */
    public function richiesti(array $p): bool
    {
        return $this->regole->richiesti($p);
    }

    /** @param array<string, mixed> $p */
    public function consegnaAperta(array $p): bool
    {
        return $this->regole->consegnaAperta($p);
    }

    /** @param array<string, mixed> $p */
    public function attiva(array $p): bool
    {
        return $this->regole->attiva($p);
    }

    /**
     * Cosa è già stato consegnato e entro quando.
     *
     * @param array<string, mixed> $p prenotazione (id, autorizzazione_file, data_turno, data_inizio, data_prenotazione)
     * @return array{studenti: int, max: int, elenco: bool, autorizzazione: bool, completi: bool, scadenza: ?string, scaduti: bool}
     */
    public function stato(array $p, int $max = 0): array
    {
        return $this->regole->stato($p, $this->repo->contaStudenti((int) $p['id']), $max);
    }

    /**
     * Avviso per l'email di prenotazione e per la pagina di conferma ('' se l'attività non è FSL di classe).
     *
     * @param array<string, mixed>|null $dettagli riga di progetti_dettagli dell'attività
     * @param string|null $dataTurno giorno del turno prenotato
     */
    public function avvisoPrenotazione(?array $dettagli, bool $eProgetto, string $codice, ?string $dataTurno, bool $perEmail): string
    {
        $p = [
            'fsl' => $dettagli['convenzione'] ?? 0, 'evento_tipo' => $eProgetto ? 'progetto' : 'evento', 'per_scuole' => $dettagli['per_scuole'] ?? 1,
            'data_turno' => $dataTurno, 'data_inizio' => $dettagli['data_inizio'] ?? null, 'data_prenotazione' => date('Y-m-d'),
        ];
        if (!$this->regole->richiesti($p)) {
            return '';
        }

        return $this->avvisi->dopoPrenotazione($codice, $this->regole->scadenza($p), $perEmail);
    }

    /**
     * Riquadro del modulo di prenotazione ('' se l'attività non è FSL di classe).
     *
     * @param array<string, mixed>|null $dettagli riga di progetti_dettagli dell'attività
     */
    public function avvisoModulo(?array $dettagli, bool $eProgetto, ?string $dataTurno): string
    {
        $p = [
            'fsl' => $dettagli['convenzione'] ?? 0, 'evento_tipo' => $eProgetto ? 'progetto' : 'evento', 'per_scuole' => $dettagli['per_scuole'] ?? 1,
            'data_turno' => $dataTurno, 'data_inizio' => $dettagli['data_inizio'] ?? null, 'data_prenotazione' => date('Y-m-d'),
        ];

        return $this->regole->richiesti($p) ? $this->avvisi->nelModulo($this->regole->scadenza($p)) : '';
    }

    /** Avviso per la pagina di conferma, dal codice della prenotazione ('' se non è una prenotazione FSL attiva). */
    public function avvisoPerCodice(string $codice): string
    {
        $p = $this->repo->perCodice($codice);

        return $p !== null && $this->regole->richiesti($p) && $this->regole->attiva($p)
            ? $this->avvisi->dopoPrenotazione($codice, $this->regole->scadenza($p), false)
            : '';
    }

    /**
     * Salva l'autorizzazione della scuola (PDF), sostituendo la precedente.
     *
     * @param array<string, mixed> $p prenotazione
     * @param array<string, mixed> $file elemento di $_FILES
     */
    public function carica(array $p, array $file): Esito
    {
        if (!$this->regole->richiesti($p) || !$this->regole->attiva($p)) {
            return Esito::errore("Per questa prenotazione non si carica l'autorizzazione.");
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return Esito::errore("Scegli il file PDF dell'autorizzazione.");
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > DocumentiClasse::MAX_BYTE_AUTORIZZAZIONE) {
            return Esito::errore('Il file è troppo grande o non è arrivato: il PDF può pesare al massimo 10 MB.');
        }
        $cartella = $this->cartella();
        $this->upload->cartellaProtetta($cartella, 'Autorizzazioni delle scuole: si scaricano solo da autorizzazione_scuola.php');
        $nome = $this->upload->salva($file, $cartella, ['pdf'], ['application/pdf']);
        if ($nome === null) {
            return Esito::errore("L'autorizzazione deve essere un file PDF.");
        }
        $vecchia = $this->repo->autorizzazione((int) $p['id']);
        $this->repo->salvaAutorizzazione((int) $p['id'], Costanti::DIR_AUTORIZZAZIONI . $nome, (string) ($file['name'] ?? 'autorizzazione.pdf'));
        if ($vecchia !== null) {
            $this->eliminaFile($vecchia['file']);
        }

        return Esito::ok("Autorizzazione della scuola caricata.");
    }

    /** @param array<string, mixed> $p prenotazione */
    public function togli(array $p): Esito
    {
        $vecchia = $this->repo->autorizzazione((int) $p['id']);
        if ($vecchia === null) {
            return Esito::errore("Non c'è nessuna autorizzazione da togliere.");
        }
        $this->repo->togliAutorizzazione((int) $p['id']);
        $this->eliminaFile($vecchia['file']);

        return Esito::ok('Autorizzazione tolta: caricane un\'altra quando è pronta.');
    }

    /**
     * File dell'autorizzazione da scaricare.
     *
     * @return array{percorso: string, nome: string}|null null se manca o non è nella cartella delle autorizzazioni
     */
    public function file(int $prenotazioneId): ?array
    {
        $a = $this->repo->autorizzazione($prenotazioneId);
        if ($a === null) {
            return null;
        }
        $base = realpath($this->cartella());
        $percorso = realpath($this->sito->radice() . '/' . $a['file']);
        if ($base === false || $percorso === false || !str_starts_with($percorso, $base . DIRECTORY_SEPARATOR) || !is_file($percorso)) {
            return null;
        }

        return ['percorso' => $percorso, 'nome' => $a['nome'] !== '' ? $a['nome'] : 'autorizzazione.pdf'];
    }

    /**
     * Compito del cron: avvisa i docenti a cui mancano elenco o autorizzazione (promemoria periodici, poi un ultimo avviso).
     * Ritorna la riga di resoconto.
     */
    public function promemoria(): string
    {
        $promemoria = 0;
        $ultimi = 0;
        foreach ($this->repo->incomplete() as $p) {
            if (empty($p['email']) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $avviso = $this->regole->prossimoAvviso($p);
            if ($avviso === null) {
                continue;
            }
            $ultimo = $avviso === 'ultimo';
            $stato = $this->regole->stato($p, (int) $p['studenti'], (int) ($p['num_posti'] ?? 0));
            $oggetto = ($ultimo ? 'Ultimo avviso: ' : 'Promemoria: ') . 'elenco degli studenti e autorizzazione della scuola - ' . $p['titolo'];
            $corpo = $this->avvisi->promemoria($p, $stato, $ultimo, $this->regole->inizio($p));
            if ($this->mailer->invia((string) $p['email'], $oggetto, $corpo, $this->colori->delTurno((int) $p['turno_id']))) {
                $ultimo ? $this->repo->segnaUltimoAvviso((int) $p['id']) : $this->repo->contaPromemoria((int) $p['id']);
                $ultimo ? ++$ultimi : ++$promemoria;
            }
        }

        return "- Documenti FSL (elenco studenti e autorizzazione): $promemoria promemoria, $ultimi ultimi avvisi.\n";
    }

    private function cartella(): string
    {
        return $this->sito->radice() . '/' . Costanti::DIR_AUTORIZZAZIONI;
    }

    private function eliminaFile(string $relativo): void
    {
        $base = realpath($this->cartella());
        $percorso = realpath($this->sito->radice() . '/' . $relativo);
        if ($base !== false && $percorso !== false && str_starts_with($percorso, $base . DIRECTORY_SEPARATOR) && is_file($percorso)) {
            @unlink($percorso);
        }
    }
}
