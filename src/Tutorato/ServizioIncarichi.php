<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Anagrafi\ServizioPersone;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Sito;
use App\Sistema\FileEnv;

/**
 * Lettere di incarico dei vincitori dei bandi di tutorato. Iter (le email partono solo quando la lettera passa alla persona successiva):
 *   1. l'operatore invia la lettera allo studente → email con il link (incarico.php);
 *   2. lo studente entra con SPID o CIE, controlla i dati e conferma: nel PDF finiscono i dati dell'autenticazione
 *      (metodo, livello, IdP, spidCode, codice fiscale, data e ora, impronta dei dati) → email al docente;
 *   3. il docente firma digitalmente in PAdES (firma remota Aruba dal portale, oppure carica il PDF firmato) → email al direttore;
 *   4. il direttore firma in PAdES allo stesso modo → email all'operatore, che scarica il PDF con tutte le firme e lo protocolla.
 */
final class ServizioIncarichi
{
    public function __construct(
        private IncaricoRepository $incarichi,
        private BandoRepository $bandi,
        private DatiLettera $dati,
        private DocumentiIncarico $documenti,
        private ArchivioIncarichi $archivio,
        private NotificheIncarichi $notifiche,
        private VerificaPdfFirmato $verifica,
        private ServizioPersone $persone,
        private Sito $sito,
        private Orologio $orologio,
        private IndirizzoClient $client,
        private FileEnv $env
    ) {
    }

    // ==============================================================================
    // BANDI E LETTERE
    // ==============================================================================

    /** @return array<string, mixed>|null */
    public function bando(int $id): ?array
    {
        return $this->bandi->perId($id);
    }

    /** @return list<array<string, string|null>> */
    public function bandiElenco(): array
    {
        return $this->bandi->elenco();
    }

    /**
     * Lettera con i dati del bando (decreti, direttore, operatore).
     *
     * @return array<string, string|null>|null
     */
    public function incarico(int $id): ?array
    {
        return $this->incarichi->perId($id);
    }

    /**
     * Lettera dal link personale (studente, docente, direttore o fine attività).
     *
     * @return array<string, string|null>|null
     */
    public function incaricoPerToken(string $ruolo, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token) || !in_array($ruolo, Costanti::RUOLI_TOKEN, true)) {
            return null;
        }
        $id = $this->incarichi->idPerToken($ruolo, $token);

        return $id ? $this->incarichi->perId($id) : null;
    }

    /**
     * Storico della lettera, dal più vecchio.
     *
     * @return list<array<string, mixed>>
     */
    public function eventi(int $id): array
    {
        return $this->incarichi->eventi($id);
    }

    /**
     * Le lettere di un bando (annullate in fondo).
     *
     * @return list<array<string, mixed>>
     */
    public function delBando(int $bandoId): array
    {
        return $this->incarichi->delBando($bandoId);
    }

    /** Storico della lettera (chi, cosa, quando, da quale indirizzo). */
    public function evento(int $id, string $tipo, string $testo, string $autore = ''): void
    {
        $this->incarichi->aggiungiEvento($id, $tipo, $testo, $autore, mb_substr((string) ($this->client->ip() ?? ''), 0, 45));
    }

    /**
     * Bando: titolo, a.a., decreti, direttore (dall'anagrafe o a mano), operatore che riceve le lettere firmate. Ritorna [id, errore].
     *
     * @param array<string, mixed> $d
     * @return array{0: int, 1: string|null}
     */
    public function salvaBando(array $d, int $uid): array
    {
        $id = (int) ($d['id'] ?? 0);
        $f = [];
        foreach (['titolo' => 255, 'anno_accademico' => 20, 'decreto_bando' => 100, 'decreto_commissione' => 100, 'direttore_nome' => 200, 'direttore_email' => 150, 'direttore_cf' => 16, 'luogo' => 100] as $k => $max) {
            $f[$k] = mb_substr(trim((string) ($d[$k] ?? '')), 0, $max);
        }
        $f['direttore_cf'] = strtoupper($f['direttore_cf']);
        $dataB = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['decreto_bando_data'] ?? '')) ? $d['decreto_bando_data'] : null;
        $dataC = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['decreto_commissione_data'] ?? '')) ? $d['decreto_commissione_data'] : null;
        $dirPid = trim((string) ($d['direttore_persona_id'] ?? '')) ?: null;
        if ($dirPid && ($p = $this->persone->persona($dirPid))) {
            $f['direttore_nome'] = trim($p['nome'] . ' ' . $p['cognome']);
            if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                $f['direttore_email'] = strtolower($p['email']);
            }
        } elseif ($dirPid) {
            $dirPid = null;
        }
        if ($f['titolo'] === '') {
            return [0, 'Scrivi il titolo del bando.'];
        }
        if ($f['decreto_bando'] === '') {
            return [0, 'Indica il decreto del bando (D.D. n.).'];
        }
        if ($f['direttore_nome'] === '' || !filter_var($f['direttore_email'], FILTER_VALIDATE_EMAIL)) {
            return [0, "Scegli il direttore dall'anagrafe o scrivi nome ed email."];
        }
        if ($f['direttore_cf'] !== '' && !preg_match('/^[A-Z0-9]{16}$/', $f['direttore_cf'])) {
            return [0, 'Codice fiscale del direttore non valido.'];
        }
        $op = (int) ($d['operatore_id'] ?? 0) ?: null;
        if ($f['luogo'] === '') {
            $f['luogo'] = 'Rende';
        }
        $v = [$f['titolo'], $f['anno_accademico'], $f['decreto_bando'], $dataB, $f['decreto_commissione'], $dataC, $dirPid, $f['direttore_nome'], $f['direttore_email'], $f['direttore_cf'], $op, $f['luogo']];
        if ($id) {
            $this->bandi->aggiorna($id, $v);
        } else {
            $id = $this->bandi->inserisci([...$v, $uid]);
        }

        return [$id, null];
    }

    /**
     * Lettera di un vincitore (solo in bozza: dopo l'invio i dati non cambiano). Docente dall'anagrafe ($d['docente_persona_id'])
     * o scritto a mano (nome, cognome, email, codice fiscale). Ritorna [id, errore].
     *
     * @param array<string, mixed> $d
     * @return array{0: int, 1: string|null}
     */
    public function salva(array $d): array
    {
        $id = (int) ($d['id'] ?? 0);
        $vecchio = $id ? $this->incarichi->perId($id) : null;
        if ($id && (!$vecchio || $vecchio['stato'] !== 'bozza')) {
            return [$id, 'La lettera è già stata inviata: per cambiarla annullala e preparane una nuova.'];
        }
        $bando = (int) ($d['bando_id'] ?? ($vecchio['bando_id'] ?? 0));
        if (!$this->bandi->perId($bando)) {
            return [0, 'Bando non trovato.'];
        }
        $f = [];
        foreach (['cognome' => 100, 'nome' => 100, 'luogo_nascita' => 150, 'comune_residenza' => 150, 'indirizzo' => 255, 'civico' => 20, 'codice_fiscale' => 16, 'email' => 255, 'telefono' => 40,
                  'attivita' => 3000, 'periodo' => 255, 'docente_nome' => 100, 'docente_cognome' => 100, 'docente_email' => 150, 'docente_cf' => 16] as $k => $max) {
            $f[$k] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($d[$k] ?? ''))), 0, $max);
        }
        $f['attivita'] = mb_substr(trim((string) ($d['attivita'] ?? '')), 0, 3000);
        $f['codice_fiscale'] = strtoupper(str_replace(' ', '', $f['codice_fiscale']));
        $f['docente_cf'] = strtoupper(str_replace(' ', '', $f['docente_cf']));
        $f['email'] = strtolower($f['email']);
        $f['docente_email'] = strtolower($f['docente_email']);
        $genere = ($d['genere'] ?? 'M') === 'F' ? 'F' : 'M';
        $nascita = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['data_nascita'] ?? '')) ? $d['data_nascita'] : null;
        $dataL = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['data_lettera'] ?? '')) ? $d['data_lettera'] : null;
        $ore = DatiLettera::numeroItaliano($d['ore'] ?? '');
        $compenso = DatiLettera::numeroItaliano($d['compenso'] ?? '');
        $docPid = trim((string) ($d['docente_persona_id'] ?? '')) ?: null;
        if ($docPid && ($p = $this->persone->persona($docPid))) {
            $f['docente_nome'] = $p['nome'];
            $f['docente_cognome'] = $p['cognome'];
            if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                $f['docente_email'] = strtolower($p['email']);
            }
        } elseif ($docPid) {
            $docPid = null;
        }
        $err = [];
        if ($f['cognome'] === '' || $f['nome'] === '') {
            $err[] = 'cognome e nome del vincitore';
        }
        if (!preg_match('/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/', $f['codice_fiscale'])) {
            $err[] = 'codice fiscale del vincitore (16 caratteri)';
        }
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            $err[] = 'email del vincitore';
        }
        if ($f['attivita'] === '') {
            $err[] = 'attività da svolgere';
        }
        if ($ore === null || $ore <= 0) {
            $err[] = 'numero di ore';
        }
        if ($f['periodo'] === '') {
            $err[] = 'periodo';
        }
        if ($compenso === null || $compenso < 0) {
            $err[] = 'compenso';
        }
        if ($f['docente_cognome'] === '' || !filter_var($f['docente_email'], FILTER_VALIDATE_EMAIL)) {
            $err[] = "docente responsabile (dall'anagrafe o con nome, cognome ed email)";
        }
        if (!$docPid && $f['docente_cf'] !== '' && !preg_match('/^[A-Z0-9]{16}$/', $f['docente_cf'])) {
            $err[] = 'codice fiscale del docente';
        }
        if ($err) {
            return [$id, 'Controlla: ' . implode(', ', $err) . '.'];
        }
        $v = [$genere, $f['cognome'], $f['nome'], $f['luogo_nascita'], $nascita, $f['comune_residenza'], $f['indirizzo'], $f['civico'], $f['codice_fiscale'], $f['email'], $f['telefono'], $f['attivita'], $ore, $f['periodo'], $compenso,
            $docPid, $f['docente_nome'], $f['docente_cognome'], $f['docente_email'], $f['docente_cf'], $dataL];
        if ($id) {
            $this->incarichi->aggiornaBozza($id, $v);
        } else {
            $codice = 'TU-' . strtoupper(bin2hex(random_bytes(4)));
            $id = $this->incarichi->inserisci([$bando, $codice, ...$v]);
            $this->evento($id, 'creata', 'Lettera preparata');
        }

        return [$id, null];
    }

    /** Copia di una lettera (es. dopo un errore segnalato): nuova bozza con gli stessi dati. */
    public function duplica(int $id): int
    {
        $i = $this->incarichi->perId($id);
        if (!$i) {
            return 0;
        }
        [$nuovo] = $this->salva(array_merge($i, ['id' => 0]));

        return (int) $nuovo;
    }

    // ==============================================================================
    // ITER: STUDENTE (SPID/CIE) → DOCENTE (PAdES) → DIRETTORE (PAdES) → OPERATORE (protocollo)
    // ==============================================================================

    /** Passo 1: la lettera va allo studente (email con il link personale). Ritorna un errore o null. */
    public function invia(int $id, string $autore = ''): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i) {
            return 'Lettera non trovata.';
        }
        if (!in_array($i['stato'], ['bozza', 'inviata'], true)) {
            return 'La lettera è già stata confermata dallo studente.';
        }
        $tok = $i['token_studente'] ?: bin2hex(random_bytes(20));
        $dataL = $i['data_lettera'] ?: $this->orologio->adesso()->format('Y-m-d');
        $this->incarichi->segnaInviata($id, $tok, $dataL);
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $this->notifiche->invia(
            $i['email'],
            'Lettera di incarico di tutorato: controlla e conferma',
            '<p>Gentile ' . $h($i['nome'] . ' ' . $i['cognome']) . ',</p><p>è pronta la tua <strong>lettera di incarico</strong> per le attività di tutorato (' . $h($i['bando_titolo']) . ').</p>'
            . '<p>Accedi al portale <strong>con SPID o CIE</strong>, controlla i dati e clicca su <strong>Confermo e accetto</strong>: la conferma, con i dati della tua identità digitale, vale come accettazione dell\'incarico. Poi la lettera passa alla firma del docente responsabile e del Direttore.</p>'
            . '<p>Se trovi un errore nei dati segnalalo dalla stessa pagina.</p>',
            $this->sito->urlBase() . '/incarico.php?t=' . $tok,
            'Controlla e conferma la lettera'
        );
        $this->evento($id, 'inviata', ($i['stato'] === 'inviata' ? 'Email di nuovo allo studente: ' : 'Inviata allo studente: ') . $i['email'], $autore);

        return null;
    }

    /**
     * Lo studente può confermare solo se è proprio lui (codice fiscale dell'accesso = codice fiscale della lettera) e, se richiesto, con SPID o CIE.
     * Ritorna un errore o null.
     *
     * @param array<string, mixed> $i
     * @param array<string, mixed>|null $utente
     * @param array<string, mixed> $meta metodo e dati dell'autenticazione (sessione)
     */
    public function accessoValido(array $i, ?array $utente, array $meta): ?string
    {
        $cfUtente = strtoupper(trim((string) ($utente['codice_fiscale'] ?? '')));
        if ($cfUtente === '' || $cfUtente !== strtoupper($i['codice_fiscale'])) {
            return "La lettera è intestata a un'altra persona: accedi con l'identità digitale di " . $i['nome'] . ' ' . $i['cognome'] . '.';
        }
        if ($this->soloSpidCie() && !in_array($meta['metodo'] ?? '', ['spid', 'cie'], true)) {
            return "Per confermare serve l'accesso con SPID o CIE: esci e rientra scegliendo SPID o CIE.";
        }

        return null;
    }

    /** Per confermare servono SPID o CIE (INCARICHI_SOLO_SPID_CIE=0 nel .env per le prove). */
    public function soloSpidCie(): bool
    {
        $v = $this->env->valore('INCARICHI_SOLO_SPID_CIE');

        return $v === null || !in_array(strtolower($v), ['0', 'no', 'false'], true);
    }

    /**
     * Passo 2: lo studente conferma. Si genera il PDF definitivo con i dati dell'autenticazione e la lettera va al docente.
     *
     * @param array<string, mixed> $utente
     * @param array<string, mixed> $meta
     */
    public function conferma(int $id, array $utente, array $meta): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i || $i['stato'] !== 'inviata') {
            return 'La lettera non è in attesa della tua conferma.';
        }
        if ($err = $this->accessoValido($i, $utente, $meta)) {
            return $err;
        }
        $ora = $this->orologio->adesso();
        $firma = ['metodo' => $meta['metodo'] ?? 'ateneo', 'livello' => $meta['livello'] ?? null, 'idp' => (string) ($meta['idp'] ?? ''), 'contesto' => (string) ($meta['contesto'] ?? ''),
                  'spid_code' => (string) ($meta['spid_code'] ?? ''), 'cf' => strtoupper((string) ($utente['codice_fiscale'] ?? '')), 'istante' => (string) ($meta['istante'] ?? $ora->format('c')),
                  'sessione' => (string) ($meta['sessione'] ?? ''), 'ip' => (string) ($this->client->ip() ?? ($meta['ip'] ?? '')), 'confermata_il' => $ora->format('Y-m-d H:i:s'),
                  'impronta' => $this->dati->impronta($i), 'utente_id' => (int) ($utente['id'] ?? 0)];
        [$pdf] = $this->documenti->pdfLettera($i, $firma);
        $firma['sha256_pdf'] = hash('sha256', $pdf);
        if (!$this->archivio->salvaLettera($i, $pdf, 'confermata')) {
            return 'Non è stato possibile salvare la lettera: riprova.';
        }
        $tok = bin2hex(random_bytes(20));
        $this->incarichi->segnaConfermata($id, (string) json_encode($firma, JSON_UNESCAPED_UNICODE), $tok);
        $this->evento($id, 'confermata', 'Confermata dallo studente con ' . (Costanti::METODI_ACCESSO[$firma['metodo']] ?? $firma['metodo']) . ($firma['livello'] ? ' livello ' . $firma['livello'] : '') . ($firma['spid_code'] ? ' (spidCode ' . $firma['spid_code'] . ')' : ''), trim($i['nome'] . ' ' . $i['cognome']));
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $this->notifiche->invia(
            $i['docente_email'],
            'Lettera di incarico da firmare: ' . trim($i['cognome'] . ' ' . $i['nome']),
            '<p>Gentile Prof. ' . $h(trim($i['docente_nome'] . ' ' . $i['docente_cognome'])) . ',</p><p>' . $h(trim($i['nome'] . ' ' . $i['cognome'])) . " ha accettato l'incarico di tutorato (" . $h($i['bando_titolo']) . ") di cui è <strong>responsabile dell'attività</strong>.</p>"
            . '<p>Apra la lettera e la firmi digitalmente <strong>in formato PAdES</strong>: con la firma remota Aruba direttamente dal portale, oppure scaricandola e caricando il PDF firmato. Dopo la sua firma la lettera passa al Direttore.</p>',
            $this->sito->urlBase() . '/firma_incarico.php?t=' . $tok,
            'Apri e firma la lettera'
        );

        return null;
    }

    /** Lo studente segnala un errore nei dati: l'operatore riceve un'email (la lettera resta da confermare). */
    public function segnala(int $id, string $testo): ?string
    {
        $i = $this->incarichi->perId($id);
        $testo = mb_substr(trim($testo), 0, 2000);
        if (!$i || $i['stato'] !== 'inviata') {
            return 'La lettera non è in attesa della tua conferma.';
        }
        if ($testo === '') {
            return 'Scrivi cosa non va.';
        }
        $this->incarichi->impostaNotaStudente($id, $testo);
        $this->evento($id, 'errore', 'Segnalazione dello studente: ' . $testo, trim($i['nome'] . ' ' . $i['cognome']));
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        foreach ($this->notifiche->emailOperatori($i) as $e) {
            $this->notifiche->invia(
                $e,
                'Lettera di incarico: segnalazione di ' . trim($i['cognome'] . ' ' . $i['nome']),
                '<p>Lo studente segnala un errore nei dati della lettera <strong>' . $h($i['codice']) . "</strong>:</p><p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($testo)) . '</p><p>Annulla la lettera e preparane una corretta (puoi duplicarla), poi inviala di nuovo.</p>',
                $this->sito->urlBase() . '/admin/tutorato.php?incarico=' . (int) $i['id'],
                'Apri nel pannello'
            );
        }

        return null;
    }

    /**
     * Passo 3 o 4: firma PAdES del docente (stato «confermata») o del direttore (stato «firmata_docente»). $pdf = file firmato.
     */
    public function registraFirma(int $id, string $ruolo, string $pdf, string $come = 'caricamento'): ?string
    {
        $i = $this->incarichi->perId($id);
        $atteso = ['docente' => 'confermata', 'direttore' => 'firmata_docente'][$ruolo] ?? null;
        if (!$i || !$atteso || $i['stato'] !== $atteso) {
            return 'La lettera non è in attesa di questa firma.';
        }
        $cor = $this->archivio->pdfCorrente($i);
        if (!$cor) {
            return 'Manca il PDF della lettera.';
        }
        [$err, $info] = $this->verifica->verifica((string) file_get_contents($cor), $pdf, $ruolo === 'docente' ? (string) $i['docente_cf'] : (string) $i['direttore_cf']);
        if ($err) {
            return $err;
        }
        $come .= ($info['nome'] !== '' ? ', certificato di ' . $info['nome'] . ($info['cf'] !== '' ? ' (' . $info['cf'] . ')' : '') . ($info['emittente'] !== '' ? ' rilasciato da ' . $info['emittente'] : '') : '')
               . ($info['integra'] === true ? ', firma integra' : '');
        if (!$this->archivio->salvaLettera($i, $pdf, 'firmata_' . $ruolo)) {
            return 'Non è stato possibile salvare il PDF firmato.';
        }
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $chi = $ruolo === 'docente' ? 'Prof. ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome']) : 'Prof. ' . $i['direttore_nome'];
        if ($ruolo === 'docente') {
            $tok = bin2hex(random_bytes(20));
            $this->incarichi->segnaFirmataDocente($id, $tok);
            $this->evento($id, 'firma_docente', "Firmata in PAdES dal docente ($come)", $chi);
            $this->notifiche->invia(
                $i['direttore_email'],
                'Lettera di incarico di tutorato da firmare: ' . trim($i['cognome'] . ' ' . $i['nome']),
                '<p>Gentile Direttore,</p><p>la lettera di incarico di tutorato di <strong>' . $h(trim($i['nome'] . ' ' . $i['cognome'])) . '</strong> (' . $h($i['bando_titolo']) . ") è stata accettata dallo studente e firmata dal responsabile dell'attività, " . $h($chi) . '.</p>'
                . "<p>La firmi digitalmente <strong>in formato PAdES</strong> (firma remota Aruba dal portale o caricando il PDF firmato): poi la lettera va all'Ufficio per il protocollo.</p>",
                $this->sito->urlBase() . '/firma_incarico.php?t=' . $tok,
                'Apri e firma la lettera'
            );
        } else {
            $this->incarichi->segnaFirmataDirettore($id);
            $this->evento($id, 'firma_direttore', "Firmata in PAdES dal direttore ($come)", $chi);
            // L'incarico è perfezionato: il tutor può segnare le ore nel registro delle attività
            $this->notifiche->invia(
                $i['email'],
                'Tutorato: lettera di incarico firmata, registro delle attività aperto',
                '<p>Gentile ' . $h($i['nome'] . ' ' . $i['cognome']) . ',</p>'
                . '<p>la tua lettera di incarico è firmata dal docente responsabile e dal Direttore. Da ora segna nel <strong>registro delle attività</strong> i giorni, le ore e le attività svolte: il docente le approva e a fine incarico dichiari concluse le attività.</p>',
                $this->sito->urlBase() . '/registro_tutorato.php?id=' . $id,
                'Apri il registro'
            );
            foreach ($this->notifiche->emailOperatori($i) as $e) {
                $this->notifiche->invia(
                    $e,
                    'Lettera di incarico firmata: da protocollare – ' . trim($i['cognome'] . ' ' . $i['nome']),
                    '<p>La lettera di incarico <strong>' . $h($i['codice']) . '</strong> di ' . $h(trim($i['nome'] . ' ' . $i['cognome'])) . ' (' . $h($i['bando_titolo']) . ") ha tutte le firme: accettazione dello studente con identità digitale, firma PAdES del responsabile dell'attività e del Direttore.</p>"
                    . '<p>Scarica il PDF firmato e protocollalo, poi registra il numero di protocollo nel pannello.</p>',
                    $this->sito->urlBase() . '/admin/tutorato.php?incarico=' . $id,
                    'Scarica e protocolla'
                );
            }
        }

        return null;
    }

    /** Ultimo passo: l'operatore registra il protocollo. $inviaCopia = la lettera protocollata va anche allo studente. */
    public function protocolla(int $id, string $prot, ?string $data, bool $inviaCopia, string $autore = ''): ?string
    {
        $i = $this->incarichi->perId($id);
        $prot = mb_substr(trim($prot), 0, 100);
        if (!$i || !in_array($i['stato'], ['firmata', 'protocollata'], true)) {
            return 'La lettera non ha ancora tutte le firme.';
        }
        if ($prot === '') {
            return 'Scrivi il numero di protocollo.';
        }
        $data = $data && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : $this->orologio->adesso()->format('Y-m-d');
        $this->incarichi->segnaProtocollata($id, $prot, $data);
        $this->evento($id, 'protocollata', 'Protocollo ' . $prot . ' del ' . date('d/m/Y', (int) strtotime($data)), $autore);
        if ($inviaCopia && ($f = $this->archivio->pdfCorrente($i))) {
            $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
            $this->notifiche->invia(
                $i['email'],
                'Lettera di incarico di tutorato firmata (prot. ' . $prot . ')',
                '<p>Gentile ' . $h($i['nome'] . ' ' . $i['cognome']) . ',</p><p>in allegato la tua lettera di incarico con tutte le firme, protocollata con il n. ' . $h($prot) . ' del ' . date('d/m/Y', (int) strtotime($data)) . '.</p>',
                '',
                '',
                [['path' => $f, 'nome' => DatiLettera::nomeFile($i, 'firmata')]]
            );
            $this->evento($id, 'copia', 'Copia della lettera firmata inviata allo studente', $autore);
        }

        return null;
    }

    public function annulla(int $id, string $motivo, string $autore = ''): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i || in_array($i['stato'], ['protocollata', 'annullata'], true)) {
            return 'La lettera non si può annullare.';
        }
        $this->incarichi->annulla($id);
        $this->evento($id, 'annullata', 'Annullata' . (trim($motivo) !== '' ? ': ' . mb_substr(trim($motivo), 0, 500) : ''), $autore);

        return null;
    }

    /**
     * Chi deve firmare con il link: il docente o il direttore riconosciuti dall'accesso (email, codice fiscale o scheda dell'anagrafe).
     *
     * @param array<string, mixed> $i
     * @param array<string, mixed>|null $u
     */
    public function firmatario(array $i, string $ruolo, ?array $u): bool
    {
        if (!$u || empty($u['id'])) {
            return false;
        }
        $email = strtolower(trim((string) ($u['email'] ?? '')));
        $cf = strtoupper(trim((string) ($u['codice_fiscale'] ?? '')));
        $pid = (string) ($u['persona_id'] ?? '');
        [$e, $c, $p] = $ruolo === 'docente' ? [$i['docente_email'], $i['docente_cf'], $i['docente_persona_id']] : [$i['direttore_email'], $i['direttore_cf'], $i['direttore_persona_id']];

        return ($email !== '' && $email === strtolower((string) $e)) || ($cf !== '' && $cf === strtoupper((string) $c)) || ($pid !== '' && $pid === (string) $p);
    }
}
