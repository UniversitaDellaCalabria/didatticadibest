<?php
// Prove di Didacticus, l'assistente virtuale dell'assistenza (App\Assistenza\Didacticus): il motore che confronta la domanda con la base di conoscenza, le risposte
// (con link, allegati, suggerimenti), il passaggio all'operatore, le domande senza risposta, le statistiche. Chiamato da esegui.php. Dati di prova con id 989xx.
sezione("Assistenza: Didacticus, l'assistente virtuale");
$q("UPDATE impostazioni_sistema SET didacticus_attivo = 1, didacticus_soglia = 60, didacticus_saluto = '' WHERE id = 1"); // le prove di assistenza.php lo avevano spento

use App\Assistenza\Costanti as CostDi;
use App\Assistenza\Didacticus\ImpostazioniDidacticus;
use App\Assistenza\Didacticus\MancateRepository;
use App\Assistenza\Didacticus\Motore;
use App\Assistenza\Didacticus\ServizioDidacticus;
use App\Assistenza\Didacticus\VociDiPartenza;
use App\Assistenza\Didacticus\VociRepository;
use App\Core\App as AppDi;
use App\Core\Database as DbDi;

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Il motore: parole, saluti, punteggi
// ---------------------------------------------------------------------------------------------------------------------------------------------------
prova(Motore::normalizza("L'Iscrizione, è già così?") === 'l iscrizione e gia cosi' && Motore::normalizza('  ÀÈÌÒÙ  ') === 'aeiou', "motore: minuscole, senza accenti né punteggiatura");
prova(Motore::radice('certificato') === 'certi' && Motore::radice('certificati') === 'certi' && Motore::radice('esame') === 'esam' && Motore::radice('esami') === 'esam' && Motore::radice('tesi') === 'tes' && Motore::radice('pc') === 'pc', "motore: la radice riunisce singolare, plurale e forme simili");
prova(Motore::parole("Come posso richiedere il certificato di iscrizione?") === ['richi', 'certi', 'iscri'] && Motore::parole('il la di un') === [] && Motore::parole('certificato certificati') === ['certi'], "motore: restano solo le parole che contano, senza doppioni");
prova(Motore::righe("Prima\nSeconda; terza, quarta\r\n\r\nQuinta") === ['Prima', 'Seconda', 'terza', 'quarta', 'Quinta'] && Motore::righe("1,5 euro") === ['1,5 euro'], "motore: righe, punti e virgola e virgole (non dentro i numeri)");
prova(Motore::eSaluto('Ciao!') && Motore::eSaluto('buongiorno salve') && !Motore::eSaluto('ciao, come richiedo il certificato') && !Motore::eSaluto(''), "motore: riconosce un saluto da solo");
prova(Motore::eRingraziamento('Grazie mille!') && Motore::eRingraziamento('ok grazie') && !Motore::eRingraziamento('grazie ma il certificato dove lo trovo') && !Motore::eRingraziamento('certificato'), "motore: riconosce un ringraziamento da solo");
prova(Motore::chiedeOperatore('Voglio parlare con un operatore') && Motore::chiedeOperatore('mi passate una persona vera?') && Motore::chiedeOperatore('operatore') && Motore::chiedeOperatore('Vorrei parlare con qualcuno')
    && !Motore::chiedeOperatore('come funziona l\'ufficio tirocini') && !Motore::chiedeOperatore('dove trovo il certificato'), "motore: riconosce quando si chiede una persona");

// Con le voci di partenza, senza database
$voci_seme = [];
foreach (VociDiPartenza::elenco() as $i => $v) {
    $voci_seme[] = ['id' => $i + 1, 'argomento' => $v['argomento'], 'titolo' => $v['titolo'], 'domande' => $v['domande'], 'parole_chiave' => $v['parole_chiave'], 'risposta' => $v['risposta'], 'solo_login' => 0];
}
$motore = new Motore($voci_seme);
$top = fn(string $d, ?string $arg = null): array => ($motore->classifica($d, $arg)[0] ?? ['punteggio' => 0, 'voce' => ['titolo' => '']]);
$casi = [
    'come richiedo il certificato di iscrizione?' => 'Come richiedo un certificato', 'dove vedo lo stato della mia pratica' => 'Dove vedo a che punto è la mia pratica',
    'posso modificare la pratica che ho inviato' => 'Posso modificare una pratica già inviata', 'come mi iscrivo a un seminario' => 'Come mi iscrivo a un evento',
    'voglio annullare la mia prenotazione' => 'Come annullo o controllo la mia prenotazione', 'marca da bollo' => 'Marca da bollo sulle pratiche', 'voglio fare la tesi in azienda' => 'Tesi in azienda',
    'come chiedo la tesi' => 'Come chiedo la tesi', 'dove faccio il tirocinio' => 'Tirocini curriculari', 'non riesco ad entrare nel portale' => 'Come accedo al portale',
    'non mi arriva la mail' => 'Non ricevo le email del portale', 'sono un tutor e devo compilare il registro' => 'Tutorato', 'dove trovo i verbali' => 'Verbali dei consigli',
    'come posso fare erasmus' => 'Mobilità internazionale', 'devo cambiare curriculum' => 'Convalide, esoneri', "dove scarico l'attestato di partecipazione" => 'Attestati degli eventi',
];
foreach ($casi as $domanda => $atteso) {
    $t = $top($domanda);
    prova($t['punteggio'] >= 60 && str_starts_with($t['voce']['titolo'], $atteso), "motore: «" . $domanda . "» → " . $atteso, $t['punteggio'] . ' ' . $t['voce']['titolo']);
}
prova($top('quanto costa la mensa')['punteggio'] === 0 && $top('chi è il direttore')['punteggio'] === 0 && $top('esame di analisi matematica quando è')['punteggio'] < 48, "motore: domande fuori argomento non somigliano a nessuna voce");
prova($top('vorrei un certificato')['punteggio'] >= 60 && $motore->classifica('')  === [] && $motore->classifica('il la di') === [], "motore: una sola parola ma giusta basta; vuoto o solo parole vuote = niente");
$cl = $motore->classifica('certificato');
prova(count($cl) >= 2 && $cl[0]['corrispondenze'] === ['certi'], "motore: elenca le parole riconosciute (per la scheda «Prova»)");
$m2 = new Motore([['id' => 1, 'argomento' => 'altro', 'titolo' => 'Orari', 'domande' => 'A che ora aprite', 'parole_chiave' => 'orario di apertura, aperto', 'risposta' => 'x', 'solo_login' => 0], ['id' => 2, 'argomento' => 'altro', 'titolo' => 'Sedi', 'domande' => 'Dove siete', 'parole_chiave' => '', 'risposta' => 'y', 'solo_login' => 0]]);
$c2 = $m2->classifica("qual è l'orario di apertura della segreteria?");
prova($c2 && $c2[0]['voce']['id'] === 1 && $c2[0]['punteggio'] >= 60, "motore: una parola chiave di più parole contenuta nella domanda porta la voce in alto");
prova($m2->classifica('orario aprite', 'altro')[0]['punteggio'] > $m2->classifica('orario aprite', 'tesi')[0]['punteggio'], "motore: l'argomento scelto dà un piccolo vantaggio alle voci di quell'argomento");

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Database: voci di partenza, repository, impostazioni, servizio
// ---------------------------------------------------------------------------------------------------------------------------------------------------
$db_di = DbDi::per($conn);
$cont_di = AppDi::per($conn);
$voci_rep = $cont_di->get(VociRepository::class);
$manc_rep = $cont_di->get(MancateRepository::class);
$imp_rep = $cont_di->get(ImpostazioniDidacticus::class);
$did = $cont_di->get(ServizioDidacticus::class);

prova(count($voci_rep->attive()) === count(VociDiPartenza::elenco()) && count(VociDiPartenza::elenco()) >= 20 && (int) $db_di->valore('SELECT didacticus_seminato FROM impostazioni_sistema WHERE id = 1') === 1, "voci di partenza: inserite una volta sola dalla migrazione");
prova(VociDiPartenza::inserisci($db_di) === 0, "voci di partenza: non si inseriscono di nuovo se la base non è vuota");
foreach (VociDiPartenza::elenco() as $v) {
    foreach ($v['link'] as $l) {
        if (!VociRepository::urlValido($l['u']) || (!str_starts_with($l['u'], 'http') && !is_file(__DIR__ . '/../../../' . strtok($l['u'], '?')))) prova(false, "voce di partenza «{$v['titolo']}»: il link {$l['u']} non porta a una pagina del portale");
    }
}
prova(true, "voci di partenza: tutti i link portano a pagine del portale che esistono");
prova(VociRepository::urlValido('https://www.unical.it/x?a=1') && VociRepository::urlValido('modulistica.php') && VociRepository::urlValido('pratiche.php?id=3#storico') && !VociRepository::urlValido('javascript:alert(1)')
    && !VociRepository::urlValido('data:text/html,x') && !VociRepository::urlValido('//evil.com') && !VociRepository::urlValido('ftp://x') && !VociRepository::urlValido('') && !VociRepository::urlValido('a b') && !VociRepository::urlValido('http://x"onclick="'), "link: solo https/http o pagine del portale (niente javascript:, data:, //, spazi)");

$imp = $imp_rep->leggi();
prova($imp['attivo'] && $imp['soglia'] === 60 && $imp['avatar'] === '' && str_contains($imp['saluto'], 'Didacticus'), "impostazioni: acceso, soglia 60, immagine di serie, saluto predefinito");
$imp_rep->salva(true, '  Benvenuto!  ', 5);
prova($imp_rep->leggi()['saluto'] === 'Benvenuto!' && $imp_rep->leggi()['soglia'] === ImpostazioniDidacticus::SOGLIA_MIN, "impostazioni: saluto salvato, soglia tenuta nei limiti");
$imp_rep->salva(true, ImpostazioniDidacticus::SALUTO_PREDEFINITO, 999);
prova($imp_rep->leggi()['soglia'] === ImpostazioniDidacticus::SOGLIA_MAX && (string) $db_di->valore('SELECT didacticus_saluto FROM impostazioni_sistema WHERE id = 1') === '', "impostazioni: il saluto uguale a quello di serie non si memorizza; soglia massima 90");
$imp_rep->salva(true, '', 60);

// Il servizio: i tipi di risposta
$r = $did->risposta('come richiedo il certificato di iscrizione?', null, true);
prova($r['tipo'] === 'risposta' && str_contains($r['testo'], 'Modulistica') && $r['voce_id'] > 0 && !empty($r['extra']['feedback']) && $r['extra']['link'][0] === ['e' => 'Modulistica', 'u' => 'modulistica.php'] && !$r['passa'] && !$r['nessuna'], "risposta: testo della voce, link e pulsanti «ti è stato utile?»");
prova((int) $db_di->valore('SELECT usi FROM didacticus_voci WHERE id = ?', [$r['voce_id']]) === 1, "risposta: la voce usata conta un uso");
$r = $did->risposta('Ciao', null, true, true);
prova($r['tipo'] === 'saluto' && $r['testo'] === ImpostazioniDidacticus::SALUTO_PREDEFINITO && !empty($r['extra']['argomenti']) && $did->risposta('ciao', null, true, false)['testo'] === 'Ciao! Dimmi pure di cosa hai bisogno.', "saluto: il saluto del pannello all'inizio, una frase breve dopo");
prova($did->risposta('grazie mille', null, true)['tipo'] === 'grazie' && $did->risposta('Voglio parlare con un operatore', null, true)['passa'] === true && $did->risposta('operatore', null, false)['tipo'] === 'operatore', "grazie e richiesta di un operatore");
$r = $did->risposta('quanto costa la mensa', null, true);
prova($r['tipo'] === 'nessuna' && $r['nessuna'] && !$r['passa'] && !empty($r['extra']['operatore']) && !empty($r['extra']['argomenti']) && $r['voce_id'] === null, "nessuna risposta: lo dice, offre gli argomenti e l'operatore");
$r = $did->risposta('problema con il portale', null, true);
prova($r['tipo'] === 'suggerimenti' && !$r['nessuna'] && count($r['extra']['suggerimenti']) >= 1 && isset($r['extra']['suggerimenti'][0]['v'], $r['extra']['suggerimenti'][0]['t']), "voce incerta: propone «intendevi una di queste?» (non conta come domanda andata a vuoto)");
$imp_rep->salva(true, '', 90);
prova($did->risposta('come mi iscrivo a un seminario', null, true)['tipo'] === 'suggerimenti', "soglia alta: la stessa domanda non basta più per rispondere");
$imp_rep->salva(true, '', 20);
prova($did->risposta('problema con il portale', null, true)['tipo'] === 'risposta', "soglia bassa: risponde anche con domande vaghe");
$imp_rep->salva(true, '', 60);
$tit_marca = (int) $db_di->valore("SELECT id FROM didacticus_voci WHERE titolo LIKE 'Marca da bollo%'");
$r = $did->rispostaDiVoce($tit_marca, false);
prova($r !== null && $r['tipo'] === 'risposta' && $r['voce_id'] === $tit_marca && !empty($r['extra']['operatore']) && $did->rispostaDiVoce(999999, true) === null, "voce scelta da un suggerimento; una voce inesistente non risponde; «proponi operatore» attivo");
$r = $did->rispostaArgomento('tesi', true);
prova($r['tipo'] === 'argomento' && count($r['extra']['suggerimenti']) === 2 && str_contains($r['testo'], 'Tesi e laurea') && $did->rispostaArgomento('inventato', true)['tipo'] === 'argomento', "argomento: l'elenco delle voci di quell'argomento (uno sconosciuto diventa «Altro»)");

// Voci riservate a chi ha il login, voci spente
$id_ris = $voci_rep->salva(0, ['argomento' => 'altro', 'titolo' => 'Codice della sala riservata', 'domande' => "Qual è il codice della sala riservata\nCodice sala riunioni", 'parole_chiave' => 'sala riservata, codice sala', 'risposta' => 'Il codice è 1234.',
    'link' => [], 'allegati' => [], 'solo_login' => 1, 'proponi_operatore' => 0, 'attiva' => 1, 'ordine' => 0], '2026-10-22 09:00:00');
$r_no = $did->risposta('qual è il codice della sala riservata', null, false);
$r_si = $did->risposta('qual è il codice della sala riservata', null, true);
prova($r_no['tipo'] === 'riservata' && !str_contains($r_no['testo'], '1234') && str_contains($r_no['extra']['link'][0]['u'], 'saml_login.php') && $r_si['tipo'] === 'risposta' && str_contains($r_si['testo'], '1234'), "voce solo per chi accede: l'esterno viene invitato ad accedere e non vede la risposta");
prova($did->rispostaDiVoce($id_ris, false)['tipo'] === 'riservata' && $did->rispostaArgomento('altro', false)['extra']['suggerimenti'] !== [] && !in_array('Codice della sala riservata', array_column($did->rispostaArgomento('altro', false)['extra']['suggerimenti'], 't'), true), "voce solo per chi accede: non compare nemmeno tra i suggerimenti degli esterni");
$voci_rep->attiva($id_ris, false);
prova($did->risposta('qual è il codice della sala riservata', null, true)['tipo'] !== 'risposta' && $did->rispostaDiVoce($id_ris, true) === null, "voce spenta: Didacticus non la usa più");
$voci_rep->elimina($id_ris);
$id_all = $voci_rep->salva(0, ['argomento' => 'altro', 'titolo' => 'Prova allegati', 'domande' => 'x', 'parole_chiave' => '', 'risposta' => 'y', 'link' => [['e' => 'Buono', 'u' => 'modulistica.php'], ['e' => 'Cattivo', 'u' => 'javascript:alert(1)']],
    'allegati' => [['n' => 'Guida', 'f' => str_repeat('a', 32) . '.pdf'], ['n' => 'Brutto', 'f' => '../../config.php']], 'solo_login' => 0, 'proponi_operatore' => 0, 'attiva' => 1, 'ordine' => 0], '2026-10-22 09:00:00');
$v_all = $voci_rep->perId($id_all);
prova(VociRepository::link($v_all) === [['e' => 'Buono', 'u' => 'modulistica.php']] && VociRepository::allegati($v_all) === [['n' => 'Guida', 'f' => str_repeat('a', 32) . '.pdf']], "voce: link non validi e nomi di file strani si scartano quando si leggono");
$voci_rep->elimina($id_all);

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// La chat con Didacticus: dall'apertura al passaggio all'operatore
// ---------------------------------------------------------------------------------------------------------------------------------------------------
foreach (["DELETE FROM didacticus_mancate", "DELETE FROM assistenza_messaggi", "DELETE FROM assistenza_conversazioni", "DELETE FROM uffici_persone WHERE email = 'op-d989@example.org'", "DELETE FROM utenti WHERE id BETWEEN 98931 AND 98949", "UPDATE didacticus_voci SET usi = 0, utile = 0, non_utile = 0"] as $sql) $q($sql);
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (98931, 'Dora', 'Operatrice', 'op-d989@example.org', 5, ''), (98932, 'Stella', 'Studente', 'stud-d989@example.org', 3, ''), (98933, 'Admin', 'Didacticus', 'adm-d989@example.org', 1, '')");
$stu = [];
foreach ([1, 2, 4, 5, 6, 7, 9] as $n) {
    $q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (9894$n, 'Studente', 'Numero$n', 'stud$n-d989@example.org', 3, '')");
    $stu[$n] = ['id' => (int) "9894$n", 'nome' => 'Studente', 'cognome' => "Numero$n", 'email' => "stud$n-d989@example.org"];
}
$ass_id = (int) $db_di->valore("SELECT id FROM uffici WHERE chiave = 'assistenza'");
$q("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo, compiti) VALUES ($ass_id, 'op-d989@example.org', 'Dora Operatrice', '', '')");

$ora_di = new class implements \App\Core\Orologio {
    public DateTimeImmutable $t;
    public function adesso(): DateTimeImmutable { return $this->t; }
};
$ora_di->t = new DateTimeImmutable('2026-10-22 10:00:00');
$srv_d = new \App\Assistenza\ServizioAssistenza($cont_di->get(\App\Assistenza\ConversazioniRepository::class), $cont_di->get(\App\Assistenza\MessaggiAssistenzaRepository::class), $cont_di->get(\App\Assistenza\OperatoriAssistenza::class),
    $cont_di->get(\App\Assistenza\NotificheAssistenza::class), new \App\Assistenza\OrariAssistenza($db_di, $ora_di), $ora_di, $did, $manc_rep);
$api_d = $cont_di->get(\App\Assistenza\ChatApi::class);
$st_d = ['id' => 98932, 'nome' => 'Stella', 'cognome' => 'Studente', 'email' => 'stud-d989@example.org'];
$op_d = ['id' => 98931, 'nome' => 'Dora Operatrice'];
$conta_mail = function (string $oggetto) use (&$EMAIL): int { return count(array_filter($EMAIL, fn($e) => str_contains($e['oggetto'], $oggetto))); };
$bot_msgs = fn(array $c) => array_values(array_filter($srv_d->messaggi($c, 0, true), fn($m) => $m['da'] === 'bot'));
$ricarica = fn(array $c) => $srv_d->perId((int) $c['id']);

// 1. Domanda che Didacticus sa
$mail0 = $conta_mail('Nuova richiesta di assistenza');
[$c1, $e1] = $srv_d->apriDaUtente($stu[1], 'certificati', 'Come richiedo il certificato di iscrizione?', null, '10.9.89.101');
$m1 = $bot_msgs($c1);
$extra1 = json_decode((string) ($m1[0]['extra'] ?? ''), true);
prova($e1 === null && $c1['stato'] === 'bot' && count($m1) === 1 && str_contains($m1[0]['testo'], 'Modulistica') && $m1[0]['autore'] === 'Didacticus' && !empty($extra1['feedback']) && ($extra1['voce'] ?? 0) > 0, "chat: la nuova chat parte con Didacticus, che risponde subito con la voce giusta", json_encode([$c1['stato'], $m1]));
prova($conta_mail('Nuova richiesta di assistenza') === $mail0 && $srv_d->contatori(98931)['da_prendere'] === 0 && $srv_d->contatori(98931)['bot'] >= 1, "chat: gli operatori non vengono disturbati finché la persona non ha bisogno di loro");
prova($srv_d->daLeggere() === 0 && count($srv_d->elenco('bot', 98931)) >= 1 && $srv_d->elenco('da_prendere', 98931) === [] && $srv_d->elenco('tutte', 98931) === [], "chat: le chat con Didacticus stanno nella scheda «Con Didacticus», non in «da prendere» né nel contatore dei messaggi");
$cu = $api_d->chatUtente($ricarica($c1), 0);
$bot_json = array_values(array_filter($cu['messaggi'], fn($m) => $m['da'] === 'bot'))[0] ?? [];
prova($cu['conv']['bot'] === true && ($bot_json['extra']['link'][0]['u'] ?? '') === 'modulistica.php' && array_key_exists('extra', $cu['messaggi'][0]) && $cu['messaggi'][0]['extra'] === null, "chat: l'API dà alla pagina i pulsanti di Didacticus (e niente extra ai messaggi della persona)");

// 2. La persona scrive ancora: risponde Didacticus
$ora_di->t = $ora_di->t->modify('+1 minute');
$srv_d->scriviComeUtente($ricarica($c1), 'e per il diploma supplement?');
$m1 = $bot_msgs($c1);
prova(count($m1) === 2 && $ricarica($c1)['stato'] === 'bot', "chat: ogni messaggio della persona riceve una risposta, la chat resta con Didacticus");

// 3. «Mi è stato utile»: la chat si chiude, la voce conta
$voce1 = (int) ($extra1['voce'] ?? 0);
prova($srv_d->azioneBot($ricarica($c1), 'utile') === null, "chat: «Sì, grazie»");
$c1r = $ricarica($c1);
prova($c1r['stato'] === 'chiusa' && (int) $c1r['risolta_da_bot'] === 1 && $c1r['chiusa_da'] === 'utente' && $c1r['passata_il'] === null && (int) $db_di->valore('SELECT utile FROM didacticus_voci WHERE id = ?', [$voce1 + 0 ?: 0]) >= 0, "chat: risolta da Didacticus e chiusa dalla persona, senza passare dagli operatori");
prova($conta_mail('Nuova richiesta di assistenza') === $mail0, "chat: nessuna email agli operatori per una chat risolta da Didacticus");
$ultima_voce = 0;
foreach ($bot_msgs($c1) as $mm) $ultima_voce = (int) (json_decode((string) $mm['extra'], true)['voce'] ?? 0) ?: $ultima_voce;
prova((int) $db_di->valore('SELECT utile FROM didacticus_voci WHERE id = ?', [$ultima_voce]) === 1, "chat: la voce che ha risposto conta «utile»");

// 4. Se la persona riscrive, la chat si riapre con Didacticus
$ora_di->t = $ora_di->t->modify('+1 hour');
$srv_d->scriviComeUtente($ricarica($c1), 'una cosa sulla tesi: come chiedo la tesi?');
prova($ricarica($c1)['stato'] === 'bot' && $ricarica($c1)['chiusa_il'] === null && count($bot_msgs($c1)) === 4, "chat: scrivendo di nuovo la chat si riapre con Didacticus");

// 5. Due domande di fila senza risposta: passa a un operatore
$srv_d->chiudi($ricarica($c1), 'utente');
[$c2] = $srv_d->apriDaUtente($stu[2], 'altro', 'Quanto costa la mensa universitaria?', null, '10.9.89.102');
$c2r = $ricarica($c2);
prova($c2r['stato'] === 'bot' && (int) $c2r['bot_mancate'] === 1 && $c2r['passata_il'] === null && $conta_mail('Nuova richiesta di assistenza') === $mail0, "chat: una domanda senza risposta, Didacticus lo dice e propone gli argomenti", json_encode([$c2r['stato'], $c2r['bot_mancate']]));
$ora_di->t = $ora_di->t->modify('+1 minute');
$srv_d->scriviComeUtente($c2r, 'E il parcheggio costa molto?');
$c2r = $ricarica($c2);
$testi_sist = array_column(array_filter($srv_d->messaggi($c2r, 0, true), fn($m) => $m['da'] === 'sistema'), 'testo');
prova($c2r['stato'] === 'nuova' && $c2r['passata_il'] !== null && $conta_mail('Nuova richiesta di assistenza') === $mail0 + 1 && in_array('Ti ho messo in contatto con un operatore: ti risponde qui appena è disponibile.', $testi_sist, true), "chat: due volte senza risposta, la chat passa a un operatore con email e messaggio alla persona");
prova(count(array_filter($testi_sist, fn($t) => str_contains($t, 'Chat passata da Didacticus (Didacticus non ha trovato risposte per due volte di fila)'))) === 1, "chat: l'operatore trova una nota che spiega perché è arrivata la chat");
prova(str_contains(end($EMAIL)['corpo'], 'Quanto costa la mensa') && $srv_d->contatori(98931)['da_prendere'] >= 1, "chat: l'email all'operatore riporta la domanda della persona; ora la chat è «da prendere»");
$manc = $manc_rep->elenco();
prova(count(array_filter($manc, fn($m) => (int) $m['conversazione_id'] === (int) $c2['id'] && $m['tipo'] === 'nessuna')) === 2 && $manc_rep->contaDaVedere() >= 2, "domande senza risposta: tutte e due registrate per il pannello");
$srv_d->prendiInCarico($c2r, $op_d);
$srv_d->rispondi($ricarica($c2), $op_d, 'La mensa è gestita dall\'Ateneo: trovi i prezzi sul loro sito.');
prova($manc_rep->rispostaOperatore((int) $c2['id']) === 'La mensa è gestita dall\'Ateneo: trovi i prezzi sul loro sito.', "domande senza risposta: la risposta dell'operatore diventa la bozza della nuova voce");

// 6. «Parla con un operatore» e «Non mi basta»
$mail1 = $conta_mail('Nuova richiesta di assistenza');
[$c3] = $srv_d->apriDaEsterno('Esterna Prova', 'esterna-d989@example.org', 'tesi', 'Come chiedo la tesi?', '10.9.89.103');
$extra3 = json_decode((string) ($bot_msgs($c3)[0]['extra'] ?? ''), true);
prova($c3['stato'] === 'bot' && $c3['canale'] === 'esterno' && str_contains($bot_msgs($c3)[0]['testo'], 'richiesta di tesi') && $conta_mail('Abbiamo ricevuto') >= 1, "chat esterna: parte con Didacticus e l'esterna riceve comunque il link personale");
prova($srv_d->azioneBot($ricarica($c3), 'non_utile') === null, "chat: «Non mi basta»");
$c3r = $ricarica($c3);
$m_nu = array_values(array_filter($manc_rep->elenco(), fn($m) => (int) $m['conversazione_id'] === (int) $c3['id']));
prova($c3r['stato'] === 'nuova' && $c3r['passata_il'] !== null && $conta_mail('Nuova richiesta di assistenza') === $mail1 + 1 && count($m_nu) === 1 && $m_nu[0]['tipo'] === 'non_utile' && (int) $m_nu[0]['voce_id'] === (int) ($extra3['voce'] ?? 0)
    && str_contains((string) $m_nu[0]['domanda'], 'Come chiedo la tesi'), "chat: «Non mi basta» passa all'operatore e registra domanda e voce da rivedere", json_encode($m_nu));
prova((int) $db_di->valore('SELECT non_utile FROM didacticus_voci WHERE id = ?', [(int) $extra3['voce']]) === 1, "chat: la voce che non è bastata conta «non utile»");
[$c4] = $srv_d->apriDaUtente($stu[4], 'altro', 'Mi serve una mano', null, '10.9.89.104');
$ora_di->t = $ora_di->t->modify('+1 minute');
$srv_d->scriviComeUtente($ricarica($c4), 'Voglio parlare con un operatore');
prova($ricarica($c4)['stato'] === 'nuova' && $ricarica($c4)['passata_il'] !== null, "chat: scrivere «operatore» passa subito la parola a una persona");
[$c5] = $srv_d->apriDaEsterno('Altra Esterna', 'altra-d989@example.org', 'altro', 'Mi serve una mano', '10.9.89.105');
$srv_d->azioneBot($ricarica($c5), 'operatore');
prova($ricarica($c5)['stato'] === 'nuova' && str_contains(implode('|', array_column($srv_d->messaggi($ricarica($c5), 0, false), 'testo')), 'Certo, ti metto in contatto'), "chat: il pulsante «Parla con un operatore» fa lo stesso");
prova($srv_d->azioneBot($ricarica($c5), 'utile') === 'Con Didacticus si può parlare solo all\'inizio della chat.' && $srv_d->azioneBot($ricarica($c5), 'operatore') === null && $srv_d->azioneBot($ricarica($c5), 'boh') !== null, "chat: i pulsanti di Didacticus valgono solo finché la chat è con lui");

// 7. Suggerimenti, argomenti, «direttamente a un operatore», Didacticus spento
[$c6] = $srv_d->apriDaUtente($stu[6], 'altro', 'Ho un problema con il portale', null, '10.9.89.106');
$ex6 = json_decode((string) ($bot_msgs($c6)[0]['extra'] ?? ''), true);
$prima_sug = $ex6['suggerimenti'][0]['v'] ?? 0;
prova(!empty($ex6['suggerimenti']) && (int) $ricarica($c6)['bot_mancate'] === 0 && $prima_sug > 0, "chat: se non è sicuro propone delle voci e non conta come domanda a vuoto");
prova($srv_d->azioneBot($ricarica($c6), 'voce', (string) $prima_sug) === null && count($bot_msgs($c6)) === 2 && in_array($did->titolo((int) $prima_sug), array_column($srv_d->messaggi($ricarica($c6)), 'testo'), true), "chat: scegliere un suggerimento scrive il suo titolo come domanda e Didacticus risponde");
prova($srv_d->azioneBot($ricarica($c6), 'argomento', 'tesi') === null && count($bot_msgs($c6)) === 3 && str_contains(end($srv_d->messaggi($ricarica($c6)))['testo'], 'Ecco cosa so su'), "chat: scegliere un argomento elenca le sue voci");
prova($srv_d->azioneBot($ricarica($c6), 'voce', '999999') === 'Questa risposta non è più disponibile.', "chat: una voce che non c'è più non si usa");
$mail2 = $conta_mail('Nuova richiesta di assistenza');
[$c7] = $srv_d->apriDaUtente($stu[7], 'altro', 'Preferisco una persona', null, '10.9.89.107', true);
prova($c7['stato'] === 'nuova' && $bot_msgs($c7) === [] && $conta_mail('Nuova richiesta di assistenza') === $mail2 + 1, "chat: chi preferisce un operatore salta Didacticus (stato «da prendere», avviso agli operatori)");
$imp_rep->salva(false, '', 60);
[$c8] = $srv_d->apriDaEsterno('Con Bot Spento', 'spento-d989@example.org', 'altro', 'Domanda con bot spento', '10.9.89.108');
prova(!$did->attivo() && $c8['stato'] === 'nuova' && $bot_msgs($c8) === [], "Didacticus spento: le chat vanno subito agli operatori");
$imp_rep->salva(true, '', 60);
prova($did->attivo(), "Didacticus riacceso");
$tutte_off = $db_di->esegui('UPDATE didacticus_voci SET attiva = 0');
prova(!$did->attivo(), "senza voci attive Didacticus non si presenta");
$db_di->esegui('UPDATE didacticus_voci SET attiva = 1');

// 8. Un operatore prende una chat ancora con Didacticus
[$c9] = $srv_d->apriDaUtente($stu[9], 'altro', 'Dove trovo i verbali?', null, '10.9.89.109');
$srv_d->rispondi($ricarica($c9), $op_d, 'Ciao, ci penso io.');
$c9r = $ricarica($c9);
prova($c9r['stato'] === 'assegnata' && $c9r['passata_il'] !== null && (int) $c9r['operatore_id'] === 98931, "operatore: se risponde a una chat con Didacticus la prende e risulta «passata»");

// 8b. Ogni richiesta nuova parte da zero: le chat lasciate con Didacticus si chiudono e restano nello storico
$rep_cv = $cont_di->get(\App\Assistenza\ConversazioniRepository::class);
[$d1] = $srv_d->apriDaUtente($stu[5], 'certificati', 'Come richiedo il certificato di iscrizione?', null, '10.9.89.115');
[$d2, $err_d2] = $srv_d->apriDaUtente($stu[5], 'tesi', 'Come chiedo la tesi?', null, '10.9.89.115');
$d1r = $ricarica($d1);
prova($err_d2 === null && (int) $d2['id'] !== (int) $d1['id'] && $d2['stato'] === 'bot' && $d1r['stato'] === 'chiusa' && $d1r['chiusa_da'] === 'sistema' && (int) $d1r['risolta_da_bot'] === 0,
    "storico: una chat rimasta con Didacticus non si riprende, la nuova richiesta parte da zero e la vecchia si chiude (non conta come risolta da Didacticus)", json_encode([$d1r['stato'], $d1r['chiusa_da'], $d2['id'], $d1['id']]));
prova($rep_cv->apertaConOperatoreDi(98945) === null && (int) $rep_cv->apertaDi(98945)['id'] === (int) $d2['id'], "storico: la chat con Didacticus non è «in mano all'ufficio»");
$st_d1 = $rep_cv->storicoDi(98945);
prova(count($st_d1) === 2 && (int) $st_d1[0]['id'] === (int) $d2['id'] && str_starts_with((string) $st_d1[0]['primo_testo'], 'Come chiedo la tesi') && (int) $st_d1[0]['n_messaggi'] >= 2 && (int) $st_d1[0]['non_letti'] >= 1
    && str_contains(implode('|', array_column($srv_d->messaggi($d1r, 0, false), 'testo')), 'hai iniziato una nuova richiesta') && $rep_cv->storicoDi(98931) === [], "storico: elenco dalla più recente con prima domanda, numero di messaggi e risposte da leggere (solo le proprie)");
$srv_d->azioneBot($ricarica($d2), 'operatore');
[$d3, $err_d3] = $srv_d->apriDaUtente($stu[5], 'altro', 'Un altro messaggio', null, '10.9.89.115');
prova($err_d3 === null && (int) $d3['id'] === (int) $d2['id'] && (int) $rep_cv->apertaConOperatoreDi(98945)['id'] === (int) $d2['id'] && count($rep_cv->storicoDi(98945)) === 2, "storico: una richiesta già in mano all'ufficio continua lì (nessuna seconda conversazione)");

// 9. Le statistiche
$stat =$cont_di->get(\App\Assistenza\StatisticheAssistenza::class)->riepilogo('2026-10-01', '2026-10-31');
prova($stat['totale'] >= 9 && $stat['risolte_da_didacticus'] === 1 && $stat['passate_a_operatore'] >= 5 && $stat['esterni'] >= 3 && $stat['con_login'] >= 6 && $stat['chiuse'] >= 1, "statistiche: totale, risolte da Didacticus, passate agli operatori, esterni e con accesso", json_encode(array_slice($stat, 0, 12)));
$arg_tesi = array_values(array_filter($stat['per_argomento'], fn($x) => $x['argomento'] === 'tesi'))[0] ?? null;
prova($arg_tesi !== null && $arg_tesi['nome'] === 'Tesi e laurea' && $arg_tesi['n'] >= 1 && $stat['per_argomento'][0]['n'] >= $stat['per_argomento'][count($stat['per_argomento']) - 1]['n'], "statistiche: per argomento, dal più frequente");
$op_stat = array_values(array_filter($stat['per_operatore'], fn($x) => $x['nome'] === 'Dora Operatrice'))[0] ?? null;
prova($op_stat !== null && $op_stat['n'] >= 2 && $op_stat['prima_risposta_min'] !== null, "statistiche: per operatore, con il nome preso dall'utente");
prova(\App\Assistenza\StatisticheAssistenza::durata(null) === '—' && \App\Assistenza\StatisticheAssistenza::durata(0.4) === 'meno di 1 min' && \App\Assistenza\StatisticheAssistenza::durata(12.4) === '12 min'
    && \App\Assistenza\StatisticheAssistenza::durata(130) === '2 h 10 min' && \App\Assistenza\StatisticheAssistenza::durata(120) === '2 h', "statistiche: i tempi si leggono come «12 min» o «2 h 10 min»");
prova($cont_di->get(\App\Assistenza\StatisticheAssistenza::class)->riepilogo('2020-01-01', '2020-01-31')['totale'] === 0, "statistiche: un periodo senza chat dà zero");

// 10. Conservazione: spariscono anche le domande senza risposta di quelle chat
$q("UPDATE assistenza_conversazioni SET ultimo_il = '2025-01-01 10:00:00' WHERE id = " . (int) $c2['id']);
(new \App\Assistenza\ConservazioneAssistenza($db_di, $ora_di))->applica(12);
prova(count(array_filter($manc_rep->elenco(), fn($m) => (int) $m['conversazione_id'] === (int) $c2['id'])) === 0 && count(array_filter($manc_rep->elenco(), fn($m) => (int) $m['conversazione_id'] === (int) $c3['id'])) === 1, "conservazione: con la chat si cancellano le sue domande senza risposta (le altre restano)");
$manc_rep->segna((int) $manc_rep->elenco()[0]['id'], MancateRepository::IGNORATA);
prova(count($manc_rep->elenco(MancateRepository::IGNORATA)) === 1 && $manc_rep->contaDaVedere() === count($manc_rep->elenco()), "domande senza risposta: si possono mettere da parte e rimettere tra quelle da vedere");

// 11. Le voci sulla didattica (strumenti/didacticus/voci_didattica.txt → CSV da importare dal pannello): file ben fatto, importazione, risposte alle domande tipiche degli studenti
require_once __DIR__ . '/../../didacticus/genera_csv.php';
$voci_did = leggi_voci_didattica(__DIR__ . '/../../didacticus/voci_didattica.txt');
$titoli_did = [];
$difetti_did = [];
foreach ($voci_did as $v) {
    if (!isset(CostDi::ARGOMENTI[$v['argomento']])) $difetti_did[] = "«{$v['titolo']}»: argomento {$v['argomento']} sconosciuto";
    if (count($v['domande']) < 3 || $v['parole'] === '' || mb_strlen($v['risposta']) < 60) $difetti_did[] = "«{$v['titolo']}»: poche domande, niente parole chiave o risposta troppo corta";
    if (isset($titoli_did[mb_strtolower($v['titolo'])])) $difetti_did[] = "«{$v['titolo']}»: titolo ripetuto";
    $titoli_did[mb_strtolower($v['titolo'])] = 1;
    foreach ($v['link'] as $l) {
        if (!VociRepository::urlValido($l['u']) || (!str_starts_with($l['u'], 'http') && !is_file(__DIR__ . '/../../../' . strtok($l['u'], '?')))) $difetti_did[] = "«{$v['titolo']}»: link {$l['u']} non valido";
    }
}
prova(count($voci_did) >= 40 && !$difetti_did, "voci sulla didattica: file ben fatto (argomenti, domande, parole chiave, link validi, titoli unici)", implode(' | ', $difetti_did));
$conn->begin_transaction();
$prima_did = count($voci_rep->tutte());
[$n_nuove, $n_agg, $n_salt] = $voci_rep->importaCsv(csv_da_voci($voci_did), date('Y-m-d H:i:s'));
prova($n_salt === 0 && $n_nuove + $n_agg === count($voci_did) && $n_agg >= 5 && count($voci_rep->tutte()) === $prima_did + $n_nuove, "voci sulla didattica: importate dal CSV (le voci con lo stesso titolo si aggiornano, le altre si aggiungono)", "$n_nuove nuove, $n_agg aggiornate, $n_salt saltate");
$ammissione_did = array_values(array_filter($voci_rep->tutte(), fn($v) => str_starts_with((string) $v['titolo'], 'Ammissione al primo anno')))[0] ?? null;
prova($ammissione_did && (int) $ammissione_did['proponi_operatore'] === 1 && (int) $ammissione_did['solo_login'] === 0 && count(VociRepository::link($ammissione_did)) === 3 && $ammissione_did['argomento'] === 'iscrizione', "voci sulla didattica: argomento, link e «propone l'operatore» arrivano dal CSV");
[$n2_nuove, $n2_agg] = $voci_rep->importaCsv(csv_da_voci($voci_did), date('Y-m-d H:i:s'));
prova($n2_nuove === 0 && $n2_agg === count($voci_did) && count($voci_rep->tutte()) === $prima_did + $n_nuove, "voci sulla didattica: importare di nuovo lo stesso file non duplica le voci");
$motore_did = new Motore($voci_rep->attive());
$top_did = fn(string $d): array => ($motore_did->classifica($d, null)[0] ?? ['punteggio' => 0, 'voce' => ['titolo' => '']]);
$casi_did = [
    'non vedo l appello su esse3' => "Non vedo l'appello", 'non riesco a prenotarmi all esame' => "Non vedo l'appello", 'come mi prenoto a un esame' => 'Come mi prenoto a un esame',
    'quando posso modificare il piano di studi' => 'Come modifico il piano di studi', 'esse3 non mi fa modificare il piano di studi' => 'Non riesco a modificare il piano di studi',
    'il mio piano di studi è stato respinto' => 'Non riesco a modificare il piano di studi',
    'la mia proposta di stage è stata rifiutata' => 'La proposta di stage risulta rifiutata', 'come faccio la proposta di stage' => 'Tirocinio: come si avvia su TSP',
    'dove carico la relazione finale del tirocinio' => 'Tirocinio: registro presenze', 'ho finito il tirocinio ma non vedo i crediti' => 'Ho finito il tirocinio ma i crediti',
    'la scuola chiede la convenzione per il tirocinio' => "L'azienda, l'ente o la scuola non è convenzionata", 'la firma digitale non è valida pades' => 'Convenzione di tirocinio: firma digitale',
    'posso prorogare il tirocinio' => 'Proroga o cambio', 'dove faccio il corso sulla sicurezza' => 'Corso sulla sicurezza', 'chi è il tutor accademico' => 'Conferma del progetto formativo',
    'come chiedo la tesi' => 'Come chiedo la tesi', 'voglio aggiungere un correlatore' => 'Variazione della tesi', 'come presento la domanda di laurea' => 'Domanda di laurea',
    'quanto costa la tassa di fine corso' => 'Domanda di laurea', 'come richiedo il certificato di laurea' => 'Come richiedo un certificato', 'come ritiro la pergamena' => 'Pergamena di laurea',
    'cosa sono gli ofa' => 'OFA', 'come estinguo l ofa di matematica' => 'OFA', 'voglio passare a un altro corso di laurea' => 'Trasferimenti e passaggi', 'come mi trasferisco in un altra università' => 'Trasferimenti e passaggi',
    'come riprendo gli studi dopo la rinuncia' => 'Rinuncia, sospensione', 'come chiedo la borsa di studio' => 'Borse di studio', 'dove si trova l ufficio didattico' => "Dove e quando trovo l'ufficio didattico",
    'ho dimenticato la password di esse3' => 'Password dimenticata', 'come funziona l erasmus' => 'Mobilità internazionale', 'come divento tutor' => 'Tutorato: accettazione',
    'cos è il mentoring' => 'Tutorato: chi sono i tutor', 'quando posso iscrivermi al primo anno' => 'Ammissione al primo anno', 'come cambio curriculum' => 'Come modifico il piano di studi',
    'devo pagare la prima rata' => 'Tasse, rate', 'come funziona il part time' => 'Attività aggiuntive, part-time',
    'come prenoto un attività fsl per la mia classe' => "Cos'è la Formazione Scuola Lavoro", 'chi deve firmare la convenzione della scuola' => 'Convenzione FSL: come si compila',
    'la convenzione inviata via pec non è stata accettata' => 'La convenzione FSL è stata rifiutata', 'la prenotazione fsl è in attesa' => 'Ho prenotato ma la prenotazione risulta in attesa',
    'come inserisco l elenco degli studenti della classe' => 'Elenco degli studenti e autorizzazione', 'la scuola ha bisogno di 40 ore di fsl' => 'Quante ore dura un percorso FSL',
    'quando arrivano gli attestati fsl' => 'Attestati FSL', 'la convenzione fsl è scaduta' => 'Rinnovo o scadenza della convenzione FSL',
    'sono uno studente universitario devo fare la fsl' => 'Tirocinio o Formazione Scuola Lavoro', 'posso fare i due tirocini insieme' => 'Due tirocini nel piano di studi',
    'non trovo un laboratorio per il tirocinio' => 'Non trovo un laboratorio', 'la convenzione risulta in lavorazione inviata a titulus' => 'La convenzione risulta «in lavorazione»',
    'ho bisogno del diploma supplement per l università straniera' => 'Diploma Supplement e documenti per l\'estero', 'non ho la password della mail istituzionale' => 'Email istituzionale: primo accesso',
    'quando è il colloquio per la magistrale' => 'Ammissione alle lauree magistrali',
];
foreach ($casi_did as $domanda => $atteso) {
    $t = $top_did($domanda);
    prova($t['punteggio'] >= 60 && str_starts_with($t['voce']['titolo'], $atteso), "voci sulla didattica: «" . $domanda . "» → " . $atteso, $t['punteggio'] . ' ' . $t['voce']['titolo']);
}
$conn->rollback(); // le prove non lasciano le voci importate nella base di prova

foreach (["DELETE FROM didacticus_mancate", "DELETE FROM assistenza_messaggi", "DELETE FROM assistenza_conversazioni", "DELETE FROM uffici_persone WHERE email = 'op-d989@example.org'", "DELETE FROM utenti WHERE id BETWEEN 98931 AND 98949", "UPDATE impostazioni_sistema SET didacticus_attivo = 0 WHERE id = 1"] as $sql) $q($sql);
