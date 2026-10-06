<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * Organizzazione proposta del sito pubblico, per pubblico invece che per area:
 * - home: carosello, «Cosa cerchi?», agenda con gli ambiti, scadenze della modulistica, card delle aree;
 * - menu: Home · Orientamento · Studenti · Eventi e seminari · Area riservata; le altre voci già presenti (es. Link utili) restano in fondo.
 * Si applica da Testata e home › Widget (admin/testata.php) con una copia di sicurezza (copie_configurazione) che si ripristina;
 * il menu si può anche creare nascosto e mostrare dopo.
 */
final class OrganizzazioneProposta
{
    private const COPIA = 'organizzazione';
    private const MENU_NASCOSTO = 'menu_proposto';
    private const PAROLE_MINUSCOLE = ['di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'e', 'ed', 'il', 'lo', 'la', 'i', 'gli', 'le', 'del', 'dello', 'della', 'dei', 'degli', 'delle',
        'al', 'allo', 'alla', 'ai', 'agli', 'alle', 'dal', 'dalla', 'dai', 'nel', 'nella', 'nei', 'sul', 'sulla'];

    public function __construct(
        private MenuRepository $menu,
        private FonteAree $aree,
        private ConfigurazioneHome $home,
    ) {
    }

    /**
     * Widget della home proposti a partire da quelli attuali.
     *
     * @param array<string, mixed> $attuale
     * @return array<string, mixed>
     */
    public static function homeProposta(array $attuale): array
    {
        $w = $attuale;
        foreach (['slideshow' => 1, 'percorsi' => 1, 'mia_prenotazione' => 1, 'annunci' => (int) !empty($attuale['annunci']), 'agenda' => 1, 'scadenze' => 1,
            'card_aree' => 1, 'ultimi_posti' => 0, 'prossimi_eventi' => 0, 'statistiche' => 0] as $k => $v) {
            $w[$k] = $v;
        }
        $w['ordine'] = ['slideshow', 'annunci', 'mia_prenotazione', 'percorsi', 'agenda', 'scadenze', 'card_aree', 'ultimi_posti', 'prossimi_eventi', 'statistiche'];
        $w['aree_colonne'] = 3;
        $w['eventi_num'] = 8;

        return $w;
    }

    /**
     * Titolo dell'area per il menu: le parole TUTTE MAIUSCOLE diventano «Maiuscola iniziale» (articoli e preposizioni
     * minuscoli dopo la prima), le sigle miste (DiBEST) restano.
     */
    public static function nomeArea(string $titolo): string
    {
        $parole = preg_split('/\s+/u', trim($titolo)) ?: [];
        foreach ($parole as $i => $w) {
            if ($w !== mb_strtoupper($w, 'UTF-8') || mb_strlen($w) < 2 && $i === 0) {
                continue;
            }
            $min = mb_strtolower($w, 'UTF-8');
            $parole[$i] = $i > 0 && in_array($min, self::PAROLE_MINUSCOLE, true) ? $min : mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
        }

        return implode(' ', $parole);
    }

    /**
     * Voci proposte: [etichetta, url, [figli…]] (tre livelli come il menu del sito: voce, colonna, collegamenti).
     *
     * @return list<array{0: string, 1: string, 2: list<mixed>}>
     */
    public function menuProposto(): array
    {
        $aree = array_values(array_filter($this->aree->areeVisibili(), static fn (array $p): bool => (int) ($p['visibile'] ?? 1) === 1));
        $nome = static fn (array $p): string => self::nomeArea((string) $p['titolo']);
        $link = static fn (array $p): array => [$nome($p), $p['slug'] . '.php', []];
        $or = array_values(array_filter($aree, static fn (array $p): bool => !in_array(Sezioni::tipoArea($p), ['fsl', 'calendario', 'gruppi'], true) && Sezioni::ambitoArea($p) === 'orientamento'));
        $fsl = array_values(array_filter($aree, static fn (array $p): bool => Sezioni::tipoArea($p) === 'fsl'));
        $cal = array_values(array_filter($aree, static fn (array $p): bool => in_array(Sezioni::tipoArea($p), ['calendario', 'gruppi'], true)));
        $ev = array_values(array_filter($aree, static fn (array $p): bool => !in_array(Sezioni::tipoArea($p), ['calendario'], true)));

        return [
            ['Home', 'index.php', []],
            ['Orientamento', 'orientamento.php', [
                ['Futuri studenti', 'orientamento.php', array_merge(array_map($link, $or), [['Prossimi appuntamenti', 'agenda.php?ambito=orientamento', []]])],
                ['Per le scuole', $fsl ? $fsl[0]['slug'] . '.php' : 'agenda.php?scuole=1', array_merge(array_map($link, $fsl), [['Tutte le attività per le scuole', 'agenda.php?scuole=1', []]])],
            ]],
            ['Studenti', 'modulistica.php', [
                ['Modulistica e pratiche', 'modulistica.php', [['Modulistica', 'modulistica.php', []], ['Le mie pratiche', 'pratiche.php', []]]],
                ['Ricevimento e sportelli', 'ricevimento.php', array_merge([['Ricevimento dei docenti', 'ricevimento.php', []]], array_map($link, $cal))],
                ['Tutorato', 'registro_tutorato.php', [['Registro delle attività', 'registro_tutorato.php', []]]],
            ]],
            ['Eventi e seminari', 'agenda.php', [
                ['Agenda', 'agenda.php', array_merge(
                    array_map(static fn (string $k): array => [AMBITI_EVENTO[$k]['nome'], 'agenda.php?ambito=' . $k, []], array_keys(AMBITI_EVENTO)),
                    [['Per le scuole', 'agenda.php?scuole=1', []], ['Avvisi per email', 'avvisi.php', []]]
                )],
                ['Archivio', '#', array_map(static fn (array $p): array => [$nome($p), $p['slug'] . '_archivio.php', []], $ev)],
            ]],
            ['Area riservata', 'area_personale.php', []],
        ];
    }

    /**
     * Voci di primo livello del menu attuale che la proposta non comprende (home, aree, archivi sono compresi):
     * restano in fondo. Una voce senza indirizzo è compresa se lo sono tutte le sue sottovoci.
     *
     * @return list<array<string, mixed>>
     */
    public function vociDaTenere(): array
    {
        $coperti = ['index.php', '/', './', 'index'];
        foreach ($this->aree->areeVisibili() as $p) {
            array_push($coperti, $p['slug'] . '.php', $p['slug'], $p['slug'] . '_archivio.php', $p['slug'] . '_archivio');
        }
        $norm = static fn (mixed $u): string => ltrim((string) preg_replace('#^https?://[^/]+(/[^/]+)?/#', '', trim((string) $u)), '/');
        $tutte = $this->menu->righe();
        $figli = static fn (mixed $id): array => array_values(array_filter($tutte, static fn (array $v): bool => (int) $v['genitore_id'] === (int) $id));
        $coperta = static function (array $v) use (&$coperta, $figli, $coperti, $norm): bool {
            $u = $norm($v['url']);
            if (in_array($u, $coperti, true)) {
                return true;
            }
            $f = $figli($v['id']);

            return ($u === '' || $u === '#') && $f && !array_filter($f, static fn (array $x): bool => !$coperta($x));
        };

        return array_values(array_filter($figli(0), static fn (array $v): bool => !$coperta($v)));
    }

    /** Copia di sicurezza di home e menu (letti dal database, non dalla cache della configurazione). */
    public function copiaConfigurazione(string $autore): void
    {
        $this->menu->salvaCopia(
            self::COPIA,
            (string) json_encode(['widgets_home' => $this->menu->widgetsHome(), 'menu' => $this->menu->righePerId()], JSON_UNESCAPED_UNICODE),
            $autore
        );
    }

    /** Applica home e menu proposti (prima una copia di sicurezza). Ritorna il numero di voci del nuovo menu. */
    public function applica(string $autore, bool $home = true, bool $menu = true): int
    {
        $this->copiaConfigurazione($autore);
        if ($home) {
            $attuale = $this->home->widgets($this->menu->widgetsHome());
            $w = $this->home->widgets((string) json_encode(self::homeProposta($attuale)));
            $this->menu->salvaWidgetsHome((string) json_encode($w));
            $this->home->invalidaCache();
        }
        if (!$menu) {
            return 0;
        }
        // Le voci proposte già create nascoste si rifanno: non si tengono (niente doppioni)
        $giaProposte = $this->vociMenuNascosto();
        $tieni = array_values(array_filter(
            $this->vociDaTenere(),
            fn (array $v): bool => !in_array((int) $v['id'], $giaProposte, true) || $this->menu->urlDi((int) $v['id']) === 'index.php'
        ));
        $this->menu->eliminaCopie(self::MENU_NASCOSTO);
        $tutte = $this->menu->righe();
        $tieniIds = [];
        $raccogli = static function (int $id) use (&$raccogli, $tutte, &$tieniIds): void {
            $tieniIds[] = $id;
            foreach ($tutte as $v) {
                if ((int) $v['genitore_id'] === $id) {
                    $raccogli((int) $v['id']);
                }
            }
        };
        foreach ($tieni as $v) {
            $raccogli((int) $v['id']);
        }
        $this->menu->eliminaTranne($tieniIds);
        $n = 0;
        $inserisci = function (array $voci, int $genitore) use (&$inserisci, &$n): void {
            foreach ($voci as $i => [$etichetta, $url, $figli]) {
                $id = $this->menu->inserisci($genitore, $etichetta, $url, $i + 1);
                $n++;
                if ($figli) {
                    $inserisci($figli, $id);
                }
            }
        };
        $proposta = $this->menuProposto();
        $inserisci($proposta, 0);
        foreach ($tieni as $i => $v) {
            $this->menu->impostaOrdine((int) $v['id'], count($proposta) + $i + 1);
        }

        return $n;
    }

    /**
     * Voci del menu proposto già create nascoste: id delle voci di primo livello ancora esistenti, in ordine.
     *
     * @return list<int>
     */
    public function vociMenuNascosto(): array
    {
        $c = $this->menu->ultimaCopia(self::MENU_NASCOSTO);
        $ids = $c ? array_map('intval', json_decode((string) $c['dati_json'], true) ?: []) : [];
        $esistenti = $this->menu->esistenti(array_values($ids));

        return array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $esistenti, true)));
    }

    /**
     * Crea le voci del menu proposto NASCOSTE (in fondo al menu attuale, che non cambia): si mostrano dopo, tutte insieme
     * (mostraMenuNascosto) o una per una da Menu del sito. La voce Home già presente si riusa. Ritorna le voci create.
     */
    public function creaMenuNascosto(string $autore = ''): int
    {
        if ($this->vociMenuNascosto()) {
            return 0;
        }
        $ordine = $this->menu->ultimoOrdinePrimoLivello();
        $n = 0;
        $primoLivello = [];
        $inserisci = function (array $voci, int $genitore, bool $nascoste) use (&$inserisci, &$n, &$ordine, &$primoLivello): void {
            foreach ($voci as $i => [$etichetta, $url, $figli]) {
                if ($genitore === 0 && $url === 'index.php' && ($home = $this->menu->idHome())) {
                    $primoLivello[] = $home;
                    continue;
                }
                $id = $this->menu->inserisci($genitore, $etichetta, $url, $genitore === 0 ? ++$ordine : $i + 1, !$nascoste);
                $n++;
                if ($genitore === 0) {
                    $primoLivello[] = $id;
                }
                if ($figli) {
                    $inserisci($figli, $id, false);
                }
            }
        };
        $inserisci($this->menuProposto(), 0, true);
        $this->menu->salvaCopia(self::MENU_NASCOSTO, (string) json_encode($primoLivello), $autore);

        return $n;
    }

    /**
     * Mostra le voci create nascoste e nasconde quelle del menu attuale che la proposta comprende (home, aree, archivi);
     * le altre (es. Link utili) restano visibili in fondo. Prima salva una copia (ripristina() la rimette).
     */
    public function mostraMenuNascosto(string $autore = ''): bool
    {
        $ids = $this->vociMenuNascosto();
        if (!$ids) {
            return false;
        }
        $this->copiaConfigurazione($autore);
        $tieni = array_map(
            static fn (array $v): int => (int) $v['id'],
            array_filter($this->vociDaTenere(), static fn (array $v): bool => !in_array((int) $v['id'], $ids, true))
        );
        foreach ($this->menu->idPrimoLivello() as $id) {
            if (!in_array($id, $ids, true) && !in_array($id, $tieni, true)) {
                $this->menu->nascondi($id);
            }
        }
        $o = 0;
        foreach (array_merge($ids, $tieni) as $id) {
            $this->menu->impostaOrdine($id, ++$o, in_array($id, $ids, true) ? true : null);
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    public function ultimaCopia(): ?array
    {
        return $this->menu->ultimaCopia(self::COPIA);
    }

    /** Ripristina home e menu com'erano prima dell'ultima applicazione della proposta (la copia si consuma). */
    public function ripristina(): bool
    {
        $c = $this->ultimaCopia();
        $d = $c ? json_decode((string) $c['dati_json'], true) : null;
        if (!is_array($d)) {
            return false;
        }
        $this->menu->salvaWidgetsHome(isset($d['widgets_home']) ? (string) $d['widgets_home'] : null);
        $this->home->invalidaCache();
        $this->menu->eliminaTranne([]);
        foreach ($d['menu'] ?? [] as $v) {
            $this->menu->reinserisci($v);
        }
        $this->menu->eliminaCopia((int) $c['id']);

        return true;
    }
}
