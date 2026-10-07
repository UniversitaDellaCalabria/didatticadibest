<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Auth\LimiteRichieste;
use App\Core\Orologio;
use App\Core\Sito;
use App\Eventi\EventoRepository;
use App\Eventi\Turni;
use App\Infrastructure\Mail\Mailer;
use App\Iscrizioni\CaptchaPrenotazione;
use App\Iscrizioni\EsitoPrenotazione;
use App\Iscrizioni\LimitiPartecipanti;
use App\Iscrizioni\PrenotazioneRepository;
use App\Iscrizioni\RegoleFsl;
use App\Iscrizioni\RichiestaPrenotazione;
use App\Iscrizioni\ServizioPrenotazioni;

/**
 * Il programma FSL della scuola: sceglie più attività di Formazione Scuola Lavoro, le prenota insieme e riceve in un solo passaggio
 * l'Allegato A (PDF, con la scheda di ogni attività) e la Convenzione (Word precompilato) da firmare in PAdES e inviare via PEC.
 *
 * Il programma non riserva posti: i posti si assegnano alla conferma, attività per attività (quelle piene vanno in lista d'attesa, se
 * l'attività la prevede, altrimenti restano nel programma con l'avviso). Ogni prenotazione passa dallo stesso servizio delle prenotazioni
 * singole, quindi vale ogni controllo (turno aperto, ruolo, limiti di studenti, convenzione, una sola edizione per progetto…).
 */
final class ServizioProgrammaFsl
{
    private const MESSAGGI = [
        'full' => 'I posti sono esauriti.',
        'closed' => 'Le iscrizioni sono chiuse.',
        'notopened' => 'Le iscrizioni non sono ancora aperte.',
        'dup' => 'Hai già una prenotazione per questa attività con la stessa email.',
        'limite' => 'Hai già raggiunto il limite di iscrizioni previsto per questa area.',
        'altra_edizione' => 'Sei già iscritto a un\'altra edizione di questo progetto.',
        'riservato' => 'L\'attività è riservata: serve l\'accesso con SPID, CIE o credenziali Unical.',
        'email' => 'Indirizzo email non valido.',
        'studenti' => 'Dati dell\'attività non validi.',
        'captcha' => 'Controllo anti-robot non superato.',
    ];

    public function __construct(
        private ProgrammaFsl $programma,
        private ProgrammaFslRepository $repo,
        private PrenotazioneRepository $prenotazioni,
        private PrenotazioneFslRepository $prenotazioniFsl,
        private EventoRepository $eventi,
        private ServizioPrenotazioni $prenotazione,
        private ServizioConvenzioneOnline $convenzioni,
        private ServizioScuole $scuole,
        private RegoleFsl $fsl,
        private CaptchaPrenotazione $captcha,
        private LimiteRichieste $limite,
        private Mailer $mailer,
        private Sito $sito,
        private Orologio $orologio,
    ) {
    }

    public function conta(): int
    {
        return $this->programma->conta();
    }

    public function contiene(int $eventoId): bool
    {
        return $this->programma->contiene($eventoId);
    }

    public function togli(int $eventoId): void
    {
        $this->programma->togli($eventoId);
    }

    public function svuota(): void
    {
        $this->programma->svuota();
    }

    /**
     * Aggiunge al programma l'edizione scelta (o sostituisce quella già scelta della stessa attività) con le risposte del modulo.
     * Controlla che l'attività sia FSL, prenotabile ora, consentita a chi sta navigando e, per le attività di classe, il numero di studenti.
     *
     * @param array<string, mixed> $post campi del modulo dell'attività (custom_*)
     * @param list<string> $ruoliSecondari
     * @return array{ok: bool, errore: string|null, titolo: string, sostituita: bool, evento_id: int}
     */
    public function aggiungi(int $turnoId, array $post, bool $connesso, int $ruolo, array $ruoliSecondari): array
    {
        $no = static fn (string $errore, string $titolo = '', int $eventoId = 0): array => ['ok' => false, 'errore' => $errore, 'titolo' => $titolo, 'sostituita' => false, 'evento_id' => $eventoId];
        $t = $this->repo->turno($turnoId);
        if ($t === null || (int) $t['archiviato'] === 1) {
            return $no('L\'attività non è più disponibile.');
        }
        $eventoId = (int) $t['evento_id'];
        $titolo = (string) $t['evento_titolo'];
        if ((int) $t['fsl'] !== 1) {
            return $no('Questa attività non fa parte della Formazione Scuola Lavoro: si prenota direttamente dalla sua scheda.', $titolo, $eventoId);
        }
        if ((int) ($t['richiede_prenotazione'] ?? 1) === 0) {
            return $no('Per questa attività non serve la prenotazione.', $titolo, $eventoId);
        }
        $dett = $this->eventi->dettagliProgetti([$eventoId])[$eventoId] ?? null;
        if ($this->conclusa($t, $dett)) {
            return $no('L\'attività è già conclusa.', $titolo, $eventoId);
        }
        $adesso = $this->orologio->adesso()->format('Y-m-d H:i:s');
        if (!empty($t['data_apertura']) && $adesso < $t['data_apertura']) {
            return $no('Le iscrizioni si aprono il ' . date('d/m/Y \a\l\l\e H:i', (int) strtotime((string) $t['data_apertura'])) . '.', $titolo, $eventoId);
        }
        if (!empty($t['data_chiusura']) && $adesso > $t['data_chiusura']) {
            return $no('Le iscrizioni sono chiuse.', $titolo, $eventoId);
        }
        $ruoloEv = (int) ($t['ruolo_accesso_id'] ?? 0);
        if ($ruoloEv !== 0 && !($connesso && ($ruoloEv === -1 || $ruolo === $ruoloEv || in_array((string) $ruoloEv, $ruoliSecondari, true) || $ruolo === 1 || in_array('1', $ruoliSecondari, true)))) {
            return $no('L\'attività è riservata: accedi con SPID, CIE o credenziali Unical.', $titolo, $eventoId);
        }

        $custom = $this->campiDelModulo($post);
        // La scuola si indica una volta sola, alla conferma del programma
        if (($campoScuola = $this->repo->campoScuola((int) $t['pagina_id'], $eventoId)) !== null) {
            unset($custom[$campoScuola]);
        }
        if ($this->fsl->prenotazioneDiClasse(($t['evento_tipo'] ?? '') === 'progetto', $dett)) {
            $err = LimitiPartecipanti::valida($custom, ($dett ?? []) + ['per_scuole' => 1], $t);
            if ($err !== null) {
                return $no($err, $titolo, $eventoId);
            }
        }
        $sostituita = $this->programma->contiene($eventoId);
        if (!$this->programma->aggiungi($eventoId, $turnoId, $custom)) {
            return $no('Il programma può contenere al massimo ' . ProgrammaFsl::MASSIMO . ' attività.', $titolo, $eventoId);
        }

        return ['ok' => true, 'errore' => null, 'titolo' => $titolo, 'sostituita' => $sostituita, 'evento_id' => $eventoId];
    }

    /**
     * Le attività del programma con il loro stato di oggi: prenotabile, lista d'attesa, posti esauriti, chiusa, conclusa o non più disponibile.
     *
     * @return list<array<string, mixed>>
     */
    public function elenco(): array
    {
        $elenco = [];
        $adesso = $this->orologio->adesso()->format('Y-m-d H:i:s');
        foreach ($this->programma->voci() as $eventoId => $voce) {
            $t = $this->repo->turno($voce['turno_id']);
            if ($t === null || (int) $t['archiviato'] === 1 || (int) $t['evento_id'] !== $eventoId) {
                $elenco[] = ['evento_id' => $eventoId, 'turno_id' => $voce['turno_id'], 'titolo' => 'Attività non più disponibile', 'turno' => '', 'periodo' => '', 'sede' => '',
                    'dal' => '', 'al' => '', 'stato' => 'assente', 'nota' => 'L\'attività è stata rimossa: toglila dal programma.', 'studenti' => 0, 'docente' => '', 'slug' => '', 'tipo' => '', 'posti' => ''];
                continue;
            }
            $dett = $this->eventi->dettagliProgetti([$eventoId])[$eventoId] ?? null;
            [$dal, $al] = $this->fsl->periodoAttivita($dett['data_inizio'] ?? null, $dett['data_fine'] ?? null, $t['data_turno'] ?? null);
            $occupati = $this->prenotazioni->postiOccupati((int) $t['id']);
            $liberi = max(0, (int) $t['max_posti'] - $occupati);
            $stato = 'ok';
            $nota = '';
            if ($this->conclusa($t, $dett)) {
                [$stato, $nota] = ['conclusa', 'L\'attività è già conclusa.'];
            } elseif (!empty($t['data_chiusura']) && $adesso > $t['data_chiusura']) {
                [$stato, $nota] = ['chiusa', 'Le iscrizioni sono chiuse.'];
            } elseif (!empty($t['data_apertura']) && $adesso < $t['data_apertura']) {
                [$stato, $nota] = ['chiusa', 'Le iscrizioni si aprono il ' . date('d/m/Y \a\l\l\e H:i', (int) strtotime((string) $t['data_apertura'])) . '.'];
            } elseif ($liberi <= 0) {
                if (!empty($t['abilita_lista_attesa'])) {
                    [$stato, $nota] = ['attesa', 'Posti esauriti: la richiesta entrerà in lista d\'attesa, in ordine di arrivo.'];
                } else {
                    [$stato, $nota] = ['piena', 'Posti esauriti: questa attività non si può più prenotare.'];
                }
            }
            $custom = $voce['custom'];
            $elenco[] = [
                'evento_id' => $eventoId, 'turno_id' => (int) $t['id'], 'titolo' => (string) $t['evento_titolo'],
                'turno' => ($t['evento_tipo'] ?? '') === 'progetto' ? trim((string) ($t['nome_turno'] ?? '')) : Turni::etichetta($t),
                'periodo' => $dal === $al ? date('d/m/Y', (int) strtotime($dal)) : 'dal ' . date('d/m/Y', (int) strtotime($dal)) . ' al ' . date('d/m/Y', (int) strtotime($al)),
                'dal' => $dal, 'al' => $al, 'sede' => (string) ($t['evento_luogo'] ?? ''), 'stato' => $stato, 'nota' => $nota,
                'studenti' => (int) ($custom[CAMPO_PARTECIPANTI] ?? 0), 'docente' => (string) ($custom['docente_riferimento'] ?? ''),
                'slug' => (string) $t['slug'], 'tipo' => (string) ($t['evento_tipo'] ?? ''),
                'posti' => $liberi,
            ];
        }

        return $elenco;
    }

    /** Il turno è passato; senza una data del turno vale la fine del progetto. */
    private function conclusa(array $t, ?array $dett): bool
    {
        if (Turni::concluso($t)) {
            return true;
        }

        return empty($t['data_turno']) && !empty($dett['data_fine']) && $dett['data_fine'] < $this->orologio->adesso()->format('Y-m-d');
    }

    /**
     * Prenota tutte le attività del programma con i dati della scuola e crea Allegato A e Convenzione precompilati.
     * I controlli sui dati si fanno prima di prenotare: se qualcosa non va non si prenota nulla. Poi ogni attività è indipendente: quelle
     * che non si possono prenotare restano nel programma con il motivo, le altre ne escono.
     */
    public function conferma(RichiestaProgramma $r): EsitoProgramma
    {
        $voci = $this->programma->voci();
        if (!$voci) {
            return new EsitoProgramma(['Il programma è vuoto: aggiungi almeno un\'attività.']);
        }
        $post = $r->post;
        $errori = [];

        // Chi prenota
        $nome = trim($r->nome);
        $cognome = trim($r->cognome);
        $email = strtolower(trim($r->email));
        if ($nome === '' || $cognome === '' || $email === '') {
            $errori[] = 'Indica nome, cognome ed email di chi prenota.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errori[] = 'Indirizzo email non valido.';
        } elseif (!$r->connesso() && strtolower(trim((string) ($post['email_conferma'] ?? ''))) !== $email) {
            $errori[] = 'Le due email non coincidono: riscrivile con attenzione.';
        }
        if (empty($post['accetta_privacy'])) {
            $errori[] = 'Conferma di aver letto l\'informativa sul trattamento dei dati personali.';
        }

        // Scuola e convenzione
        $codiceScuola = strtoupper(trim((string) (((array) ($post['scuola_codice'] ?? []))['scuola'] ?? '')));
        $anagrafe = $codiceScuola !== '' ? $this->scuole->perCodice($codiceScuola) : null;
        $nomeScuola = trim((string) ($post['custom_scuola'] ?? ''));
        if ($anagrafe) {
            $nomeScuola = Testi::etichettaScuola($anagrafe);
        } else {
            $codiceScuola = '';
        }
        if ($nomeScuola === '') {
            $errori[] = 'Indica la scuola.';
        }
        $risposta = in_array($post['convenzione'] ?? '', ['si', 'no'], true) ? (string) $post['convenzione'] : '';
        if ($risposta === '') {
            $errori[] = 'Indica se la scuola ha già stipulato la convenzione con il Dipartimento.';
        }
        $datiScuola = $this->datiScuola($post, $anagrafe, $nomeScuola);
        $v = $this->convenzioni->validaScuola($datiScuola, $r->fileLogo, null);
        $errori = array_merge($errori, $v['errori']);
        if ($r->logoObbligatorio && !$v['logo'] && ($r->fileLogo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errori[] = "Carica il logo della scuola: serve per l'Allegato A.";
        }

        // Anti-robot e limite per indirizzo IP: una volta per tutto il programma
        if (!$errori && !$r->connesso()) {
            if (trim((string) ($post['sito_web'] ?? '')) !== '') {
                $errori[] = 'Prenotazione non registrata: riprova.';
            } elseif (!$this->limite->consenti($r->ip, 'prenotazione_pubblica', 20, 3600)) {
                $errori[] = 'Troppe prenotazioni da questa connessione: riprova tra un\'ora o accedi con SPID/CIE.';
            } elseif (($errCaptcha = $this->captcha->verifica((string) ($post['captcha_id'] ?? ''), (string) ($post['captcha_risposta'] ?? ''))) !== null) {
                $errori[] = $errCaptcha;
            }
        }
        if ($errori) {
            $this->togliLogo($v['logo']);

            return new EsitoProgramma(array_values(array_unique($errori)));
        }

        // Prenotazioni, una per attività
        $docenti = (array) ($post['docente'] ?? []);
        $esiti = [];
        $riuscite = [];
        $rinnovo = false;
        $serveConvenzione = false;
        foreach ($voci as $eventoId => $voce) {
            $t = $this->repo->turno($voce['turno_id']);
            $titolo = $t ? (string) $t['evento_titolo'] : 'Attività non più disponibile';
            $etichetta = $t ? (($t['evento_tipo'] ?? '') === 'progetto' ? trim((string) ($t['nome_turno'] ?? '')) : Turni::etichetta($t)) : '';
            $area = $t ? $this->repo->area((int) $t['pagina_id']) : null;
            if ($t === null || $area === null || (int) $t['archiviato'] === 1) {
                $esiti[] = $this->voceEsito($eventoId, $titolo, $etichetta, 'errore', null, 'L\'attività non è più disponibile.');
                continue;
            }
            $docente = trim(mb_substr((string) ($docenti[$eventoId] ?? ''), 0, 150)) ?: trim((string) ($voce['custom']['docente_riferimento'] ?? '')) ?: trim($nome . ' ' . $cognome);
            $esito = $this->prenotazione->prenota($this->richiesta($r, $area, $t, $voce, $docente, $risposta, $nomeScuola, $codiceScuola, $email));
            $esiti[] = $this->esitoVoce($eventoId, $titolo, $etichetta, $esito);
            if ($esito->riuscita()) {
                $riuscite[$eventoId] = ['esito' => $esito, 'docente' => $docente, 'studenti' => (int) ($voce['custom'][CAMPO_PARTECIPANTI] ?? 0)];
                $rinnovo = $rinnovo || $esito->convenzioneDaRinnovare();
                $serveConvenzione = $serveConvenzione || $esito->inAttesaConvenzione();
            }
        }

        if (!$riuscite) {
            $this->togliLogo($v['logo']);

            return new EsitoProgramma([], $esiti);
        }

        // Una sola compilazione con tutte le prenotazioni: Allegato A (PDF) e Convenzione (Word) precompilati
        $attivita = [];
        $primo = 0;
        foreach ($riuscite as $eventoId => $x) {
            $pr = $this->prenotazioniFsl->perCodiceConvenzione((string) $x['esito']->codice());
            if ($pr) {
                $primo = $primo ?: (int) $pr['id'];
                $attivita[] = ['pr' => (int) $pr['id'], 'studenti' => $x['studenti'], 'tutor' => $x['docente']];
            }
            $this->programma->togli($eventoId);
        }
        // Convenzione da stipulare solo se almeno una prenotazione la aspetta (il registro può già coprire la scuola anche se ha risposto «No»)
        $statoConv = $rinnovo ? 'rinnovo' : ($serveConvenzione ? 'no' : 'si');
        $cc = $primo > 0 ? $this->convenzioni->creaDaProgramma($v['s'], $v['logo'], $attivita, $primo, $codiceScuola ?: null, $v['s']['email'] ?: $email, $statoConv) : null;
        if (!$cc) {
            $this->togliLogo($v['logo']);
        }
        $token = $cc ? (string) $cc['token'] : null;
        $this->inviaRiepilogo($email, $nomeScuola, $esiti, $token, $statoConv);

        return new EsitoProgramma([], $esiti, $token, $statoConv);
    }

    /**
     * Dati della scuola e del Dirigente per la convenzione: quelli scritti nel modulo e, dove mancano, quelli dell'anagrafe delle scuole
     * (istituto principale se la sede è una succursale).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $anagrafe
     * @return array<string, mixed>
     */
    private function datiScuola(array $post, ?array $anagrafe, string $nomeScuola): array
    {
        $dati = $post;
        if ($anagrafe && ($d = $this->scuole->perConvenzione((string) $anagrafe['codice']))) {
            $dati['denominazione'] = $d['denominazione'];
            $dati['codice'] = $d['codice'];
            foreach (['comune', 'indirizzo'] as $k) {
                if (trim((string) ($dati[$k] ?? '')) === '') {
                    $dati[$k] = $d[$k];
                }
            }
        } else {
            $dati['denominazione'] = $nomeScuola;
        }
        if (trim((string) ($dati['email'] ?? '')) === '') {
            $dati['email'] = strtolower(trim((string) ($post['email'] ?? '')));
        }

        return $dati;
    }

    /**
     * @param array<string, mixed> $area riga di pagine_eventi
     * @param array<string, string|null> $t turno con l'evento
     * @param array{turno_id: int, custom: array<string, string>} $voce
     */
    private function richiesta(RichiestaProgramma $r, array $area, array $t, array $voce, string $docente, string $conv, string $nomeScuola, string $codiceScuola, string $email): RichiestaPrenotazione
    {
        $campoScuola = $this->repo->campoScuola((int) $t['pagina_id'], (int) $t['evento_id']) ?? 'scuola';
        $post = ['convenzione' => $conv, 'accetta_privacy' => 'on'];
        foreach ($voce['custom'] as $k => $val) {
            $post['custom_' . $k] = $val;
        }
        $post['custom_docente_riferimento'] = $docente;
        $post['custom_' . $campoScuola] = $nomeScuola;
        if ($codiceScuola !== '') {
            $post['scuola_codice'] = [$campoScuola => $codiceScuola];
        }

        return new RichiestaPrenotazione(
            $area, (string) $area['slug'], (int) $voce['turno_id'], $r->nome, $r->cognome, $email, '', 1, $r->utenteId, $r->ruoloUtente,
            $r->ruoliSecondari, $post, [], $r->ip, $r->baseLink, true, true
        );
    }

    /** @return array{evento_id: int, titolo: string, turno: string, esito: string, codice: string|null, messaggio: string|null} */
    private function esitoVoce(int $eventoId, string $titolo, string $turno, EsitoPrenotazione $e): array
    {
        if (!$e->riuscita()) {
            $msg = $e->erroreSessione ?: (self::MESSAGGI[$e->stato()] ?? 'Prenotazione non registrata: riprova.');

            return $this->voceEsito($eventoId, $titolo, $turno, 'errore', null, $msg);
        }
        $tipo = $e->parametri()['st_tipo'] ?? '';
        $esito = match (true) {
            $tipo === 'attesa' => 'attesa',
            $tipo === 'convenzione' => 'convenzione',
            $tipo === 'approvare' => 'da_approvare',
            default => 'confermata',
        };

        return $this->voceEsito($eventoId, $titolo, $turno, $esito, $e->codice(), null);
    }

    /** @return array{evento_id: int, titolo: string, turno: string, esito: string, codice: string|null, messaggio: string|null} */
    private function voceEsito(int $eventoId, string $titolo, string $turno, string $esito, ?string $codice, ?string $messaggio): array
    {
        return ['evento_id' => $eventoId, 'titolo' => $titolo, 'turno' => $turno, 'esito' => $esito, 'codice' => $codice, 'messaggio' => $messaggio];
    }

    /**
     * Risposte del modulo di un'attività: i campi custom_* con il nome senza prefisso (gli elenchi diventano testo).
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function campiDelModulo(array $post): array
    {
        $custom = [];
        foreach ($post as $k => $v) {
            if (str_starts_with((string) $k, 'custom_')) {
                $custom[substr((string) $k, 7)] = mb_substr(is_array($v) ? implode(', ', array_map('strval', $v)) : trim((string) $v), 0, 2000);
            }
        }

        return $custom;
    }

    private function togliLogo(?string $logo): void
    {
        if ($logo && is_file($this->sito->radice() . '/' . $logo)) {
            @unlink($this->sito->radice() . '/' . $logo);
        }
    }

    /**
     * Un'unica email a chi ha prenotato: attività con codice e stato, avvisi (lista d'attesa, convenzione) e link ai documenti.
     *
     * @param list<array{evento_id: int, titolo: string, turno: string, esito: string, codice: string|null, messaggio: string|null}> $esiti
     */
    private function inviaRiepilogo(string $email, string $scuola, array $esiti, ?string $token, string $convenzione): void
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $etichette = ['confermata' => 'Confermata', 'da_approvare' => 'In attesa di approvazione', 'attesa' => 'Lista d\'attesa', 'convenzione' => 'In attesa della convenzione', 'errore' => 'Non prenotata'];
        $righe = '';
        foreach ($esiti as $x) {
            $righe .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;"><strong>' . $h($x['titolo']) . '</strong>' . ($x['turno'] !== '' ? '<br><span style="color:#64748b;">' . $h($x['turno']) . '</span>' : '') . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;">' . $h($etichette[$x['esito']] ?? $x['esito']) . ($x['messaggio'] ? '<br><span style="color:#b91c1c;">' . $h($x['messaggio']) . '</span>' : '') . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;font-family:monospace;">' . $h($x['codice'] ?? '') . '</td></tr>';
        }
        $corpo = '<p>Gentile docente,</p><p>ecco il riepilogo delle attività di Formazione Scuola Lavoro richieste per <strong>' . $h($scuola) . '</strong>:</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:14px;"><tr><th align="left" style="padding:6px 10px;background:#f1f5f9;">Attività</th><th align="left" style="padding:6px 10px;background:#f1f5f9;">Stato</th><th align="left" style="padding:6px 10px;background:#f1f5f9;">Codice</th></tr>' . $righe . '</table>';
        if (array_filter($esiti, static fn (array $x): bool => $x['esito'] === 'attesa')) {
            $corpo .= '<p><strong>Lista d\'attesa:</strong> per le attività indicate i posti erano esauriti. Se un posto si libera ti scriveremo per confermarlo.</p>';
        }
        if (array_filter($esiti, static fn (array $x): bool => $x['esito'] === 'errore')) {
            $corpo .= '<p><strong>Attività non prenotate:</strong> restano nel tuo programma sul portale; puoi riprovare o toglierle.</p>';
        }
        if ($token !== null) {
            $link = $this->sito->urlBase() . '/convenzione_online.php?t=' . $token;
            $corpo .= "<p style='margin-top:18px;'>Abbiamo preparato l'<strong>Allegato A</strong> (PDF, con la scheda di ogni attività)"
                . ($convenzione === 'si' ? '' : ' e la <strong>Convenzione</strong> (Word)') . ' con i dati che hai inserito:</p>'
                . "<p style='margin:18px 0;'><a href='" . $h($link) . "' style='background:#B30000;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Scarica i documenti</a></p>"
                . '<p>Il Dirigente Scolastico li <strong>firma digitalmente in PAdES</strong> (PDF firmato, non .p7m) e la scuola li invia via PEC. Appena riceviamo la documentazione confermiamo le prenotazioni in attesa.</p>';
        }
        $this->mailer->invia($email, 'Formazione Scuola Lavoro – riepilogo delle attività richieste', $corpo);
    }
}
