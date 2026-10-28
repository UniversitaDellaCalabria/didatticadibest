<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Assistenza\Costanti;

/**
 * Didacticus, l'assistente virtuale: dalla domanda di chi scrive prepara la risposta (testo, link, allegati, suggerimenti) usando solo la base di conoscenza
 * scritta dal pannello. Non scrive nulla nella chat e non avvisa nessuno: lo fa ServizioAssistenza, che decide anche quando passare la parola a un operatore.
 *
 * Tipi di risposta: «risposta» (ha trovato la voce giusta), «suggerimenti» (non è sicuro: propone voci vicine), «nessuna» (non sa), «riservata» (la voce c'è ma
 * serve il login), «pagina» (nessuna voce sicura: riporta il brano più pertinente delle pagine di Ateneo con il link, senza modello), «generata» (risposta scritta dal modello linguistico, se collegato, solo con le voci trovate), «saluto», «grazie», «operatore» (la persona ha chiesto una persona vera), «argomento» (elenco delle voci di un argomento).
 */
final class ServizioDidacticus
{
    /** Quanti punti sotto la soglia una voce può stare per essere proposta come «forse intendevi…». */
    private const MARGINE_SUGGERIMENTI = 12;
    /** Una seconda voce si affianca alla prima («vedi anche») se ha almeno questo punteggio. */
    private const MARGINE_ALTRE = 8;
    /** Da questo punteggio le regole si fidano di sé: sotto, il significato della domanda può indicare un'altra voce. */
    private const PUNTEGGIO_SICURO = 75;
    /** Quanto sotto la soglia di risposta può stare la somiglianza per correggere una scelta incerta delle regole. */
    private const MARGINE_SOSTITUZIONE = 0.07;

    /** Punteggio minimo perché una voce vicina sia data come fonte al modello linguistico (se c'è). */
    private const PUNTEGGIO_FONTE = 15;
    /** Quanto testo si dà in tutto al modello linguistico come fonti (più testo = risposta più lenta). */
    private const CARATTERI_FONTI = 3600;
    /** In fondo alle risposte scritte dal modello linguistico: dice che sono automatiche e vanno controllate. */
    /** Punteggio minimo (0-100) del brano di una pagina di Ateneo per rispondere con quello, e da quando prevale sulle voci vicine proposte come «forse intendevi…». */
    private const PUNTEGGIO_PAGINA = 70;
    private const PUNTEGGIO_PAGINA_FORTE = 85;
    /** Lunghezza massima del brano riportato nella chat. */
    private const CARATTERI_BRANO = 600;
    /** In fondo alle risposte con un brano di pagina: non è una voce scritta dal Dipartimento, va controllato sulla pagina. */
    public const AVVISO_PAGINA = "\n\n**Testo preso dal sito.** Controlla i dati sulla pagina indicata oppure parla con un operatore.";
    public const AVVISO_GENERATA ="\n\n**Risposta preparata automaticamente.** Controlla i dati sulle pagine indicate oppure parla con un operatore.";

    public function __construct(private VociRepository $voci, private ImpostazioniDidacticus $impostazioni, private ?ModelloLinguistico $modello = null, private ?PagineRepository $pagine = null, private ?ServizioSignificato $significato = null, private ?RicercaSito $sito = null)
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
        // Il significato della domanda (embedding): se le regole non sono sicure, cerca la voce per somiglianza di significato; se è molto simile la sceglie,
        // se lo è abbastanza la propone come «forse intendevi…». Mostra sempre il testo scritto dal Dipartimento.
        // Se le regole sono sicure ma non troppo (punteggio sotto PUNTEGGIO_SICURO) e il significato indica un'altra voce, vince il significato e la voce delle regole
        // resta tra i «vedi anche».
        $vicineSignificato = [];
        $dubbia = $sicura && $migliore['punteggio'] < self::PUNTEGGIO_SICURO;
        if ((!$sicura || $dubbia) && $this->significato !== null && $this->significato->attivo()) {
            $trovate = $this->significato->cerca($domanda, $voci, $argomento);
            if ($trovate) {
                $sig = $this->significato->significato();
                $minimo = $dubbia ? $sig->sogliaRisposta() - self::MARGINE_SOSTITUZIONE : $sig->sogliaRisposta();
                if ($dubbia && (int) $trovate[0]['voce']['id'] === (int) $migliore['voce']['id']) {
                    // le due strade sono d'accordo: nessun cambiamento
                } elseif ($trovate[0]['somiglianza'] >= $minimo) {
                    $scelta = ['voce' => $trovate[0]['voce'], 'punteggio' => max($soglia, (int) ($migliore['punteggio'] ?? 0)), 'corrispondenze' => ['significato']];
                    $classifica = array_merge([$scelta], array_values(array_filter($classifica, static fn (array $c): bool => (int) $c['voce']['id'] !== (int) $scelta['voce']['id'])));
                    $migliore = $classifica[0];
                    $sicura = (int) $migliore['voce']['solo_login'] === 0 || $conLogin;
                } elseif (!$dubbia) {
                    foreach (array_slice($trovate, 0, 3) as $t) {
                        if ($t['somiglianza'] >= $sig->sogliaSuggerimento() && ((int) $t['voce']['solo_login'] === 0 || $conLogin)) {
                            $vicineSignificato[] = ['t' => (string) $t['voce']['titolo'], 'v' => (int) $t['voce']['id']];
                        }
                    }
                }
            }
        }
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
        $vicine = $vicineSignificato;
        foreach (array_slice($classifica, 0, 3) as $c) {
            if (count($vicine) < 3 && $c['punteggio'] >= $soglia - self::MARGINE_SUGGERIMENTI && ((int) $c['voce']['solo_login'] === 0 || $conLogin) && !in_array((int) $c['voce']['id'], array_column($vicine, 'v'), true)) {
                $vicine[] = ['t' => (string) $c['voce']['titolo'], 'v' => (int) $c['voce']['id']];
            }
        }
        // Nessuna voce sicura: il brano più pertinente delle pagine di Ateneo, con il collegamento (senza modello linguistico). Se ci sono voci vicine da proporre
        // ha la precedenza solo un brano molto pertinente.
        $dallaPagina = $this->dallaPagina($domanda);
        if ($dallaPagina !== null && (!$vicine || $dallaPagina['punteggio'] >= self::PUNTEGGIO_PAGINA_FORTE)) {
            return $dallaPagina['esito'];
        }
        if ($vicine) {
            return self::esito('suggerimenti', 'Non sono sicuro di aver capito. Intendevi una di queste?', ['suggerimenti' => $vicine]);
        }

        return self::esito('nessuna', 'Non ho trovato una risposta a questa domanda. Prova a scriverla in modo diverso, scegli un argomento qui sotto oppure parla con un operatore.', ['argomenti' => true, 'operatore' => true], null, true);
    }

    /**
     * I brani più pertinenti delle pagine di Ateneo: quelle scelte a mano dal pannello e quelle lette dal crawler (a parità di punteggio vincono le prime).
     *
     * @return list<array{titolo: string, url: string, testo: string, punteggio: int}>
     */
    private function braniDiAteneo(string $domanda, int $quanti): array
    {
        $out = [];
        foreach ([fn (): array => $this->pagine !== null ? RicercaPagine::cerca($domanda, $this->pagine->conTesto(), $quanti) : [], fn (): array => $this->sito?->cerca($domanda, $quanti) ?? []] as $cerca) {
            try {
                $out = array_merge($out, $cerca());
            } catch (\Throwable $e) {
                error_log('[Didacticus] pagine di Ateneo non disponibili: ' . $e->getMessage());
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['punteggio'] <=> $a['punteggio']);
        $viste = [];
        $unici = [];
        foreach ($out as $b) {
            $chiave = md5($b['testo']);
            if (!isset($viste[$chiave])) {
                $viste[$chiave] = true;
                $unici[] = $b;
            }
        }

        return array_slice($unici, 0, $quanti);
    }

    /**
     * Il brano più pertinente delle pagine di Ateneo e del Dipartimento, riportato com'è con il collegamento alla pagina; null se non c'è un brano abbastanza pertinente
     * o le pagine non sono disponibili.
     *
     * @return array{punteggio: int, esito: array{tipo: string, testo: string, extra: array<string, mixed>, voce_id: ?int, nessuna: bool, passa: bool}}|null
     */
    private function dallaPagina(string $domanda): ?array
    {
        $brani = $this->braniDiAteneo($domanda, 3);
        if (!$brani || $brani[0]['punteggio'] < self::PUNTEGGIO_PAGINA) {
            return null;
        }
        $primo = $brani[0];
        $brano = trim($primo['testo']);
        if (mb_strlen($brano) > self::CARATTERI_BRANO) {
            $brano = rtrim((string) preg_replace('/\s+\S*$/u', '', mb_substr($brano, 0, self::CARATTERI_BRANO)), " ,;:.") . '…';
        }
        $nome = static fn (array $b): string => $b['titolo'] !== '' ? $b['titolo'] : $b['url'];
        $link = [];
        foreach ($brani as $b) {
            if ($b['punteggio'] >= self::PUNTEGGIO_PAGINA && count($link) < 2) {
                $link[$b['url']] = ['e' => 'Pagina di Ateneo: ' . mb_strimwidth($nome($b), 0, 70, '…'), 'u' => $b['url']];
            }
        }
        $testo = 'Ho cercato nelle pagine del sito di Ateneo e ho trovato questo nella pagina «' . $nome($primo) . "»:\n\n" . $brano . self::AVVISO_PAGINA;

        return ['punteggio' => $primo['punteggio'], 'esito' => self::esito('pagina', $testo, ['feedback' => true, 'operatore' => true, 'link' => array_values($link)])];
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
        foreach ($this->braniDiAteneo($domanda, 2) as $br) {
            if ($budget < 200) {
                break;
            }
            $testo = mb_substr($br['testo'], 0, min($budget, 800));
            $fonti[] = ['titolo' => 'Pagina «' . ($br['titolo'] !== '' ? $br['titolo'] : $br['url']) . '»', 'testo' => $testo];
            $pagine[$br['url']] = $br['titolo'] !== '' ? $br['titolo'] : $br['url'];
            $budget -= mb_strlen($testo);
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

        // Il significato (embedding): stesse regole di risposta(), per mostrarlo nella prova del pannello
        $sign = [];
        if ($this->significato !== null && $this->significato->attivo()) {
            $attive = array_values(array_filter($voci, static fn (array $v): bool => (int) $v['attiva'] === 1));
            $trovate = $this->significato->cerca($domanda, $attive, $argomento);
            if ($trovate) {
                $sg = $this->significato->significato();
                foreach (array_slice($trovate, 0, 5) as $t) {
                    $sign[] = ['id' => (int) $t['voce']['id'], 'titolo' => (string) $t['voce']['titolo'], 'somiglianza' => round($t['somiglianza'], 3)];
                }
                $regoleSicure = $cl && $cl[0]['punteggio'] >= $imp['soglia'];
                $dubbia = $regoleSicure && $cl[0]['punteggio'] < self::PUNTEGGIO_SICURO && (int) $cl[0]['voce']['id'] !== (int) $trovate[0]['voce']['id'];
                if (!in_array($r['tipo'], ['operatore', 'saluto'], true) && (!$regoleSicure || $dubbia)) {
                    if ($trovate[0]['somiglianza'] >= $sg->sogliaRisposta() - ($dubbia ? self::MARGINE_SOSTITUZIONE : 0.0)) {
                        $r = self::esito('risposta', (string) $trovate[0]['voce']['risposta']);
                    } elseif (!$dubbia && $trovate[0]['somiglianza'] >= $sg->sogliaSuggerimento()) {
                        $r = self::esito('suggerimenti', 'Proporrebbe queste voci come «forse intendevi…».');
                    }
                }
            }
        }

        return [
            'tipo' => $r['tipo'], 'testo' => $r['testo'], 'soglia' => $imp['soglia'], 'significato' => $sign,
            'classifica' => array_map(static fn (array $c): array => ['id' => (int) $c['voce']['id'], 'titolo' => (string) $c['voce']['titolo'], 'punteggio' => $c['punteggio'], 'corrispondenze' => $c['corrispondenze'], 'attiva' => true], array_slice($cl, 0, 5)),
        ];
    }
}
