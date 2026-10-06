<?php

declare(strict_types=1);

namespace App\Attestati;

use App\Core\Orologio;
use App\Core\Sito;
use App\Eventi\RegoleIscrizioni;
use App\Eventi\Turni;
use App\Infrastructure\Mail\Mailer;
use App\Portale\ColoriAree;

/**
 * Attestati singoli (una prenotazione = un attestato) e di gruppo (progetti per le scuole ed eventi con attestati per la
 * classe: un attestato per ogni studente dell'elenco inserito dal docente, inviati al docente). Ogni attestato ha un
 * codice verificabile su verifica_attestato.php.
 */
final class ServizioAttestati
{
    public function __construct(
        private AttestatoRepository $repo,
        private RegoleClasse $classi,
        private RegoleIscrizioni $iscrizioni,
        private Mailer $mailer,
        private ColoriAree $colori,
        private Sito $sito,
        private Orologio $orologio,
    ) {
    }

    /**
     * Come si comporta l'attestato per l'evento della prenotazione: evento normale, progetto senza attestati,
     * progetto non ancora concluso, attestati per gli studenti dell'elenco o attestato alla persona iscritta.
     */
    public function regola(int $eventoId): RegolaAttestato
    {
        $row = $this->repo->schedaRegola($eventoId);
        if (!$row) {
            return RegolaAttestato::Evento;
        }
        if (($row['tipo'] ?? '') !== 'progetto') {
            return (int) ($row['attestati'] ?? 0) === 1 ? RegolaAttestato::Gruppo : RegolaAttestato::Evento;
        }
        if ((int) ($row['attestati'] ?? 0) !== 1) {
            return RegolaAttestato::No;
        }
        if (!empty($row['data_fine']) && $row['data_fine'] >= $this->orologio->adesso()->format('Y-m-d')) {
            return RegolaAttestato::Attendi;
        }

        return (int) ($row['per_scuole'] ?? 1) === 1 ? RegolaAttestato::Gruppo : RegolaAttestato::Singolo;
    }

    /**
     * Elenco degli studenti di una prenotazione (ordine di inserimento).
     *
     * @return list<array<string, string|null>>
     */
    public function partecipanti(int $prenotazioneId): array
    {
        return $this->repo->partecipanti($prenotazioneId);
    }

    /** @param array<string, mixed> $p */
    public static function nomePartecipante(array $p): string
    {
        return trim(($p['cognome'] ?? '') . ' ' . ($p['nome'] ?? ''));
    }

    /**
     * Sostituisce l'elenco degli studenti (usata dal docente prima dell'invio degli attestati).
     *
     * @param list<array{cognome: string, nome: string}> $righe
     */
    public function salvaElenco(int $prenotazioneId, array $righe): void
    {
        $this->repo->sostituisciPartecipanti($prenotazioneId, $righe);
    }

    /**
     * Quanti studenti può contenere l'elenco: il numero dichiarato nell'iscrizione, altrimenti il massimo del progetto.
     *
     * @param array<string, mixed> $pren
     * @param array<string, mixed>|null $dett
     */
    public function maxPartecipanti(array $pren, ?array $dett): int
    {
        $custom = json_decode((string) ($pren['dati_custom_json'] ?? ''), true) ?: [];
        $dich = (int) ($custom[CAMPO_PARTECIPANTI] ?? 0);
        if ($dich > 0) {
            return $dich;
        }

        return $this->iscrizioni->limitiPartecipanti($dett, $pren)['max'] ?? 200;
    }

    /** Codice di verifica casuale, senza caratteri ambigui (0/O, 1/I). */
    public static function nuovoCodice(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $c = '';
        for ($i = 0; $i < 10; $i++) {
            $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return 'AT-' . $c;
    }

    public function urlVerifica(string $codice): string
    {
        return $this->sito->urlBase() . '/verifica_attestato.php?c=' . urlencode($codice);
    }

    /**
     * Prenotazione con evento, area, portale e scheda del progetto: i dati che servono agli attestati.
     *
     * @return array<string, string|null>|null
     */
    public function prenotazione(int $prenotazioneId): ?array
    {
        return $this->repo->prenotazionePerAttestati($prenotazioneId);
    }

    /**
     * Dati dell'attestato personale a partire dal codice della prenotazione.
     *
     * @return array<string, mixed>|null
     */
    public function perCodice(string $codice): ?array
    {
        return $this->repo->attestatoPerCodice($codice);
    }

    /** Id della prenotazione con questo codice (0 se non esiste). */
    public function idPerCodice(string $codice): int
    {
        return $this->repo->idPerCodice($codice);
    }

    /** Codice di verifica per gli studenti che non l'hanno ancora (assegnato una volta, non cambia più). */
    public function assegnaCodici(int $prenotazioneId): void
    {
        foreach ($this->repo->idsSenzaCodice($prenotazioneId) as $id) {
            for ($tent = 0; $tent < 5; $tent++) {
                if ($this->repo->impostaCodice($id, self::nuovoCodice())) {
                    break; // codice UNIQUE: in caso (rarissimo) di doppione si riprova
                }
            }
        }
    }

    /**
     * Progetti per le scuole ed eventi con attestati per la classe: genera i codici degli studenti e manda a chi ha
     * prenotato il link agli attestati. Condizioni: prenotazione confermata e presente, almeno uno studente non escluso,
     * attività conclusa (progetto: data di fine; evento: giorno del turno), oppure $forza dal pulsante «Invia attestati ora»
     * dell'admin. Ritorna true o il motivo.
     */
    public function inviaGruppo(int $prenotazioneId, bool $forza = false): bool|string
    {
        $p = $this->repo->prenotazionePerAttestati($prenotazioneId);
        if (!$p || !$this->classi->attestatiDiClasse($p)) {
            return 'Questa attività non prevede attestati per gli studenti.';
        }
        $isProgetto = ($p['evento_tipo'] ?? '') === 'progetto';
        if (($p['stato'] ?? 'confermata') !== 'confermata') {
            return 'La prenotazione non è confermata.';
        }
        if ((int) $p['presente'] !== 1) {
            return 'Segna prima la presenza della classe.';
        }
        if (!$forza && !empty($p['attestato_inviato'])) {
            return 'Attestati già inviati.';
        }
        if (!$forza && !$this->classi->attivitaConclusaClasse($p)) {
            return $isProgetto ? 'Il progetto non è ancora concluso.' : "L'evento non si è ancora svolto.";
        }
        $n = $this->repo->contaNonEsclusi($prenotazioneId);
        if ($n === 0) {
            return "L'elenco degli studenti è vuoto.";
        }
        if (empty($p['email'])) {
            return "Manca l'email del docente.";
        }

        $this->assegnaCodici($prenotazioneId);
        $url = $this->sito->urlBase();
        $link = $url . '/attestati_gruppo.php?code=' . urlencode((string) $p['codice_prenotazione']);
        $corpo = '<p>Gentile <strong>' . htmlspecialchars($p['nome'] . ' ' . $p['cognome']) . '</strong>,</p>'
               . '<p>grazie per aver partecipato con la tua classe ' . ($isProgetto ? 'al progetto' : "all'attività") . ' <strong>' . htmlspecialchars((string) $p['evento_titolo']) . '</strong>.</p>'
               . "<p>Sono pronti gli <strong>attestati di partecipazione di $n " . ($n === 1 ? 'studente' : 'studenti') . "</strong>: li trovi tutti in un'unica pagina, uno per foglio, pronti da stampare o salvare in PDF. Ogni attestato ha un codice e un QR per verificarne l'autenticità.</p>"
               . "<p style='text-align:center; margin:30px 0;'><a href='" . htmlspecialchars($link) . "' style='background-color:#198754; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:16px;'>📄 Apri gli attestati</a></p>"
               . "<p>Per aprirli accedi con le stesse credenziali usate per l'iscrizione. Li ritrovi anche nella tua <a href='" . htmlspecialchars($url . '/area_personale.php') . "'>Area Personale</a>.</p>";
        $this->mailer->invia((string) $p['email'], 'Attestati degli studenti: ' . $p['evento_titolo'], $corpo, $this->colori->delTurno((int) $p['turno_id']));
        $this->repo->segnaAttestatoInviato($prenotazioneId);

        return true;
    }

    /**
     * Dopo la presenza: se l'evento è concluso (o il progetto prevede l'attestato) manda l'email con il link all'attestato.
     * Per le classi partono gli attestati degli studenti (al docente), non quello personale. Ritorna true se ha inviato.
     */
    public function inviaSeConcluso(int $prenotazioneId): bool
    {
        $row = $this->repo->perEmailAttestato($prenotazioneId);
        if (!$row || $row['presente'] != 1) {
            return false;
        }
        if (!empty($row['attestato_inviato'])) {
            return false;
        }
        if (empty($row['email'])) {
            return false;
        }

        // Progetti: niente attestato se non previsto o se il progetto non è finito; per le scuole
        // partono gli attestati degli studenti (al docente), non quello personale
        $regola = $this->regola($this->repo->eventoDelTurno((int) $row['turno_id']));
        if ($regola === RegolaAttestato::No || $regola === RegolaAttestato::Attendi) {
            return false;
        }
        if ($regola === RegolaAttestato::Gruppo) {
            return $this->inviaGruppo($prenotazioneId) === true;
        }

        // Turni senza data: l'attestato parte alla registrazione della presenza
        if (!empty($row['data_turno']) && !Turni::concluso($row)) {
            return false;
        }

        $domain = $this->sito->urlBase();

        $linkAttestato = $domain . '/stampa_attestato.php?code=' . urlencode((string) $row['codice_prenotazione']);
        $linkArea = $domain . '/area_personale.php';
        $oggetto = 'Il tuo Attestato è pronto: ' . $row['titolo'];
        $corpo = '<p>Gentile <strong>' . htmlspecialchars((string) $row['nome']) . ' ' . htmlspecialchars((string) $row['cognome']) . '</strong>,</p>'
               . "<p>Grazie per aver partecipato all'evento <strong>" . htmlspecialchars((string) $row['titolo']) . '</strong>' . (!empty($row['data_turno']) ? ' del ' . date('d/m/Y', (int) strtotime((string) $row['data_turno'])) : '') . '.</p>'
               . '<p>Il tuo <strong>Attestato di Partecipazione</strong> è disponibile per il download.</p>'
               . "<p style='text-align:center; margin:30px 0;'>"
               . "<a href='" . $linkAttestato . "' style='background-color:#198754; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:16px;'>📄 Scarica il tuo Attestato</a>"
               . '</p>'
               . "<p>In alternativa puoi recuperarlo dalla tua <a href='" . $linkArea . "'>Area Personale</a>.</p>"
               . '<p>Cordiali saluti,<br>Il team Didattica DiBEST</p>';

        $this->mailer->invia((string) $row['email'], $oggetto, $corpo, $this->colori->delTurno((int) $row['turno_id']));
        $this->repo->segnaAttestatoInviato($prenotazioneId);

        return true;
    }

    /**
     * Verifica pubblica di un codice stampato sull'attestato (o letto dal QR): i dati dell'attestato se è valido, altrimenti null.
     * Prima si cerca uno studente (progetti per le scuole), poi la prenotazione.
     *
     * @return array<string, mixed>|null
     */
    public function verifica(string $codice): ?array
    {
        $s = $this->repo->partecipantePerCodice($codice);
        if ($s) {
            $p = $this->repo->prenotazionePerAttestati((int) $s['pr_id']);
            $ok = $p && empty($s['escluso']) && (int) $p['presente'] === 1 && ($p['stato'] ?? '') === 'confermata' && (int) ($p['attestati'] ?? 0) === 1;
            if (!$ok) {
                return null;
            }
            $dati = DatiAttestato::crea($p, trim($s['nome'] . ' ' . $s['cognome']), $codice);
            $dati['anonimizzato'] = !empty($s['anonimizzato']);

            return $dati;
        }
        // Attestato personale (codice della prenotazione)
        $id = $this->repo->idPerCodiceMaiuscolo($codice);
        $p = $id !== null ? $this->repo->prenotazionePerAttestati($id) : null;
        if ($p && (int) $p['presente'] === 1 && ($p['stato'] ?? '') === 'confermata') {
            $regola = $this->regola((int) $p['evento_id']);
            if ($regola === RegolaAttestato::Evento || $regola === RegolaAttestato::Singolo) {
                return DatiAttestato::crea($p, trim($p['nome'] . ' ' . $p['cognome']), (string) $p['codice_prenotazione'], '');
            }
        }

        return null;
    }
}
