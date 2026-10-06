<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Core\Orologio;
use App\Iscrizioni\ServizioVincoli;

/** Convenzioni con le scuole: registro, validità sul periodo dell'attività, stato delle prenotazioni e verifica delle iscrizioni FSL. */
final class ServizioConvenzioni
{
    /** @var array<string, array<string, mixed>|null> convenzioni valide già lette nella richiesta (codice|dal|al) */
    private array $cache = [];

    public function __construct(
        private ConvenzioneRepository $convenzioni,
        private PrenotazioneFslRepository $prenotazioni,
        private PeriodiConvenzione $periodi,
        private NotificheConvenzioni $notifiche,
        private ServizioScuole $scuole,
        private ServizioVincoli $vincoli,
        private Orologio $orologio
    ) {
    }

    /**
     * Convenzione del registro valida per TUTTO il periodo $dal-$al (default: oggi), null se non c'è.
     * Valida dal (data_stipula) vuoto = da sempre; valida fino al (scadenza) vuoto = senza scadenza.
     * $rileggi = true dopo averne registrata o modificata una nella stessa richiesta.
     *
     * @return array<string, mixed>|null
     */
    public function valida(?string $codice, bool $rileggi = false, ?string $dal = null, ?string $al = null): ?array
    {
        $codice = ConvenzioneRepository::codiceValido($codice);
        if ($codice === null) {
            return null;
        }
        $dal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dal) ? (string) $dal : $this->orologio->adesso()->format('Y-m-d');
        $al = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $al) && $al >= $dal ? (string) $al : $dal;
        $k = "$codice|$dal|$al";
        if ($rileggi) {
            $this->cache = [];
        }
        if (!array_key_exists($k, $this->cache)) {
            $this->cache[$k] = $this->convenzioni->valida($codice, $dal, $al);
        }

        return $this->cache[$k];
    }

    /**
     * Tutte le convenzioni della scuola nel registro, dalla più recente.
     *
     * @return list<array<string, mixed>>
     */
    public function dellaScuola(?string $codice): array
    {
        return $this->convenzioni->dellaScuola($codice);
    }

    /**
     * Prenotazione con turno, evento, periodo dell'attività e impostazioni dell'area (modelli e PEC della convenzione).
     *
     * @return array<string, string|null>|null
     */
    public function datiPrenotazione(int $prenotazioneId): ?array
    {
        return $this->prenotazioni->dati($prenotazioneId);
    }

    /** Email alla scuola con modelli e PEC. $tipo: «richiesta» (pulsante in Iscrizioni) | «promemoria» (cron). */
    public function richiedi(int $prenotazioneId, string $tipo = 'richiesta'): bool
    {
        $p = $this->prenotazioni->dati($prenotazioneId);

        return $p !== null && $this->notifiche->richiesta($p, $tipo);
    }

    /**
     * La convenzione della scuola è arrivata (o è nel registro): la prenotazione passa a «ricevuta» e, se era da approvare solo
     * per la convenzione (turno senza approvazione), diventa confermata con l'email alla scuola.
     */
    public function segnaRicevuta(int $prenotazioneId, bool $email = true): bool
    {
        $p = $this->prenotazioni->dati($prenotazioneId);
        if (!$p || ($p['convenzione'] ?? '') === 'ricevuta') {
            return false;
        }
        $this->prenotazioni->segnaConvenzioneRicevuta($prenotazioneId);
        $confermata = false;
        if ($p['stato'] === 'da_approvare' && (int) $p['richiede_approvazione'] === 0 && $this->prenotazioni->confermaSeDaApprovare($prenotazioneId)) {
            $confermata = true;
            $this->vincoli->decadiAttese($prenotazioneId);
        }
        // Email solo a chi aspettava la convenzione ('no'); chi l'aveva dichiarata non ha nulla da fare
        if ($email && ($p['convenzione'] ?? '') === 'no' && !empty($p['email']) && in_array($p['stato'], Costanti::STATI_ATTIVI, true)) {
            $this->notifiche->ricevuta($p, $confermata);
        }

        return true;
    }

    /**
     * Dopo una registrazione o modifica nel registro: le prenotazioni della scuola in attesa (o dichiarate) il cui periodo è
     * coperto da una convenzione diventano «ricevuta». Ritorna quante sono state aggiornate.
     */
    public function applicaAllaScuola(string $codice): int
    {
        $n = 0;
        foreach ($this->prenotazioni->inAttesaDellaScuola($codice) as $x) {
            [$dal, $al] = $this->periodi->prenotazione($x);
            if ($this->valida($codice, true, $dal, $al) && $this->segnaRicevuta((int) $x['id'])) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Registra (id = 0) o modifica una convenzione. $d: scuola_codice, data_stipula (valida dal), scadenza (valida fino al),
     * protocollo, note, docenti (array di ['nome' =>, 'email' =>]), file_convenzione, file_allegato (percorsi già salvati;
     * null = invariati). Ritorna [id, prenotazioni aggiornate] oppure null (dati non validi).
     *
     * @param array<string, mixed> $d
     * @return array{0: int, 1: int}|null
     */
    public function salva(array $d, int $id = 0, string $autore = ''): ?array
    {
        $codice = strtoupper(trim((string) ($d['scuola_codice'] ?? '')));
        if (!$this->scuole->perCodice($codice)) {
            return null;
        }
        $dataOk = static fn ($x): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x) ? $x : null;
        $dal = $dataOk($d['data_stipula'] ?? null);
        $al = $dataOk($d['scadenza'] ?? null);
        if ($dal && $al && $al < $dal) {
            return null;
        }
        $prot = mb_substr(trim((string) ($d['protocollo'] ?? '')), 0, 100);
        $note = mb_substr(trim((string) ($d['note'] ?? '')), 0, 500);
        $docenti = [];
        foreach ((array) ($d['docenti'] ?? []) as $doc) {
            $nome = mb_substr(trim((string) ($doc['nome'] ?? '')), 0, 150);
            $email = strtolower(trim((string) ($doc['email'] ?? '')));
            if ($nome === '' && $email === '') {
                continue;
            }
            $docenti[] = ['nome' => $nome, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''];
        }
        $docJson = $docenti ? (string) json_encode($docenti, JSON_UNESCAPED_UNICODE) : null;
        $autore = mb_substr($autore, 0, 255);
        if ($id > 0) {
            if (!$this->convenzioni->aggiorna($id, $codice, $dal, $al, $prot, $note, $docJson)) {
                return null;
            }
        } else {
            $id = $this->convenzioni->inserisci($codice, $dal, $al, $prot, $note, $docJson, $autore);
            if ($id === 0) {
                return null;
            }
        }
        foreach (['file_convenzione', 'file_allegato'] as $col) {
            if (!empty($d[$col])) {
                $this->convenzioni->impostaFile($id, $col, (string) $d[$col]);
            }
        }
        $this->valida($codice, true);

        return [$id, $this->applicaAllaScuola($codice)];
    }

    /**
     * Scorciatoia per una convenzione senza file (conferma da Iscrizioni, pulsante «Ricevuta»).
     *
     * @return array{0: int, 1: int}|null
     */
    public function registra(string $codice, ?string $dal, ?string $al, string $protocollo = '', string $note = '', string $autore = ''): ?array
    {
        return $this->salva(['scuola_codice' => $codice, 'data_stipula' => $dal, 'scadenza' => $al, 'protocollo' => $protocollo, 'note' => $note], 0, $autore);
    }

    /**
     * Un gestore conferma che la convenzione è arrivata: se la scuola è dell'anagrafe e nel registro non c'è una convenzione che
     * copre il periodo dell'attività, la si registra, così vale anche per le altre prenotazioni e le prossime iscrizioni.
     */
    public function ricevutaDaGestore(int $prenotazioneId, string $autore = ''): bool
    {
        $p = $this->prenotazioni->dati($prenotazioneId);
        if (!$p) {
            return false;
        }
        $cod = (string) ($p['scuola_codice'] ?? '');
        [$attDal, $attAl] = $this->periodi->prenotazione($p);
        if ($cod !== '' && !$this->valida($cod, false, $attDal, $attAl)) {
            [$dal, $al] = $this->periodi->nuova($attDal, $attAl);
            $this->registra($cod, $dal, $al, '', 'Registrata alla conferma della prenotazione ' . $p['codice_prenotazione'] . ": completa con i file e i docenti dell'Allegato A", $autore);
        }

        // Se la registrazione ha già aggiornato la prenotazione, segnaRicevuta non ha altro da fare: conta lo stato finale
        return $this->segnaRicevuta($prenotazioneId) || (($this->prenotazioni->dati($prenotazioneId)['convenzione'] ?? '') === 'ricevuta');
    }

    /**
     * Controllo delle iscrizioni delle attività di Formazione Scuola Lavoro non ancora concluse, anche già confermate:
     * - scuola dell'anagrafe con una convenzione che copre il periodo → «ricevuta» (chi era in attesa viene confermato e avvisato);
     * - nessuna convenzione valida per il periodo → «da stipulare» (lo stato della prenotazione non cambia e non parte nessuna
     *   email: la richiesta la invia il gestore da Iscrizioni, poi seguono i promemoria);
     * - scuola scritta a mano → non verificabile (va abbinata all'anagrafe).
     *
     * @return array{coperte: int, da_stipulare: int, nuove_da_stipulare: int, senza_codice: int}
     */
    public function verifica(): array
    {
        $out = ['coperte' => 0, 'da_stipulare' => 0, 'nuove_da_stipulare' => 0, 'senza_codice' => 0];
        foreach ($this->prenotazioni->daVerificare() as $x) {
            if (empty($x['scuola_codice'])) {
                if (($x['convenzione'] ?? '') !== 'ricevuta') {
                    ++$out['senza_codice'];
                }
                continue;
            }
            [$dal, $al] = $this->periodi->prenotazione($x);
            if ($this->valida($x['scuola_codice'], false, $dal, $al)) {
                if (($x['convenzione'] ?? '') !== 'ricevuta') {
                    $this->segnaRicevuta((int) $x['id']);
                }
                ++$out['coperte'];
            } elseif ((int) $x['fsl'] !== 1) {
                // Attività non FSL: si aggiorna solo quando la convenzione arriva, lo stato resta com'è
                continue;
            } else {
                if (($x['convenzione'] ?? '') !== 'no') {
                    // Senza promemoria automatici finché il gestore non invia la richiesta (conv_promemoria = 3)
                    $this->prenotazioni->segnaDaStipulare((int) $x['id']);
                    ++$out['nuove_da_stipulare'];
                }
                ++$out['da_stipulare'];
            }
        }

        return $out;
    }
}
