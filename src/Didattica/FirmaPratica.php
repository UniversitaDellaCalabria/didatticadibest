<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Sito;
use App\Fsl\ServizioFirmaDirettore;
use App\Portale\SospensionePromemoria;
use App\Uffici\Direzione;
use App\Tutorato\FirmaRemota;
use App\Tutorato\VerificaPdfFirmato;

/**
 * Le firme dell'iter di una pratica: un passo «firma di una persona indicata nel modulo» (es. il relatore della tesi) o «firma del Direttore».
 * La persona riceve un link personale per email, entra con le credenziali di Ateneo e firma in PAdES con la firma remota Aruba (se configurata)
 * oppure scarica il PDF, lo firma e lo ricarica; può anche rifiutare con un motivo (la pratica torna allo studente come «integrazione»).
 * Il PDF da firmare è la domanda dello studente (relatore) oppure l'ultimo documento caricato dall'ufficio (Direttore, es. attestati e certificati).
 */
final class FirmaPratica
{
    private const RIQUADRO = ['x' => 60, 'y' => 90, 'l' => 230, 'a' => 60];
    private const MAX_SOLLECITI = 3;

    public function __construct(
        private FirmaPraticaRepository $firme,
        private PraticaRepository $pratiche,
        private ModuloRepository $moduli,
        private ServizioIter $iter,
        private StoricoPratica $storico,
        private NotifichePratiche $notifiche,
        private ServizioUffici $ufficio,
        private Direzione $direzione,
        private SospensionePromemoria $sospensione,
        private FirmaRemota $firmaRemota,
        private VerificaPdfFirmato $verifica,
        private Sito $sito,
        private AllegatiPratiche $allegati
    ) {
    }

    public function firmaRemotaDisponibile(): bool
    {
        return $this->firmaRemota->disponibile();
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $this->firme->perToken($token);
    }

    /** @return array<string, mixed>|null */
    public function inAttesa(int $praticaId): ?array
    {
        return $this->firme->inAttesa($praticaId);
    }

    /** @return list<array<string, mixed>> */
    public function storico(int $praticaId): array
    {
        return $this->firme->dellaPratica($praticaId);
    }

    /** Percorso reale di un PDF della pratica (solo dentro la cartella degli allegati). */
    private function percorso(?string $rel): ?string
    {
        if (!$rel) {
            return null;
        }
        $base = realpath($this->sito->radice() . '/' . $this->allegati->cartella());
        $p = realpath($this->sito->radice() . '/' . $rel);

        return $base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p) ? $p : null;
    }

    /** Il PDF da firmare: la domanda dello studente (persona) oppure l'ultimo documento dell'ufficio (Direttore); l'altro se manca il primo. */
    private function documento(array $p, string $tipo): ?string
    {
        $domanda = $this->percorso((string) ($p['domanda_pdf'] ?? '')) ? (string) $p['domanda_pdf'] : null;
        $uff = ($e = $this->firme->ultimoPdfUfficio((int) $p['id'])) && $this->percorso((string) $e['allegato']) ? (string) $e['allegato'] : null;

        return $tipo === 'direttore' ? ($uff ?? $domanda) : ($domanda ?? $uff);
    }

    /**
     * Manda la pratica alla firma del passo indicato (1 = primo passo dell'iter). Ritorna il messaggio di errore, null se la richiesta è partita.
     */
    public function avvia(int $id, int $passo): ?string
    {
        $p = $this->pratiche->perId($id);
        $m = $p ? $this->moduli->perId((int) $p['modulo_id']) : null;
        $def = $m ? ($this->iter->definizione($m)[$passo - 1] ?? null) : null;
        if (!$p || !$def || !in_array($def['t'], ['persona', 'direttore'], true)) {
            return 'Questo passo dell\'iter non è una firma.';
        }
        if ($def['t'] === 'direttore') {
            $d = $this->direzione->chiFirma();
            [$nome, $email, $ruolo] = [$d['nome'], $d['email'], 'Direttore'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 'Indica prima il Direttore (nome ed email) in Gestione del portale → Sistema e registri → «Direttore del Dipartimento»: si indica una volta sola e vale per tutte le firme.';
            }
        } else {
            $ruolo = $def['campo'];
            $nome = '';
            foreach (json_decode((string) $p['risposte_json'], true) ?: [] as $r) {
                if (mb_strtolower(trim((string) ($r['etichetta'] ?? ''))) === mb_strtolower($def['campo'])) {
                    $nome = trim((string) ($r['valore'] ?? ''));
                    break;
                }
            }
            if ($nome === '') {
                return 'Lo studente non ha indicato «' . $def['campo'] . '».';
            }
            $doc = $this->firme->docentePerNome($nome);
            $email = (string) ($doc['email'] ?? '');
            if (!$doc || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 'Non trovo l\'email di «' . $nome . '» nell\'anagrafe del personale (il nome deve coincidere con uno e un solo docente): scrivigli tu e carica la firma dalla scheda.';
            }
            $nome = trim($doc['cognome'] . ' ' . $doc['nome']);
        }
        $file = $this->documento($p, $def['t']);
        if ($file === null) {
            return 'Manca il PDF da firmare: ' . ($def['t'] === 'direttore' ? 'carica il documento (certificato o attestato) nella pratica come allegato dell\'ufficio.' : 'attiva la «domanda in PDF/A» nel modulo o carica un documento.');
        }
        $this->firme->annullaAttese($id);
        $token = bin2hex(random_bytes(20));
        $this->firme->crea($id, $passo, $def['t'], $nome, $email, $token, $file);
        $this->pratiche->impostaPasso($id, $passo);
        $this->storico->evento($id, 'passaggio', 'ufficio', 0, null, 'In attesa di firma: ' . $nome . ' (' . $ruolo . ')');
        $this->emailFirma($p, $nome, $email, $token, $ruolo, false);
        if ($p2 = $this->pratiche->perId($id)) {
            $this->notifiche->email($p2, 'passaggio', 'Firma: ' . $ruolo);
        }

        return null;
    }

    /** Rimanda l'email con il link a chi deve firmare (la richiesta in attesa). Ritorna l'errore, null se inviata. */
    public function reinvia(int $praticaId): ?string
    {
        $f = $this->firme->inAttesa($praticaId);
        $p = $this->pratiche->perId($praticaId);
        if (!$f || !$p) {
            return 'Non c\'è nessuna firma in attesa.';
        }
        $this->emailFirma($p, (string) $f['nome'], (string) $f['email'], (string) $f['token'], $f['tipo'] === 'direttore' ? 'Direttore' : 'firmatario', true);

        return null;
    }

    private function emailFirma(array $p, string $nome, string $email, string $token, string $ruolo, bool $sollecito): void
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $this->notifiche->invia(
            $email,
            ($sollecito ? 'Promemoria · ' : '') . 'Firma richiesta: ' . $p['modulo_titolo'] . ' – ' . trim($p['cognome'] . ' ' . $p['nome']),
            '<p>Gentile ' . $h($nome) . ',</p><p>' . ($sollecito ? 'ti ricordiamo che è ancora in attesa' : 'è in attesa') . ' la tua firma digitale sulla pratica <strong>' . $h($p['codice']) . '</strong> (' . $h($p['modulo_titolo']) . ') di '
            . $h(trim($p['nome'] . ' ' . $p['cognome'])) . ' (' . $h($ruolo) . '). Accedi con le tue credenziali di Ateneo, controlla il documento e firma (o rifiuta indicando il motivo).</p>'
            . $this->notifiche->bottone($this->sito->urlBase() . '/firma_pratica.php?t=' . $token, 'Apri e firma')
        );
    }

    /**
     * La persona collegata è chi deve firmare (stessa email; per il Direttore anche lo stesso codice fiscale).
     *
     * @param array<string, mixed> $f
     * @param array<string, mixed>|null $utente
     */
    public function puoFirmare(array $f, ?array $utente): bool
    {
        if (!$utente || empty($utente['id'])) {
            return false;
        }
        $email = strtolower(trim((string) ($utente['email'] ?? '')));
        if ($email !== '' && $email === strtolower((string) $f['email'])) {
            return true;
        }
        if ($f['tipo'] === 'direttore') {
            $cf = strtoupper(trim((string) ($utente['codice_fiscale'] ?? '')));
            $d = $this->direzione->chiFirma();

            return $cf !== '' && $d['cf'] !== '' && $cf === strtoupper($d['cf']);
        }

        return false;
    }

    /** @param array<string, mixed> $f */
    public function pdfDaFirmare(array $f): ?string
    {
        return $this->percorso((string) ($f['file_origine'] ?? ''));
    }

    /**
     * Firma con la firma remota Aruba (utente, password e OTP non si salvano). Ritorna il messaggio di errore, null se la firma è registrata.
     *
     * @param array<string, mixed> $f
     */
    public function firmaConAruba(array $f, string $utente, string $password, string $otp): ?string
    {
        if ($f['stato'] !== 'attesa' || ($p = $this->pdfDaFirmare($f)) === null) {
            return 'Il documento non è più in attesa della tua firma.';
        }
        $pdf = (string) file_get_contents($p);
        $n = ServizioFirmaDirettore::contaPagine($pdf);
        [$firmato, $err] = $this->firmaRemota->firma($pdf, $utente, $password, $otp, $n > 0 ? ['pagina' => $n] + self::RIQUADRO : null, $f['tipo'] === 'direttore' ? 'Il Direttore del Dipartimento' : 'Il firmatario');
        if ($firmato === null) {
            return $err ?: 'Firma non riuscita.';
        }

        return $this->registra($f, $firmato, 'firma remota Aruba');
    }

    /** @param array<string, mixed> $f */
    public function caricaFirmato(array $f, string $pdfFirmato): ?string
    {
        if (strlen($pdfFirmato) > 20 * 1024 * 1024) {
            return 'Il file è troppo grande.';
        }

        return $this->registra($f, $pdfFirmato, 'PDF firmato caricato');
    }

    /**
     * Controlla il PDF firmato (il documento con una firma in più, senza altre modifiche), lo salva nella pratica e avvisa chi la ha in carico.
     *
     * @param array<string, mixed> $f
     */
    private function registra(array $f, string $firmato, string $come): ?string
    {
        if ($f['stato'] !== 'attesa' || ($p = $this->pdfDaFirmare($f)) === null) {
            return 'Il documento non è più in attesa della tua firma.';
        }
        $cf = $f['tipo'] === 'direttore' ? $this->direzione->chiFirma()['cf'] : '';
        [$err, $info] = $this->verifica->verifica((string) file_get_contents($p), $firmato, $cf);
        if ($err) {
            return $err;
        }
        $this->allegati->proteggi();
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        if (file_put_contents($this->sito->radice() . '/' . $this->allegati->cartella() . $nome, $firmato) === false) {
            return 'Non riesco a salvare il file: riprova tra poco.';
        }
        $pr = $this->pratiche->perId((int) $f['pratica_id']);
        $chi = ($info['nome'] ?? '') !== '' ? $info['nome'] : (string) $f['nome'];
        $this->firme->segnaFirmata((int) $f['id'], $this->allegati->cartella() . $nome, $chi . ' (' . $come . ')');
        // Se dopo la firma c'è ancora il protocollo in uscita, il documento firmato non è per lo studente: lo vede quando l'ufficio carica quello protocollato
        $m = $pr ? $this->moduli->perId((int) $pr['modulo_id']) : null;
        $ancoraUscita = $m && $this->iter->haPassoDopo('uscita', $m, (int) $f['passo']);
        $this->storico->evento((int) $f['pratica_id'], 'attivita', 'ufficio', 0, null, 'Documento firmato da ' . $chi . ' (' . $come . ').', $this->allegati->cartella() . $nome,
            'Firmato_' . ($f['tipo'] === 'direttore' ? 'direttore' : 'firmatario') . '_' . ($pr['codice'] ?? $f['pratica_id']) . '.pdf', $ancoraUscita);

        return null;
    }

    /**
     * Chi deve firmare rifiuta: la pratica torna allo studente («integrazione») con il motivo, e chi la aveva in carico è avvisato.
     *
     * @param array<string, mixed> $f
     */
    public function rifiuta(array $f, string $motivo): ?string
    {
        $motivo = mb_substr(trim($motivo), 0, 500);
        if ($f['stato'] !== 'attesa') {
            return 'La firma non è più in attesa.';
        }
        if ($motivo === '') {
            return 'Scrivi il motivo del rifiuto: lo studente lo riceve.';
        }
        $p = $this->pratiche->perId((int) $f['pratica_id']);
        if (!$p) {
            return 'Pratica non trovata.';
        }
        $this->firme->segnaRifiutata((int) $f['id'], $motivo);
        $testo = 'Firma rifiutata da ' . $f['nome'] . ': ' . $motivo;
        $this->pratiche->impostaStato((int) $p['id'], 'integrazione', (string) json_encode(['tipo' => 'documenti', 'testo' => $motivo, 'da' => (string) $f['nome'], 'il' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
        $this->storico->evento((int) $p['id'], 'stato', 'ufficio', 0, 'integrazione', $testo);
        $p['stato'] = 'integrazione';
        $this->notifiche->email($p, 'stato', $testo);
        $this->avvisaOperatori($p, 'Firma rifiutata sulla pratica ' . $p['codice'], '<p>' . htmlspecialchars((string) $f['nome']) . ' ha rifiutato di firmare la pratica <strong>' . htmlspecialchars((string) $p['codice']) . '</strong>: ' . nl2br(htmlspecialchars($motivo)) . '</p><p>La pratica è tornata allo studente come «integrazione richiesta».</p>');

        return null;
    }

    /** Avvisa chi ha in carico la pratica (e chi l'ha avuta prima). */
    public function avvisaOperatori(array $p, string $oggetto, string $corpo): void
    {
        $ids = array_map('intval', $this->pratiche->operatori((int) $p['id']));
        if (!empty($p['assegnata_a'])) {
            $ids[] = (int) $p['assegnata_a'];
        }
        $inviati = [];
        foreach (array_unique($ids) as $oid) {
            $o = $this->ufficio->operatore($oid);
            if ($o && filter_var($o['email'], FILTER_VALIDATE_EMAIL) && !in_array($o['email'], $inviati, true)) {
                $inviati[] = $o['email'];
                $this->notifiche->invia($o['email'], $oggetto, $corpo . $this->notifiche->bottone($this->notifiche->linkPannello((int) $p['id']), 'Apri la pratica'));
            }
        }
    }

    /** Promemoria a chi deve ancora firmare: dopo i giorni del passo (o del modulo), al massimo tre volte. Ritorna le email inviate. */
    public function solleciti(): int
    {
        $n = 0;
        $sospesi = $this->sospensione->attiva();
        foreach ($this->firme->attese() as $f) {
            if ($sospesi && $f['tipo'] === 'direttore') {
                continue; // sospeso: il promemoria al Direttore non si segna e riparte alla riattivazione
            }
            $gg = $this->iter->giorniPasso($this->moduli->perId((int) $f['modulo_id']), (int) $f['passo']);
            $p = $this->pratiche->perId((int) $f['pratica_id']);
            if ($gg > 0 && $p && (int) $f['giorni_ultimo'] >= $gg && (int) $f['solleciti'] < self::MAX_SOLLECITI) {
                $this->emailFirma($p, (string) $f['nome'], (string) $f['email'], (string) $f['token'], $f['tipo'] === 'direttore' ? 'Direttore' : 'firmatario', true);
                $this->firme->segnaSollecito((int) $f['id']);
                $n++;
            }
        }

        return $n;
    }
}
