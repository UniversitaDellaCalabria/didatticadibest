<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Sito;
use App\Email\InvioEmail;

/**
 * Il flusso delle pratiche che passano dal protocollo (la «Richiesta di convalida/riconoscimento esami»): ufficio protocollo →
 * ufficio del corso di studio della pratica (scelto dal consiglio che ha quel corso) → convalide del referente → seduta e verbale →
 * invio alla segreteria studenti scelta dal referente, con il verbale. Uffici con un tipo (uffici.tipo): «protocollo»,
 * «cdl» (con il consiglio dei corsi), «segreteria».
 */
final class ServizioFlussoConvalide implements FlussoPratica
{
    public function __construct(
        private Database $db,
        private UfficioRepository $uffici,
        private ServizioUffici $ufficio,
        private ModuloRepository $moduli,
        private PraticaRepository $pratiche,
        private StoricoPratica $storico,
        private ServizioIter $iter,
        private NotifichePratiche $notifiche,
        private AllegatiPratiche $allegati,
        private Sito $sito,
        private VerbaleFirmato $verbale,
        private EstrattoVerbale $estratto,
        private ConsiglioRepository $consigli,
        private SedutaRepository $sedute,
        private DomandaPdfa $domanda,
        private FirmaPratica $firme,
        private ServizioConfermeDocenti $conferme
    ) {
    }

    public function ufficiDiTipo(string $tipo): array
    {
        return array_filter($this->uffici->tutti(), fn ($u) => (string)($u['tipo'] ?? '') === $tipo);
    }

    public function personeUfficio(int $uff_id): array
    {
        return array_values(array_filter($this->ufficio->operatori(), fn ($o) => in_array($uff_id, $o['_uffici'], true)));
    }

    public static function corsoPratica(array $p): string
    {
        foreach (json_decode((string)$p['risposte_json'], true) ?: [] as $r) {
            if (($r['tipo'] ?? '') === 'corso_studio' && trim((string)$r['valore']) !== '' && empty($r['nascosto'])) {
                return trim((string)$r['valore']);
            }
        }
        return '';
    }

    public function ufficioDelCorso(string $corso): ?int
    {
        $corso = mb_strtolower(trim($corso));
        if ($corso === '') {
            return null;
        }
        foreach ($this->ufficiDiTipo('cdl') as $id => $u) {
            $c = !empty($u['consiglio_id']) ? $this->consigli->perId((int)$u['consiglio_id']) : null;
            $corsi = array_map(fn ($x) => mb_strtolower(trim((string)$x)), $c ? (json_decode((string)$c['corsi'], true) ?: []) : []);
            if (in_array($corso, $corsi, true)) {
                return (int)$id;
            }
        }
        // Consigli senza i corsi indicati: il corso citato nel nome del consiglio («… del Corso di Laurea in Biologia, …»)
        foreach ($this->ufficiDiTipo('cdl') as $id => $u) {
            $c = !empty($u['consiglio_id']) ? $this->consigli->perId((int)$u['consiglio_id']) : null;
            if (!$c || json_decode((string)$c['corsi'], true)) {
                continue;
            }
            if (preg_match('/' . preg_quote($corso, '/') . '(?![\p{L}])/u', mb_strtolower((string)$c['nome']))) {
                return (int)$id;
            }
        }
        return null;
    }

    public function preparaFlusso(): void
    {
        $this->uffici->tutti(true);
        if (!$this->ufficiDiTipo('protocollo')) {
            $this->db->esegui(
                "INSERT INTO uffici (nome, descrizione, chiave, ambito, smista, segue_corsi, ordine, tipo) VALUES (?, ?, 'protocollo', 'dipartimento', 0, 0, 0, 'protocollo')",
                ['Ufficio protocollo', 'Scarica le domande, le protocolla nel sistema di Ateneo e registra numero e data: la pratica passa da sola all\'ufficio del corso di studio']
            );
        }
        foreach ($this->consigli->tutti() as $c) {
            if ($this->db->valore("SELECT id FROM uffici WHERE consiglio_id = ?", [(int)$c['id']])) {
                continue;
            }
            $nome = Costanti::NOMI_UFFICI_CONSIGLI[(int)$c['ordine']] ?? ('Ufficio del ' . mb_substr((string)$c['nome'], 0, 130));
            $this->db->esegui(
                "INSERT INTO uffici (nome, descrizione, chiave, ambito, smista, segue_corsi, ordine, tipo, consiglio_id) VALUES (?, ?, ?, 'dipartimento', 0, 1, ?, 'cdl', ?)",
                [$nome, mb_substr('Pratiche dei corsi del ' . $c['nome'], 0, 500), 'cdl_' . (int)$c['id'], 10 + (int)$c['ordine'], (int)$c['id']]
            );
        }
        $this->uffici->tutti(true);
        if (!$this->db->valore("SELECT id FROM didattica_moduli WHERE chiave = 'convalida_esami'")) {
            $this->creaModuloConvalida();
        }
        // Le altre pratiche dell'elenco del Dipartimento (moduli inattivi da rivedere e attivare)
        (new ModelliPratiche($this->db))->prepara();
        $this->uffici->tutti(true);
    }

    public function creaModuloConvalida(): int
    {
        $cond_u = ['campo' => 'Ateneo della carriera precedente', 'op' => 'uguale', 'valore' => 'Università della Calabria'];
        $cond_a = ['campo' => 'Ateneo della carriera precedente', 'op' => 'uguale', 'valore' => 'Altro Ateneo'];
        $campi = [
            ['tipo' => 'info', 'etichetta' => 'Prima di iniziare', 'aiuto' => "Sei riconosciuto dall'accesso con le credenziali di Ateneo: non serve il documento di riconoscimento.\nAll'invio il portale prepara la domanda in PDF/A, pronta per il protocollo: la trovi nella tua pratica.\nL'imposta di bollo (16,00 euro) si paga dalla tua area riservata Esse3."],
            ['tipo' => 'titolo', 'etichetta' => 'Dati anagrafici'],
            ['tipo' => 'text', 'etichetta' => 'Luogo di nascita', 'obbligatorio' => 1, 'aiuto' => 'Comune (provincia) o Stato estero'],
            ['tipo' => 'date', 'etichetta' => 'Data di nascita', 'obbligatorio' => 1],
            ['tipo' => 'codice_fiscale', 'etichetta' => 'Codice fiscale', 'obbligatorio' => 1],
            ['tipo' => 'text', 'etichetta' => 'Comune di residenza', 'obbligatorio' => 1, 'aiuto' => 'Con la provincia, es. Rende (CS)'],
            ['tipo' => 'text', 'etichetta' => 'Indirizzo di residenza', 'obbligatorio' => 1, 'aiuto' => 'Via, numero civico e CAP'],
            ['tipo' => 'tel', 'etichetta' => 'Cellulare', 'obbligatorio' => 1],
            ['tipo' => 'titolo', 'etichetta' => 'Iscrizione attuale'],
            ['tipo' => 'corso_studio', 'etichetta' => 'Corso di studio', 'obbligatorio' => 1, 'aiuto' => 'Il corso a cui sei iscritto: la pratica va all\'ufficio di quel corso.'],
            ['tipo' => 'anno_accademico', 'etichetta' => 'Anno accademico di iscrizione', 'obbligatorio' => 1],
            ['tipo' => 'text', 'etichetta' => 'Matricola', 'obbligatorio' => 1],
            ['tipo' => 'titolo', 'etichetta' => 'Carriera precedente'],
            ['tipo' => 'radio', 'etichetta' => 'Ateneo della carriera precedente', 'opzioni' => ['Università della Calabria', 'Altro Ateneo'], 'obbligatorio' => 1],
            ['tipo' => 'text', 'etichetta' => 'Denominazione dell\'Ateneo', 'obbligatorio' => 1, 'cond' => $cond_a, 'aiuto' => 'es. Università degli Studi di Messina'],
            ['tipo' => 'text', 'etichetta' => 'Corso di studio della carriera precedente', 'obbligatorio' => 1],
            ['tipo' => 'select', 'etichetta' => 'La carriera precedente si è conclusa con', 'opzioni' => ['Laurea conseguita', 'Rinuncia agli studi', 'Decadenza', 'Trasferimento', 'Passaggio di corso', 'Corsi singoli'], 'obbligatorio' => 1],
            ['tipo' => 'titolo', 'etichetta' => 'Esami da convalidare', 'aiuto' => 'Un esame per riga; con «Aggiungi riga» ne inserisci altri.'],
            ['tipo' => 'tabella', 'etichetta' => 'Esami sostenuti all\'Università della Calabria', 'obbligatorio' => 1, 'cond' => $cond_u,
             'opzioni' => ['Insegnamento:insegnamento', 'CFU:cfu', 'S.S.D.:ssd', 'Voto:voto', 'Data sostenimento:data', 'Da inserire nel piano come a scelta:piano'],
             'aiuto' => 'Scegli ogni insegnamento dal catalogo di Ateneo (lente): CFU e S.S.D. si compilano da soli. Spunta «a scelta» se vuoi l\'insegnamento convalidato nel piano di studi come insegnamento a scelta.'],
            ['tipo' => 'tabella', 'etichetta' => 'Esami sostenuti in altro Ateneo', 'obbligatorio' => 1, 'cond' => $cond_a,
             'opzioni' => ['Codice insegnamento:codice', 'Denominazione insegnamento:denominazione', 'CFU:cfu', 'Settore scientifico disciplinare:ssd', 'Voto:voto', 'Data sostenimento:data', 'Da inserire nel piano come a scelta:piano'],
             'aiuto' => 'Un esame per riga, come nel certificato dell\'Ateneo. Spunta «a scelta» se vuoi l\'insegnamento convalidato nel piano di studi come insegnamento a scelta.'],
            ['tipo' => 'file', 'etichetta' => 'Programmi dei corsi', 'obbligatorio' => 1, 'cond' => $cond_a, 'aiuto' => 'Un unico PDF con i programmi degli insegnamenti, nell\'ordine della tabella.'],
            ['tipo' => 'textarea', 'etichetta' => 'Note per l\'ufficio'],
            ['tipo' => 'dichiarazione', 'etichetta' => 'Dichiaro che quanto indicato corrisponde al vero', 'obbligatorio' => 1, 'aiuto' => Costanti::TESTO_DICHIARAZIONE_DPR],
            ['tipo' => 'dichiarazione', 'etichetta' => 'Ho letto l\'informativa sul trattamento dei dati personali', 'obbligatorio' => 1,
             'aiuto' => 'I dati sono trattati dall\'Università della Calabria per la gestione della domanda e della carriera, ai sensi del Regolamento (UE) 2016/679 e del D.Lgs. 196/2003; l\'informativa completa è sul sito di Ateneo.'],
        ];
        $verbale = ['sezione' => 'Riconoscimento e convalida esami', 'stile' => 'scheda', 'decisione' => 'convalide',
                    'testo' => '{STUDENTE}, matricola {MATRICOLA}, iscritto/a per l\'a.a. {Anno accademico di iscrizione} al {Corso di studio}, con domanda prot. n. {PROTOCOLLO}, chiede il riconoscimento degli esami sostenuti presso {ATENEO_PRECEDENTE} ({Corso di studio della carriera precedente}).',
                    'delibera' => 'Il Consiglio, esaminata la documentazione, delibera le convalide riportate nel quadro.'];
        $domanda = ['pdf' => 1, 'bollo' => 1, 'oggetto' => 'Richiesta di convalida/riconoscimento esami',
                    'destinatario' => "Al Direttore del Dipartimento di Biologia, Ecologia e Scienze della Terra\ne/o al Coordinatore del Corso di Studio\nUniversità della Calabria – SEDE",
                    'chiede' => 'la convalida/il riconoscimento dei seguenti esami sostenuti nella precedente carriera presso {ATENEO_PRECEDENTE} ({Corso di studio della carriera precedente}):'];
        $this->db->esegui(
            "INSERT INTO didattica_moduli (titolo, descrizione, categoria, tipo, campi_json, destinatari, attivo, ordine, verbale_json, iter_json, giorni_promemoria, chiave, domanda_json, aggiornato_il)
                          VALUES (?, ?, ?, 'online', ?, 'studenti', 0, 1, ?, ?, 7, 'convalida_esami', ?, NOW())",
            ['Richiesta di convalida/riconoscimento esami', '<p>Per chiedere la convalida degli esami sostenuti in una precedente carriera universitaria (all\'Università della Calabria o in un altro Ateneo). La domanda arriva all\'Ufficio protocollo e poi all\'ufficio del tuo corso di studio; l\'esito lo decide il Consiglio di corso di studio.</p>',
                   'Carriera', json_encode($campi, JSON_UNESCAPED_UNICODE), json_encode($verbale, JSON_UNESCAPED_UNICODE), json_encode(['protocollo', '@cdl', '@segreteria']), json_encode($domanda, JSON_UNESCAPED_UNICODE)]
        );
        return (int)$this->db->valore("SELECT id FROM didattica_moduli WHERE chiave = 'convalida_esami'");
    }

    public function rigenera(int $id, array $m): void
    {
        if ($this->domanda->config($m)['pdf']) {
            $this->domanda->genera($id);
        }
        // Un docente non aveva confermato e lo studente ha corretto: i docenti ricevono di nuovo la richiesta
        $p = $this->pratiche->perId($id);
        $rq = $p ? (json_decode((string) ($p['richiesta_json'] ?? ''), true) ?: []) : [];
        if ($p && $p['stato'] === 'integrazione' && !empty($rq['conferma']) && ($err = $this->conferme->riavvia($id))) {
            $this->storico->evento($id, 'messaggio', 'ufficio', 0, null, 'La conferma dei docenti non è ripartita: ' . $err, null, null, true);
        }
    }

    public function avvia(int $id, array $m, array $u): void
    {
        if ($this->domanda->config($m)['pdf']) {
            $this->domanda->genera($id);
        }
        $p = $this->pratiche->perId($id);
        $primo = $this->iter->iter($m, $p)[0] ?? 0;
        if ($primo > 0 && ($this->uffici->tutti()[$primo]['tipo'] ?? '') === 'protocollo') {
            $this->db->esegui("UPDATE pratiche SET ufficio_id = ?, passo = 1 WHERE id = ?", [$primo, $id]);
            $this->storico->evento($id, 'passaggio', 'ufficio', 0, null, 'In carico a: ' . $this->uffici->tutti()[$primo]['nome'] . ' (da protocollare)');
        }
        // Iter che comincia con una firma (es. il relatore firma prima del protocollo): la richiesta parte subito
        if (in_array($primo, [Costanti::ITER_PERSONA, Costanti::ITER_DIRETTORE], true) && ($err = $this->firme->avvia($id, 1))) {
            $this->storico->evento($id, 'messaggio', 'ufficio', 0, null, 'La firma non è partita: ' . $err, null, null, true);
        }
        // Iter che comincia con la conferma dei docenti (attestati): i docenti ricevono subito il loro link
        if ($primo === Costanti::ITER_CONFERMA && ($err = $this->conferme->avvia($id, 1))) {
            $this->storico->evento($id, 'messaggio', 'ufficio', 0, null, 'La conferma dei docenti non è partita: ' . $err, null, null, true);
        }
    }

    /**
     * Porta la pratica al passo dell'iter con indice $idx (0 = il primo): un ufficio (l'ufficio del corso e la segreteria si risolvono con la pratica)
     * oppure una firma (persona indicata nel modulo, Direttore). Ritorna [errore, messaggio]: errore null = fatto.
     *
     * @return array{0: ?string, 1: string}
     */
    public function trasmettiAlPasso(int $id, int $idx, int $uid, string $autore_nome = ''): array
    {
        $p = $this->pratiche->perId($id);
        $m = $p ? $this->moduli->perId((int)$p['modulo_id']) : null;
        if (!$p || !$m) {
            return ['Pratica non trovata.', ''];
        }
        $dopo = $this->iter->iter($m, $p)[$idx] ?? null;
        if ($dopo === null) {
            // Documento firmato dal Direttore (certificato, attestato): lo studente è avvisato e lo scarica dalla pratica
            if ($this->db->valore("SELECT id FROM pratiche_eventi WHERE pratica_id = ? AND interno = 0 AND nome_allegato LIKE 'Firmato\_direttore\_%' LIMIT 1", [$id])) {
                $this->notifiche->documentoPronto($p);
                $this->storico->evento($id, 'attivita', 'ufficio', 0, null, 'Il documento firmato dal Direttore è pronto: lo studente è stato avvisato e può scaricarlo.');
            }
            $this->firme->avvisaOperatori($p, 'pratica_iter_concluso', ['codice' => (string) $p['codice']]);
            return [null, "Non ci sono altri passi nell'iter: concludi la pratica."];
        }
        if (in_array($dopo, [Costanti::ITER_PERSONA, Costanti::ITER_DIRETTORE], true)) {
            $err = $this->firme->avvia($id, $idx + 1);
            return $err ? [$err, ''] : [null, 'La pratica è stata mandata alla firma.'];
        }
        if ($dopo === Costanti::ITER_CONFERMA) {
            $err = $this->conferme->avvia($id, $idx + 1);
            return $err ? [$err, ''] : [null, 'La pratica è stata mandata alla conferma dei docenti.'];
        }
        $extra = [];
        if ($dopo === Costanti::ITER_CDL) {
            $dopo = $this->ufficioDelCorso(self::corsoPratica($p));
            if (!$dopo) {
                return ["Nessun ufficio di corso di studio ha il corso «" . (self::corsoPratica($p) ?: '—') . "»: assegna la pratica a mano (o indica i corsi nel consiglio).", ''];
            }
            $extra = ['cdl_id' => $dopo];
        }
        if ($dopo <= 0) {
            return ["Il passo successivo (segreteria studenti) si sceglie con la pratica: assegnala a mano.", ''];
        }
        $this->assegnaAUfficio($id, $dopo, $idx + 1, $uid, $autore_nome, '', $extra);
        return [null, 'La pratica è passata a «' . $this->uffici->tutti()[$dopo]['nome'] . '».'];
    }

    public function assegnaAUfficio(int $id, int $uff_id, int $passo, int $uid, string $autore_nome = '', string $nota = '', array $extra = [], bool $email_ufficio = true): void
    {
        $p = $this->pratiche->perId($id);
        $uff = $this->uffici->tutti()[$uff_id] ?? null;
        if (!$p || !$uff) {
            return;
        }
        $persone = $this->personeUfficio($uff_id);
        $corso = self::corsoPratica($p);
        $seguono = array_values(array_filter($persone, fn ($o) => $corso !== '' && in_array($corso, json_decode((string)($o['corsi'] ?? ''), true) ?: [], true)));
        $chi = count($seguono) === 1 ? $seguono[0] : (count($persone) === 1 ? $persone[0] : null);
        $stato = $p['stato'] === 'inviata' ? 'in_lavorazione' : $p['stato'];
        // Chi l'aveva in carico continua a vederla (operatori della pratica)
        if (!empty($p['assegnata_a'])) {
            $this->db->esegui("INSERT IGNORE INTO pratiche_operatori (pratica_id, operatore_id, passo) VALUES (?, ?, ?)", [$id, (int)$p['assegnata_a'], (int)$p['passo']]);
        }
        $set = "ufficio_id = ?, assegnata_a = ?, passo = ?, stato = ?, aggiornata_il = NOW()";
        $par = [$uff_id, $chi ? (int)$chi['id'] : null, $passo, $stato];
        foreach ($extra as $k => $v) {
            if (in_array($k, ['cdl_id', 'segreteria_id', 'inviata_segreteria_il'], true)) {
                $set .= ", $k = ?";
                $par[] = $v;
            }
        }
        $par[] = $id;
        $this->db->esegui("UPDATE pratiche SET $set WHERE id = ?", $par);
        if ($chi) {
            $this->db->esegui("INSERT INTO pratiche_operatori (pratica_id, operatore_id, passo) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE passo = VALUES(passo)", [$id, (int)$chi['id'], $passo]);
        }
        $this->storico->evento($id, 'passaggio', 'ufficio', $uid, $stato !== $p['stato'] ? $stato : null, 'In carico a: ' . $uff['nome'] . ($chi ? ' – ' . $chi['nominativo'] : ''), null, null, false, $autore_nome);
        if (trim($nota) !== '') {
            $this->storico->evento($id, 'messaggio', 'ufficio', $uid, null, mb_substr(trim($nota), 0, 3000), null, null, true, $autore_nome);
        }
        foreach ($email_ufficio ? $persone : [] as $o) {
            if (!filter_var($o['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $this->notifiche->modello(
                $o['email'],
                'pratica_ufficio_arrivata',
                NotifichePratiche::datiPratica($p) + [
                    'operatore' => (string) $o['nominativo'],
                    'ufficio' => (string) $uff['nome'],
                    'corso' => $corso !== '' ? ' del corso ' . $corso : '',
                    'assegnazione' => $chi ? ($chi['id'] === $o['id'] ? ': è assegnata a te.' : ': è assegnata a ' . $chi['nominativo'] . '.') : ": la può prendere in carico chiunque dell'ufficio.",
                    'nota' => InvioEmail::riquadro($nota),
                ],
                $this->sito->urlBase() . '/admin/didattica.php?tab=pratiche&id=' . $id
            );
        }
        if ($p2 = $this->pratiche->perId($id)) {
            $this->notifiche->email($p2, 'passaggio', (string) $uff['nome']);
        }
    }

    public function registraProtocollo(int $id, string $numero, string $data, int $uid, string $autore_nome = '', bool $bollo = false): array
    {
        $p = $this->pratiche->perId($id);
        if (!$p) {
            return ["Pratica non trovata.", ''];
        }
        $numero = mb_substr(trim($numero), 0, 100) ?: (string)$p['protocollo'];
        $data = $data ?: (string)$p['protocollo_data'];
        if ($numero === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > date('Y-m-d')) {
            return ["Indica il numero e la data del protocollo (non nel futuro).", ''];
        }
        if ($numero !== (string)$p['protocollo'] || $data !== (string)$p['protocollo_data']) {
            $this->db->esegui("UPDATE pratiche SET protocollo = ?, protocollo_data = ?, aggiornata_il = NOW() WHERE id = ?", [$numero, $data, $id]);
            $this->storico->evento($id, 'attivita', 'ufficio', $uid, null, 'Domanda protocollata: prot. n. ' . $numero . ' del ' . date('d/m/Y', (int) strtotime($data)) . '.', null, null, false, $autore_nome);
        }
        $m = $this->moduli->perId((int)$p['modulo_id']);
        if ($bollo && empty($p['bollo_il'])) {
            $this->db->esegui("UPDATE pratiche SET bollo_il = NOW(), bollo_da = ? WHERE id = ?", [mb_substr($autore_nome, 0, 200), $id]);
            $this->storico->evento($id, 'attivita', 'ufficio', $uid, null, 'Marca da bollo: pagamento verificato.', null, null, false, $autore_nome);
            $p['bollo_il'] = date('Y-m-d H:i:s');
        }
        if ($this->domanda->config($m)['bollo'] && empty($p['bollo_il'])) {
            return [null, "Protocollo registrato. La pratica resta all'Ufficio protocollo finché non risulta pagata la marca da bollo: spuntala quando c'è e la pratica sarà trasmessa."];
        }
        // Già trasmessa (il passo attuale non è più il protocollo): si aggiornano solo i dati
        $it = $this->iter->iter($m, $p);
        $pos = (int)$p['passo'] - 1;
        if (($this->uffici->tutti()[$it[$pos] ?? 0]['tipo'] ?? '') !== 'protocollo') {
            return [null, "Dati del protocollo aggiornati."];
        }
        // Passo successivo dell'iter: l'ufficio del corso della pratica ("@cdl") o un ufficio fisso
        $dopo = $it[$pos + 1] ?? null;
        if ($dopo === null) {
            return [null, "Protocollo registrato."];
        }
        if (in_array($dopo, [Costanti::ITER_PERSONA, Costanti::ITER_DIRETTORE], true)) {
            $err = $this->firme->avvia($id, $pos + 2);
            return [null, $err ? "Protocollo registrato. La firma non è partita: " . $err : "Protocollo registrato: la pratica è stata mandata alla firma."];
        }
        if ($dopo === Costanti::ITER_CONFERMA) {
            $err = $this->conferme->avvia($id, $pos + 2);
            return [null, $err ? "Protocollo registrato. La conferma dei docenti non è partita: " . $err : "Protocollo registrato: la pratica è stata mandata alla conferma dei docenti."];
        }
        $extra = [];
        if ($dopo === Costanti::ITER_CDL) {
            $corso = self::corsoPratica($p);
            $dopo = $this->ufficioDelCorso($corso);
            if (!$dopo) {
                $this->storico->evento($id, 'messaggio', 'ufficio', $uid, null, 'Nessun ufficio per il corso «' . ($corso ?: '—') . '»: va assegnata a mano (indica i corsi nel consiglio e il consiglio nell\'ufficio).', null, null, true, $autore_nome);
                return [null, "Protocollo registrato. Nessun ufficio di corso di studio ha il corso «" . ($corso ?: '—') . "»: assegna la pratica a mano (o indica i corsi nel consiglio)."];
            }
            $extra = ['cdl_id' => $dopo];
        }
        if ($dopo <= 0) {
            return [null, "Protocollo registrato. Il passo successivo si sceglie dopo (es. segreteria studenti): assegna la pratica a mano."];
        }
        $this->assegnaAUfficio($id, $dopo, $pos + 2, $uid, $autore_nome, '', $extra);
        $cdl = $dopo;
        return [null, "Protocollo registrato: la pratica è passata a «" . $this->uffici->tutti()[$cdl]['nome'] . "»."];
    }

    public function inviaPraticheSegreteria(array $ids, int $uff_id, int $uid, string $autore_nome = ''): array
    {
        $uff = $this->uffici->tutti(true)[$uff_id] ?? null;
        if (!$uff || ($uff['tipo'] ?? '') !== 'segreteria') {
            return [0, ["Scegli una segreteria studenti."]];
        }
        $inviate = [];
        $escluse = [];
        $verbali = [];
        foreach ($ids as $id) {
            $p = $this->pratiche->perId((int)$id);
            if (!$p) {
                continue;
            }
            $chi = trim($p['cognome'] . ' ' . $p['nome']) . ' (' . $p['codice'] . ')';
            $s = $p['seduta_id'] ? $this->sedute->perId((int)$p['seduta_id']) : null;
            if (!$s || in_array((string)$p['esito_seduta'], ['', 'rinviata'], true)) {
                $escluse[] = "$chi: non ancora esaminata in seduta";
                continue;
            }
            if (!$this->db->valore("SELECT id FROM pratiche_eventi WHERE pratica_id = ? AND nome_allegato LIKE 'Estratto_verbale_%'", [(int)$p['id']])) {
                $this->estratto->allega($s, (int)$p['id'], $uid, $autore_nome);
            }
            // Verbale della seduta: una copia per seduta tra gli allegati delle pratiche (si scarica solo da chi vede la pratica)
            $sid = (int)$s['id'];
            if (!array_key_exists($sid, $verbali)) {
                $verbali[$sid] = null;
                if (($f = $this->verbale->percorso($s))) {
                    $nome = 'verbale_seduta_' . $sid . '_' . bin2hex(random_bytes(4)) . '.pdf';
                    if (@copy($f, $this->sito->radice() . '/' . $this->allegati->cartella() . $nome)) {
                        $verbali[$sid] = [$this->allegati->cartella() . $nome, ($s['verbale_stato'] ?? '') === 'firmato'];
                    }
                }
            }
            if ($verbali[$sid]) {
                $this->storico->evento(
                    (int)$p['id'],
                    'attivita',
                    'ufficio',
                    $uid,
                    null,
                    'Verbale della seduta del ' . ($s['data'] ? date('d/m/Y', strtotime($s['data'])) : '') . ($verbali[$sid][1] ? ' (firmato in PAdES)' : ' (non ancora firmato)') . ' per la segreteria studenti.',
                    $verbali[$sid][0],
                    'Verbale_seduta_' . ($s['data'] ? date('Ymd', strtotime($s['data'])) : $sid) . '.pdf',
                    true,
                    $autore_nome
                );
            }
            $m = $this->moduli->perId((int)$p['modulo_id']);
            $p['segreteria_id'] = $uff_id;
            $passo = array_search($uff_id, $this->iter->iter($m, $p), true);
            $this->assegnaAUfficio((int)$p['id'], $uff_id, $passo === false ? max(1, (int)$p['passo']) : $passo + 1, $uid, $autore_nome, '', ['segreteria_id' => $uff_id, 'inviata_segreteria_il' => date('Y-m-d H:i:s')], false);
            $inviate[] = $p + ['_seduta' => $s];
        }
        // Una sola email di riepilogo alle persone della segreteria
        if ($inviate) {
            $h = fn ($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
            $righe = '';
            foreach ($inviate as $p) {
                $righe .= "<tr><td style='padding:4px 8px;border:1px solid #e2e8f0;'><a href='" . $h($this->sito->urlBase() . '/admin/didattica.php?tab=pratiche&id=' . (int)$p['id']) . "'>" . $h($p['codice']) . "</a></td>"
                    . "<td style='padding:4px 8px;border:1px solid #e2e8f0;'>" . $h(trim($p['cognome'] . ' ' . $p['nome'])) . ($p['matricola'] !== '' ? ' – ' . $h($p['matricola']) : '') . "</td>"
                    . "<td style='padding:4px 8px;border:1px solid #e2e8f0;'>" . $h($p['modulo_titolo']) . "</td><td style='padding:4px 8px;border:1px solid #e2e8f0;'>" . $h(Costanti::ESITI_SEDUTA[$p['esito_seduta']][0] ?? '') . "</td>"
                    . "<td style='padding:4px 8px;border:1px solid #e2e8f0;'>" . ($p['_seduta']['data'] ? date('d/m/Y', strtotime($p['_seduta']['data'])) : '') . "</td></tr>";
            }
            $tabella = "<table style='border-collapse:collapse;font-size:13px;'><tr><th style='padding:4px 8px;border:1px solid #e2e8f0;'>Pratica</th><th style='padding:4px 8px;border:1px solid #e2e8f0;'>Studente</th><th style='padding:4px 8px;border:1px solid #e2e8f0;'>Richiesta</th><th style='padding:4px 8px;border:1px solid #e2e8f0;'>Esito</th><th style='padding:4px 8px;border:1px solid #e2e8f0;'>Seduta</th></tr>$righe</table>";
            foreach ($this->personeUfficio($uff_id) as $o) {
                if (!filter_var($o['email'], FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $this->notifiche->modello(
                    $o['email'],
                    'pratica_riepilogo_segreteria',
                    ['operatore' => (string) $o['nominativo'], 'quante' => (string) count($inviate), 'ufficio' => (string) $uff['nome'], 'autore' => $autore_nome ?: "L'ufficio", 'tabella' => $tabella],
                    $this->sito->urlBase() . '/admin/didattica.php?tab=pratiche&carico=ufficio'
                );
            }
        }
        return [count($inviate), $escluse];
    }
}
