<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Sito;
use App\Infrastructure\Storage\Upload;

/**
 * File PDF del tutorato che si caricano a mano: i tre decreti del bando (bando, commissione, approvazione atti) e l'UNILAV di ogni tutor.
 * Stanno in una cartella bloccata al web e si scaricano solo da documento_tutorato.php, dopo il controllo dei permessi.
 */
final class FileTutorato
{
    /** Tipo di decreto => colonna di tutorato_bandi. */
    public const DECRETI = ['bando' => 'decreto_bando_file', 'commissione' => 'decreto_commissione_file', 'atti' => 'decreto_atti_file'];

    /** Dimensione massima di un PDF caricato. */
    public const MAX = 20 * 1024 * 1024;

    public function __construct(
        private BandoRepository $bandi,
        private IncaricoRepository $incarichi,
        private Upload $upload,
        private Sito $sito,
        private string $cartella = Costanti::DIR_INCARICHI
    ) {
    }

    /**
     * Il PDF di un modulo (elemento di $_FILES) come testo. Ritorna [errore, contenuto]: un solo dei due è valorizzato.
     *
     * @param array<string, mixed>|null $file
     * @return array{0: string|null, 1: string|null}
     */
    public function leggiUpload(?array $file): array
    {
        $errore = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errore === UPLOAD_ERR_NO_FILE || $file === null) {
            return ['Scegli il file PDF da caricare.', null];
        }
        if ($errore === UPLOAD_ERR_INI_SIZE || $errore === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::MAX) {
            return ['Il file è troppo grande: il limite è ' . (self::MAX / 1048576) . ' MB.', null];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($errore !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return ['Il caricamento non è riuscito: riprova.', null];
        }
        $c = file_get_contents($tmp);

        return $c === false ? ['Il file non si può leggere.', null] : [null, $c];
    }

    /** Un decreto in PDF del bando (sostituisce quello già caricato). Ritorna l'errore o null. */
    public function salvaDecreto(int $bandoId, string $tipo, string $pdf): ?string
    {
        $colonna = self::DECRETI[$tipo] ?? null;
        $bando = $this->bandi->perId($bandoId);
        if ($colonna === null || !$bando) {
            return 'Bando o decreto non trovato.';
        }
        if (($err = $this->controllaPdf($pdf)) !== null) {
            return $err;
        }
        $nuovo = $this->scrivi('decreti/', $pdf);
        if ($nuovo === null) {
            return 'Non riesco a salvare il file: riprova tra poco.';
        }
        $vecchio = $this->percorsoSicuro((string) ($bando[$colonna] ?? ''));
        $this->bandi->impostaFile($bandoId, $colonna, $nuovo);
        if ($vecchio !== null) {
            @unlink($vecchio);
        }

        return null;
    }

    /**
     * L'UNILAV del tutor (sostituisce il precedente). Si carica dopo il protocollo della lettera e finché la pratica non è all'ufficio pagamenti;
     * si riferisce alle ore approvate (se già note) o a quelle della lettera. Ritorna l'errore o null.
     */
    public function salvaUnilav(int $incaricoId, string $pdf): ?string
    {
        $i = $this->incarichi->perId($incaricoId);
        if (!$i) {
            return 'Lettera non trovata.';
        }
        if (!in_array((string) $i['stato'], ['protocollata'], true)) {
            return "L'UNILAV si carica dopo il protocollo della lettera di incarico.";
        }
        if (in_array((string) $i['pagamento_stato'], ['in_ufficio', 'pagata'], true)) {
            return "La pratica è già all'ufficio pagamenti: l'UNILAV non si cambia più.";
        }
        if (($err = $this->controllaPdf($pdf)) !== null) {
            return $err;
        }
        $nuovo = $this->scrivi('unilav/', $pdf);
        if ($nuovo === null) {
            return 'Non riesco a salvare il file: riprova tra poco.';
        }
        $ore = $i['ore_approvate'] !== null ? (float) $i['ore_approvate'] : (float) $i['ore'];
        $vecchio = $this->percorsoSicuro((string) ($i['unilav_file'] ?? ''));
        $this->incarichi->impostaUnilav($incaricoId, $nuovo, $ore);
        if ($vecchio !== null) {
            @unlink($vecchio);
        }

        return null;
    }

    /** Percorso reale di un decreto del bando (null se non c'è). @param array<string, mixed> $bando */
    public function percorsoDecreto(array $bando, string $tipo): ?string
    {
        $colonna = self::DECRETI[$tipo] ?? null;

        return $colonna !== null && !empty($bando[$colonna]) ? $this->percorsoSicuro((string) $bando[$colonna]) : null;
    }

    /** Percorso reale dell'UNILAV di una lettera (null se non c'è). @param array<string, mixed> $incarico */
    public function percorsoUnilav(array $incarico): ?string
    {
        return !empty($incarico['unilav_file']) ? $this->percorsoSicuro((string) $incarico['unilav_file']) : null;
    }

    private function controllaPdf(string $pdf): ?string
    {
        if ($pdf === '') {
            return 'Il file è vuoto.';
        }
        if (strlen($pdf) > self::MAX) {
            return 'Il file è troppo grande: il limite è ' . (self::MAX / 1048576) . ' MB.';
        }

        return strncmp($pdf, '%PDF-', 5) === 0 ? null : 'Il file non è un PDF.';
    }

    /** Scrive il file con un nome casuale in una sottocartella protetta; ritorna il percorso relativo. */
    private function scrivi(string $sotto, string $pdf): ?string
    {
        $dir = $this->sito->radice() . '/' . $this->cartella . $sotto;
        $this->upload->cartellaProtetta($dir, 'Documenti del tutorato: si scaricano solo da documento_tutorato.php');
        $nome = bin2hex(random_bytes(16)) . '.pdf';

        return file_put_contents($dir . $nome, $pdf) === false ? null : $this->cartella . $sotto . $nome;
    }

    private function percorsoSicuro(string $relativo): ?string
    {
        $base = realpath($this->sito->radice() . '/' . rtrim($this->cartella, '/'));
        $p = $relativo !== '' ? realpath($this->sito->radice() . '/' . $relativo) : false;

        return $base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p) ? $p : null;
    }
}
