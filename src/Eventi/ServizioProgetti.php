<?php

declare(strict_types=1);

namespace App\Eventi;

/** Schede dei progetti: destinazione (rimando a un'altra pagina) e edizioni con posti, coda e scuola assegnata. */
final class ServizioProgetti
{
    /** @var array<string, string>|null [slug in minuscolo => titolo] delle aree, letto una volta per richiesta */
    private ?array $cacheAree = null;

    public function __construct(
        private EventoRepository $eventi,
        private AreaRepository $areeRepo,
        private RegoleIscrizioni $regole,
    ) {
    }

    /**
     * Progetto che rimanda a un'altra pagina (es. OpenLab): la card resta nell'elenco dei progetti ma «Dettagli» porta lì.
     * progetti_dettagli.destinazione = slug di un'area del portale oppure indirizzo http(s).
     *
     * @return array{url: string, nome: string, esterno: bool}|null null se il progetto è normale (anche area eliminata o indirizzo non valido)
     */
    public function destinazione(?array $d): ?array
    {
        $v = trim((string) ($d['destinazione'] ?? ''));
        if ($v === '') {
            return null;
        }
        $this->cacheAree ??= $this->areeRepo->titoliPerSlug();
        $slug = strtolower((string) preg_replace('/\.php$/i', '', $v));
        if (isset($this->cacheAree[$slug])) {
            return ['url' => $slug . '.php', 'nome' => $this->cacheAree[$slug] !== '' ? $this->cacheAree[$slug] : $slug, 'esterno' => false];
        }
        if (preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL)) {
            return ['url' => $v, 'nome' => (string) preg_replace('/^www\./i', '', (string) parse_url($v, PHP_URL_HOST)), 'esterno' => true];
        }

        return null;
    }

    /**
     * Edizioni (repliche) di un progetto: ogni turno è un'edizione da 1 scuola con la sua lista d'attesa.
     * $mie = [turno_id => stato] dell'utente corrente. Ritorna le edizioni con posti, coda e scuola assegnata, lo stato
     * complessivo (su un turno «riassuntivo») e l'eventuale iscrizione dell'utente.
     *
     * @return array<string, mixed>
     */
    public function infoEdizioni(?array $d, array $turni, array $mie = []): array
    {
        $edizioni = [];
        $occupate = 0;
        $posti = 0;
        $mio = null;
        $mioTurno = null;
        $prossimaApertura = null;
        foreach (array_values($turni) as $i => $t) {
            $tId = (int) $t['id'];
            $occ = $this->regole->postiOccupati($tId);
            $max = max(1, (int) $t['max_posti']);
            $stEd = Progetti::stato($d, $t + ['abilita_lista_attesa' => 1], $occ);
            $lim = $this->regole->limitiPartecipanti($d, $t);
            $edizioni[] = [
                't' => $t, 'numero' => $i + 1,
                'etichetta' => trim((string) ($t['nome_turno'] ?? '')) !== '' ? $t['nome_turno'] : 'Edizione ' . ($i + 1),
                'occ' => $occ, 'libera' => $occ < $max,
                'attesa' => $this->eventi->inAttesa($tId),
                'assegnata' => $this->eventi->primaPrenotazioneAttiva($tId),
                'mio' => $mie[$tId] ?? null,
                // Ogni edizione ha la sua finestra di iscrizione e i suoi limiti di partecipanti
                'stato' => $stEd, 'min' => $lim['min'], 'max' => $lim['max'],
            ];
            // Posti liberi contati solo sulle edizioni con iscrizioni aperte o ancora da aprire
            if (in_array($stEd['codice'], ['aperte', 'attesa', 'arrivo'], true)) {
                $posti += $max;
                $occupate += min($max, $occ);
            }
            if (isset($mie[$tId]) && $mio === null) {
                $mio = $mie[$tId];
                $mioTurno = $tId;
            }
            if ($stEd['codice'] === 'arrivo' && ($prossimaApertura === null || $t['data_apertura'] < $prossimaApertura)) {
                $prossimaApertura = $t['data_apertura'];
            }
        }
        // Stato del progetto: il più favorevole tra le edizioni (una aperta basta per «Iscrizioni aperte»)
        $stato = $edizioni ? $edizioni[0]['stato'] : Progetti::stato($d, null, 0);
        foreach ($edizioni as $ed) {
            if ($ed['stato']['ordine'] < $stato['ordine']) {
                $stato = $ed['stato'];
            }
        }

        return ['edizioni' => $edizioni, 'stato' => $stato, 'liberi' => $posti - $occupate,
            'mio' => $mio, 'mio_turno' => $mioTurno, 'prossima_apertura' => $prossimaApertura];
    }
}
