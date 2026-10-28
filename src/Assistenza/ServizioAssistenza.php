<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Assistenza\Didacticus\MancateRepository;
use App\Assistenza\Didacticus\ServizioDidacticus;
use App\Core\Orologio;

/**
 * L'assistenza a studenti, personale ed esterni: si apre una conversazione, ci si scrive, un operatore la prende in carico, risponde, la passa a un collega
 * o la chiude. I messaggi sono testo semplice (la pagina li mostra con l'escape). Le email di avviso partono solo se l'altra parte non ha la chat aperta e non più
 * spesso del necessario; i limiti sul numero di richieste e di messaggi fermano gli abusi, soprattutto degli esterni che non hanno un login.
 *
 * Le chat nascono con Didacticus (stato «bot»), l'assistente virtuale: risponde alle domande che sa, e passa la parola a un operatore (stato «nuova», con gli avvisi) se la
 * persona lo chiede, se la risposta non è bastata o se non riesce a capire due volte di fila. Chi preferisce scrivere subito a una persona può saltarlo.
 *
 * Gli errori si ritornano come testo da mostrare (null = tutto bene), come negli altri servizi del portale.
 */
final class ServizioAssistenza
{
    public function __construct(
        private ConversazioniRepository $conversazioni,
        private MessaggiAssistenzaRepository $messaggi,
        private OperatoriAssistenza $operatori,
        private NotificheAssistenza $notifiche,
        private OrariAssistenza $orari,
        private Orologio $orologio,
        private ServizioDidacticus $didacticus,
        private MancateRepository $mancate,
    ) {
    }

    private const SOSPESO = 'Il servizio di assistenza è temporaneamente sospeso: riprova più tardi.';

    private function adesso(): string
    {
        return $this->orologio->adesso()->format('Y-m-d H:i:s');
    }

    private function prima(string $intervallo): string
    {
        return $this->orologio->adesso()->modify($intervallo)->format('Y-m-d H:i:s');
    }

    /** Testo semplice: a capo uniformi, niente righe vuote ripetute, niente caratteri di controllo. */
    public static function ripulisci(string $testo): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $testo);
        $t = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t);
        $t = (string) preg_replace("/\n{3,}/", "\n\n", $t);

        return trim($t);
    }

    private function controllaTesto(string &$testo): ?string
    {
        $testo = self::ripulisci($testo);
        if ($testo === '') {
            return 'Scrivi il messaggio.';
        }
        if (mb_strlen($testo) > Costanti::MAX_CARATTERI) {
            return 'Il messaggio è troppo lungo (al massimo ' . Costanti::MAX_CARATTERI . ' caratteri).';
        }

        return null;
    }

    /**
     * Una persona con il login chiede aiuto. Se ha già una richiesta in mano all'ufficio il messaggio va lì (ne ha una sola alla volta); altrimenti parte una
     * conversazione nuova e quelle rimaste solo con Didacticus si chiudono (restano nello storico).
     *
     * @param array<string, mixed> $utente id, nome, cognome, email
     * @param array{tipo: string, id: int, testo: string}|null $contesto la pratica o la prenotazione di cui si parla
     * @param bool $conOperatore true = salta Didacticus e scrive subito a un operatore
     * @return array{0: array<string, string|null>|null, 1: string|null} conversazione ed errore
     */
    public function apriDaUtente(array $utente, string $argomento, string $testo, ?array $contesto = null, string $ip = '', bool $conOperatore = false, ?string $avvio = null): array
    {
        if (!$this->orari->attiva()) {
            return [null, self::SOSPESO];
        }
        $testo = $this->testoAvvio($testo, $argomento, $avvio);
        if (($err = $this->controllaTesto($testo)) !== null) {
            return [null, $err];
        }
        $uid = (int) ($utente['id'] ?? 0);
        $aperta = $uid > 0 ? $this->conversazioni->apertaConOperatoreDi($uid) : null; // la richiesta già in mano all'ufficio continua lì; una chat rimasta solo con Didacticus no
        if ($aperta !== null) {
            $err = $this->scriviComeUtente($aperta, $testo);

            return [$err === null ? $this->conversazioni->perId((int) $aperta['id']) : null, $err];
        }
        if ($uid <= 0) {
            return [null, 'Per scrivere all\'assistenza devi accedere.'];
        }
        if ($this->conversazioni->nuoveDiUtente($uid, $this->prima('-1 hour')) >= Costanti::MAX_NUOVE_PER_UTENTE_ORA) {
            return [null, 'Hai aperto molte richieste nell\'ultima ora: attendi la risposta o riprova più tardi.'];
        }
        $nome = trim(trim((string) ($utente['nome'] ?? '')) . ' ' . trim((string) ($utente['cognome'] ?? ''))) ?: (string) ($utente['email'] ?? 'Utente');
        $this->chiudiRimasteConBot($uid); // la nuova richiesta parte da zero: le chat lasciate con Didacticus restano nello storico, chiuse

        return [$this->nuova(Costanti::CANALE_UTENTE, $uid, $nome, (string) ($utente['email'] ?? ''), $argomento, $testo, $contesto, $ip, $conOperatore, $avvio), null];
    }

    /** Chiude da sistema le chat di una persona che sono ancora con Didacticus (nessun operatore le ha viste): restano leggibili nello storico. */
    private function chiudiRimasteConBot(int $utenteId): void
    {
        $quando = $this->adesso();
        foreach ($this->conversazioni->diUtente($utenteId, 20) as $c) {
            if ($c['stato'] === Costanti::STATO_BOT) {
                $this->conversazioni->chiudi((int) $c['id'], Costanti::DA_SISTEMA, $quando);
                $this->messaggi->aggiungi((int) $c['id'], Costanti::DA_SISTEMA, Costanti::TIPO_MESSAGGIO, 'Conversazione chiusa: hai iniziato una nuova richiesta.', '', $quando);
            }
        }
    }

    /**
     * Una persona senza login chiede aiuto: servono nome ed email (le risposte e il link personale arrivano lì). Il controllo anti-robot lo fa la pagina.
     *
     * @param array{tipo: string, id: int, testo: string}|null $contesto
     * @return array{0: array<string, string|null>|null, 1: string|null}
     */
    public function apriDaEsterno(string $nome, string $email, string $argomento, string $testo, string $ip, ?array $contesto = null, bool $conOperatore = false, ?string $avvio = null): array
    {
        if (!$this->orari->attiva()) {
            return [null, self::SOSPESO];
        }
        $testo = $this->testoAvvio($testo, $argomento, $avvio);
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        $email = strtolower(trim($email));
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > Costanti::MAX_NOME) {
            return [null, 'Scrivi il tuo nome e cognome.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return [null, 'Scrivi un indirizzo email valido: le risposte arrivano lì.'];
        }
        if (($err = $this->controllaTesto($testo)) !== null) {
            return [null, $err];
        }
        $dal = $this->prima('-1 hour');
        if (($ip !== '' && $this->conversazioni->nuoveDaIp($ip, $dal) >= Costanti::MAX_NUOVE_PER_IP_ORA) || $this->conversazioni->nuoveDaEmail($email, $dal) >= Costanti::MAX_NUOVE_PER_EMAIL_ORA) {
            return [null, 'Hai mandato molte richieste nell\'ultima ora: riprova più tardi.'];
        }
        $c = $this->nuova(Costanti::CANALE_ESTERNO, null, $nome, $email, $argomento, $testo, $contesto, $ip, $conOperatore, $avvio);
        $this->notifiche->ricevuta($c);

        return [$c, null];
    }

    /**
     * @param array{tipo: string, id: int, testo: string}|null $contesto
     * @return array<string, string|null>
     */
    private function nuova(string $canale, ?int $utenteId, string $nome, string $email, string $argomento, string $testo, ?array $contesto, string $ip, bool $conOperatore = false, ?string $avvio = null): array
    {
        $quando = $this->adesso();
        $conBot = !$conOperatore && $this->didacticus->attivo();
        $ct = $contesto !== null && in_array($contesto['tipo'], Costanti::CONTESTI, true);
        $id = $this->conversazioni->crea([
            'canale' => $canale, 'utente_id' => $utenteId, 'nome' => $nome, 'email' => $email, 'argomento' => Costanti::argomentoValido($argomento),
            'contesto_tipo' => $ct ? $contesto['tipo'] : '', 'contesto_id' => $ct ? (int) $contesto['id'] : null, 'contesto_testo' => $ct ? (string) $contesto['testo'] : '',
            'stato' => $conBot ? Costanti::STATO_BOT : Costanti::STATO_NUOVA, 'ip' => $ip,
        ], $quando);
        if ($conBot && $avvio !== null) { // la chat parte da Didacticus: prima il saluto con il nome, poi quello che la persona ha scelto o scritto
            $this->messaggi->aggiungi($id, Costanti::DA_BOT, Costanti::TIPO_MESSAGGIO, self::salutoAvvio($nome), 'Didacticus', $quando);
        }
        $this->messaggi->aggiungi($id, Costanti::DA_UTENTE, Costanti::TIPO_MESSAGGIO, $testo, $nome, $quando);
        $this->conversazioni->visto($id, Costanti::DA_UTENTE, $quando);
        $c = $this->conversazioni->perId($id) ?? [];
        if ($conBot && $avvio === 'argomento') {
            $r = $this->didacticus->rispostaArgomento(Costanti::argomentoValido($argomento), $canale === Costanti::CANALE_UTENTE);
            $this->scriviBot($c, $r['testo'], $r['extra']);

            return $this->conversazioni->perId($id) ?? $c;
        }
        if ($conBot) {
            // Didacticus risponde subito; gli operatori non vengono disturbati finché la persona non ha bisogno di loro
            $this->rispondiBot($c, $testo, true);

            return $this->conversazioni->perId($id) ?? $c;
        }
        $this->avvisoFuoriOrario($id, $quando);
        $this->notifiche->nuovaRichiesta($c, $testo, $this->operatori->emailAvvisi());
        $this->notifiche->pushNuovaRichiesta($c, $testo);
        $this->conversazioni->avvisato($id, Costanti::DA_OPERATORE, $quando);

        return $this->conversazioni->perId($id) ?? $c;
    }

    /** Chi sceglie un argomento nella chat non scrive niente: il suo «messaggio» è il nome dell'argomento. */
    private function testoAvvio(string $testo, string $argomento, ?string $avvio): string
    {
        return $avvio === 'argomento' && trim($testo) === '' ? Costanti::nomeArgomento(Costanti::argomentoValido($argomento)) : $testo;
    }

    /** Il primo messaggio di Didacticus quando la chat parte da lui: saluta per nome (quello del login o quello scritto da chi non ha l'accesso). */
    public static function salutoAvvio(string $nome): string
    {
        $primo = trim(explode(' ', trim($nome))[0] ?? '');
        $primo = str_contains($primo, '@') ? '' : $primo;

        return 'Ciao' . ($primo !== '' ? ' ' . $primo : '') . '! Sono Didacticus, l\'assistente virtuale del Dipartimento. Dimmi cosa ti serve: scegli un argomento qui sotto oppure scrivimi la tua domanda.';
    }

    /**
     * Chi ha chiesto aiuto scrive un altro messaggio (anche in una conversazione chiusa, che così si riapre).
     *
     * @param array<string, string|null> $conv
     */
    public function scriviComeUtente(array $conv, string $testo): ?string
    {
        if (($err = $this->controllaTesto($testo)) !== null) {
            return $err;
        }
        $id = (int) $conv['id'];
        if ($this->messaggi->inviatiDa($id, Costanti::DA_UTENTE, $this->prima('-10 minutes')) >= Costanti::MAX_MESSAGGI_UTENTE_10_MIN) {
            return 'Hai mandato molti messaggi in pochi minuti: attendi la risposta.';
        }
        $quando = $this->adesso();
        if ($conv['stato'] === Costanti::STATO_CHIUSA) {
            $this->conversazioni->riapri($id, (int) $conv['operatore_id'] > 0 ? Costanti::STATO_ASSEGNATA : ($conv['passata_il'] === null && $this->didacticus->attivo() ? Costanti::STATO_BOT : Costanti::STATO_NUOVA));
            $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_MESSAGGIO, 'La conversazione è stata riaperta.', '', $quando);
        }
        $this->messaggi->aggiungi($id, Costanti::DA_UTENTE, Costanti::TIPO_MESSAGGIO, $testo, (string) $conv['nome'], $quando);
        $this->conversazioni->movimento($id, Costanti::DA_UTENTE, $quando);
        $this->conversazioni->visto($id, Costanti::DA_UTENTE, $quando);
        $aggiornata = $this->conversazioni->perId($id) ?? $conv;
        if ($aggiornata['stato'] === Costanti::STATO_BOT) {
            $this->rispondiBot($aggiornata, $testo);

            return null;
        }
        $this->avvisaOperatori($aggiornata, $testo);

        return null;
    }

    /** Fuori orario lo si dice subito, con gli orari: la risposta arriva per email. */
    private function avvisoFuoriOrario(int $id, string $quando): void
    {
        if ($this->orari->inOrario($this->orologio->adesso())) {
            return;
        }
        $d = $this->orari->descrizione();
        $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_MESSAGGIO, 'Al momento non c\'è nessuno in linea' . ($d !== '' ? ' (rispondiamo: ' . $d . ')' : '')
            . '. Abbiamo ricevuto il tuo messaggio e ti risponderemo appena possibile: se non hai questa pagina aperta, la risposta ti arriva per email.', '', $quando);
    }

    /** Un messaggio di Didacticus nella chat. @param array<string, string|null> $conv @param array<string, mixed> $extra */
    private function scriviBot(array $conv, string $testo, array $extra = []): void
    {
        $id = (int) $conv['id'];
        $quando = $this->adesso();
        $this->messaggi->aggiungi($id, Costanti::DA_BOT, Costanti::TIPO_MESSAGGIO, $testo, 'Didacticus', $quando, $extra ?: null);
        $this->conversazioni->movimento($id, Costanti::DA_BOT, $quando);
    }

    /**
     * Didacticus risponde a quello che la persona ha scritto. Se non sa rispondere due volte di fila, o se la persona chiede un operatore, passa la parola.
     *
     * @param array<string, string|null> $conv
     */
    private function rispondiBot(array $conv, string $testo, bool $primo = false): void
    {
        $id = (int) $conv['id'];
        if ($this->inAttesaCorso($id)) { // Didacticus ha chiesto il corso di laurea: quello che la persona scrive è la risposta
            $corso = $this->corsoDaTesto($testo);
            if ($corso === null) {
                $this->scriviBot($conv, 'Non ho riconosciuto il corso: scegli dai pulsanti qui sotto oppure scrivimi il nome del corso di laurea.', $this->extraCorsi());

                return;
            }
            $this->passaConCorso($conv, $corso, $this->ultimaDomandaVera($id));

            return;
        }
        $r = $this->didacticus->risposta($testo, (string) $conv['argomento'], $conv['canale'] === Costanti::CANALE_UTENTE, $primo);
        $this->scriviBot($conv, $r['testo'], $r['extra'] + ($r['voce_id'] !== null ? ['voce' => $r['voce_id']] : []));
        if ($r['tipo'] === 'nessuna' || $r['tipo'] === 'suggerimenti') {
            $this->mancate->registra($id, $testo, (string) $conv['argomento'], MancateRepository::NESSUNA, null, $this->adesso());
        }
        $mancate = $r['nessuna'] ? (int) $conv['bot_mancate'] + 1 : 0;
        if ((int) $conv['bot_mancate'] !== $mancate) {
            $this->conversazioni->botMancate($id, $mancate);
        }
        if ($r['passa']) {
            $this->chiediCorso($this->conversazioni->perId($id) ?? $conv, 'su richiesta della persona', $this->ultimaDomandaVera($id));
        } elseif ($mancate >= 2) {
            $this->scriviBot($conv, 'Mi dispiace, non riesco ad aiutarti con le risposte che ho: ti passo un operatore.');
            $this->chiediCorso($this->conversazioni->perId($id) ?? $conv, 'Didacticus non ha trovato risposte per due volte di fila');
        }
    }

    /** I pulsanti della domanda «a quale corso sei iscritto?». @return array<string, mixed> */
    private function extraCorsi(): array
    {
        return ['corsi' => $this->operatori->corsiSeguiti()];
    }

    /** L'ultimo messaggio di Didacticus è la domanda sul corso di laurea (ha i pulsanti dei corsi): la prossima cosa scritta è la risposta. */
    private function inAttesaCorso(int $id): bool
    {
        $extra = null;
        foreach ($this->messaggi->della($id) as $m) {
            if ($m['da'] === Costanti::DA_BOT) {
                $extra = (array) json_decode((string) $m['extra'], true);
            }
        }

        return $extra !== null && !empty($extra['corsi']);
    }

    /** Il corso scritto dalla persona: il nome di uno dei corsi seguiti dagli operatori, '' se dice che non sa o non è iscritta, null se non lo riconosco. */
    private function corsoDaTesto(string $testo): ?string
    {
        $t = mb_strtolower(trim($testo));
        if ($t === '' || preg_match('/\b(altro|non so|nessun[oa]?|non sono iscritt[oa]|qualsiasi|indifferente)\b/u', $t)) {
            return '';
        }
        foreach ($this->operatori->corsiSeguiti() as $c) {
            $n = mb_strtolower($c);
            if (str_contains($n, $t) || (mb_strlen($n) >= 6 && str_contains($t, $n))) {
                return $c;
            }
        }

        return null;
    }

    /**
     * La persona vuole un operatore (o Didacticus non sa risponderle): se gli operatori hanno indicato i corsi che seguono, Didacticus chiede a quale corso di laurea
     * è iscritta, così la chat arriva a chi segue quel corso; altrimenti la passa subito.
     *
     * @param array<string, string|null> $conv
     */
    private function chiediCorso(array $conv, string $motivo, string $domanda = ''): void
    {
        if (!$this->operatori->corsiSeguiti()) {
            $this->scriviBot($conv, 'Certo, ti metto in contatto con una persona dell\'ufficio.');
            $this->passaAOperatore($conv, $motivo, $domanda);

            return;
        }
        $this->scriviBot($conv, 'Certo! Per mandarti alla persona giusta, a quale corso di laurea sei iscritto/a?', $this->extraCorsi());
    }

    /** @param array<string, string|null> $conv */
    private function passaConCorso(array $conv, string $corso, string $domanda): void
    {
        $id = (int) $conv['id'];
        $this->scriviBot($conv, $corso !== '' ? 'Grazie! Ti passo a un operatore che segue ' . $corso . ': ti risponde qui appena è disponibile.' : 'Va bene, ti passo a un operatore dell\'ufficio.');
        $this->passaAOperatore($this->conversazioni->perId($id) ?? $conv, 'su richiesta della persona', $domanda, $corso);
    }

    /** La domanda vera della persona, cioè l'ultima che non è un corso di laurea o una richiesta di operatore. */
    private function ultimaDomandaVera(int $id): string
    {
        $corsi = $this->operatori->corsiSeguiti();
        $d = '';
        foreach ($this->messaggi->della($id) as $m) {
            if ($m['da'] === Costanti::DA_UTENTE && !in_array((string) $m['testo'], $corsi, true) && !\App\Assistenza\Didacticus\Motore::chiedeOperatore((string) $m['testo'])) {
                $d = (string) $m['testo'];
            }
        }

        return $d !== '' ? $d : $this->ultimaDomanda($id);
    }

    /**
     * La chat passa da Didacticus agli operatori: da quel momento è «da prendere» e partono gli avvisi (email e finestrella), con la domanda e una nota che dice perché.
     *
     * @param array<string, string|null> $conv
     */
    private function passaAOperatore(array $conv, string $motivo, string $domanda = '', string $corso = ''): void
    {
        if ($conv['stato'] !== Costanti::STATO_BOT) {
            return;
        }
        $id = (int) $conv['id'];
        $quando = $this->adesso();
        $this->conversazioni->segnaPassataDaBot($id, $quando);
        $this->conversazioni->impostaStato($id, Costanti::STATO_NUOVA);
        $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_MESSAGGIO, 'Ti ho messo in contatto con un operatore: ti risponde qui appena è disponibile.', '', $quando);
        $this->avvisoFuoriOrario($id, $quando);
        $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_NOTA, 'Chat passata da Didacticus (' . $motivo . ').' . ($corso !== '' ? ' Corso di laurea dichiarato: ' . $corso . '.' : '') . ' Rileggi le risposte che ha già dato qui sopra.', '', $quando);
        $c = $this->conversazioni->perId($id) ?? $conv;
        $prima = $domanda !== '' ? $domanda : $this->ultimeDomande($id);
        $this->notifiche->nuovaRichiesta($c, ($corso !== '' ? '[' . $corso . '] ' : '') . $prima, $corso !== '' ? $this->operatori->emailPerCorso($corso) : $this->operatori->emailAvvisi());
        $this->notifiche->pushNuovaRichiesta($c, $prima);
        $this->conversazioni->avvisato($id, Costanti::DA_OPERATORE, $quando);
    }

    /**
     * Quello che la persona fa con i pulsanti sotto le risposte di Didacticus: «mi è stato utile» (chiude), «non mi basta» (passa a un operatore),
     * «parla con un operatore», una voce suggerita, un argomento. Ritorna l'errore o null.
     *
     * @param array<string, string|null> $conv
     */
    public function azioneBot(array $conv, string $azione, string $valore = ''): ?string
    {
        if ($conv['stato'] !== Costanti::STATO_BOT) {
            return $azione === 'operatore' ? null : 'Con Didacticus si può parlare solo all\'inizio della chat.';
        }
        $id = (int) $conv['id'];
        $ultimaVoce = $this->ultimaVoceBot($id);
        $quando = $this->adesso();
        switch ($azione) {
            case 'utile':
                if ($ultimaVoce) {
                    $this->didacticus->registraEsito($ultimaVoce, true);
                }
                $this->conversazioni->risoltaDaBot($id);
                $this->scriviBot($conv, 'Ne sono contento! Se ti serve altro scrivimi pure.');
                $this->chiudi($this->conversazioni->perId($id) ?? $conv, Costanti::DA_UTENTE);

                return null;
            case 'non_utile':
                if ($ultimaVoce) {
                    $this->didacticus->registraEsito($ultimaVoce, false);
                }
                $this->mancate->registra($id, $this->ultimaDomanda($id), (string) $conv['argomento'], MancateRepository::NON_UTILE, $ultimaVoce ?: null, $quando);
                $this->scriviBot($conv, 'Mi dispiace che non sia bastato: ti passo un operatore.');
                $this->chiediCorso($this->conversazioni->perId($id) ?? $conv, 'la risposta di Didacticus non è bastata', $this->ultimaDomanda($id));

                return null;
            case 'operatore':
                $this->chiediCorso($this->conversazioni->perId($id) ?? $conv, 'su richiesta della persona', $this->ultimaDomandaVera($id));

                return null;
            case 'corso':
                if (!$this->inAttesaCorso($id)) {
                    return 'Questa scelta non è più disponibile.';
                }
                $corso = trim($valore);
                if ($corso !== '' && !in_array($corso, $this->operatori->corsiSeguiti(), true)) {
                    return 'Corso non valido.';
                }
                $this->messaggi->aggiungi($id, Costanti::DA_UTENTE, Costanti::TIPO_MESSAGGIO, $corso !== '' ? $corso : 'Un altro corso / non lo so', (string) $conv['nome'], $quando);
                $this->conversazioni->visto($id, Costanti::DA_UTENTE, $quando);
                $this->passaConCorso($conv, $corso, $this->ultimaDomandaVera($id));

                return null;
            case 'voce':
            case 'argomento':
                if ($this->messaggi->inviatiDa($id, Costanti::DA_UTENTE, $this->prima('-10 minutes')) >= Costanti::MAX_MESSAGGI_UTENTE_10_MIN) {
                    return 'Hai mandato molti messaggi in pochi minuti: attendi la risposta.';
                }
                $logged = $conv['canale'] === Costanti::CANALE_UTENTE;
                $r = $azione === 'voce' ? $this->didacticus->rispostaDiVoce((int) $valore, $logged) : $this->didacticus->rispostaArgomento($valore, $logged);
                if ($r === null) {
                    return 'Questa risposta non è più disponibile.';
                }
                $this->messaggi->aggiungi($id, Costanti::DA_UTENTE, Costanti::TIPO_MESSAGGIO, $azione === 'voce' ? (string) ($this->titoloVoce((int) $valore)) : Costanti::nomeArgomento($valore), (string) $conv['nome'], $quando);
                $this->conversazioni->visto($id, Costanti::DA_UTENTE, $quando);
                $this->scriviBot($conv, $r['testo'], $r['extra'] + ($r['voce_id'] !== null ? ['voce' => $r['voce_id']] : []));
                if ((int) $conv['bot_mancate'] !== 0) {
                    $this->conversazioni->botMancate($id, 0);
                }

                return null;
        }

        return 'Azione non valida.';
    }

    private function titoloVoce(int $voceId): string
    {
        return $this->didacticus->titolo($voceId);
    }

    /** L'ultima voce con cui Didacticus ha risposto in questa chat (0 se nessuna). */
    private function ultimaVoceBot(int $id): int
    {
        $voce = 0;
        foreach ($this->messaggi->della($id) as $m) {
            if ($m['da'] === Costanti::DA_BOT) {
                $voce = (int) (((array) json_decode((string) $m['extra'], true))['voce'] ?? 0);
            }
        }

        return $voce;
    }

    /** Le ultime due cose scritte dalla persona (di solito è tutto quello che Didacticus non ha capito), per l'operatore che riceve la chat. */
    private function ultimeDomande(int $id): string
    {
        $d = [];
        foreach ($this->messaggi->della($id) as $m) {
            if ($m['da'] === Costanti::DA_UTENTE) {
                $d[] = (string) $m['testo'];
            }
        }

        return implode("
— ", array_slice($d, -2));
    }

    /** L'ultima cosa scritta dalla persona. */
    private function ultimaDomanda(int $id): string
    {
        $d = '';
        foreach ($this->messaggi->della($id) as $m) {
            if ($m['da'] === Costanti::DA_UTENTE) {
                $d = (string) $m['testo'];
            }
        }

        return $d;
    }

    /** Email agli operatori solo se serve: l'operatore non ha la chat aperta e non è già stato avvisato da poco. @param array<string, string|null> $conv */
    private function avvisaOperatori(array $conv, string $testo): void
    {
        $assegnata = $conv['stato'] === Costanti::STATO_ASSEGNATA && (int) $conv['operatore_id'] > 0;
        if ($assegnata && $this->presente((string) $conv['operatore_visto_il'])) {
            return;
        }
        $this->notifiche->pushMessaggio($conv, $testo); // la finestrella parte a ogni messaggio (la stessa chat sostituisce la precedente); l'email no
        $minuti = $assegnata ? Costanti::MINUTI_TRA_AVVISI_OPERATORE : Costanti::MINUTI_RICORDO_NUOVA;
        if ((string) $conv['avviso_operatore_il'] !== '' && strtotime((string) $conv['avviso_operatore_il']) > strtotime($this->prima("-$minuti minutes"))) {
            return;
        }
        if ($assegnata) {
            $a = $this->operatori->emailDi((int) $conv['operatore_id']);
            $partita = $a !== '' ? $this->notifiche->messaggioPerOperatore($conv, $testo, $a) : $this->notifiche->nuovaRichiesta($conv, $testo, $this->operatori->emailAvvisi()) > 0;
        } else {
            $partita = $this->notifiche->nuovaRichiesta($conv, $testo, $this->operatori->emailAvvisi()) > 0;
        }
        if ($partita) {
            $this->conversazioni->avvisato((int) $conv['id'], Costanti::DA_OPERATORE, $this->adesso());
        }
    }

    private function presente(string $visto): bool
    {
        return $visto !== '' && strtotime($visto) > strtotime($this->prima('-' . Costanti::MINUTI_PRESENZA . ' minutes'));
    }

    /**
     * Un operatore risponde. Se la chat è da prendere diventa sua; se è in carico a un collega prima va presa con «Prendo io».
     *
     * @param array<string, string|null> $conv
     * @param array{id: int, nome: string} $operatore
     */
    public function rispondi(array $conv, array $operatore, string $testo): ?string
    {
        if (($err = $this->controllaTesto($testo)) !== null) {
            return $err;
        }
        if ($conv['stato'] === Costanti::STATO_CHIUSA) {
            return 'La conversazione è chiusa: riaprila per rispondere.';
        }
        $id = (int) $conv['id'];
        if ($conv['stato'] === Costanti::STATO_ASSEGNATA && (int) $conv['operatore_id'] !== $operatore['id']) {
            return 'La chat è in carico a ' . ($conv['operatore_nome'] ?: 'un collega') . ': usa «Prendo io» se vuoi rispondere tu.';
        }
        $quando = $this->adesso();
        if ($conv['stato'] !== Costanti::STATO_ASSEGNATA) {
            $this->assegnaA($id, $operatore, $quando, true);
        }
        $this->messaggi->aggiungi($id, Costanti::DA_OPERATORE, Costanti::TIPO_MESSAGGIO, $testo, $operatore['nome'], $quando);
        $this->conversazioni->movimento($id, Costanti::DA_OPERATORE, $quando);
        $this->conversazioni->primaRisposta($id, $quando);
        $this->conversazioni->visto($id, Costanti::DA_OPERATORE, $quando);
        $this->avvisaUtente($this->conversazioni->perId($id) ?? $conv, $testo);

        return null;
    }

    /** @param array<string, string|null> $conv */
    private function avvisaUtente(array $conv, string $testo): void
    {
        if ($this->presente((string) $conv['utente_visto_il'])) {
            return;
        }
        $this->notifiche->pushRispostaUtente($conv);
        if ((string) $conv['avviso_utente_il'] !== '' && strtotime((string) $conv['avviso_utente_il']) > strtotime($this->prima('-' . Costanti::MINUTI_TRA_AVVISI_UTENTE . ' minutes'))) {
            return;
        }
        if ($this->notifiche->rispostaPerUtente($conv, $testo)) {
            $this->conversazioni->avvisato((int) $conv['id'], Costanti::DA_UTENTE, $this->adesso());
        }
    }

    /** Assegna la chat e, la prima volta, lo dice a chi ha chiesto aiuto. @param array{id: int, nome: string} $operatore */
    private function assegnaA(int $id, array $operatore, string $quando, bool $avvisaUtente): void
    {
        $this->conversazioni->segnaPassataDaBot($id, $quando);
        $this->conversazioni->assegna($id, $operatore['id'], $operatore['nome'], Costanti::STATO_ASSEGNATA);
        if ($avvisaUtente) {
            $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_MESSAGGIO, 'Ti risponde ' . $operatore['nome'] . '.', '', $quando);
        }
    }

    /**
     * «Prendo io»: la chat da prendere, o quella di un collega, passa a questo operatore.
     *
     * @param array<string, string|null> $conv
     * @param array{id: int, nome: string} $operatore
     */
    public function prendiInCarico(array $conv, array $operatore): ?string
    {
        if ($conv['stato'] === Costanti::STATO_CHIUSA) {
            return 'La conversazione è chiusa: riaprila prima.';
        }
        if ($conv['stato'] === Costanti::STATO_ASSEGNATA && (int) $conv['operatore_id'] === $operatore['id']) {
            return null;
        }
        $id = (int) $conv['id'];
        $quando = $this->adesso();
        $dacollega = $conv['stato'] === Costanti::STATO_ASSEGNATA ? (string) $conv['operatore_nome'] : '';
        $this->assegnaA($id, $operatore, $quando, $dacollega === '');
        if ($dacollega !== '') {
            $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_NOTA, "Presa in carico da {$operatore['nome']} (prima: $dacollega).", $operatore['nome'], $quando);
        }
        $this->conversazioni->visto($id, Costanti::DA_OPERATORE, $quando);

        return null;
    }

    /**
     * Passa la chat a un collega.
     *
     * @param array<string, string|null> $conv
     * @param array{id: int, nome: string} $da chi la passa
     * @param array{id: int, nome: string} $a il collega
     */
    public function passaA(array $conv, array $da, array $a): ?string
    {
        if ($conv['stato'] === Costanti::STATO_CHIUSA) {
            return 'La conversazione è chiusa: riaprila prima.';
        }
        $id = (int) $conv['id'];
        $quando = $this->adesso();
        $ultimo = $this->messaggi->ultimo($id);
        $this->conversazioni->assegna($id, $a['id'], $a['nome'], Costanti::STATO_ASSEGNATA);
        $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_NOTA, "Passata da {$da['nome']} a {$a['nome']}.", $da['nome'], $quando);
        $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_MESSAGGIO, 'Ora ti risponde ' . $a['nome'] . '.', '', $quando);
        // il collega deve saperlo anche se non guarda la casella: l'avviso parte subito
        $email = $this->operatori->emailDi($a['id']);
        if ($email !== '' && $a['id'] !== $da['id']) {
            $this->notifiche->messaggioPerOperatore($this->conversazioni->perId($id) ?? $conv, (string) ($ultimo['testo'] ?? ''), $email);
        }
        if ($a['id'] !== $da['id']) {
            $this->notifiche->pushMessaggio($this->conversazioni->perId($id) ?? $conv, (string) ($ultimo['testo'] ?? ''));
        }

        return null;
    }

    /** Nota interna: la vedono solo gli operatori. @param array<string, string|null> $conv @param array{id: int, nome: string} $operatore */
    public function nota(array $conv, array $operatore, string $testo): ?string
    {
        if (($err = $this->controllaTesto($testo)) !== null) {
            return $err;
        }
        $this->messaggi->aggiungi((int) $conv['id'], Costanti::DA_OPERATORE, Costanti::TIPO_NOTA, $testo, $operatore['nome'], $this->adesso());

        return null;
    }

    /** Chiude la conversazione ($da = operatore o utente). @param array<string, string|null> $conv */
    public function chiudi(array $conv, string $da): ?string
    {
        if ($conv['stato'] === Costanti::STATO_CHIUSA) {
            return null;
        }
        $id = (int) $conv['id'];
        $quando = $this->adesso();
        if ($conv['stato'] === Costanti::STATO_BOT && $da !== Costanti::DA_OPERATORE) {
            $this->conversazioni->risoltaDaBot($id); // chi chiude da sé una chat rimasta con Didacticus l'ha risolta con lui
        }
        $this->conversazioni->chiudi($id, $da === Costanti::DA_OPERATORE ? Costanti::DA_OPERATORE : Costanti::DA_UTENTE, $quando);
        $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_MESSAGGIO, 'La conversazione è stata chiusa. Se ti serve ancora aiuto, scrivi qui e si riapre.', '', $quando);

        return null;
    }

    /** @param array<string, string|null> $conv */
    public function riapri(array $conv): ?string
    {
        if ($conv['stato'] !== Costanti::STATO_CHIUSA) {
            return null;
        }
        $id = (int) $conv['id'];
        $this->conversazioni->riapri($id, (int) $conv['operatore_id'] > 0 ? Costanti::STATO_ASSEGNATA : Costanti::STATO_NUOVA);
        $this->messaggi->aggiungi($id, Costanti::DA_SISTEMA, Costanti::TIPO_MESSAGGIO, 'La conversazione è stata riaperta.', '', $this->adesso());

        return null;
    }

    /** Valutazione di chi ha chiesto aiuto, solo a conversazione chiusa. @param array<string, string|null> $conv */
    public function valuta(array $conv, bool $utile): ?string
    {
        if ($conv['stato'] !== Costanti::STATO_CHIUSA) {
            return 'Puoi valutare la conversazione quando è chiusa.';
        }
        $this->conversazioni->valuta((int) $conv['id'], $utile);

        return null;
    }

    /**
     * La pagina dell'utente o dell'operatore è aperta: segna i messaggi letti e l'ultima volta in cui la chat era guardata.
     *
     * @param array<string, string|null> $conv
     * @param string $lettore operatore o utente
     */
    public function apertaDa(array $conv, string $lettore): void
    {
        $id = (int) $conv['id'];
        $quando = $this->adesso();
        $this->conversazioni->visto($id, $lettore === Costanti::DA_OPERATORE ? Costanti::DA_OPERATORE : Costanti::DA_UTENTE, $quando);
        $this->messaggi->segnaLetti($id, $lettore === Costanti::DA_OPERATORE ? Costanti::DA_OPERATORE : Costanti::DA_UTENTE, $quando);
    }

    /**
     * I messaggi da mostrare, solo quelli dopo $dopoId.
     *
     * @param array<string, string|null> $conv
     * @return list<array<string, string|null>>
     */
    public function messaggi(array $conv, int $dopoId = 0, bool $perOperatore = false): array
    {
        return $this->messaggi->della((int) $conv['id'], $dopoId, $perOperatore);
    }

    public function perId(int $id): ?array
    {
        return $this->conversazioni->perId($id);
    }

    public function perToken(string $token): ?array
    {
        return $this->conversazioni->perToken($token);
    }

    /**
     * La casella di un operatore ($vista: da_prendere, mie, tutte, chiuse).
     *
     * @return list<array<string, string|null>>
     */
    public function elenco(string $vista, int $operatoreId): array
    {
        return $this->conversazioni->elenco($vista, $operatoreId);
    }

    /** @return array{da_prendere: int, mie: int, tutte: int} */
    public function contatori(int $operatoreId): array
    {
        return $this->conversazioni->contatori($operatoreId);
    }

    /** Il numero del pulsante «Messaggi»: conversazioni aperte con messaggi da leggere. */
    public function daLeggere(): int
    {
        return $this->messaggi->conversazioniDaLeggere();
    }
}
