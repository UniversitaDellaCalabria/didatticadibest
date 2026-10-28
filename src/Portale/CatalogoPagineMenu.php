<?php

declare(strict_types=1);

namespace App\Portale;

use App\Uffici\UfficiRepository;

/**
 * Il catalogo di tutte le pagine utili del portale (per le persone, non quelle del pannello né quelle che si aprono solo da un link personale), da mettere nel menu del sito.
 * «crea» le aggiunge al menu NASCOSTE, raggruppate per argomento sotto una voce «Pagine del portale (da scegliere)»: il sito non cambia; poi da Menu si spostano
 * dove servono (Posizione) e si mostrano (occhio). Le pagine già presenti nel menu, visibili o nascoste, non si ripetono: si può rilanciare quando nascono pagine nuove.
 */
final class CatalogoPagineMenu
{
    public const RADICE = 'Pagine del portale (da scegliere)';

    /** Pagine per un gruppo di persone: indirizzo => 'direzione' (ufficio Direzione: Direttore e vice) | 'risorse' (responsabili di risorse). */
    private const RISERVATE = ['cruscotto_direttore.php' => 'direzione', 'direttore_assente.php' => 'direzione', 'risorse_responsabile.php' => 'risorse'];

    public function __construct(private MenuRepository $menu, private FonteAree $aree, private ?UfficiRepository $uffici = null)
    {
    }

    /** Il codice di visibilità del gruppo per quella pagina (0 = per tutti, se il gruppo non esiste). */
    private function visibilitaDi(string $url): int
    {
        $g = self::RISERVATE[self::norma($url)] ?? '';
        if ($g === 'risorse') {
            return AppartenenzeVisitatore::RESPONSABILI_RISORSE;
        }
        $u = $g === 'direzione' && $this->uffici !== null ? $this->uffici->perChiave('direzione') : null;

        return $u ? AppartenenzeVisitatore::codiceUfficio((int) $u['id']) : 0;
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
        $c['Studenti'] = [['Domande frequenti (assistenza): le risposte per argomento e pubblico', 'domande_frequenti.php'], ['Contatti: chi contattare per cosa', 'contatti.php'], ['Notizie e comunicazioni', 'comunicazioni.php'], ['Scadenze e date utili', 'scadenze.php'], ['Corsi di studio e insegnamenti', 'corsi.php'], ['Docenti e ricevimento: cerca e prenota', 'docenti.php'], ['Tesi e laurea: i passi', 'percorso_tesi.php'], ['Tirocinio: i passi', 'percorso_tirocini.php'], ['Modulistica e moduli online', 'modulistica.php'], ['Le mie pratiche', 'pratiche.php'], ['Registro delle attività di tutorato', 'registro_tutorato.php'],
            ['Tirocini curriculari: aziende e laboratori', 'tirocini.php'], ['Registra la presenza (scanner QR)', 'scanner_studente.php'], ['Come si fa: le guide per ruolo', 'guida.php']];
        $c['Tesi e tirocini: disponibilità dei docenti'] = [['Disponibilità dei docenti per la tesi', 'disponibilita_tesi.php'], ['Disponibilità dei docenti per i tirocini', 'disponibilita_tirocini.php']];
        $c['Tesi: enti e aziende'] = [['Aziende convenzionate per la tesi', 'aziende_convenzionate_tesi.php'], ['Convenzione di tesi per enti e aziende', 'richiedi_convenzione_tesi.php']];
        $c['Studenti'][] = ['Ricerca nel sito', 'ricerca.php'];
        $c['Studenti'][] = ['Assistenza: scrivi agli operatori', 'assistenza.php'];
        $c['Studenti'][] = ['Notizie in RSS (per chi segue il sito con un lettore di feed)', 'comunicazioni_feed.php'];
        $c['Area riservata'] = [['Area personale', 'area_personale.php'], ['Il mio calendario', 'calendario.php'], ['I miei eventi, attestati e sondaggi', 'miei_eventi.php'], ['Il mio tutorato', 'il_mio_tutorato.php'], ['Le mie conversazioni di assistenza', 'assistenza_storico.php'], ['Ufficio pagamenti del tutorato', 'pagamenti_tutorato.php'], ['Il mio profilo', 'profilo.php'], ['La mia disponibilità per tesi e tirocini (docenti)', 'la_mia_disponibilita.php'], ['Le mie risorse (responsabili di aule e mezzi)', 'risorse_responsabile.php'],
            ['Cruscotto del Direttore', 'cruscotto_direttore.php'], ['Direttore assente (Direttore, vice e segreteria)', 'direttore_assente.php']];
        $c['Informazioni'] = [['Verifica un attestato', 'verifica_attestato.php'], ['Verbali dei consigli (con password)', 'verbali.php'], ['Informativa sulla privacy', 'privacy.php'], ['Crediti', 'crediti.php']];

        return array_filter($c, static fn (array $v): bool => $v !== []);
    }

    /** A cosa serve ogni pagina (indirizzo => nota breve), per sceglierla senza sbagliare quando si crea una voce del menu. */
    private const NOTE = [
        'agenda.php' => 'Tutti gli eventi e i seminari in programma, con filtri e iscrizione.',
        'orientamento.php' => 'Vetrina per futuri studenti e scuole: corsi, incontri, attività di orientamento.',
        'avvisi.php' => 'Ci si iscrive per ricevere un’email quando nasce un nuovo evento.',
        'programma_fsl.php' => 'Le scuole prenotano insieme le attività di Formazione Scuola Lavoro.',
        'ricevimento.php' => 'Elenco dei docenti con gli orari di ricevimento da prenotare.',
        'domande_frequenti.php' => 'Risposte pronte per argomento e per pubblico (studenti, scuole, aziende).',
        'contatti.php' => 'Chi contattare per ogni esigenza, con email e telefono.',
        'comunicazioni.php' => 'Le notizie e le comunicazioni del Dipartimento, per pubblico.',
        'scadenze.php' => 'Le date utili: iscrizioni, tasse, domande di laurea, termini.',
        'corsi.php' => 'I corsi di studio e i loro insegnamenti, dall’anagrafe di Ateneo.',
        'docenti.php' => 'Cerca un docente e prenota il ricevimento.',
        'percorso_tesi.php' => 'I passi da fare per la tesi e la laurea.',
        'percorso_tirocini.php' => 'I passi da fare per il tirocinio.',
        'modulistica.php' => 'Moduli da scaricare e moduli online da compilare e inviare.',
        'pratiche.php' => 'Le pratiche dello studente: invio, stato e risposte (serve l’accesso).',
        'registro_tutorato.php' => 'Il registro delle attività dei tutor (serve l’accesso).',
        'tirocini.php' => 'Aziende e laboratori dove svolgere il tirocinio curriculare.',
        'scanner_studente.php' => 'Per registrare la propria presenza a un evento inquadrando il codice QR.',
        'guida.php' => 'Guide «Come si fa» scritte per ruolo: studente, docente, gestore.',
        'ricerca.php' => 'Cerca in un colpo solo eventi, notizie, guide, docenti e risposte.',
        'assistenza.php' => 'Si sceglie l’argomento, si scrive e si continua in chat con gli operatori.',
        'comunicazioni_feed.php' => 'Indirizzo RSS delle notizie: serve a chi le segue con un lettore di feed.',
        'disponibilita_tesi.php' => 'Docenti che accettano tesisti, con semaforo (verde, ultimo posto, completo), corsi, temi e obiettivi.',
        'disponibilita_tirocini.php' => 'Docenti che accettano tirocinanti, con barra dei posti, corsi, attività e obiettivi.',
        'aziende_convenzionate_tesi.php' => 'Elenco delle aziende e degli enti con convenzione per la tesi.',
        'richiedi_convenzione_tesi.php' => 'Modulo con cui un ente o un’azienda chiede la convenzione per la tesi.',
        'area_personale.php' => 'Il cruscotto della persona: le sue tessere, da fare e agenda (serve l’accesso).',
        'calendario.php' => 'Eventi prenotati, aule, ricevimento e scadenze in un calendario, con file .ics (serve l’accesso).',
        'miei_eventi.php' => 'Prenotazioni, storico con attestati, sondaggi e aule prenotate (serve l’accesso).',
        'il_mio_tutorato.php' => 'A che punto è ogni incarico di tutorato e i suoi documenti (per i tutor).',
        'assistenza_storico.php' => 'Le conversazioni passate con Didacticus e l’assistenza, da riaprire (serve l’accesso).',
        'pagamenti_tutorato.php' => 'L’ufficio pagamenti lavora le pratiche di tutorato validate (per gli uffici associati).',
        'profilo.php' => 'I dati della persona, le notifiche e il tema (serve l’accesso).',
        'la_mia_disponibilita.php' => 'Il docente indica posti, corsi, temi e attività per tesi e tirocini (solo docenti).',
        'risorse_responsabile.php' => 'Le risorse di cui si è responsabili: prenotazioni, diario di bordo, scadenze.',
        'cruscotto_direttore.php' => 'Numeri e cose da decidere per il Direttore (solo Direttore e vice).',
        'direttore_assente.php' => 'Per segnalare l’assenza del Direttore e a chi passano le firme.',
        'verifica_attestato.php' => 'Si controlla l’autenticità di un attestato con il suo codice.',
        'verbali.php' => 'I verbali dei consigli, protetti da password.',
        'privacy.php' => 'L’informativa sul trattamento dei dati personali.',
        'crediti.php' => 'Chi ha realizzato il portale.',
    ];

    /**
     * Come catalogo(), con una nota su ogni pagina: [argomento => list<[etichetta, indirizzo, nota]>].
     *
     * @return array<string, list<array{0: string, 1: string, 2: string}>>
     */
    public function conNote(): array
    {
        $r = [];
        foreach ($this->catalogo() as $argomento => $pagine) {
            foreach ($pagine as [$etichetta, $url]) {
                $r[$argomento][] = [$etichetta, $url, $this->nota($argomento, $url)];
            }
        }

        return $r;
    }

    private function nota(string $argomento, string $url): string
    {
        if (isset(self::NOTE[$url])) {
            return self::NOTE[$url];
        }
        if (str_ends_with($url, '_archivio.php')) {
            return 'Gli eventi passati di quest’area, raggruppati per anno.';
        }
        if (str_starts_with($url, 'agenda.php?ambito=')) {
            return 'L’agenda con solo gli eventi di questo ambito.';
        }
        if ($url === 'agenda.php?scuole=1') {
            return 'L’agenda con solo le attività pensate per le scuole.';
        }

        return match ($argomento) {
            'Aree (pagine degli eventi e dei progetti)' => 'Pagina pubblica dell’area: eventi e progetti, con iscrizione.',
            'Aule, laboratori e ricevimento' => 'Elenco delle risorse e settimana con gli slot liberi da prenotare.',
            default => '',
        };
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
                $this->menu->inserisci($colonna, $etichetta, $url, ++$n, false, $this->visibilitaDi($url));
                ++$aggiunte;
            }
        }

        // Le voci che c'erano già per queste pagine, ancora per tutti, si riservano a chi le usa davvero
        foreach (array_keys(self::RISERVATE) as $u) {
            if ($cod = $this->visibilitaDi($u)) {
                $this->menu->riservaVociPubbliche($u, $cod);
            }
        }

        return [$aggiunte, $presenti];
    }
}
