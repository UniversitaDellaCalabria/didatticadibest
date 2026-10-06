<?php

declare(strict_types=1);

namespace App\Auth\Abilitazioni;

use App\Auth\UtenteRepository;

/**
 * Chi può lavorare su cosa: gestori delle aree e delle attività, perimetri (tutti i progetti / tutti gli eventi
 * di un'area, Formazione Scuola Lavoro, moduli interi) e destinatari delle notifiche sulle prenotazioni.
 * Spostato dalle funzioni di inc/base.php (ambiti_utente, ha_ambito, ha_modulo, get_gestori_ids_area, ...), che restano come facciate.
 *
 * Perimetri ('tipo' di abilitazioni_ambito):
 *   'progetti' / 'eventi'  tutte le attività di quel tipo di un'area, anche quelle create dopo (pagina_id = area)
 *   'fsl'                  pannello Formazione Scuola Lavoro + tutte le attività FSL di tutte le aree
 *   'fsl_convenzioni'      solo il registro delle convenzioni;  'fsl_scuole'  solo l'anagrafe delle scuole
 *   'modulo_<modulo>'      il modulo intero (pagina_id 0)
 */
final class ServizioAbilitazioni
{
    /** @var array<int, list<array{tipo: string, pagina_id: int}>> perimetri già letti nella richiesta, per utente */
    private array $cache = [];

    public function __construct(
        private AbilitazioniRepository $repo,
        private UtenteRepository $utenti,
        private ModuliAree $moduli,
    ) {
    }

    /** @return list<array{tipo: string, pagina_id: int}> */
    public function ambitiUtente(int $uid, bool $rileggi = false): array
    {
        if ($rileggi) {
            $this->cache = [];
        }
        if ($uid <= 0) {
            return [];
        }
        if (!isset($this->cache[$uid])) {
            $this->cache[$uid] = $this->repo->ambitiDi($uid);
        }

        return $this->cache[$uid];
    }

    public function haAmbito(int $uid, string $tipo, int $paginaId = 0): bool
    {
        foreach ($this->ambitiUtente($uid) as $a) {
            if ($a['tipo'] === $tipo && $a['pagina_id'] === $paginaId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Abilitato al modulo intero.
     * La Formazione Scuola Lavoro era un sottomodulo dell'Orientamento: chi ha il modulo Orientamento o il perimetro «fsl»
     * (tutta la FSL) ha anche il modulo FSL, così nessuno perde gli accessi con la separazione.
     */
    public function haModulo(int $uid, string $modulo): bool
    {
        if ($modulo === '') {
            return false;
        }
        if ($modulo === 'fsl') {
            return $this->haAmbito($uid, 'modulo_fsl') || $this->haAmbito($uid, 'modulo_orientamento') || $this->haAmbito($uid, 'fsl');
        }

        return $this->haAmbito($uid, 'modulo_' . $modulo);
    }

    /** @param array<string, mixed>|null $pagina L'area appartiene a un modulo a cui l'utente è abilitato per intero */
    public function areaNelModuloUtente(int $uid, ?array $pagina): bool
    {
        return $pagina && $this->moduli->disponibile() && $this->haModulo($uid, $this->moduli->moduloDiArea($pagina));
    }

    /** Condizione SQL (alias e = eventi) con le attività dell'area comprese nei perimetri dell'utente; '' se nessuna */
    public function sqlAttivitaAmbiti(int $uid, int $paginaId): string
    {
        $cond = [];
        if ($this->haAmbito($uid, 'progetti', $paginaId)) {
            $cond[] = "e.tipo = 'progetto'";
        }
        if ($this->haAmbito($uid, 'eventi', $paginaId)) {
            $cond[] = "IFNULL(e.tipo, 'evento') <> 'progetto'";
        }
        if ($this->haModulo($uid, 'fsl')) {
            $cond[] = 'e.id IN (SELECT evento_id FROM progetti_dettagli WHERE convenzione = 1)';
        }
        // Modulo intero a cui appartiene l'area: tutte le attività
        $pag = $this->repo->area($paginaId);
        if ($pag && $this->areaNelModuloUtente($uid, $pag)) {
            $cond[] = '1 = 1';
        }

        return $cond ? '(' . implode(' OR ', $cond) . ')' : '';
    }

    /** @return list<int> ID delle attività dell'area comprese nei perimetri dell'utente */
    public function attivitaDaAmbiti(int $uid, int $paginaId): array
    {
        $cond = $this->sqlAttivitaAmbiti($uid, $paginaId);

        return $cond === '' ? [] : $this->repo->attivitaArea($paginaId, $cond);
    }

    /** @return list<int> aree in cui l'utente lavora grazie ai perimetri (tipo di attività, oppure attività FSL) */
    public function areeDaAmbiti(int $uid): array
    {
        $aree = [];
        foreach ($this->ambitiUtente($uid) as $a) {
            if (in_array($a['tipo'], ['progetti', 'eventi'], true) && $a['pagina_id'] > 0) {
                $aree[$a['pagina_id']] = true;
            }
        }
        // Moduli interi: tutte le aree del modulo
        $moduli = array_filter(array_map(static fn (array $a): ?string => str_starts_with($a['tipo'], 'modulo_') ? substr($a['tipo'], 7) : null, $this->ambitiUtente($uid)));
        if ($this->haModulo($uid, 'fsl')) {
            $moduli[] = 'fsl';
        }
        if ($moduli) {
            foreach ($this->repo->tutteLeAree() as $x) {
                if (in_array($this->moduli->moduloDiArea($x), $moduli, true)) {
                    $aree[(int) $x['id']] = true;
                }
            }
        }
        if ($this->haModulo($uid, 'fsl')) {
            foreach ($this->repo->areeConAttivitaFsl() as $p) {
                $aree[$p] = true;
            }
        }

        return array_keys($aree);
    }

    /**
     * Utenti che vedono un'attività grazie a un perimetro ('progetti'/'eventi' della sua area; con $conFsl anche 'fsl'
     * e il modulo intero dell'area).
     *
     * @return list<int>
     */
    public function idsAmbitoAttivita(int $evId, bool $conFsl = true): array
    {
        $e = $this->repo->tipoAttivita($evId);
        if (!$e) {
            return [];
        }
        $perimetri = [[$e['tipo'] === 'progetto' ? 'progetti' : 'eventi', (int) $e['pagina_id']]];
        if ($conFsl && (int) $e['fsl'] === 1) {
            array_push($perimetri, ['fsl', null], ['modulo_orientamento', null], ['modulo_fsl', null]);
        }
        // Con $conFsl (perimetri ampi) anche chi ha il modulo intero dell'area
        if ($conFsl && $this->moduli->disponibile()) {
            $pag = $this->repo->area((int) $e['pagina_id']);
            if ($pag) {
                $modulo = $this->moduli->moduloDiArea($pag);
                if ($modulo === 'fsl') {
                    array_push($perimetri, ['modulo_fsl', null], ['modulo_orientamento', null], ['fsl', null]);
                } else {
                    $perimetri[] = ['modulo_' . $modulo, null];
                }
            }
        }

        return $this->repo->utentiConPerimetri($perimetri);
    }

    /** L'utente lavora su questa attività: gestore dell'area, dell'attività o con un perimetro che la comprende */
    public function utenteGestisceAttivita(int $uid, int $evId): bool
    {
        if ($uid <= 0 || $evId <= 0) {
            return false;
        }
        $x = $this->repo->gestoriAttivita($evId);
        if (!$x) {
            return false;
        }
        if (in_array($uid, IdsGestori::daCampi($x['gestore_utente_id'], $x['p_csv'], $x['p_json']), true)) {
            return true;
        }
        if (in_array($uid, IdsGestori::daCampi(0, $x['ev_csv'], $x['ev_json']), true)) {
            return true;
        }

        return in_array($uid, $this->idsAmbitoAttivita($evId), true);
    }

    /** Ha almeno un'abilitazione: su un'area, su un'attività o un perimetro (progetti, eventi, FSL) */
    public function utenteHaAbilitazioni(int $uid): bool
    {
        if ($uid <= 0) {
            return false;
        }
        if ($this->ambitiUtente($uid)) {
            return true;
        }
        foreach ($this->repo->gestoriTutteLeAree() as $x) {
            if (in_array($uid, IdsGestori::daCampi($x['gestore_utente_id'], $x['gestori_utenti_ids'], $x['permessi_gestori_json']), true)) {
                return true;
            }
        }
        foreach ($this->repo->gestoriAttivitaNonArchiviate() as $x) {
            if (in_array($uid, IdsGestori::daCampi(0, $x['gestori_utenti_ids'], $x['permessi_gestori_json']), true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $tipiAmmessi i perimetri esistenti (TIPI_AMBITO) */
    public function assegnaAmbito(int $uid, string $tipo, int $paginaId, int $da, array $tipiAmmessi): bool
    {
        if ($uid <= 0 || !isset($tipiAmmessi[$tipo])) {
            return false;
        }
        if (in_array($tipo, ['fsl', 'fsl_convenzioni', 'fsl_scuole'], true) || str_starts_with($tipo, 'modulo_')) {
            $paginaId = 0;
        } elseif ($paginaId <= 0) {
            return false;
        }
        $ok = $this->repo->inserisciAmbito($uid, $tipo, $paginaId, $da);
        $this->ambitiUtente($uid, true);

        return $ok;
    }

    /** $tipo null = tutti i perimetri dell'utente nell'area $paginaId (con $paginaId 0: quelli FSL e dei moduli) */
    public function revocaAmbito(int $uid, ?string $tipo, int $paginaId = 0): void
    {
        $this->repo->eliminaAmbito($uid, $tipo, $paginaId);
        $this->ambitiUtente($uid, true);
    }

    /** @return list<int> tutti i gestori di un'area: quelli dell'intera area + quelli assegnati ai singoli eventi + i perimetri */
    public function gestoriIdsArea(int $paginaId): array
    {
        $ids = [];
        $r = $this->repo->gestoriArea($paginaId);
        if ($r) {
            $ids = IdsGestori::daCampi($r['gestore_utente_id'], $r['gestori_utenti_ids'], $r['permessi_gestori_json']);
        }
        foreach ($this->repo->gestoriAttivitaArea($paginaId) as $r) {
            $ids = array_merge($ids, IdsGestori::daCampi(0, $r['gestori_utenti_ids'], $r['permessi_gestori_json']));
        }
        // Abilitati a tutti i progetti o a tutti gli eventi dell'area
        $ids = array_merge($ids, $this->repo->utentiConTipiArea($paginaId));

        return array_values(array_unique($ids));
    }

    /**
     * ID dei gestori che ricevono le email sulle prenotazioni dell'area.
     * null = mai configurato dall'admin → le ricevono tutti i gestori.
     *
     * @return list<int>|null
     */
    public function notificheGestoriAttive(int $paginaId): ?array
    {
        $r = $this->repo->notificheGestori($paginaId);
        if (!$r || $r['notifiche_gestori_ids'] === null) {
            return null;
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $r['notifiche_gestori_ids']))));
    }

    public function impostaNotificaGestore(int $paginaId, int $utenteId, bool $attiva): void
    {
        $attivi = $this->notificheGestoriAttive($paginaId) ?? $this->gestoriIdsArea($paginaId);
        $attivi = $attiva ? array_merge($attivi, [$utenteId]) : array_diff($attivi, [$utenteId]);
        $this->repo->impostaNotificheGestori($paginaId, implode(',', array_unique(array_map('intval', $attivi))));
    }

    /**
     * Email dei gestori da avvisare per un evento: gestori dell'intera area + gestori del singolo evento.
     * $soloNotificheAttive = true applica l'interruttore "Notifiche prenotazioni" di admin/abilitazioni.php.
     *
     * @return list<string>
     */
    public function emailGestoriEvento(int $eventoId, bool $soloNotificheAttive = true): array
    {
        $r = $this->repo->gestoriAttivita($eventoId);
        if (!$r) {
            return [];
        }
        $ids = array_unique(array_merge(
            IdsGestori::daCampi($r['gestore_utente_id'], $r['p_csv'], $r['p_json']),
            IdsGestori::daCampi(0, $r['ev_csv'], $r['ev_json']),
            $this->idsAmbitoAttivita($eventoId, false)   // perimetri dell'area (progetti / eventi), non la FSL di tutte le aree
        ));
        if ($soloNotificheAttive) {
            $attivi = $this->notificheGestoriAttive((int) $r['pagina_id']);
            if ($attivi !== null) {
                $ids = array_intersect($ids, $attivi);
            }
        }

        return $ids ? $this->utenti->emailDi(array_values($ids)) : [];
    }

    /** Attività dell'area visibile al gestore (con il filtro SQL dei suoi permessi, $rbac) */
    public function attivitaAutorizzata(int $evId, int $paginaId, string $rbac): bool
    {
        return $this->repo->attivitaVisibile($evId, $paginaId, $rbac);
    }

    public function turnoAutorizzato(int $turnoId, int $paginaId, string $rbac): bool
    {
        return $this->repo->turnoVisibile($turnoId, $paginaId, $rbac);
    }

    /** Prenotazione di un evento dell'area corrente visibile al gestore */
    public function prenotazioneAutorizzata(int $prenotazioneId, int $paginaId, string $rbac): bool
    {
        return $this->repo->prenotazioneVisibile($prenotazioneId, $paginaId, $rbac);
    }
}
