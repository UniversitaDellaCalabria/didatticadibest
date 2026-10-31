<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Orologio;

/**
 * Catalogo di Ateneo per i moduli della Didattica: corsi di studio di tutti i dipartimenti per anno di offerta (API cds,
 * aggiornati con l'anagrafe ogni settimana) e insegnamenti di un corso in un anno di offerta (API activities), scaricati la
 * prima volta che uno studente li cerca e rinnovati ogni 30 giorni. Spostato da inc/catalogo_ateneo.php, dove restano le facciate.
 */
final class ServizioCatalogo
{
    public function __construct(
        private CatalogoRepository $catalogo,
        private CorsoRepository $corsi,
        private InsegnamentoRepository $insegnamenti,
        private ClientApiAteneo $api,
        private Orologio $orologio,
    ) {
    }

    /** Anno accademico in corso come lo usano le API (2026 = 2026/2027): da settembre quello nuovo */
    public function annoCorrente(): int
    {
        return Anagrafe::annoAccademico($this->orologio->adesso());
    }

    /** Corsi di studio di tutto l'Ateneo (API cds senza filtri), anni di offerta dagli ultimi 7 anni. Ritorna quanti, null se le API non rispondono. */
    public function sincronizzaCorsi(): ?int
    {
        $cds = $this->api->tutte('cds/');
        if ($cds === null) {
            return null;
        }
        $min = $this->annoCorrente() - 7;
        $n = 0;
        foreach ($cds as $c) {
            $cod = trim((string) ($c['CdSCod'] ?? ''));
            $anno = (int) ($c['AcademicYear'] ?? 0);
            if ($cod === '' || strlen($cod) > 20 || $anno < $min) {
                continue;
            }
            $ok = $this->catalogo->salvaCorso([
                'codice' => $cod, 'anno' => $anno,
                'nome' => mb_substr(Testi::maiuscoleCorso((string) ($c['CdSName'] ?? '')), 0, 255),
                'tipo' => mb_substr((string) ($c['CourseType'] ?? ''), 0, 10),
                'tipo_descrizione' => mb_substr((string) ($c['CourseTypeDescription'] ?? ''), 0, 100),
                'dipartimento_cod' => mb_substr((string) ($c['DepartmentCod'] ?? ''), 0, 20),
                'dipartimento' => mb_substr(trim((string) ($c['DepartmentName'] ?? '')), 0, 255),
            ]);
            if ($ok) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Tipi di corso proposti: quelli presenti nel catalogo, altrimenti quelli dell'anagrafe dei corsi del Dipartimento.
     *
     * @return array<string, string> tipo => nome
     */
    public function tipi(): array
    {
        $out = [];
        foreach ($this->catalogo->tipi() as $x) {
            $out[$x['tipo']] = Anagrafe::TIPI_CORSO_ATENEO[$x['tipo']] ?? ($x['tipo_descrizione'] ?: $x['tipo']);
        }
        if (!$out) {
            foreach ($this->corsi->tipiPresenti() as $x) {
                $out[$x['tipo']] = Anagrafe::TIPI_CORSO_ATENEO[$x['tipo']] ?? ($x['tipo_descrizione'] ?: $x['tipo']);
            }
        }
        $ord = array_flip(array_keys(Anagrafe::TIPI_CORSO_ATENEO));
        uksort($out, static fn ($a, $b): int => ($ord[$a] ?? 9) <=> ($ord[$b] ?? 9) ?: strcmp((string) $a, (string) $b));

        return $out;
    }

    /**
     * Corsi di studio di un tipo: [['codice', 'nome', 'dipartimento', 'anni' => [2025, 2024…]]], dal catalogo o dall'anagrafe del Dipartimento.
     * $aa > 0: solo i corsi offerti in quell'anno accademico (uno per nome: l'Ateneo può cambiare il codice negli anni).
     *
     * @return list<array<string, mixed>>
     */
    public function corsi(string $tipo, int $aa = 0): array
    {
        $out = [];
        if ($aa > 0) {
            foreach ($this->catalogo->corsiDelTipoNell($tipo, $aa) as $x) {
                $k = mb_strtolower($x['nome'] . '|' . $x['dipartimento']);
                if (!isset($out[$k])) {
                    $out[$k] = ['codice' => $x['codice'], 'nome' => $x['nome'], 'dipartimento' => (string) $x['dipartimento'], 'anni' => [$aa]];
                }
            }
            if (!$out) {
                foreach ($this->corsi->presentiDiTipo($tipo) as $x) {
                    $out[$x['codice']] = ['codice' => $x['codice'], 'nome' => $x['nome'], 'dipartimento' => '', 'anni' => [$aa]];
                }
            }

            return array_values($out);
        }
        foreach ($this->catalogo->corsiDelTipo($tipo) as $x) {
            $k = $x['codice'];
            if (isset($out[$k])) {
                $out[$k]['anni'] = array_values(array_unique(array_merge($out[$k]['anni'], array_map('intval', explode(',', (string) $x['anni'])))));
                rsort($out[$k]['anni']);
                continue;
            }
            $out[$k] = ['codice' => $k, 'nome' => $x['nome'], 'dipartimento' => (string) $x['dipartimento'], 'anni' => array_map('intval', explode(',', (string) $x['anni']))];
        }
        if (!$out) {
            $aa = $this->annoCorrente();
            foreach ($this->corsi->presentiDiTipo($tipo) as $x) {
                $out[$x['codice']] = ['codice' => $x['codice'], 'nome' => $x['nome'], 'dipartimento' => '', 'anni' => range($aa + 1, $aa - 6)];
            }
        }

        return array_values($out);
    }

    /** @return list<int> anni accademici di offerta (anno di inizio) dei corsi di un tipo, dal più recente */
    public function anni(string $tipo): array
    {
        $anni = $this->catalogo->anni($tipo);
        if (!$anni) {
            $aa = $this->annoCorrente();
            $anni = range($aa + 1, $aa - 6);
        }

        return $anni;
    }

    /** @return array<string, mixed>|null nome del corso dal catalogo (o dall'anagrafe del Dipartimento) */
    public function corso(string $codice): ?array
    {
        $x = $this->catalogo->corso($codice);
        if ($x) {
            return $x;
        }
        $c = $codice === '' || strlen($codice) > 20 ? null : $this->corsi->perCodice(trim($codice));

        return $c ? ['codice' => $c['codice'], 'nome' => $c['nome'], 'tipo' => $c['tipo'], 'dipartimento' => ''] : null;
    }

    /**
     * Insegnamenti di un corso per l'anno accademico di offerta (coorte nelle API): dalla copia locale se ha meno di 30 giorni,
     * altrimenti dalle API (se non rispondono resta la copia precedente, anche vecchia). Si aggiungono quelli dell'anagrafe del Dipartimento.
     *
     * @return list<array<string, mixed>>
     */
    public function insegnamenti(string $cds, int $coorte, bool $scarica = true): array
    {
        if ($cds === '' || strlen($cds) > 20 || $coorte < 1990 || $coorte > 2100) {
            return [];
        }
        $ult = $this->catalogo->scaricatoIl($cds, $coorte);
        if ($scarica && (!$ult || strtotime($ult) < time() - 30 * 86400)) {
            $el = $this->api->tutte('activities/', ['cds' => $cds, 'academic_year' => $coorte]);
            if ($el !== null) {
                $this->salvaInsegnamenti($cds, $coorte, $el);
            }
        }
        $out = $this->catalogo->insegnamenti($cds, $coorte);
        if (!$out) {
            // Corsi del Dipartimento: l'anagrafe degli insegnamenti è già sul portale (anno di erogazione = coorte + anno di corso - 1)
            $out = $this->insegnamenti->perCorsoECoorte($cds, $coorte);
        }

        return $out;
    }

    /**
     * Salva gli insegnamenti arrivati dalle API per un corso e un anno di offerta (solo quelli di quel corso).
     *
     * @param list<array<string, mixed>> $el
     */
    public function salvaInsegnamenti(string $cds, int $coorte, array $el): int
    {
        $n = 0;
        foreach ($el as $r) {
            $x = AttivitaAteneo::daElenco($r);
            $cdsR = (string) ($r['StudyActivityCdSCod'] ?? $cds);
            if ($x === null || ($cdsR !== '' && $cdsR !== $cds)) {
                continue;
            }
            if ($this->catalogo->salvaInsegnamento(['cds_cod' => $cds, 'coorte' => $coorte] + $x)) {
                $n++;
            }
        }
        $this->catalogo->segnaScaricato($cds, $coorte, $n);

        return $n;
    }

    /**
     * Scarica il dettaglio degli insegnamenti (activities/<id>/) per i CFU, che l'elenco non dà: prima quelli dell'archivio
     * (dal 2026/2027), poi il resto del catalogo. Si ferma allo scadere dei secondi o se le API non rispondono; il lavoro
     * riprende alla chiamata dopo. Ritorna quanti ne ha completati.
     */
    public function completaDettagli(int $secondi = 120, int $parallele = 10): int
    {
        $fine = microtime(true) + $secondi;
        $fatti = 0;
        while (microtime(true) < $fine) {
            $ids = array_values(array_unique(array_merge($this->insegnamenti->daCompletare($parallele), $this->catalogo->daCompletare($parallele))));
            $ids = array_slice($ids, 0, $parallele);
            if (!$ids) {
                break;
            }
            $percorsi = [];
            foreach ($ids as $id) {
                $percorsi[$id] = "activities/$id/";
            }
            if ($this->api instanceof ClientApiAteneoMultiplo) {
                $risp = $this->api->getMolti(array_values($percorsi));
            } else {
                $risp = [];
                foreach ($percorsi as $p) {
                    $risp[$p] = $this->api->get($p);
                }
            }
            $arrivati = 0;
            foreach ($percorsi as $id => $p) {
                $d = $risp[$p] ?? null;
                if (is_array($d) && is_array($d['results'] ?? null)) {
                    $arrivati++;
                }
            }
            if ($arrivati === 0) {
                break; // API non raggiungibili (o tutte le schede mancano): si riprova alla prossima sincronizzazione
            }
            foreach ($percorsi as $id => $p) {
                // Scheda mancante (l'Ateneo non la pubblica più): si segna comunque, senza CFU
                $cfu = is_array($risp[$p]['results'] ?? null) ? AttivitaAteneo::cfuDaDettaglio($risp[$p]['results']) : null;
                $this->insegnamenti->salvaDettaglio($id, $cfu);
                $this->catalogo->salvaDettaglio($id, $cfu);
                $fatti++;
            }
        }

        return $fatti;
    }
}
