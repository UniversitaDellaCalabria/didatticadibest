<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Auth\Sessione;
use App\Core\Sito;

/**
 * Le conferme dei docenti sugli attestati (frequenza alle lezioni, esame sostenuto): durante l'iter il portale manda a ogni docente indicato dallo
 * studente un link personale; il docente entra con le credenziali di Ateneo, vede solo le righe che lo riguardano e le conferma o le rifiuta (con un
 * motivo, e la pratica torna allo studente per correggere). È una conferma nel portale, registrata con chi, quando e come si è entrati: non una firma digitale.
 * Quando tutti i docenti hanno confermato, la pratica passa al passo successivo (chi chiama usa ServizioFlussoConvalide::trasmettiAlPasso).
 */
final class ServizioConfermeDocenti
{
    private const MAX_SOLLECITI = 3;

    public function __construct(
        private ConfermaPraticaRepository $conferme,
        private FirmaPraticaRepository $firme,
        private PraticaRepository $pratiche,
        private ModuloRepository $moduli,
        private ServizioIter $iter,
        private StoricoPratica $storico,
        private NotifichePratiche $notifiche,
        private Sito $sito,
        private Sessione $sessione
    ) {
    }

    /**
     * Il passo di conferma del modulo (indice dell'iter, 1 = primo) e la sua definizione.
     *
     * @param array<string, mixed>|null $m
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    public function passoConferma(?array $m, int $dal = 1): ?array
    {
        foreach ($this->iter->definizione($m) as $i => $d) {
            if ($i + 1 >= $dal && $d['t'] === 'conferma') {
                return [$i + 1, $d];
            }
        }

        return null;
    }

    /**
     * I docenti da interpellare con le righe che li riguardano: [nome => [indici delle righe]] (una sola riga, indice 0, se il campo è un valore).
     *
     * @param list<array<string, mixed>> $risposte
     * @param array<string, mixed> $def definizione del passo (campo e colonna)
     * @return array<string, list<int>>
     */
    public function gruppi(array $risposte, array $def): array
    {
        $campo = mb_strtolower(trim((string) $def['campo']));
        $colonna = mb_strtolower(trim((string) ($def['colonna'] ?? '')));
        $out = [];
        foreach ($risposte as $r) {
            if (mb_strtolower(trim((string) ($r['etichetta'] ?? ''))) !== $campo || !empty($r['nascosto'])) {
                continue;
            }
            if (($r['tipo'] ?? '') === 'tabella') {
                $idx = array_search($colonna, array_map(static fn ($n): string => mb_strtolower(trim((string) $n)), (array) ($r['colonne'] ?? [])), true);
                if ($idx === false) {
                    return [];
                }
                foreach ((array) ($r['righe'] ?? []) as $i => $riga) {
                    $nome = trim((string) (((array) $riga)[$idx] ?? ''));
                    if ($nome !== '') {
                        $out[$nome][] = (int) $i;
                    }
                }
            } elseif (trim((string) ($r['valore'] ?? '')) !== '') {
                $out[trim((string) $r['valore'])][] = 0;
            }
        }

        return $out;
    }

    /**
     * Il docente scelto dallo studente come persona dell'anagrafe con l'email (null se non c'è o ce n'è più d'uno con lo stesso nome).
     *
     * @return array{cognome: string, nome: string, email: string}|null
     */
    public function docente(string $nome): ?array
    {
        $d = $this->firme->docentePerNome($nome);
        if (!$d || !filter_var((string) ($d['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return ['cognome' => (string) $d['cognome'], 'nome' => (string) $d['nome'], 'email' => strtolower((string) $d['email'])];
    }

    /** «Nome Cognome» del docente come lo si legge sull'attestato (se non si riconosce resta il testo scritto). */
    public function nomeVisibile(string $cognomeNome): string
    {
        $d = $this->docente($cognomeNome);

        return $d ? trim($d['nome'] . ' ' . $d['cognome']) : trim($cognomeNome);
    }

    /**
     * Controlla, all'invio dello studente, che ogni docente indicato sia nell'anagrafe con una email: senza, la conferma non potrebbe partire.
     *
     * @param array<string, mixed> $m
     * @param list<array<string, mixed>> $risposte
     * @return list<string> gli errori da mostrare
     */
    public function controllaDocenti(array $m, array $risposte): array
    {
        $errori = [];
        foreach ($this->iter->definizione($m) as $d) {
            if ($d['t'] !== 'conferma') {
                continue;
            }
            $g = $this->gruppi($risposte, $d);
            if (!$g) {
                $errori[] = 'Indica il docente (' . $d['campo'] . ').';
            }
            foreach (array_keys($g) as $nome) {
                if (!$this->docente($nome)) {
                    $errori[] = 'Il docente «' . $nome . '» non è nell\'anagrafe di Ateneo (o ce n\'è più di uno con questo nome): scegli il nome dall\'elenco.';
                }
            }
        }

        return $errori;
    }

    /**
     * Manda la pratica alla conferma dei docenti del passo indicato (1 = primo passo dell'iter). Ritorna un errore o null.
     */
    public function avvia(int $id, int $passo): ?string
    {
        $p = $this->pratiche->perId($id);
        $m = $p ? $this->moduli->perId((int) $p['modulo_id']) : null;
        $def = $m ? ($this->iter->definizione($m)[$passo - 1] ?? null) : null;
        if (!$p || !$def || $def['t'] !== 'conferma') {
            return 'Questo passo dell\'iter non è una conferma dei docenti.';
        }
        $gruppi = $this->gruppi((array) json_decode((string) $p['risposte_json'], true), $def);
        if (!$gruppi) {
            return 'Lo studente non ha indicato nessun docente («' . $def['campo'] . '»).';
        }
        $docenti = [];
        foreach (array_keys($gruppi) as $nome) {
            $d = $this->docente($nome);
            if (!$d) {
                return 'Non trovo l\'email di «' . $nome . '» nell\'anagrafe del personale (il nome deve coincidere con uno e un solo docente).';
            }
            $docenti[$nome] = $d;
        }
        $this->conferme->annullaAttese($id);
        $invio = [];
        foreach ($docenti as $nome => $d) {
            $token = bin2hex(random_bytes(20));
            $this->conferme->crea($id, $passo, $nome, $d['email'], $token);
            $invio[] = [trim($d['nome'] . ' ' . $d['cognome']), $d['email'], $token, count($gruppi[$nome])];
        }
        $this->pratiche->impostaPasso($id, $passo);
        $this->storico->evento($id, 'passaggio', 'ufficio', 0, null, 'In attesa della conferma dei docenti: ' . implode(', ', array_column($invio, 0)));
        foreach ($invio as [$nome, $email, $token, $n]) {
            $this->emailConferma($p, $nome, $email, $token, $n, false);
        }
        if ($p2 = $this->pratiche->perId($id)) {
            $this->notifiche->email($p2, 'passaggio', 'Conferma dei docenti');
        }

        return null;
    }

    /**
     * Dopo il rifiuto di un docente lo studente corregge e la richiesta riparte da capo: le conferme vecchie si annullano e i docenti ricevono un nuovo link.
     */
    public function riavvia(int $id): ?string
    {
        $p = $this->pratiche->perId($id);
        $m = $p ? $this->moduli->perId((int) $p['modulo_id']) : null;
        $passo = $m ? $this->passoConferma($m) : null;
        if (!$p || !$passo) {
            return 'Questa pratica non ha un passo di conferma dei docenti.';
        }
        $this->conferme->annullaTutte($id);
        $this->pratiche->riprendi($id);

        return $this->avvia($id, $passo[0]);
    }

    private function emailConferma(array $p, string $nome, string $email, string $token, int $righe, bool $sollecito): void
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $this->notifiche->invia(
            $email,
            ($sollecito ? 'Promemoria · ' : '') . 'Da confermare: ' . $p['modulo_titolo'] . ' – ' . trim($p['cognome'] . ' ' . $p['nome']),
            '<p>Gentile ' . $h($nome) . ',</p><p>' . $h(trim($p['nome'] . ' ' . $p['cognome'])) . ($p['matricola'] !== '' ? ' (matricola ' . $h($p['matricola']) . ')' : '')
            . ' ha chiesto un attestato (<strong>' . $h($p['modulo_titolo']) . '</strong>) e ti ha indicato come docente: ' . ($sollecito ? 'è ancora in attesa la tua conferma' : 'ti chiediamo di confermare')
            . ' ' . ($righe === 1 ? 'una riga' : $righe . ' righe') . ' che ti riguarda' . ($righe === 1 ? '' : 'no') . '.</p>'
            . '<p>Accedi con le tue credenziali di Ateneo, controlla i dati e premi «Confermo» (oppure «Non confermo», indicando il motivo: lo studente può correggere).</p>'
            . $this->notifiche->bottone($this->sito->urlBase() . '/conferma_attestato.php?t=' . $token, 'Apri e conferma')
        );
    }

    /** Rimanda l'email con il link ai docenti che non hanno ancora risposto. Ritorna quante email sono partite. */
    public function reinvia(int $praticaId): int
    {
        $p = $this->pratiche->perId($praticaId);
        $n = 0;
        foreach ($p ? $this->conferme->inAttesa($praticaId) : [] as $c) {
            $this->emailConferma($p, $this->nomeVisibile((string) $c['nome']), (string) $c['email'], (string) $c['token'], count($this->righeDelDocente($p, $c)), true);
            ++$n;
        }

        return $n;
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $this->conferme->perToken($token);
    }

    /** @return list<array<string, mixed>> */
    public function dellaPratica(int $praticaId): array
    {
        return $this->conferme->dellaPratica($praticaId);
    }

    public function richieste(int $praticaId): int
    {
        return $this->conferme->richieste($praticaId);
    }

    /** @return list<array<string, mixed>> */
    public function inAttesaPerEmail(string $email): array
    {
        return $this->conferme->inAttesaPerEmail($email);
    }

    /**
     * Chi entra è il docente a cui è arrivato il link (stessa email).
     *
     * @param array<string, mixed> $c
     * @param array<string, mixed>|null $utente
     */
    public function puoConfermare(array $c, ?array $utente): bool
    {
        $email = strtolower(trim((string) ($utente['email'] ?? '')));

        return !empty($utente['id']) && $email !== '' && $email === strtolower((string) $c['email']);
    }

    /**
     * Le righe dell'attestato che riguardano il docente di questa conferma: [colonna => valore] (per i campi singoli: [etichetta => valore]).
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $c
     * @return list<array<string, string>>
     */
    public function righeDelDocente(array $p, array $c): array
    {
        $m = $this->moduli->perId((int) $p['modulo_id']);
        $pc = $m ? $this->passoConferma($m) : null;
        if (!$pc) {
            return [];
        }
        $risposte = (array) json_decode((string) $p['risposte_json'], true);
        $campo = mb_strtolower(trim((string) $pc[1]['campo']));
        $indici = $this->gruppi($risposte, $pc[1])[(string) $c['nome']] ?? [];
        $out = [];
        foreach ($risposte as $r) {
            if (mb_strtolower(trim((string) ($r['etichetta'] ?? ''))) !== $campo) {
                continue;
            }
            if (($r['tipo'] ?? '') === 'tabella') {
                foreach ($indici as $i) {
                    $riga = (array) (($r['righe'] ?? [])[$i] ?? []);
                    $o = [];
                    foreach ((array) ($r['colonne'] ?? []) as $j => $nomeCol) {
                        $o[(string) $nomeCol] = (string) ($riga[$j] ?? '');
                    }
                    $out[] = $o;
                }
            } else {
                // Campi singoli (esame): si mostrano tutte le altre risposte dell'attestazione
                foreach ($risposte as $x) {
                    if (empty($x['nascosto']) && trim((string) ($x['valore'] ?? '')) !== '' && ($x['tipo'] ?? '') !== 'dichiarazione' && !in_array($x['tipo'] ?? '', ['tabella', 'file'], true)
                        && in_array(mb_strtolower((string) $x['etichetta']), array_map('mb_strtolower', ModelliPratiche::CAMPI_ATTESTAZIONE_ESAME), true)) {
                        $out[0][(string) $x['etichetta']] = (string) $x['valore'];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Il docente conferma: registrati chi, quando e come è entrato. Ritorna un errore o null.
     *
     * @param array<string, mixed> $c
     */
    public function conferma(array $c): ?string
    {
        if ($c['stato'] !== 'attesa') {
            return 'La conferma non è più in attesa.';
        }
        $this->conferme->segna((int) $c['id'], 'confermata', '', $this->accesso());
        $this->storico->evento((int) $c['pratica_id'], 'attivita', 'ufficio', 0, null, 'Attestazione confermata da ' . $this->nomeVisibile((string) $c['nome']) . ' (' . $this->descrizioneAccesso() . ').');

        return null;
    }

    /** Tutte le conferme richieste sono arrivate (nessuna in attesa, nessun rifiuto). */
    public function tutteConfermate(int $praticaId): bool
    {
        $tutte = $this->conferme->dellaPratica($praticaId);

        return $tutte !== [] && count(array_filter($tutte, static fn (array $c): bool => $c['stato'] !== 'confermata')) === 0;
    }

    /**
     * Il docente non conferma: la pratica torna allo studente («integrazione») con il motivo, per correggere; le altre conferme in attesa si annullano.
     *
     * @param array<string, mixed> $c
     */
    public function rifiuta(array $c, string $motivo): ?string
    {
        $motivo = mb_substr(trim($motivo), 0, 500);
        if ($c['stato'] !== 'attesa') {
            return 'La conferma non è più in attesa.';
        }
        if ($motivo === '') {
            return 'Scrivi il motivo: lo studente lo riceve e può correggere.';
        }
        $p = $this->pratiche->perId((int) $c['pratica_id']);
        if (!$p) {
            return 'Pratica non trovata.';
        }
        $nome = $this->nomeVisibile((string) $c['nome']);
        $this->conferme->segna((int) $c['id'], 'rifiutata', $motivo, $this->accesso());
        $this->conferme->annullaAttese((int) $p['id']);
        $testo = $nome . ' non conferma: ' . $motivo;
        $this->pratiche->impostaStato((int) $p['id'], 'integrazione', (string) json_encode(['tipo' => 'documenti', 'conferma' => 1, 'testo' => $motivo, 'da' => $nome, 'il' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
        $this->storico->evento((int) $p['id'], 'stato', 'ufficio', 0, 'integrazione', $testo);
        $p['stato'] = 'integrazione';
        $this->notifiche->email($p, 'stato', $testo . "\nPuoi correggere la richiesta dalla pagina della pratica: i docenti riceveranno di nuovo la conferma.");

        return null;
    }

    /** @return string i metadati dell'accesso del docente (metodo, livello, idp) in JSON */
    private function accesso(): string
    {
        return (string) json_encode(array_intersect_key((array) ($this->sessione->leggi('auth_meta') ?? ['metodo' => 'ateneo']), array_flip(['metodo', 'livello', 'idp', 'spid_code', 'istante'])), JSON_UNESCAPED_UNICODE);
    }

    /** «accesso con credenziali di Ateneo», «accesso con SPID livello 2»… */
    private function descrizioneAccesso(): string
    {
        $a = (array) ($this->sessione->leggi('auth_meta') ?? ['metodo' => 'ateneo']);
        $metodo = (\App\Tutorato\Costanti::METODI_ACCESSO[$a['metodo'] ?? 'ateneo'] ?? '') ?: 'credenziali di Ateneo';

        return 'accesso con ' . $metodo . (!empty($a['livello']) ? ' livello ' . (int) $a['livello'] : '');
    }

    /** Promemoria ai docenti che non hanno ancora risposto: dopo i giorni del passo (o del modulo), al massimo tre volte. Ritorna le email inviate. */
    public function solleciti(): int
    {
        $n = 0;
        foreach ($this->conferme->attese() as $c) {
            $gg = $this->iter->giorniPasso($this->moduli->perId((int) $c['modulo_id']), (int) $c['passo']);
            $p = $this->pratiche->perId((int) $c['pratica_id']);
            if ($gg > 0 && $p && (int) $c['giorni_ultimo'] >= $gg && (int) $c['solleciti'] < self::MAX_SOLLECITI) {
                $this->emailConferma($p, $this->nomeVisibile((string) $c['nome']), (string) $c['email'], (string) $c['token'], count($this->righeDelDocente($p, $c)), true);
                $this->conferme->segnaSollecito((int) $c['id']);
                ++$n;
            }
        }

        return $n;
    }
}
