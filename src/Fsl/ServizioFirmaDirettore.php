<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use App\Infrastructure\Storage\Upload;
use App\Sistema\ImpostazioniSistemaRepository;
use App\Tutorato\FirmaRemota;
use App\Tutorato\VerificaPdfFirmato;

/**
 * Firma PAdES del Direttore sulla convenzione che la scuola ha già firmato. L'ufficio manda la convenzione al Direttore (link personale
 * per email); il Direttore, dopo l'accesso, la firma con la firma remota Aruba dal portale oppure scarica il PDF, lo firma e lo carica.
 * Il PDF firmato deve essere il file della scuola con una firma in più (nessuna modifica). Poi l'ufficio la protocolla e la registra.
 */
final class ServizioFirmaDirettore
{
    /** Riquadro della firma visibile sull'ultima pagina (punti, A4): pagina, x, y, larghezza, altezza. */
    private const RIQUADRO = ['x' => 60, 'y' => 90, 'l' => 230, 'a' => 60];

    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private ImpostazioniSistemaRepository $impostazioni,
        private NotificheConvenzioni $notifiche,
        private FirmaRemota $firmaRemota,
        private VerificaPdfFirmato $verifica,
        private Upload $upload,
        private Sito $sito
    ) {
    }

    /** @return array{nome: string, email: string, cf: string} */
    public function direttore(): array
    {
        return $this->impostazioni->direttoreConvenzioni();
    }

    public function configurato(): bool
    {
        return filter_var($this->direttore()['email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Salva il Direttore che firma le convenzioni. Ritorna il messaggio di errore, null se salvato. */
    public function salvaDirettore(string $nome, string $email, string $cf): ?string
    {
        $nome = mb_substr(trim($nome), 0, 150);
        $email = strtolower(trim($email));
        $cf = strtoupper((string) preg_replace('/\s+/', '', $cf));
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Indica nome ed email del Direttore: a questa email arriva il link per firmare.';
        }
        if ($cf !== '' && !preg_match('/^[A-Z0-9]{16}$/', $cf)) {
            return 'Il codice fiscale ha 16 caratteri (lascialo vuoto se non vuoi controllare il certificato di firma).';
        }
        $this->impostazioni->salvaDirettoreConvenzioni($nome, $email, $cf);

        return null;
    }

    public function firmaRemotaDisponibile(): bool
    {
        return $this->firmaRemota->disponibile();
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $this->compilate->perTokenDirettore($token);
    }

    /**
     * L'ufficio manda la convenzione presa in carico al Direttore. Ritorna il messaggio di errore, null se è andato a buon fine.
     */
    public function invia(int $id): ?string
    {
        $cc = $this->compilate->perId($id);
        if (!$cc || empty($cc['file_firmato']) || !in_array((string) $cc['stato_iter'], [Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE], true)) {
            return 'Prima prendi in carico la convenzione, poi puoi mandarla al Direttore.';
        }
        if (!$this->configurato()) {
            return 'Indica prima il Direttore del Dipartimento (nome ed email, in Sistema e registri): il link di firma arriva a quell\'indirizzo.';
        }
        $token = bin2hex(random_bytes(20));
        $this->compilate->daFirmare($id, $token);
        $d = $this->direttore();
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $this->notifiche->firmaRichiesta($d['email'], $d['nome'], (string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola'), $token);

        return null;
    }

    /**
     * La persona collegata è il Direttore indicato (stessa email o stesso codice fiscale).
     *
     * @param array<string, mixed>|null $utente
     */
    public function puoFirmare(?array $utente): bool
    {
        if (!$utente || empty($utente['id'])) {
            return false;
        }
        $d = $this->direttore();
        $email = strtolower(trim((string) ($utente['email'] ?? '')));
        $cf = strtoupper(trim((string) ($utente['codice_fiscale'] ?? '')));

        return ($email !== '' && $email === strtolower($d['email'])) || ($cf !== '' && $d['cf'] !== '' && $cf === strtoupper($d['cf']));
    }

    /** Percorso del PDF della scuola da firmare (solo dentro la cartella delle firmate), null se non c'è. */
    public function pdfDaFirmare(array $cc): ?string
    {
        $base = realpath($this->sito->radice() . '/' . rtrim(Costanti::DIR_FIRMATE, '/'));
        $p = !empty($cc['file_firmato']) ? realpath($this->sito->radice() . '/' . $cc['file_firmato']) : false;

        return $base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p) ? $p : null;
    }

    /**
     * Firma con la firma remota Aruba (utente, password e OTP non si salvano). Ritorna il messaggio di errore, null se la firma è registrata.
     *
     * @param array<string, mixed> $cc
     */
    public function firmaConAruba(array $cc, string $utente, string $password, string $otp): ?string
    {
        if ((string) $cc['stato_iter'] !== Costanti::ITER_DA_FIRMARE) {
            return 'La convenzione non è in attesa della tua firma.';
        }
        $p = $this->pdfDaFirmare($cc);
        if ($p === null) {
            return 'Manca il PDF della convenzione.';
        }
        $pdf = (string) file_get_contents($p);
        [$firmato, $err] = $this->firmaRemota->firma($pdf, $utente, $password, $otp, $this->riquadro($pdf), 'Il Direttore del Dipartimento');
        if ($firmato === null) {
            return $err ?: 'Firma non riuscita.';
        }

        return $this->registra($cc, $firmato, 'firma remota Aruba');
    }

    /** Firma fatta fuori dal portale: si carica il PDF firmato. Ritorna il messaggio di errore, null se la firma è registrata. */
    public function caricaFirmato(array $cc, string $pdfFirmato): ?string
    {
        if (strlen($pdfFirmato) > Costanti::MAX_FIRMATA * 2) {
            return 'Il file è troppo grande.';
        }

        return $this->registra($cc, $pdfFirmato, 'PDF firmato caricato');
    }

    /**
     * Controlla il PDF firmato (la convenzione della scuola più la firma del Direttore, senza altre modifiche), lo salva e avvisa l'ufficio.
     *
     * @param array<string, mixed> $cc
     */
    private function registra(array $cc, string $firmato, string $come): ?string
    {
        if ((string) $cc['stato_iter'] !== Costanti::ITER_DA_FIRMARE) {
            return 'La convenzione non è in attesa della tua firma.';
        }
        $p = $this->pdfDaFirmare($cc);
        if ($p === null) {
            return 'Manca il PDF della convenzione.';
        }
        [$err, $info] = $this->verifica->verifica((string) file_get_contents($p), $firmato, $this->direttore()['cf']);
        if ($err) {
            return $err;
        }
        $cartella = $this->sito->radice() . '/' . Costanti::DIR_CONVENZIONI;
        $this->upload->cartellaProtetta($cartella, 'Convenzioni firmate: si scaricano solo dal pannello (admin/convenzione_file.php)');
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        if (file_put_contents($cartella . $nome, $firmato) === false) {
            return 'Non riesco a salvare il file: riprova tra poco.';
        }
        $chi = $info['nome'] !== '' ? $info['nome'] : $this->direttore()['nome'];
        $this->compilate->segnaFirmataDirettore((int) $cc['id'], Costanti::DIR_CONVENZIONI . $nome, $chi . ' (' . $come . ')');
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $destinatari = array_values(array_unique(array_map('strtolower', array_merge($this->compilate->emailAbilitati(), [Costanti::EMAIL_UFFICIO]))));
        $this->notifiche->firmataDalDirettore((string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola'), $destinatari);

        return null;
    }

    /**
     * Riquadro della firma visibile sull'ultima pagina; se non si riesce a contare le pagine la firma è invisibile (valida lo stesso).
     *
     * @return array{pagina: int, x: int, y: int, l: int, a: int}|null
     */
    public function riquadro(string $pdf): ?array
    {
        $n = self::contaPagine($pdf);

        return $n > 0 ? ['pagina' => $n] + self::RIQUADRO : null;
    }

    /** Numero di pagine dal dizionario /Pages principale (0 se il PDF le tiene in flussi compressi e non si leggono). */
    public static function contaPagine(string $pdf): int
    {
        $max = 0;
        if (preg_match_all('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)|/Count\s+(\d+)[^>]*?/Type\s*/Pages\b#s', $pdf, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $max = max($max, (int) ($x[1] !== '' ? $x[1] : ($x[2] ?? 0)));
            }
        }

        return $max;
    }
}
