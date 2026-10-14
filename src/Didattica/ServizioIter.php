<?php

declare(strict_types=1);

namespace App\Didattica;

/** L'iter delle pratiche: gli uffici che le ricevono in ordine, a chi sono in carico e come passano dall'uno all'altro. */
final class ServizioIter
{
    public function __construct(
        private ModuloRepository $moduli,
        private UfficioRepository $uffici,
        private ServizioUffici $ufficio,
        private PraticaRepository $pratiche,
        private StoricoPratica $storico,
        private NotifichePratiche $notifiche
    ) {
    }

    /**
     * I passi dell'iter del modulo, normalizzati. Nel JSON un passo è un ufficio (id, chiave o «@cdl» / «@segreteria») oppure un oggetto:
     * {"t": "ufficio", "u": …, "gg": N, "pagamento": 1}, {"t": "persona", "campo": «domanda di tipo docente», "gg": N}, {"t": "direttore", "gg": N};
     * «gg» = giorni dopo i quali si ricorda il passo (0 = quelli del modulo); «pagamento» = passo di attesa del pagamento delle marche da bollo (l'ufficio le
     * carica in Esse3, avvisa lo studente e spunta il pagamento). I passi non validi (ufficio sparito, di smistamento) si saltano.
     *
     * @param array<string, mixed>|null $m
     * @return list<array{t: string, u: int|string|null, campo: string, gg: int, pagamento: bool}>
     */
    public function definizione(?array $m): array
    {
        $out = [];
        foreach (json_decode((string) ($m['iter_json'] ?? ''), true) ?: [] as $x) {
            $t = is_array($x) ? (string) ($x['t'] ?? 'ufficio') : 'ufficio';
            $gg = is_array($x) ? max(0, min(90, (int) ($x['gg'] ?? 0))) : 0;
            if ($t === 'direttore') {
                $out[] = ['t' => 'direttore', 'u' => null, 'campo' => '', 'gg' => $gg, 'pagamento' => false];
                continue;
            }
            if ($t === 'persona') {
                $campo = trim((string) ($x['campo'] ?? ''));
                if ($campo !== '') {
                    $out[] = ['t' => 'persona', 'u' => null, 'campo' => $campo, 'gg' => $gg, 'pagamento' => false];
                }
                continue;
            }
            $u = self::voceUfficio($x);
            $pag = is_array($x) && !empty($x['pagamento']);
            if ($u === '@cdl' || $u === '@segreteria') {
                $out[] = ['t' => 'ufficio', 'u' => $u, 'campo' => '', 'gg' => $gg, 'pagamento' => $pag];
                continue;
            }
            $id = $this->uffici->idDa($u);
            if ($id && !(int) ($this->uffici->tutti()[$id]['smista'] ?? 0)) {
                $out[] = ['t' => 'ufficio', 'u' => $id, 'campo' => '', 'gg' => $gg, 'pagamento' => $pag];
            }
        }

        return $out;
    }

    /** L'ufficio di una voce del JSON dell'iter (la voce è l'ufficio stesso o un oggetto con «u»). */
    public static function voceUfficio(mixed $x): mixed
    {
        return is_array($x) ? ($x['u'] ?? null) : $x;
    }

    /**
     * Il passo dell'iter in cui si trova la pratica è l'attesa del pagamento delle marche da bollo (e la pratica non è conclusa).
     *
     * @param array<string, mixed>|null $m
     * @param array<string, mixed> $p
     */
    public function passoPagamento(?array $m, array $p): bool
    {
        if (in_array($p['stato'] ?? '', ['bozza', 'accolta', 'respinta', 'chiusa'], true) || (int) ($p['passo'] ?? 0) < 1) {
            return false;
        }

        return !empty($this->definizione($m)[(int) $p['passo'] - 1]['pagamento']);
    }

    /**
     * Giorni dopo i quali si ricorda il passo (1 = primo): quelli del passo, altrimenti quelli del modulo (0 = mai).
     *
     * @param array<string, mixed>|null $m
     */
    public function giorniPasso(?array $m, int $passo): int
    {
        $gg = $this->definizione($m)[$passo - 1]['gg'] ?? 0;

        return $gg > 0 ? $gg : (int) ($m['giorni_promemoria'] ?? 0);
    }

    /**
     * Passi dell'iter in ordine: id dell'ufficio, oppure ITER_CDL / ITER_SEGRETERIA (da risolvere con la pratica), ITER_PERSONA (firma di una persona
     * indicata nel modulo) o ITER_DIRETTORE; vuoto = un solo passo generico (0). Con la pratica «@cdl» e «@segreteria» si risolvono nell'ufficio vero
     * (cdl_id, segreteria_id della pratica).
     *
     * @param array<string, mixed>|null $m
     * @param array<string, mixed>|null $p
     * @return list<int>
     */
    public function iter(?array $m, ?array $p = null): array
    {
        $it = [];
        foreach ($this->definizione($m) as $d) {
            $it[] = match (true) {
                $d['t'] === 'persona' => Costanti::ITER_PERSONA,
                $d['t'] === 'direttore' => Costanti::ITER_DIRETTORE,
                $d['u'] === '@cdl' => !empty($p['cdl_id']) ? (int) $p['cdl_id'] : Costanti::ITER_CDL,
                $d['u'] === '@segreteria' => !empty($p['segreteria_id']) ? (int) $p['segreteria_id'] : Costanti::ITER_SEGRETERIA,
                default => (int) $d['u'],
            };
        }

        return $it ?: [0];
    }

    /**
     * Passi mostrati: 0 = ricevuta (da smistare), 1..n = i passi dell'iter, n+1 = conclusa.
     *
     * @param array<string, mixed>|null $m
     * @param array<string, mixed>|null $p
     * @return array<int, string>
     */
    public function passi(?array $m, ?array $p = null): array
    {
        $out = [0 => 'Ricevuta e da smistare'];
        $def = $this->definizione($m);
        foreach ($this->iter($m, $p) as $i => $id) {
            $out[$i + 1] = match (true) {
                $id === Costanti::ITER_PERSONA => 'Firma: ' . ($def[$i]['campo'] ?? 'persona indicata'),
                $id === Costanti::ITER_DIRETTORE => 'Firma del Direttore',
                $id === Costanti::ITER_CDL => 'Ufficio del corso di studio',
                $id === Costanti::ITER_SEGRETERIA => 'Segreteria studenti',
                $id > 0 => (string) ($this->uffici->tutti()[$id]['nome'] ?? 'Ufficio didattico'),
                default => 'Ufficio didattico',
            };
            if (!empty($def[$i]['pagamento'])) {
                $out[$i + 1] .= ' · marche da bollo';
            }
        }
        // Pratiche che vanno prima al protocollo: non c'è uno smistamento
        $primo = $this->iter($m, $p)[0] ?? 0;
        if ($primo > 0 && ($this->uffici->tutti()[$primo]['tipo'] ?? '') === 'protocollo') {
            $out[0] = 'Inviata';
        }

        return $out;
    }

    /**
     * Operatori per il passo: prima chi è nell'ufficio del passo (e, negli uffici che seguono i corsi, chi segue il corso della pratica).
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed>|null $m
     * @return list<array<string, mixed>>
     */
    public function operatoriSuggeriti(array $p, ?array $m, int $passo): array
    {
        $uff = max(0, $this->iter($m, $p)[$passo - 1] ?? 0);
        $corso = '';
        foreach (json_decode((string) $p['risposte_json'], true) ?: [] as $r) {
            if (($r['tipo'] ?? '') === 'corso_studio' && $r['valore'] !== '') {
                $corso = $r['valore'];
                break;
            }
        }
        $punti = function ($o) use ($uff, $corso) {
            $s = $uff && in_array($uff, $o['_uffici'], true) ? 10 : 0;
            if ($corso !== '' && in_array($corso, json_decode((string) ($o['corsi'] ?? ''), true) ?: [], true)) {
                $s += 5;
            }

            return $s;
        };
        $ops = $this->ufficio->operatori();
        usort($ops, fn ($a, $b) => $punti($b) <=> $punti($a) ?: strcmp($a['nominativo'], $b['nominativo']));

        return array_map(fn ($o) => $o + ['_consigliato' => $punti($o) >= 10], $ops);
    }

    /**
     * Smista o passa la pratica all'operatore $opId al passo $passo dell'iter; lo studente vede il passaggio, la nota resta interna.
     * Ritorna un errore o null.
     */
    public function assegna(int $id, int $opId, int $passo, string $nota, int $uid, string $autoreNome = ''): ?string
    {
        $p = $this->pratiche->perId($id);
        $o = $this->ufficio->operatore($opId);
        if (!$p || !$o) {
            return "Scegli la pratica e l'operatore.";
        }
        $m = $this->moduli->perId((int) $p['modulo_id']);
        $passo = max(1, min(count($this->iter($m, $p)), $passo));
        $stato = in_array($p['stato'], ['inviata'], true) ? 'in_lavorazione' : $p['stato'];
        // Con più uffici vale quello del passo dell'iter, se la persona lo ha; altrimenti il suo principale
        $uffPasso = max(0, $this->iter($m, $p)[$passo - 1] ?? 0);
        $uffOp = ($uffPasso && in_array($uffPasso, $o['_uffici'], true)) ? $uffPasso : ((int) $o['ufficio_id'] ?: null);
        $this->pratiche->assegna($id, $opId, $passo, $stato, $uffOp);
        // Chi l'ha avuta resta tra gli operatori della pratica: continua a vederla e a integrarla
        $this->pratiche->ricordaOperatore($id, $opId, $passo, true);
        if (!empty($p['assegnata_a'])) {
            $this->pratiche->ricordaOperatore($id, (int) $p['assegnata_a'], (int) $p['passo'], false);
        }
        $this->storico->evento($id, 'passaggio', 'ufficio', $uid, $stato !== $p['stato'] ? $stato : null, 'In carico a: ' . $this->passi($m, $p)[$passo] . ' – ' . $o['nominativo'], null, null, false, $autoreNome);
        if (trim($nota) !== '') {
            $this->storico->evento($id, 'messaggio', 'ufficio', $uid, null, mb_substr(trim($nota), 0, 3000), null, null, true, $autoreNome);
        }
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $this->notifiche->invia(
            $o['email'],
            'Pratica assegnata: ' . $p['modulo_titolo'] . ' – ' . trim($p['cognome'] . ' ' . $p['nome']),
            '<p>Gentile ' . $h($o['nominativo']) . ',</p><p>ti è stata assegnata la pratica <strong>' . $h($p['codice']) . '</strong> (' . $h($p['modulo_titolo']) . ') come <strong>' . $h($this->passi($m, $p)[$passo]) . '</strong>' . ($autoreNome !== '' ? ' da ' . $h($autoreNome) : '') . '.</p>'
            . (trim($nota) !== '' ? "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($nota)) . '</p>' : '')
            . $this->notifiche->bottone($this->notifiche->linkPannello($id), 'Apri la pratica')
        );
        if ((int) ($p['assegnata_a'] ?? 0) !== $opId || (int) $p['passo'] !== $passo) {
            $this->notifiche->email($p, 'passaggio', $this->passi($m, $p)[$passo]);
        }

        return null;
    }

    /**
     * Operatori che hanno avuto (o hanno) in carico la pratica: id.
     *
     * @return list<int>
     */
    public function operatoriPratica(int $id): array
    {
        return $this->pratiche->operatori($id);
    }

    /**
     * Chi ha in carico la pratica chiede un'integrazione a un operatore che l'ha avuta prima (nota interna + email a quell'operatore).
     */
    public function richiediAOperatore(int $id, int $opId, string $testo, int $uid, string $autoreNome = ''): ?string
    {
        $p = $this->pratiche->perId($id);
        $o = $this->ufficio->operatore($opId);
        $testo = mb_substr(trim($testo), 0, 3000);
        if (!$p || !$o) {
            return "Scegli l'operatore.";
        }
        if ($testo === '') {
            return 'Scrivi cosa serve.';
        }
        $this->storico->evento($id, 'richiesta', 'ufficio', $uid, null, 'Richiesta a ' . $o['nominativo'] . ': ' . $testo, null, null, true, $autoreNome);
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $this->notifiche->invia(
            $o['email'],
            'Integrazione richiesta sulla pratica ' . $p['codice'] . ' – ' . trim($p['cognome'] . ' ' . $p['nome']),
            '<p>Gentile ' . $h($o['nominativo']) . ',</p><p>' . $h($autoreNome ?: "L'ufficio") . ' ti chiede di integrare la pratica <strong>' . $h($p['codice']) . '</strong> (' . $h($p['modulo_titolo']) . '), che hai avuto in carico:</p>'
            . "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($testo)) . '</p><p>Aggiungi i documenti come attività o nota nella pratica.</p>'
            . $this->notifiche->bottone($this->notifiche->linkPannello($id), 'Apri la pratica')
        );

        return null;
    }

    /**
     * Iter della pratica a passi (pannello e Area personale): fatti, attuale con chi l'ha in carico, da fare, conclusione.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed>|null $m
     */
    public function html(array $p, ?array $m): string
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $passi = $this->passi($m, $p);
        $conclusa = in_array($p['stato'], ['accolta', 'respinta', 'chiusa'], true);
        $att = $conclusa ? count($passi) : (int) $p['passo'];
        $o = !empty($p['assegnata_a']) ? $this->ufficio->operatore((int) $p['assegnata_a']) : null;
        // Pratica in un ufficio senza una persona assegnata: si mostra l'ufficio
        if (!$o && !empty($p['ufficio_id']) && ($uAtt = $this->uffici->tutti()[(int) $p['ufficio_id']] ?? null)) {
            $o = ['nominativo' => $uAtt['nome']];
        }
        $passi[count($passi)] = $conclusa ? Costanti::STATI_PRATICA[$p['stato']][0] : 'Conclusa';
        $out = '<ol class="list-unstyled d-flex flex-wrap gap-1 mb-0 small" aria-label="Iter della pratica">';
        foreach ($passi as $i => $nome) {
            $stile = $i < $att ? 'background:#dcfce7;color:#166534;' : ($i === $att ? 'background:#047857;color:#fff;' : 'background:#f1f5f9;color:#64748b;');
            $ico = $i < $att ? 'fa-check' : ($i === $att ? 'fa-location-dot' : 'fa-circle');
            $chi = ($i === $att && $o && !$conclusa) ? '<span class="d-block fw-normal" style="font-size:.7rem;">' . $h($o['nominativo']) . '</span>' : '';
            $out .= '<li class="px-2 py-1 rounded fw-bold" style="' . $stile . '"' . ($i === $att ? ' aria-current="step"' : '') . '><i class="fa ' . $ico . ' me-1" aria-hidden="true"></i>' . $h($nome) . $chi . '</li>';
            if ($i < count($passi) - 1) {
                $out .= '<li class="align-self-center text-secondary" aria-hidden="true">›</li>';
            }
        }

        return $out . '</ol>';
    }

    /**
     * I passi dell'iter nella forma della linea dell'iter del pannello (Pannello::iter): fatti, passo corrente con chi ha la pratica, da fare.
     * Pratica conclusa: tutti fatti, l'ultimo porta l'esito.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed>|null $m
     * @return list<array{nome: string, stato: string, nota: string}>
     */
    public function passiIter(array $p, ?array $m): array
    {
        $passi = $this->passi($m, $p);
        $conclusa = in_array($p['stato'], ['accolta', 'respinta', 'chiusa'], true);
        $att = (int) $p['passo'];
        $o = !empty($p['assegnata_a']) ? $this->ufficio->operatore((int) $p['assegnata_a']) : null;
        if (!$o && !empty($p['ufficio_id']) && ($uAtt = $this->uffici->tutti()[(int) $p['ufficio_id']] ?? null)) {
            $o = ['nominativo' => $uAtt['nome']];
        }
        $passi[count($passi)] = $conclusa ? Costanti::STATI_PRATICA[$p['stato']][0] : 'Conclusa';
        $out = [];
        foreach ($passi as $i => $nome) {
            $out[] = [
                'nome' => (string) $nome,
                'stato' => $conclusa || $i < $att ? 'fatto' : ($i === $att ? 'qui' : ''),
                'nota' => ($i === $att && $o && !$conclusa) ? (string) $o['nominativo'] : '',
            ];
        }

        return $out;
    }

    /** Etichetta colorata dello stato della pratica. */
    public static function badge(string $stato): string
    {
        [$n, $col, $ico] = Costanti::STATI_PRATICA[$stato] ?? [$stato, '#64748b', 'fa-circle'];

        return '<span class="badge" style="background:' . $col . ';"><i class="fa ' . $ico . ' me-1" aria-hidden="true"></i>' . htmlspecialchars($n) . '</span>';
    }
}
