<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Storage\Upload;

/** Registro delle convenzioni con le scuole nel pannello FSL: registrazione e modifica con i file firmati (solo PAdES), eliminazione, download. */
final class ServizioRegistroConvenzioni
{
    /** Cartella dei documenti firmati: mai raggiungibili dal web, si scaricano solo da admin/convenzione_file.php. */
    private const CARTELLA = 'uploads/convenzioni/';

    public function __construct(
        private ConvenzioneRepository $convenzioni,
        private ServizioConvenzioni $servizio,
        private Upload $upload,
        private VerificaFirme $firme,
        private Sito $sito
    ) {
    }

    /**
     * Registra (conv_id = 0) o modifica una convenzione dal modulo del pannello: la scuola, le date, i docenti dell'Allegato A e i
     * PDF firmati in PAdES (i .p7m CAdES non si accettano: finiscono nei file non caricati).
     *
     * @param array<string, mixed> $post campi del modulo
     * @param array<string, mixed> $files elementi di $_FILES (file_convenzione, file_allegato)
     */
    public function salvaDalPannello(array $post, array $files, string $autore): EsitoRegistro
    {
        $id = (int) ($post['conv_id'] ?? 0);
        $codice = strtoupper(trim((string) ($post['scuola_codice']['conv'] ?? '')));
        $cartella = $this->sito->radice() . '/' . self::CARTELLA;
        $this->upload->cartellaProtetta($cartella, 'Convenzioni firmate: si scaricano solo dal pannello (admin/convenzione_file.php)');
        $nuovi = [];
        $nonCaricati = [];
        foreach (['file_convenzione' => 'convenzione', 'file_allegato' => 'Allegato A'] as $campo => $nome) {
            if (($files[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $pades = is_uploaded_file((string) ($files[$campo]['tmp_name'] ?? '')) && $this->firme->firmePades((string) file_get_contents($files[$campo]['tmp_name']));
            $fn = $pades ? $this->upload->salva($files[$campo], $cartella, ['pdf'], ['application/pdf']) : null;
            if ($fn) {
                $nuovi[$campo] = self::CARTELLA . $fn;
            } else {
                $nonCaricati[] = $nome;
            }
        }
        $docenti = [];
        foreach ((array) ($post['doc_nome'] ?? []) as $i => $nomeDocente) {
            $docenti[] = ['nome' => $nomeDocente, 'email' => $post['doc_email'][$i] ?? ''];
        }
        // File sostituiti: si cancellano i vecchi
        $vecchia = $id > 0 ? $this->convenzioni->perId($id) : null;
        if ($id > 0 && !$vecchia) {
            $id = -1;
        }
        $esito = $id < 0 ? null : $this->servizio->salva([
            'scuola_codice' => $codice, 'data_stipula' => $post['data_stipula'] ?? null, 'scadenza' => $post['scadenza'] ?? null,
            'protocollo' => $post['protocollo'] ?? '', 'note' => $post['note'] ?? '', 'docenti' => $docenti,
        ] + $nuovi, max(0, $id), $autore);
        if ($esito === null) {
            foreach ($nuovi as $nuovo) {
                @unlink($this->sito->radice() . '/' . $nuovo);
            }

            return new EsitoRegistro(false, $id, 0, false, $nonCaricati, $codice);
        }
        [$idSalvato, $aggiornate] = $esito;
        if ($vecchia) {
            foreach ($nuovi as $campo => $nuovo) {
                if (!empty($vecchia[$campo])) {
                    @unlink($this->sito->radice() . '/' . $vecchia[$campo]);
                }
            }
        }

        return new EsitoRegistro(true, $idSalvato, $aggiornate, $vecchia !== null, $nonCaricati, $codice);
    }

    /**
     * Toglie la convenzione dal registro con i suoi file; ritorna la riga eliminata (null se non c'era).
     *
     * @return array<string, string|null>|null
     */
    public function elimina(int $id): ?array
    {
        $vecchia = $this->convenzioni->perId($id);
        if (!$vecchia) {
            return null;
        }
        foreach (['file_convenzione', 'file_allegato'] as $campo) {
            if (!empty($vecchia[$campo])) {
                @unlink($this->sito->radice() . '/' . $vecchia[$campo]);
            }
        }
        $this->convenzioni->elimina($id);

        return $vecchia;
    }

    /**
     * File firmato di una convenzione da scaricare dal pannello: percorso reale (solo dentro uploads/convenzioni/) e nome del download.
     * $colonna: file_convenzione | file_allegato. Null se il file non c'è o è fuori dalla cartella.
     *
     * @return array{percorso: string, nome: string, ext: string}|null
     */
    public function fileDaScaricare(int $id, string $colonna): ?array
    {
        $c = $this->convenzioni->fileDi($id, $colonna);
        $base = realpath($this->sito->radice() . '/' . rtrim(self::CARTELLA, '/'));
        $percorso = $c && !empty($c['file']) ? realpath($this->sito->radice() . '/' . $c['file']) : false;
        if (!$percorso || !$base || strpos($percorso, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($percorso)) {
            return null;
        }
        $ext = strtolower(pathinfo($percorso, PATHINFO_EXTENSION));

        return ['percorso' => $percorso, 'ext' => $ext, 'nome' => ($colonna === 'file_allegato' ? 'Allegato_A_' : 'Convenzione_') . preg_replace('/[^A-Z0-9]/', '', (string) $c['scuola_codice']) . '.' . $ext];
    }
}
