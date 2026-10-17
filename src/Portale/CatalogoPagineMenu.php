<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * Il catalogo di tutte le pagine utili del portale (per le persone, non quelle del pannello né quelle che si aprono solo da un link personale), da mettere nel menu del sito.
 * «crea» le aggiunge al menu NASCOSTE, raggruppate per argomento sotto una voce «Pagine del portale (da scegliere)»: il sito non cambia; poi da Menu si spostano
 * dove servono (Posizione) e si mostrano (occhio). Le pagine già presenti nel menu, visibili o nascoste, non si ripetono: si può rilanciare quando nascono pagine nuove.
 */
final class CatalogoPagineMenu
{
    public const RADICE = 'Pagine del portale (da scegliere)';

    public function __construct(private MenuRepository $menu, private FonteAree $aree)
    {
    }

    /**
     * Le pagine per argomento: [argomento => list<[etichetta, indirizzo]>]. Le aree (OpenLab, FSL, aule…) vengono dall'elenco delle aree pubblicate.
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public function catalogo(): array
    {
        $aree = array_values(array_filter($this->aree->areeVisibili(), static fn (array $p): bool => (int) ($p['visibile'] ?? 1) === 1));
        $nome = static fn (array $p): string => OrganizzazioneProposta::nomeArea((string) $p['titolo']);
        $prenotazioni = array_values(array_filter($aree, static fn (array $p): bool => in_array(Sezioni::tipoArea($p), ['calendario', 'gruppi'], true)));
        $eventi = array_values(array_filter($aree, static fn (array $p): bool => Sezioni::tipoArea($p) !== 'calendario'));

        $c = [];
        $c['Eventi e seminari'] = array_merge(
            [['Agenda degli eventi e dei seminari', 'agenda.php'], ['Orientamento: la vetrina per futuri studenti e scuole', 'orientamento.php']],
            array_map(static fn (string $k): array => ['Agenda: ' . AMBITI_EVENTO[$k]['nome'], 'agenda.php?ambito=' . $k], array_keys(AMBITI_EVENTO)),
            [['Agenda: attività per le scuole', 'agenda.php?scuole=1'], ['Avvisi per email dei nuovi eventi', 'avvisi.php']]
        );
        $c['Aree (pagine degli eventi e dei progetti)'] = array_map(static fn (array $p): array => [$nome($p), $p['slug'] . '.php'], $eventi);
        $c['Archivi degli eventi'] = array_map(static fn (array $p): array => ['Archivio: ' . $nome($p), $p['slug'] . '_archivio.php'], $eventi);
        $c['Aule, laboratori e ricevimento'] = array_merge(
            array_map(static fn (array $p): array => [$nome($p), $p['slug'] . '.php'], $prenotazioni),
            [['Ricevimento dei docenti', 'ricevimento.php']]
        );
        $c['Formazione Scuola Lavoro'] = [['Programma FSL della scuola (attività da prenotare insieme)', 'programma_fsl.php']];
        $c['Studenti'] = [['Modulistica e moduli online', 'modulistica.php'], ['Le mie pratiche', 'pratiche.php'], ['Registro delle attività di tutorato', 'registro_tutorato.php'],
            ['Registra la presenza (scanner QR)', 'scanner_studente.php'], ['Come si fa: le guide per ruolo', 'guida.php']];
        $c['Tesi: enti e aziende'] = [['Aziende convenzionate per la tesi', 'aziende_convenzionate_tesi.php'], ['Convenzione di tesi per enti e aziende', 'richiedi_convenzione_tesi.php']];
        $c['Area riservata'] = [['Area personale', 'area_personale.php'], ['Il mio profilo', 'profilo.php'], ['Le mie risorse (responsabili di aule e mezzi)', 'risorse_responsabile.php'],
            ['Cruscotto del Direttore', 'cruscotto_direttore.php'], ['Direttore assente (Direttore, vice e segreteria)', 'direttore_assente.php']];
        $c['Informazioni'] = [['Verifica un attestato', 'verifica_attestato.php'], ['Verbali dei consigli (con password)', 'verbali.php'], ['Informativa sulla privacy', 'privacy.php'], ['Crediti', 'crediti.php']];

        return array_filter($c, static fn (array $v): bool => $v !== []);
    }

    private static function norma(string $u): string
    {
        return ltrim((string) preg_replace('#^https?://[^/]+(/[^/]+)?/#', '', trim($u)), '/');
    }

    /**
     * Aggiunge al menu le pagine che non ci sono ancora, nascoste. Ritorna [pagine aggiunte, pagine già presenti nel menu].
     *
     * @return array{0: int, 1: int}
     */
    public function crea(): array
    {
        $righe = $this->menu->righe();
        $esistenti = [];
        foreach ($righe as $r) {
            $u = self::norma((string) $r['url']);
            if ($u !== '' && $u !== '#') {
                $esistenti[$u] = true;
            }
        }
        $radice = 0;
        foreach ($righe as $r) {
            if ((int) $r['genitore_id'] === 0 && (string) $r['etichetta'] === self::RADICE) {
                $radice = (int) $r['id'];
            }
        }
        $aggiunte = 0;
        $presenti = 0;
        $ordine = 0;
        foreach ($this->catalogo() as $argomento => $pagine) {
            $nuove = [];
            foreach ($pagine as [$etichetta, $url]) {
                if (isset($esistenti[self::norma($url)])) {
                    ++$presenti;
                    continue;
                }
                $esistenti[self::norma($url)] = true;
                $nuove[] = [$etichetta, $url];
            }
            if (!$nuove) {
                continue;
            }
            if ($radice === 0) {
                $radice = $this->menu->inserisci(0, self::RADICE, '#', $this->menu->ultimoOrdinePrimoLivello() + 1, false);
            }
            $colonna = 0;
            foreach ($this->menu->figli($radice) as $f) {
                if ((string) $f['etichetta'] === $argomento) {
                    $colonna = (int) $f['id'];
                }
            }
            if ($colonna === 0) {
                $colonna = $this->menu->inserisci($radice, $argomento, '#', ++$ordine + 100, false);
            }
            $n = count($this->menu->figli($colonna));
            foreach ($nuove as [$etichetta, $url]) {
                $this->menu->inserisci($colonna, $etichetta, $url, ++$n, false);
                ++$aggiunte;
            }
        }

        return [$aggiunte, $presenti];
    }
}
