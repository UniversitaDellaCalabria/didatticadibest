<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Assistenza\Costanti;

/**
 * Didacticus, l'assistente virtuale: dalla domanda di chi scrive prepara la risposta (testo, link, allegati, suggerimenti) usando solo la base di conoscenza
 * scritta dal pannello. Non scrive nulla nella chat e non avvisa nessuno: lo fa ServizioAssistenza, che decide anche quando passare la parola a un operatore.
 *
 * Tipi di risposta: «risposta» (ha trovato la voce giusta), «suggerimenti» (non è sicuro: propone voci vicine), «nessuna» (non sa), «riservata» (la voce c'è ma
 * serve il login), «generata» (risposta scritta dal modello linguistico, se collegato, solo con le voci trovate), «saluto», «grazie», «operatore» (la persona ha chiesto una persona vera), «argomento» (elenco delle voci di un argomento).
 */
final class ServizioDidacticus
{
    /** Quanti punti sotto la soglia una voce può stare per essere proposta come «forse intendevi…». */
    private const MARGINE_SUGGERIMENTI = 12;
    /** Una seconda voce si affianca alla prima («vedi anche») se ha almeno questo punteggio. */
    private const MARGINE_ALTRE = 8;

    /** Punteggio minimo perché una voce vicina sia data come fonte al modello linguistico (se c'è). */
    private const PUNTEGGIO_FONTE = 15;
    /** Quanto testo si dà in tutto al modello linguistico come fonti (più testo = risposta più lenta). */
    private const CARATTERI_FONTI = 3600;
    /** In fondo alle risposte scritte dal modello linguistico: dice che sono automatiche e vanno controllate. */
    public const AVVISO_GENERATA = "\n\n**Risposta preparata automaticamente.** Controlla i dati sulle pagine indicate oppure parla con un operatore.";

    public function __construct(private VociRepository $voci, private ImpostazioniDidacticus $impostazioni, private ?ModelloLinguistico $modello = null, private ?PagineRepository $pagine = null)
    {
    }

    public function attivo(): bool
    {
        return $this->impostazioni->leggi()['attivo'] && $this->voci->contaAttive() > 0;
    }

    public function saluto(): string
    {
        return $this->impostazioni->leggi()['saluto'];
    }

    /** @return array{tipo: string, testo: string, extra: array<string, mixed>, voce_id: ?int, nessuna: bool, passa: bool} */
    private static function esito(string $tipo, string $testo, array $extra = [], ?int $voceId = null, bool $nessuna = false, bool $passa = false): array
    {
        return ['tipo' => $tipo, 'testo' => $testo, 'extra' => $extra, 'voce_id' => $voceId, 'nessuna' => $nessuna, 'passa' => $passa];
    }

    /**
     * La risposta a una domanda.
     *
     * @return array{tipo: string, testo: string, extra: array<string, mixed>, voce_id: ?int, nessuna: bool, passa: bool}
     */
    public function risposta(string $domanda, ?string $argomento, bool $conLogin, bool $primoMessaggio = false): array
    {
        if (Motore::chiedeOperatore($domanda)) {
            return self::esito('operatore', 'Certo, ti metto in contatto con una persona dell\'ufficio.', [], null, false, true);
        }
        if (Motore::eSaluto($domanda)) {
            return self::esito('saluto', $primoMessaggio ? $this->saluto() : 'Ciao! Dimmi pure di cosa hai bisogno.', ['argomenti' => true]);
        }
        if (Motore::eRingraziamento($domanda)) {
            return self::esito('grazie', 'Prego! Se ti serve altro scrivimi pure; se hai risolto puoi chiudere la chat con «Ho risolto».');
        }
        $soglia = $this->impostazioni->leggi()['soglia'];
        $voci = $this->voci->attive();
        $classifica = (new Motore($voci))->classifica($domanda, $argomento);
        $migliore = $classifica[0] ?? null;

        $sicura = $migliore !== null && $migliore['punteggio'] >= $soglia && ((int) $migliore['voce']['solo_login'] === 0 || $conLogin);
        if ($this->modello !== null && $this->modello->attivo() && (!$sicura || $this->modello->modalita() === ModelloLinguistico::MODALITA_SEMPRE)) {
            $g = $this->generata($domanda, $classifica, $conLogin);
            if ($g !== null) {
                return $g;
            }
        }
        if ($migliore !== null && $migliore['punteggio'] >= $soglia) {
            if ((int) $migliore['voce']['solo_login'] === 1 && !$conLogin) {
                return self::esito('riservata', 'Questa informazione è riservata a chi ha effettuato l\'accesso: entra con le tue credenziali Unical e riscrivimi la domanda.', [
                    'link' => [['e' => 'Accedi al portale', 'u' => 'saml_login.php?redirect=' . rawurlencode('assistenza.php')]],
                ], (int) $migliore['voce']['id']);
            }
            $altre = [];
            foreach (array_slice($classifica, 1, 3) as $c) {
                if ($c['punteggio'] >= $soglia && $migliore['punteggio'] - $c['punteggio'] <= self::MARGINE_ALTRE && ((int) $c['voce']['solo_login'] === 0 || $conLogin)) {
                    $altre[] = ['t' => (string) $c['voce']['titolo'], 'v' => (int) $c['voce']['id']];
                }
            }

            return $this->daVoce($migliore['voce'], $altre);
        }
        $vicine = [];
        foreach (array_slice($classifica, 0, 3) as $c) {
            if ($c['punteggio'] >= $soglia - self::MARGINE_SUGGERIMENTI && ((int) $c['voce']['solo_login'] === 0 || $conLogin)) {
                $vicine[] = ['t' => (string) $c['voce']['titolo'], 'v' => (int) $c['voce']['id']];
            }
        }
        if ($vicine) {
            return self::esito('suggerimenti', 'Non sono sicuro di aver capito. Intendevi una di queste?', ['suggerimenti' => $vicine]);
        }

        return self::esito('nessuna', 'Non ho trovato una risposta a questa domanda. Prova a scriverla in modo diverso, scegli un argomento qui sotto oppure parla con un operatore.', ['argomenti' => true, 'operatore' => true], null, true);
    }

    /**
     * La risposta scritta dal modello linguistico con le voci più vicine come uniche fonti; null se non ci sono fonti o il modello non sa rispondere
     * (allora si continua con il comportamento a regole).
     *
     * @param list<array{voce: array<string, mixed>, punteggio: int, corrispondenze: list<string>}> $classifica
     * @return array{tipo: string, testo: string, extra: array<string, mixed>, voce_id: ?int, nessuna: bool, passa: bool}|null
     */
    private function generata(string $domanda, array $classifica, bool $conLogin): ?array
    {
        $fonti = [];
        $voci = [];
        $budget = self::CARATTERI_FONTI;
        foreach ($classifica as $c) {
            if ($c['punteggio'] < self::PUNTEGGIO_FONTE || count($voci) >= 3 || $budget < 300) {
                break;
            }
            if ((int) $c['voce']['solo_login'] === 1 && !$conLogin) {
                continue;
            }
            $testo = mb_substr((string) $c['voce']['risposta'], 0, min($budget, 1100));
            $fonti[] = ['titolo' => (string) $c['voce']['titolo'], 'testo' => $testo];
            $voci[] = $c['voce'];
            $budget -= mb_strlen($testo);
        }
        // I brani delle pagine di Ateneo e del Dipartimento scelte dal pannello, con il collegamento alla pagina
        $pagine = [];
        if ($this->pagine !== null) {
            try {
                foreach (RicercaPagine::cerca($domanda, $this->pagine->conTesto(), 2) as $br) {
                    if ($budget < 200) {
                        break;
                    }
                    $testo = mb_substr($br['testo'], 0, min($budget, 800));
                    $fonti[] = ['titolo' => 'Pagina «' . ($br['titolo'] !== '' ? $br['titolo'] : $br['url']) . '»', 'testo' => $testo];
                    $pagine[$br['url']] = $br['titolo'] !== '' ? $br['titolo'] : $br['url'];
                    $budget -= mb_strlen($testo);
                }
            } catch (\Throwable $e) {
                error_log('[Didacticus] pagine di Ateneo non disponibili: ' . $e->getMessage());
            }
        }
        $testo = $fonti ? $this->modello->rispondi($domanda, $fonti) : null;
        if ($testo === null) {
            return null;
        }
        if ($voci) {
            $this->voci->registraUso((int) $voci[0]['id']);
        }
        $link = [];
        foreach ($voci as $v) {
            foreach (VociRepository::link($v) as $l) {
                $link[$l['u']] = $l;
            }
        }
        foreach ($pagine as $url => $titolo) {
            $link[$url] = ['e' => 'Pagina di Ateneo: ' . mb_strimwidth($titolo, 0, 70, '…'), 'u' => $url];
        }
        $extra = ['feedback' => true, 'operatore' => true, 'generata' => true];
        if ($link) {
            $extra['link'] = array_slice(array_values($link), 0, 4);
        }

        return self::esito('generata', $testo . self::AVVISO_GENERATA, $extra, $voci ? (int) $voci[0]['id'] : null);
    }

    /**
     * La risposta di una voce scelta direttamente (da un suggerimento).
     *
     * @return array{tipo: string, testo: string, extra: array<string, mixed>, voce_id: ?int, nessuna: bool, passa: bool}|null null se la voce non c'è o non è attiva
     */
    public function rispostaDiVoce(int $voceId, bool $conLogin): ?array
    {
        $v = $this->voci->perId($voceId);
        if ($v === null || (int) $v['attiva'] !== 1) {
            return null;
        }
        if ((int) $v['solo_login'] === 1 && !$conLogin) {
            return self::esito('riservata', 'Questa informazione è riservata a chi ha effettuato l\'accesso.', ['link' => [['e' => 'Accedi al portale', 'u' => 'saml_login.php?redirect=' . rawurlencode('assistenza.php')]]], $voceId);
        }

        return $this->daVoce($v, []);
    }

    /**
     * Le voci di un argomento, come elenco di suggerimenti.
     *
     * @return array{tipo: string, testo: string, extra: array<string, mixed>, voce_id: ?int, nessuna: bool, passa: bool}
     */
    public function rispostaArgomento(string $argomento, bool $conLogin): array
    {
        $argomento = Costanti::argomentoValido($argomento);
        $elenco = [];
        foreach ($this->voci->attive() as $v) {
            if ($v['argomento'] === $argomento && ((int) $v['solo_login'] === 0 || $conLogin)) {
                $elenco[] = ['t' => (string) $v['titolo'], 'v' => (int) $v['id']];
            }
        }
        if (!$elenco) {
            return self::esito('nessuna', 'Su «' . Costanti::nomeArgomento($argomento) . '» non ho ancora risposte pronte: scrivimi la tua domanda oppure parla con un operatore.', ['operatore' => true], null, false);
        }

        return self::esito('argomento', 'Ecco cosa so su «' . Costanti::nomeArgomento($argomento) . '». Scegli una domanda o scrivimi la tua:', ['suggerimenti' => array_slice($elenco, 0, 8), 'operatore' => true]);
    }

    /**
     * @param array<string, string|null> $voce
     * @param list<array{t: string, v: int}> $altre
     * @return array{tipo: string, testo: string, extra: array<string, mixed>, voce_id: ?int, nessuna: bool, passa: bool}
     */
    private function daVoce(array $voce, array $altre): array
    {
        $this->voci->registraUso((int) $voce['id']);
        $extra = ['feedback' => true];
        if ($l = VociRepository::link($voce)) {
            $extra['link'] = $l;
        }
        $file = [];
        foreach (VociRepository::allegati($voce) as $i => $a) {
            $file[] = ['n' => $a['n'], 'v' => (int) $voce['id'], 'i' => $i];
        }
        if ($file) {
            $extra['file'] = $file;
        }
        if ($altre) {
            $extra['suggerimenti'] = $altre;
            $extra['suggerimenti_titolo'] = 'Vedi anche:';
        }
        if ((int) $voce['proponi_operatore'] === 1) {
            $extra['operatore'] = true;
        }

        return self::esito('risposta', (string) $voce['risposta'], $extra, (int) $voce['id']);
    }

    public function titolo(int $voceId): string
    {
        return (string) ($this->voci->perId($voceId)['titolo'] ?? '');
    }

    public function registraEsito(int $voceId, bool $utile): void
    {
        $this->voci->registraEsito($voceId, $utile);
    }

    /**
     * Per la pagina di prova del pannello: cosa risponderebbe e le cinque voci più vicine con il loro punteggio.
     *
     * @return array{tipo: string, testo: string, soglia: int, classifica: list<array{id: int, titolo: string, punteggio: int, corrispondenze: list<string>, attiva: bool}>}
     */
    public function prova(string $domanda, ?string $argomento): array
    {
        $imp = $this->impostazioni->leggi();
        $voci = $this->voci->tutte();
        $cl = (new Motore(array_values(array_filter($voci, static fn (array $v): bool => (int) $v['attiva'] === 1))))->classifica($domanda, $argomento);
        $r = Motore::chiedeOperatore($domanda) ? self::esito('operatore', 'Ti metterebbe in contatto con un operatore.') : (Motore::eSaluto($domanda) ? self::esito('saluto', 'Saluto.') : null);
        if ($r === null) {
            $r = $cl && $cl[0]['punteggio'] >= $imp['soglia'] ? self::esito('risposta', (string) $cl[0]['voce']['risposta'])
                : ($cl && $cl[0]['punteggio'] >= $imp['soglia'] - self::MARGINE_SUGGERIMENTI ? self::esito('suggerimenti', 'Proporrebbe queste voci come «forse intendevi…».') : self::esito('nessuna', 'Non saprebbe rispondere (la domanda finirebbe tra quelle senza risposta).'));
        }

        return [
            'tipo' => $r['tipo'], 'testo' => $r['testo'], 'soglia' => $imp['soglia'],
            'classifica' => array_map(static fn (array $c): array => ['id' => (int) $c['voce']['id'], 'titolo' => (string) $c['voce']['titolo'], 'punteggio' => $c['punteggio'], 'corrispondenze' => $c['corrispondenze'], 'attiva' => true], array_slice($cl, 0, 5)),
        ];
    }
}
