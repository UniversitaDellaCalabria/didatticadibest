<?php
// Prove dell'assistenza (App\Assistenza): conversazioni di chi ha il login e degli esterni, operatori dell'ufficio «Assistenza», prese in carico, note interne,
// chiusura e riapertura, avvisi per email solo quando servono e limiti contro gli abusi. Chiamato da esegui.php. Dati di prova con id 989xx.
sezione("Assistenza: chat con operatori, esterni, avvisi e limiti");

use App\Assistenza\Costanti as CostAs;
use App\Core\App as AppAs;

foreach (["DELETE FROM assistenza_messaggi", "DELETE FROM assistenza_conversazioni", "DELETE FROM uffici_persone WHERE email = 'op989@example.org'", "DELETE FROM utenti WHERE id IN (98901, 98902, 98903)"] as $sql) $q($sql);
$q("UPDATE impostazioni_sistema SET didacticus_attivo = 0 WHERE id = 1"); // Didacticus ha le sue prove (assistenza_didacticus.php)
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (98901, 'Olga', 'Operatrice', 'op989@example.org', 5, ''), (98902, 'Sara', 'Studente', 'stud989@example.org', 3, ''), (98903, 'Aldo', 'Admin', 'adm989@example.org', 1, '')");

$uff_as = AppAs::per($conn)->get(\App\Uffici\UfficiRepository::class);
$ass = $uff_as->perChiave('assistenza');
prova($ass !== null && \App\Uffici\ServizioUffici::fisso($ass), "l'ufficio «Assistenza» esiste ed è fisso (non si può eliminare)");

$op_as = AppAs::per($conn)->get(\App\Assistenza\OperatoriAssistenza::class);
$u_op = ['id' => 98901, 'email' => 'op989@example.org', 'ruolo_id' => 5, 'ruoli_secondari' => ''];
$u_st = ['id' => 98902, 'nome' => 'Sara', 'cognome' => 'Studente', 'email' => 'stud989@example.org', 'ruolo_id' => 3, 'ruoli_secondari' => ''];
$u_ad = ['id' => 98903, 'email' => 'adm989@example.org', 'ruolo_id' => 1, 'ruoli_secondari' => ''];
prova(!$op_as->eOperatore($u_op) && !$op_as->eOperatore($u_st) && $op_as->eOperatore($u_ad), "prima di entrare nell'ufficio solo l'amministratore è operatore");
prova(in_array('adm989@example.org', $op_as->emailAvvisi(), true), "ufficio vuoto: gli avvisi vanno agli amministratori");
$q("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo, compiti) VALUES ({$ass['id']}, 'op989@example.org', 'Olga Operatrice', '', '')");
prova($op_as->eOperatore($u_op) && !$op_as->eOperatore($u_st) && $op_as->emailAvvisi() === ['op989@example.org'], "dopo l'inserimento nell'ufficio la persona è operatrice e riceve gli avvisi", json_encode($op_as->emailAvvisi()));
prova($op_as->colleghi() === [98901 => 'Olga Operatrice'] && $op_as->emailDi(98901) === 'op989@example.org', "colleghi (persone dell'ufficio con un utente nel portale) ed email dell'operatrice");

$ora_as = new class implements \App\Core\Orologio {
    public DateTimeImmutable $t;
    public function adesso(): DateTimeImmutable { return $this->t; }
};
$ora_as->t = new DateTimeImmutable('2026-10-22 10:00:00');
$cont_as = AppAs::per($conn);
$srv = new \App\Assistenza\ServizioAssistenza($cont_as->get(\App\Assistenza\ConversazioniRepository::class), $cont_as->get(\App\Assistenza\MessaggiAssistenzaRepository::class),
    $op_as, $cont_as->get(\App\Assistenza\NotificheAssistenza::class), $orari_as = new \App\Assistenza\OrariAssistenza(\App\Core\Database::per($conn), $ora_as), $ora_as, $cont_as->get(\App\Assistenza\Didacticus\ServizioDidacticus::class), $cont_as->get(\App\Assistenza\Didacticus\MancateRepository::class));
$minuti = fn(int $m) => $ora_as->t = $ora_as->t->modify("+$m minutes");
$em = function (string $a, string $oggetto = '') use (&$EMAIL): int { return count(array_filter($EMAIL, fn($e) => $e['a'] === $a && ($oggetto === '' || str_contains($e['oggetto'], $oggetto)))); };
$operatrice = ['id' => 98901, 'nome' => 'Olga Operatrice'];
$amministratore = ['id' => 98903, 'nome' => 'Aldo Admin'];

prova(\App\Assistenza\ServizioAssistenza::ripulisci("  a\r\n\r\n\r\n\r\nb\x07c  ") === "a\n\n" . "bc", "il testo si ripulisce: a capo uniformi, righe vuote ripetute e caratteri di controllo tolti");

// --- Chi ha il login apre la chat
[$c, $err] = $srv->apriDaUtente($u_st, 'certificati', "Come richiedo il certificato?\nGrazie", ['tipo' => 'pratica', 'id' => 5, 'testo' => 'la pratica P-2026-0001'], '10.9.89.9');
prova($err === null && $c !== null && $c['stato'] === 'nuova' && $c['canale'] === 'utente' && $c['nome'] === 'Sara Studente' && $c['argomento'] === 'certificati' && $c['contesto_testo'] === 'la pratica P-2026-0001', "apertura da utente: conversazione «da prendere» con argomento e pratica collegata", json_encode([$c, $err]));
$id1 = (int) $c['id'];
prova($em('op989@example.org', 'Nuova richiesta di assistenza') === 1, "l'operatrice riceve l'email di nuova richiesta");
prova(str_contains(end($EMAIL)['corpo'], 'la pratica P-2026-0001') && str_contains(end($EMAIL)['corpo'], 'admin/assistenza.php?c=' . $id1), "l'email dice di cosa parla e porta alla chat");
[$c2, $err2] = $srv->apriDaUtente($u_st, 'altro', 'Un\'altra domanda', null, '10.9.89.9');
prova($err2 === null && (int) $c2['id'] === $id1 && count($srv->messaggi($c2)) === 2 && $em('op989@example.org') === 1, "chi ha già una chat aperta continua quella (nessuna seconda conversazione, nessuna seconda email)");
prova($srv->apriDaUtente($u_st, 'altro', '   ')[1] === 'Scrivi il messaggio.' && str_contains((string) $srv->apriDaUtente($u_st, 'altro', str_repeat('x', 1501))[1], 'troppo lungo'), "messaggio vuoto o troppo lungo rifiutato");
prova($srv->apriDaUtente(['id' => 0], 'altro', 'Ciao')[1] !== null, "senza login non si apre una chat di utente");

// --- L'operatrice risponde
$minuti(1);
prova($srv->rispondi($c, $operatrice, '') === 'Scrivi il messaggio.', "risposta vuota rifiutata");
prova($srv->rispondi($c, $operatrice, 'Ciao Sara, il certificato si chiede dall\'Area personale.') === null, "l'operatrice risponde");
$c = $srv->perId($id1);
prova($c['stato'] === 'assegnata' && (int) $c['operatore_id'] === 98901 && $c['prima_risposta_il'] === '2026-10-22 10:01:00', "la chat da prendere diventa sua e si segna la prima risposta", json_encode($c));
$vis = array_column($srv->messaggi($c), 'testo');
prova(in_array('Ti risponde Olga Operatrice.', $vis, true), "chi ha chiesto aiuto vede chi gli risponde");
prova($em('stud989@example.org') === 0, "nessuna email a chi ha la chat aperta (era qui un minuto fa)");
$minuti(5);
$srv->rispondi($c, $operatrice, 'Fammi sapere se ti serve altro.');
prova($em('stud989@example.org', 'risposta dall\'assistenza') === 1, "a chi non ha la chat aperta arriva l'email di risposta");
prova(str_contains(end($EMAIL)['corpo'], 'Fammi sapere se ti serve altro.') && str_contains(end($EMAIL)['corpo'], '/assistenza.php') && !str_contains(end($EMAIL)['corpo'], '?t='), "l'email porta il testo e la pagina dell'utente (non un link con codice)");
$minuti(2);
$srv->rispondi($srv->perId($id1), $operatrice, 'Un\'ultima cosa.');
prova($em('stud989@example.org') === 1, "non più di una email ogni dieci minuti");

// --- Il collega non può rispondere sopra
prova(str_contains((string) $srv->rispondi($srv->perId($id1), $amministratore, 'Rispondo io'), 'in carico a Olga Operatrice'), "una chat in carico a un collega non si risponde senza «Prendo io»");

// --- Avvisi all'operatrice solo se non guarda la chat
$minuti(1);
prova($srv->scriviComeUtente($srv->perId($id1), 'Grazie mille!') === null && $em('op989@example.org', 'Nuovo messaggio') === 0, "l'operatrice ha la chat aperta: nessuna email");
$minuti(30);
$srv->scriviComeUtente($srv->perId($id1), 'Ancora una domanda.');
prova($em('op989@example.org', 'Nuovo messaggio da Sara Studente') === 1, "operatrice assente da mezz'ora: arriva l'email");
$minuti(5);
$srv->scriviComeUtente($srv->perId($id1), 'E un\'altra.');
prova($em('op989@example.org', 'Nuovo messaggio') === 1, "non più di una email ogni quarto d'ora");

// --- Lettura, note interne
$prima_da_leggere = $srv->daLeggere();
$srv->apertaDa($srv->perId($id1), 'operatore');
prova($prima_da_leggere >= 1 && $srv->daLeggere() === $prima_da_leggere - 1, "aprendo la chat l'operatrice segna letto: il contatore scende");
prova($srv->nota($srv->perId($id1), $operatrice, 'Studentessa del primo anno.') === null, "nota interna");
prova(count($srv->messaggi($srv->perId($id1), 0, true)) === count($srv->messaggi($srv->perId($id1), 0, false)) + 1, "la nota la vedono solo gli operatori");
prova(!in_array('Studentessa del primo anno.', array_column($srv->messaggi($srv->perId($id1)), 'testo'), true), "la nota non è tra i messaggi di chi ha chiesto aiuto");

// --- Prendo io, passa a
prova($srv->prendiInCarico($srv->perId($id1), $amministratore) === null && (int) $srv->perId($id1)['operatore_id'] === 98903, "«Prendo io»: la chat passa all'amministratore");
$n_prima = $em('op989@example.org');
prova($srv->passaA($srv->perId($id1), $amministratore, $operatrice) === null && (int) $srv->perId($id1)['operatore_id'] === 98901 && $em('op989@example.org') === $n_prima + 1, "«Passa a…»: il collega riceve subito l'email");

// --- Chiusura, valutazione, riapertura
prova($srv->valuta($srv->perId($id1), true) !== null, "non si valuta una chat ancora aperta");
$srv->chiudi($srv->perId($id1), 'operatore');
$c = $srv->perId($id1);
prova($c['stato'] === 'chiusa' && $c['chiusa_da'] === 'operatore' && $c['chiusa_il'] !== null, "chiusura da parte dell'operatrice");
prova(str_contains((string) $srv->rispondi($c, $operatrice, 'Ancora'), 'chiusa'), "su una chat chiusa non si risponde senza riaprirla");
prova($srv->valuta($c, true) === null && $srv->perId($id1)['valutazione'] === '1', "chi ha chiesto aiuto valuta la risposta");
$minuti(60);
prova($srv->scriviComeUtente($srv->perId($id1), 'Mi serve ancora aiuto') === null, "se chi ha chiesto aiuto riscrive, la chat si riapre");
$c = $srv->perId($id1);
prova($c['stato'] === 'assegnata' && (int) $c['operatore_id'] === 98901 && $c['valutazione'] === null && $c['chiusa_il'] === null, "riaperta: torna alla stessa operatrice, senza valutazione né chiusura");

// --- Esterni: nome, email, link personale
$n_ext = $em('rossi989@example.org');
prova($srv->apriDaEsterno('A', 'rossi989@example.org', 'tirocini', 'Ciao', '10.9.89.1')[1] === 'Scrivi il tuo nome e cognome.', "esterno: nome troppo corto rifiutato");
prova($srv->apriDaEsterno('Mario Rossi', 'non-una-email', 'tirocini', 'Ciao', '10.9.89.1')[1] !== null, "esterno: email non valida rifiutata");
[$e1, $err_e] = $srv->apriDaEsterno('Mario Rossi', 'Rossi989@Example.org', 'tirocini', 'Come funziona il tirocinio per le aziende?', '10.9.89.1');
prova($err_e === null && $e1['canale'] === 'esterno' && $e1['utente_id'] === null && $e1['email'] === 'rossi989@example.org' && preg_match('/^[a-f0-9]{32}$/', (string) $e1['token']) === 1, "esterno: conversazione con email normalizzata e codice personale", json_encode([$e1, $err_e]));
$ricevuta_as = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'rossi989@example.org' && str_contains($e['oggetto'], 'Abbiamo ricevuto')));
prova($em('rossi989@example.org', 'Abbiamo ricevuto') === $n_ext + 1 && str_contains((string) ($ricevuta_as[0]['corpo'] ?? ''), 'assistenza.php?t=' . $e1['token']), "l'esterno riceve il link personale per riprendere la chat");
prova($srv->perToken((string) $e1['token'])['id'] === $e1['id'] && $srv->perToken('xyz') === null && $srv->perToken(str_repeat('a', 32)) === null, "il codice personale apre la sua chat e nessun'altra");
prova($em('op989@example.org', 'Nuova richiesta di assistenza') === 2, "anche la richiesta dell'esterno avvisa l'operatrice");
$srv->apriDaEsterno('Mario Rossi', 'rossi989@example.org', 'tirocini', 'Seconda', '10.9.89.2');
$srv->apriDaEsterno('Mario Rossi', 'rossi989@example.org', 'tirocini', 'Terza', '10.9.89.3');
prova(str_contains((string) $srv->apriDaEsterno('Mario Rossi', 'rossi989@example.org', 'tirocini', 'Quarta', '10.9.89.4')[1], 'molte richieste'), "limite di tre richieste all'ora per email");
$minuti(61);
prova($srv->apriDaEsterno('Mario Rossi', 'rossi989@example.org', 'tirocini', 'Dopo un\'ora', '10.9.89.5')[1] === null, "passata un'ora si può riscrivere");
for ($i = 1; $i <= 6; $i++) {
    $srv->apriDaEsterno('Persona ' . $i, "ip$i-989@example.org", 'altro', 'Domanda ' . $i, '10.9.89.77');
}
prova(str_contains((string) $srv->apriDaEsterno('Persona 7', 'ip7-989@example.org', 'altro', 'Domanda 7', '10.9.89.77')[1], 'molte richieste'), "limite di sei richieste all'ora per indirizzo IP");
prova($srv->apriDaEsterno('Persona 8', 'ip8-989@example.org', 'altro', 'Domanda 8', '10.9.89.78')[1] === null, "un altro indirizzo non è toccato dal limite");

// --- Limite di messaggi
[$f, ] = $srv->apriDaEsterno('Flood Test', 'flood989@example.org', 'altro', 'Primo', '10.9.89.88');
$ultimo_err = null;
for ($i = 2; $i <= 21; $i++) $ultimo_err = $srv->scriviComeUtente($srv->perId((int) $f['id']), "Messaggio $i");
prova(str_contains((string) $ultimo_err, 'molti messaggi'), "limite di venti messaggi in dieci minuti per conversazione");
$minuti(11);
prova($srv->scriviComeUtente($srv->perId((int) $f['id']), 'Dopo dieci minuti') === null, "passati dieci minuti si può scrivere di nuovo");

// --- Argomenti, casella, catalogo email
prova(CostAs::argomentoValido('tesi') === 'tesi' && CostAs::argomentoValido('inventato') === 'altro' && CostAs::nomeArgomento('certificati') === 'Certificati e attestati', "argomenti: uno sconosciuto diventa «Altro»");
$rep_as = $cont_as->get(\App\Assistenza\ConversazioniRepository::class);
prova(count(array_filter($rep_as->elenco('da_prendere', 0), fn($x) => (int) $x['id'] === (int) $e1['id'])) === 1 && count(array_filter($rep_as->elenco('mie', 98901), fn($x) => (int) $x['id'] === $id1)) === 1
    && count(array_filter($rep_as->elenco('da_prendere', 0), fn($x) => (int) $x['id'] === $id1)) === 0, "casella: «da prendere» e «mie» separate");
$srv->chiudi($srv->perId((int) $f['id']), 'utente');
prova(count(array_filter($rep_as->elenco('chiuse', 0), fn($x) => (int) $x['id'] === (int) $f['id'])) === 1 && count(array_filter($rep_as->elenco('tutte', 0), fn($x) => (int) $x['id'] === (int) $f['id'])) === 0, "casella: le chiuse stanno solo in «chiuse»");
$pc = $rep_as->contaPerStato();
prova(($pc['assegnata'] ?? 0) >= 1 && ($pc['nuova'] ?? 0) >= 1 && ($pc['chiusa'] ?? 0) >= 1, "conteggio per stato");
foreach (['assistenza_operatori_nuova', 'assistenza_operatori_messaggio', 'assistenza_utente_ricevuta', 'assistenza_utente_risposta'] as $chiave_as) {
    prova(isset(\App\Email\CatalogoEmail::modelli()[$chiave_as]), "le email dell'assistenza sono nel catalogo: $chiave_as");
}

// --- Le risposte JSON delle pagine (ChatApi) e la casella unica
$api = $cont_as->get(\App\Assistenza\ChatApi::class);
$el = $api->elenco($operatrice, 'tutte');
$mia = array_values(array_filter($el['elenco'], fn($x) => $x['id'] === $id1));
prova($el['ok'] && count($mia) === 1 && $mia[0]['stato'] === 'assegnata' && $mia[0]['operatore_nome'] === 'Olga Operatrice' && $mia[0]['argomento_nome'] === 'Certificati e attestati' && $el['contatori']['mie'] === 1, "ChatApi: la casella dice stato, operatrice, argomento e i numeri delle schede", json_encode($el['contatori']));
prova($api->elenco($operatrice, 'inventata')['vista'] === 'da_prendere', "ChatApi: una scheda sconosciuta diventa «da prendere»");
$ch = $api->chatOperatore($id1, 0);
$tipi = array_column($ch['messaggi'], 'tipo');
prova($ch['ok'] && in_array('nota', $tipi, true) && $ch['conv']['nome'] === 'Sara Studente' && $ch['colleghi'] === [['id' => 98901, 'nome' => 'Olga Operatrice']], "ChatApi: la chat dell'operatrice ha le note e i colleghi a cui passarla");
$ultimo_id = (int) end($ch['messaggi'])['id'];
prova($api->chatOperatore($id1, $ultimo_id)['messaggi'] === [], "ChatApi: chiedendo «dopo l'ultimo» non torna nulla di vecchio");
$cu = $api->chatUtente($srv->perId($id1), 0);
prova($cu['ok'] && !in_array('nota', array_column($cu['messaggi'], 'tipo'), true) && !isset($cu['conv']['email']) && $cu['conv']['operatore_nome'] === 'Olga Operatrice', "ChatApi: chi ha chiesto aiuto non vede note né dati degli operatori");
prova($api->chatOperatore(99999999, 0)['ok'] === false, "ChatApi: conversazione inesistente");
$dopo_invio = $api->chatOperatore($id1, 0)['messaggi'];
prova($api->azioneOperatore('invia', $id1, $operatrice, ['testo' => 'Risposta dal JSON'])['ok'] === true && end($dopo_invio) !== false && in_array('Risposta dal JSON', array_column($api->chatOperatore($id1, 0)['messaggi'], 'testo'), true), "ChatApi: l'operatrice risponde");
prova($api->azioneOperatore('invia', $id1, $operatrice, ['testo' => 'Solo per noi', 'nota' => 1])['ok'] === true && !in_array('Solo per noi', array_column($api->chatUtente($srv->perId($id1), 0)['messaggi'], 'testo'), true), "ChatApi: la nota interna non arriva a chi ha chiesto aiuto");
prova($api->azioneOperatore('passa', $id1, $operatrice, ['a' => 98903])['errore'] === 'Scegli un collega dell\'ufficio.', "ChatApi: si passa la chat solo a chi è nell'ufficio");
prova($api->azioneOperatore('boh', $id1, $operatrice, [])['ok'] === false, "ChatApi: azione sconosciuta rifiutata");
prova($api->azioneUtente('invia', $srv->perId($id1), ['testo' => 'Grazie!'])['ok'] === true && $api->azioneUtente('valuta', $srv->perId($id1), ['utile' => 1])['ok'] === false, "ChatApi: chi ha chiesto aiuto scrive, ma non valuta una chat ancora aperta");
$api->azioneUtente('chiudi', $srv->perId($id1), []);
prova($api->azioneUtente('valuta', $srv->perId($id1), ['utile' => 1])['ok'] === true && $api->chatUtente($srv->perId($id1), 0)['conv']['valutata'] === true, "ChatApi: chiusa la chat, la valutazione si registra");

$casella_as = $cont_as->get(\App\Iscritti\CasellaMessaggi::class);
$conv_cas = array_values(array_filter($casella_as->conversazioni(98903, true, false, true), fn($c) => $c['tipo'] === 'assistenza'));
prova(count($conv_cas) >= 3 && count(array_filter($conv_cas, fn($c) => $c['id'] === $id1 && $c['archiviata'] && $c['nome'] === 'Sara Studente' && $c['modulo_nome'] === 'Assistenza')) === 1, "casella unica: le chat di assistenza compaiono (le chiuse tra le archiviate)");
prova(count(array_filter($casella_as->conversazioni(98903, true, false), fn($c) => $c['tipo'] === 'assistenza')) === 0, "casella unica: senza il permesso le chat di assistenza non si vedono");
$srv_reale = $cont_as->get(\App\Assistenza\ServizioAssistenza::class);
prova($casella_as->puoAssistenza($u_op) && !$casella_as->puoAssistenza($u_st) && $casella_as->nonLetti(98903, true, false, true) === $casella_as->nonLetti(98903, true, false) + $srv_reale->daLeggere(), "casella unica: il contatore somma le chat con messaggi da leggere");

// --- Di cosa si parla: la pratica o la prenotazione, solo se sono di chi scrive
foreach (["DELETE FROM pratiche WHERE id = 98911", "DELETE FROM didattica_moduli WHERE id = 98910"] as $sql) $q($sql);
$q("INSERT INTO didattica_moduli (id, titolo) VALUES (98910, 'Certificato di iscrizione')");
$q("INSERT INTO pratiche (id, modulo_id, utente_id, codice, stato) VALUES (98911, 98910, 98902, 'P-989-001', 'inviata')");
$ctx_as = $cont_as->get(\App\Assistenza\Contesti::class);
prova($ctx_as->pratica(98911, 98902) === ['tipo' => 'pratica', 'id' => 98911, 'testo' => 'la pratica P-989-001 («Certificato di iscrizione»)'], "contesto: la pratica dello studente, con codice e modulo");
prova($ctx_as->pratica(98911, 98901) === null && $ctx_as->pratica(0, 98902) === null && $ctx_as->pratica(98911, 0) === null && $ctx_as->prenotazione(1, 98901) === null, "contesto: la pratica di un altro (o un numero inventato) non si collega");
$q("DELETE FROM assistenza_messaggi");
$q("DELETE FROM assistenza_conversazioni");
[$cx, $ex] = $srv->apriDaUtente($u_st, 'pratiche', 'Quando sarà pronta?', $ctx_as->pratica(98911, 98902), '10.9.89.50');
prova($ex === null && $cx['contesto_tipo'] === 'pratica' && (int) $cx['contesto_id'] === 98911 && $cx['contesto_testo'] === 'la pratica P-989-001 («Certificato di iscrizione»)', "la chat nasce collegata alla pratica", json_encode([$cx, $ex]));
prova($srv->apriDaUtente($u_st, 'altro', 'Domanda', ['tipo' => 'inventato', 'id' => 1, 'testo' => 'x'])[0]['contesto_tipo'] === 'pratica', "chi ha già una chat continua quella (il contesto non cambia)");
$rep_as2 = $cont_as->get(\App\Assistenza\ConversazioniRepository::class);
prova(count($rep_as2->diUtente(98902)) === 1 && (int) $rep_as2->diUtente(98902, 1)[0]['id'] === (int) $cx['id'] && $rep_as2->apertaDi(98902) !== null && $rep_as2->apertaDi(98901) === null, "le chat di una persona collegata si trovano per utente");
$ora_as->t = $ora_as->t->modify('+10 minutes');
$srv->rispondi($srv->perId((int) $cx['id']), $operatrice, 'Domani.');
$msg_rep = $cont_as->get(\App\Assistenza\MessaggiAssistenzaRepository::class);
prova($msg_rep->nonLetti((int) $cx['id'], 'utente') === 2 && $msg_rep->nonLetti((int) $cx['id'], 'operatore') === 2, "messaggi da leggere: la risposta e «Ti risponde…» per la persona, i suoi due per l'operatrice");
$srv->apertaDa($srv->perId((int) $cx['id']), 'utente');
prova($msg_rep->nonLetti((int) $cx['id'], 'utente') === 0 && $msg_rep->nonLetti((int) $cx['id'], 'operatore') === 2, "la persona apre la chat: legge le risposte, ma i suoi messaggi restano da leggere per l'operatrice");
foreach (["DELETE FROM pratiche WHERE id = 98911", "DELETE FROM didattica_moduli WHERE id = 98910"] as $sql) $q($sql);

// --- Orari, servizio sospeso e fuori orario
$q("INSERT IGNORE INTO impostazioni_sistema (id) VALUES (1)");
$ora_as->t = new DateTimeImmutable('2026-10-22 10:00:00'); // giovedì
prova($orari_as->inOrario() && $orari_as->descrizione() === '' && $orari_as->attiva(), "orari: senza orari scritti il servizio è sempre aperto");
prova(\App\Assistenza\OrariAssistenza::fasce('') === [] && \App\Assistenza\OrariAssistenza::fasce('09:00-13:00, 14:30-16:00') === [[540, 780], [870, 960]] && \App\Assistenza\OrariAssistenza::fasce('9-13') === null
    && \App\Assistenza\OrariAssistenza::fasce('13:00-09:00') === null && \App\Assistenza\OrariAssistenza::fasce('09:00-25:00') === null && \App\Assistenza\OrariAssistenza::fasce('09:00-24:00') === [[540, 1440]], "orari: lettura delle fasce e controllo degli errori");
$giorno_lavoro = '09:00-13:00, 14:00-16:00';
prova($orari_as->salva(true, [1 => $giorno_lavoro, 2 => $giorno_lavoro, 3 => $giorno_lavoro, 4 => $giorno_lavoro, 5 => $giorno_lavoro, 6 => '', 7 => '']) === null
    && $orari_as->descrizione() === 'Lunedì–Venerdì 09:00-13:00, 14:00-16:00', "orari: salvati e raccontati a parole (giorni uguali raggruppati)", $orari_as->descrizione());
prova(str_contains((string) $orari_as->salva(true, [1 => '9-13']), 'Lunedì') && $orari_as->descrizione() === 'Lunedì–Venerdì 09:00-13:00, 14:00-16:00', "orari: un orario scritto male non cambia nulla e dice quale giorno");
prova($orari_as->inOrario(new DateTimeImmutable('2026-10-22 10:00')) && !$orari_as->inOrario(new DateTimeImmutable('2026-10-22 13:30')) && $orari_as->inOrario(new DateTimeImmutable('2026-10-22 15:59'))
    && !$orari_as->inOrario(new DateTimeImmutable('2026-10-22 16:00')) && !$orari_as->inOrario(new DateTimeImmutable('2026-10-24 10:00')), "orari: dentro, nella pausa, a fine turno e di sabato");
$ora_as->t = new DateTimeImmutable('2026-10-24 10:00:00'); // sabato
[$fo, $err_fo] = $srv->apriDaEsterno('Fuori Orario', 'fuori989@example.org', 'altro', 'Scrivo di sabato', '10.9.89.60');
$sist_fo = array_values(array_filter($srv->messaggi($fo), fn($m) => $m['da'] === 'sistema'));
prova($err_fo === null && count($sist_fo) === 1 && str_contains($sist_fo[0]['testo'], 'nessuno in linea') && str_contains($sist_fo[0]['testo'], 'Lunedì–Venerdì'), "fuori orario: la chat funziona e dice subito gli orari e che la risposta arriva per email");
$ora_as->t = new DateTimeImmutable('2026-10-22 10:00:00');
[$in_o] = $srv->apriDaEsterno('In Orario', 'inorario989@example.org', 'altro', 'Scrivo di giovedì', '10.9.89.61');
prova(count(array_filter($srv->messaggi($in_o), fn($m) => $m['da'] === 'sistema')) === 0, "in orario: nessun avviso di chiusura");
prova($orari_as->salva(false, [1 => $giorno_lavoro, 2 => '', 3 => '', 4 => '', 5 => '', 6 => '', 7 => '']) === null && !$orari_as->attiva()
    && str_contains((string) $srv->apriDaEsterno('Sospeso Test', 'sospeso989@example.org', 'altro', 'Ciao', '10.9.89.62')[1], 'sospeso') && str_contains((string) $srv->apriDaUtente($u_st, 'altro', 'Ciao')[1], 'sospeso'), "servizio spento: non si aprono nuove chat");
$orari_as->salva(true, []);
prova($orari_as->attiva() && $orari_as->inOrario() && $orari_as->descrizione() === '', "servizio riacceso e senza orari: di nuovo sempre aperto");

// --- Conservazione: dopo 12 mesi niente messaggi né dati personali, restano argomento, tempi e valutazione
$cons_as = new \App\Assistenza\ConservazioneAssistenza(\App\Core\Database::per($conn), $ora_as);
$q("UPDATE assistenza_conversazioni SET ultimo_il = '2025-09-01 10:00:00', creato_il = '2025-09-01 10:00:00' WHERE id = " . (int) $fo['id']);
$token_vecchio = $fo['token'];
$ripulite = $cons_as->applica(12);
$dopo_c = $srv->perId((int) $fo['id']);
prova($ripulite === 1 && $dopo_c['nome'] === 'F. O.' && $dopo_c['email'] === '' && $dopo_c['ip'] === '' && $dopo_c['argomento'] === 'altro' && $srv->messaggi($dopo_c) === [], "conservazione: nome alle iniziali, email e messaggi cancellati, argomento e tempi restano", json_encode([$ripulite, $dopo_c]));
prova($srv->perToken((string) $token_vecchio) === null && $dopo_c['token'] !== $token_vecchio, "conservazione: il vecchio link personale non vale più");
prova($cons_as->applica(12) === 0 && $srv->perId((int) $in_o['id'])['email'] === 'inorario989@example.org' && $cons_as->applica(0) === 0, "conservazione: una volta sola, le chat recenti restano com'erano");
prova(\App\Assistenza\ConservazioneAssistenza::iniziali('  maria  rossi ') === 'M. R.' && \App\Assistenza\ConservazioneAssistenza::iniziali('') === '', "conservazione: iniziali del nome");
foreach (["DELETE FROM assistenza_messaggi", "DELETE FROM assistenza_conversazioni", "DELETE FROM uffici_persone WHERE email = 'op989@example.org'", "DELETE FROM utenti WHERE id IN (98901, 98902, 98903)"] as $sql) $q($sql);
