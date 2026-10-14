<?php

declare(strict_types=1);

namespace App\Didattica;

/**
 * Il passo «attesa del pagamento» dell'iter (certificati con marche da bollo): l'ufficio carica le marche in Esse3 e lo segnala (lo studente è avvisato
 * e può pagare); quando il pagamento risulta lo spunta e carica il certificato generato da Esse3. La marca da bollo si considera assolta virtualmente
 * e la pratica passa al passo successivo (la firma del Direttore).
 */
final class ServizioMarcheBollo
{
    public const DICITURA = 'Marca da bollo assolta virtualmente';

    public function __construct(
        private PraticaRepository $pratiche,
        private ModuloRepository $moduli,
        private ServizioIter $iter,
        private StoricoPratica $storico,
        private NotifichePratiche $notifiche,
        private AllegatiPratiche $allegati,
        private ServizioFlussoConvalide $flusso
    ) {
    }

    /**
     * La pratica è nel passo di attesa del pagamento.
     *
     * @param array<string, mixed> $p
     */
    public function inAttesa(array $p): bool
    {
        return $this->iter->passoPagamento($this->moduli->perId((int) $p['modulo_id']), $p);
    }

    /**
     * Le marche da bollo sono state caricate in Esse3: lo studente è avvisato e può pagare. Ritorna un errore o null.
     */
    public function avvisaMarche(int $id, int $uid, string $autoreNome = ''): ?string
    {
        $p = $this->pratiche->perId($id);
        if (!$p || !$this->inAttesa($p)) {
            return 'La pratica non è in attesa del pagamento delle marche da bollo.';
        }
        if (!empty($p['marche_il'])) {
            return 'Lo studente è già stato avvisato il ' . date('d/m/Y', (int) strtotime((string) $p['marche_il'])) . '.';
        }
        $this->pratiche->segnaMarche($id, $autoreNome);
        $this->storico->evento(
            $id,
            'attivita',
            'ufficio',
            $uid,
            null,
            'Le marche da bollo sono state caricate in Esse3 (Segreteria > Situazione tasse): puoi procedere al pagamento.',
            null,
            null,
            false,
            $autoreNome
        );
        if ($p2 = $this->pratiche->perId($id)) {
            $this->notifiche->marcheCaricate($p2);
        }

        return null;
    }

    /**
     * Il pagamento risulta: l'operatore lo spunta e carica il certificato (PDF generato da Esse3). La pratica passa alla firma del Direttore.
     * Ritorna [errore|null, messaggio].
     *
     * @param array<string, mixed>|null $file elemento di $_FILES
     * @return array{0: ?string, 1: string}
     */
    public function registraPagamento(int $id, ?array $file, bool $conferma, int $uid, string $autoreNome = ''): array
    {
        $p = $this->pratiche->perId($id);
        if (!$p || !$this->inAttesa($p)) {
            return ['La pratica non è in attesa del pagamento delle marche da bollo.', ''];
        }
        if (empty($p['marche_il'])) {
            return ['Prima segnala che le marche sono state caricate in Esse3: lo studente è avvisato e può pagare.', ''];
        }
        if (!empty($p['bollo_il'])) {
            return ['Il pagamento è già stato registrato.', ''];
        }
        if (!$conferma) {
            return ['Spunta che il pagamento della marca da bollo risulta.', ''];
        }
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['Carica il certificato in PDF generato da Esse3: è il documento che firma il Direttore.', ''];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'pdf' || !is_file($tmp)) {
            return ['Il certificato deve essere un PDF (fino a 10 MB).', ''];
        }
        $rel = $this->allegati->salvaPdf((string) file_get_contents($tmp));
        if (!$rel) {
            return ['Il file non è un PDF valido (fino a 10 MB).', ''];
        }
        $this->pratiche->segnaBollo($id, $autoreNome);
        // Il certificato da firmare resta interno: lo studente vede il documento firmato quando è pronto
        $this->storico->evento($id, 'attivita', 'ufficio', $uid, null, 'Certificato generato da Esse3, da firmare.', $rel, 'Certificato_' . $p['codice'] . '.pdf', true, $autoreNome);
        $this->storico->evento($id, 'attivita', 'ufficio', $uid, null, 'Pagamento verificato. ' . self::DICITURA . '.', null, null, false, $autoreNome);
        [$err, $msg] = $this->flusso->trasmettiAlPasso($id, (int) $p['passo'], $uid, $autoreNome);

        return [$err, $err ? '' : 'Pagamento registrato. ' . $msg];
    }
}
