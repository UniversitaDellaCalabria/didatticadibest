<?php
// Prove delle notifiche push con il database: iscrizioni, preferenze (silenzia, solo le mie chat), destinatari, recapiti scaduti e integrazione con la chat.
// Usa le variabili di assistenza_push.php (finto servizio push, chiavi VAPID, funzione che decifra) ed è chiamato subito dopo da esegui.php.
sezione("Assistenza: notifiche push (iscrizioni, preferenze, avvisi nella chat)");

use App\Assistenza\Push\ChiaviVapid;
use App\Assistenza\Push\ClienteHttp;
use App\Assistenza\Push\CurvaEc;
use App\Assistenza\Push\InvioPush;
use App\Assistenza\Push\ServizioPush;
use App\Assistenza\Push\SottoscrizioniRepository;
use App\Core\App as AppPu;
use App\Core\Config;

foreach (["DELETE FROM push_sottoscrizioni", "DELETE FROM assistenza_messaggi", "DELETE FROM assistenza_conversazioni", "DELETE FROM utenti WHERE id IN (98921, 98922, 98923)"] as $sql) $q($sql);
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (98921, 'Olga', 'Uno', 'op1-989@example.org', 1, ''), (98922, 'Piero', 'Due', 'op2-989@example.org', 1, ''), (98923, 'Sara', 'Studente', 'stud-989@example.org', 3, '')");

$ora_pu = new class implements \App\Core\Orologio {
    public DateTimeImmutable $t;
    public function adesso(): DateTimeImmutable { return $this->t; }
};
$ora_pu->t = new DateTimeImmutable('2026-10-22 10:00:00');
$cont_pu = AppPu::per($conn);
$rep_pu = $cont_pu->get(SottoscrizioniRepository::class);
$vapid_pu = new ChiaviVapid($cfg, $ora_pu);
$push_pu = new ServizioPush(new InvioPush($vapid_pu, $finto), $rep_pu, $vapid_pu, $ora_pu);
$push_spento = new ServizioPush(new InvioPush(new ChiaviVapid(new Config([]), $ora_pu), $finto), $rep_pu, new ChiaviVapid(new Config([]), $ora_pu), $ora_pu);

// Browser simulati (ognuno con le sue chiavi, per poter leggere cosa gli arriva)
$mk = function (string $nome) {
    $k = CurvaEc::genera();
    $a = random_bytes(16);
    return ['priv' => $k['privata'], 'pub' => $k['pubblica'], 'auth' => $a, 'sott' => ['endpoint' => "https://push.example.org/wpush/$nome-" . bin2hex(random_bytes(6)), 'keys' => ['p256dh' => CurvaEc::b64uCodifica($k['pubblica']), 'auth' => CurvaEc::b64uCodifica($a)]]];
};
$br1 = $mk('olga');
$br2 = $mk('piero');
$br3 = $mk('sara');
$ricevuto = function (array $br) use ($finto, $decifra): array {
    $out = [];
    foreach ($finto->richieste as $r) {
        if ($r['url'] === $br['sott']['endpoint']) $out[] = json_decode($decifra($r['corpo'], $br['priv'], $br['pub'], $br['auth']), true);
    }
    return $out;
};

prova(ServizioPush::sottoscrizioneValida($br1['sott']) !== null && ServizioPush::sottoscrizioneValida(['endpoint' => 'http://x.it/abcdefgh'] + $br1['sott']) === null
    && ServizioPush::sottoscrizioneValida(['endpoint' => $br1['sott']['endpoint'], 'keys' => ['p256dh' => 'corta', 'auth' => $br1['sott']['keys']['auth']]]) === null
    && ServizioPush::sottoscrizioneValida(['endpoint' => $br1['sott']['endpoint'], 'keys' => ['p256dh' => $br1['sott']['keys']['p256dh'], 'auth' => 'a']]) === null && ServizioPush::sottoscrizioneValida([]) === null, "iscrizione: si accettano solo recapiti https con chiavi e segreto della lunghezza giusta");
prova($push_spento->iscriviOperatore(98921, $br1['sott'], 'Chrome') !== null && $rep_pu->perEndpoint($br1['sott']['endpoint']) === null && !$push_spento->attivo(), "iscrizione: senza chiavi VAPID sul server non si iscrive nessuno");
prova($push_pu->iscriviOperatore(98921, $br1['sott'], 'Chrome su Windows') === null && $push_pu->iscriviOperatore(98922, $br2['sott'], 'Edge') === null && $push_pu->iscriviOperatore(98921, ['endpoint' => 'x'], '') !== null, "iscrizione: due operatori si iscrivono, un recapito rotto è rifiutato");
prova($push_pu->iscrittoOperatore($br1['sott']['endpoint'], 98921) && !$push_pu->iscrittoOperatore($br1['sott']['endpoint'], 98922) && count($rep_pu->operatori()) === 2, "iscrizione: ogni browser risulta iscritto solo per il suo operatore");
$push_pu->iscriviOperatore(98922, $br1['sott'], 'Stesso browser, altra persona');
prova(!$push_pu->iscrittoOperatore($br1['sott']['endpoint'], 98921) && $push_pu->iscrittoOperatore($br1['sott']['endpoint'], 98922) && count($rep_pu->operatori()) === 2, "iscrizione: lo stesso browser passa a chi lo usa adesso (nessun doppione)");
$push_pu->iscriviOperatore(98921, $br1['sott'], 'Chrome su Windows');

// Avvisi agli operatori
$conv_pu = ['id' => '501', 'nome' => 'Sara Studente', 'argomento' => 'certificati', 'utente_id' => '98923', 'stato' => 'nuova', 'operatore_id' => null, 'canale' => 'utente', 'token' => str_repeat('a', 32)];
$finto->richieste = [];
$n_pu = $push_pu->avvisaOperatori($conv_pu, "Ciao, ho chiesto il certificato\ne non so se serve   la marca da bollo, mi potete dire come devo fare per favore? Grazie mille!", 'https://dibest.example.org/admin/assistenza.php?c=501', true);
$r1 = $ricevuto($br1)[0] ?? [];
prova($n_pu === 2 && count($finto->richieste) === 2 && ($r1['titolo'] ?? '') === 'Nuova richiesta di assistenza' && str_starts_with($r1['corpo'] ?? '', 'Sara Studente · Certificati e attestati: Ciao, ho chiesto il certificato e non so se serve la marca')
    && mb_strlen(explode(': ', $r1['corpo'] ?? '', 2)[1] ?? '') <= 90 && str_ends_with($r1['corpo'] ?? '', '…') && ($r1['url'] ?? '') === 'https://dibest.example.org/admin/assistenza.php?c=501' && ($r1['tag'] ?? '') === 'assistenza-501', "nuova richiesta: la finestrella arriva a tutti gli operatori con nome, argomento e prime parole", json_encode($r1));
prova(in_array('Urgency: high', $finto->richieste[0]['intestazioni'], true), "nuova richiesta: urgenza alta (il servizio push la recapita subito)");

// Preferenze: silenzia e «solo le mie chat»
$pref = $push_pu->preferenze(98921, 60, false);
prova($pref === ['silenzia_fino' => '2026-10-22 11:00:00', 'solo_mie' => false] && $push_pu->leggiPreferenze(98921)['silenzia_fino'] === '2026-10-22 11:00:00' && $push_pu->leggiPreferenze(98922)['silenzia_fino'] === null, "preferenze: silenzia per un'ora, solo per quell'operatore");
$finto->richieste = [];
prova($push_pu->avvisaOperatori($conv_pu, 'x', 'https://u/', true) === 1 && $ricevuto($br1) === [] && count($ricevuto($br2)) === 1, "silenziate: l'operatore silenziato non riceve nulla, l'altro sì");
$ora_pu->t = new DateTimeImmutable('2026-10-22 11:00:01');
$finto->richieste = [];
prova($push_pu->avvisaOperatori($conv_pu, 'x', 'https://u/', true) === 2 && $push_pu->leggiPreferenze(98921)['silenzia_fino'] === null, "silenziate: passata l'ora tornano da sole");
$push_pu->preferenze(98922, 0, true);
$finto->richieste = [];
prova($push_pu->avvisaOperatori($conv_pu, 'x', 'https://u/', true) === 1 && $ricevuto($br2) === [] && $push_pu->leggiPreferenze(98922)['solo_mie'] === true, "«solo le mie chat»: non riceve le chat da prendere");
$finto->richieste = [];
$conv_mia = ['operatore_id' => '98922', 'stato' => 'assegnata'] + $conv_pu;
prova($push_pu->avvisaOperatore(98922, $conv_mia, 'Una domanda in più', 'https://u/') === 1 && ($ricevuto($br2)[0]['titolo'] ?? '') === 'Messaggio da Sara Studente' && $ricevuto($br1) === [], "messaggio in una chat in carico: lo sente solo l'operatore che la segue (anche con «solo le mie»)");
$push_pu->preferenze(98922, 0, false);

// Chi chiede aiuto: nessun testo nella notifica
prova($push_pu->iscriviUtente($conv_pu, $br3['sott'], 'Chrome Android') === null && $push_pu->iscrittoUtente($conv_pu, $br3['sott']['endpoint']) && !$push_pu->iscrittoUtente(['id' => '999'] + $conv_pu, $br3['sott']['endpoint']), "chi chiede aiuto: si iscrive alla sua conversazione");
$finto->richieste = [];
prova($push_pu->avvisaUtente($conv_pu, 'https://dibest.example.org/assistenza.php') === 1, "chi chiede aiuto: la risposta dell'operatore arriva");
$ru = $ricevuto($br3)[0] ?? [];
prova(($ru['corpo'] ?? '') === "Hai una risposta dall'assistenza. Tocca per leggerla." && !str_contains(json_encode($ru), 'certificato') && ($ru['url'] ?? '') === 'https://dibest.example.org/assistenza.php', "chi chiede aiuto: la finestrella non contiene il testo della risposta", json_encode($ru));
prova($push_pu->avvisaUtente(['id' => '777', 'utente_id' => null] + $conv_pu, 'https://u/') === 0, "chi chiede aiuto: senza iscrizione non parte nulla");
$push_pu->disiscriviUtente(['id' => '777'] + $conv_pu, $br3['sott']['endpoint']);
prova($push_pu->iscrittoUtente($conv_pu, $br3['sott']['endpoint']), "rinuncia: nessuno toglie il recapito di un'altra conversazione");
$push_pu->disiscriviUtente($conv_pu, $br3['sott']['endpoint']);
prova(!$push_pu->iscrittoUtente($conv_pu, $br3['sott']['endpoint']) && $rep_pu->perEndpoint($br3['sott']['endpoint']) === null, "rinuncia: chi ha chiesto aiuto toglie il suo recapito");
$push_pu->disiscrivi($br2['sott']['endpoint'], 98921);
prova($push_pu->iscrittoOperatore($br2['sott']['endpoint'], 98922), "rinuncia: un operatore non toglie il recapito di un collega");
$push_pu->disiscrivi($br2['sott']['endpoint'], 98922);
prova(!$push_pu->iscrittoOperatore($br2['sott']['endpoint'], 98922), "rinuncia: ognuno toglie il proprio");
$push_pu->iscriviOperatore(98922, $br2['sott'], 'Edge');

// Recapiti scaduti ed errori
$finto->risposta = 410;
$push_pu->avvisaOperatori($conv_pu, 'x', 'https://u/', true);
prova($rep_pu->operatori() === [], "recapito scaduto (410): il portale lo elimina da solo");
$push_pu->iscriviOperatore(98921, $br1['sott'], 'Chrome');
$finto->risposta = 503;
for ($i = 0; $i < SottoscrizioniRepository::MAX_ERRORI - 1; $i++) $push_pu->avvisaOperatori($conv_pu, 'x', 'https://u/', true);
$ancora = $rep_pu->perEndpoint($br1['sott']['endpoint']);
prova($ancora !== null && (int) $ancora['errori'] === SottoscrizioniRepository::MAX_ERRORI - 1, "errore passeggero (503): si conta, il recapito resta");
$finto->risposta = 201;
$push_pu->avvisaOperatori($conv_pu, 'x', 'https://u/', true);
prova((int) $rep_pu->perEndpoint($br1['sott']['endpoint'])['errori'] === 0 && $rep_pu->perEndpoint($br1['sott']['endpoint'])['ultimo_invio_il'] === '2026-10-22 11:00:01', "dopo un invio riuscito gli errori ripartono da zero");
$finto->risposta = 503;
for ($i = 0; $i < SottoscrizioniRepository::MAX_ERRORI; $i++) $push_pu->avvisaOperatori($conv_pu, 'x', 'https://u/', true);
prova($rep_pu->perEndpoint($br1['sott']['endpoint']) === null, "troppi errori di fila: il recapito si elimina");
$finto->risposta = 201;

// Integrazione: la chat manda le finestrelle nei momenti giusti
$push_pu->iscriviOperatore(98921, $br1['sott'], 'Chrome');
$push_pu->iscriviOperatore(98922, $br2['sott'], 'Edge');
$notifiche_pu = new \App\Assistenza\NotificheAssistenza($cont_pu->get(\App\Email\InvioEmail::class), $cont_pu->get(\App\Core\Sito::class), $push_pu);
$srv_pu = new \App\Assistenza\ServizioAssistenza($cont_pu->get(\App\Assistenza\ConversazioniRepository::class), $cont_pu->get(\App\Assistenza\MessaggiAssistenzaRepository::class),
    $cont_pu->get(\App\Assistenza\OperatoriAssistenza::class), $notifiche_pu, new \App\Assistenza\OrariAssistenza(\App\Core\Database::per($conn), $ora_pu), $ora_pu, $cont_pu->get(\App\Assistenza\Didacticus\ServizioDidacticus::class), $cont_pu->get(\App\Assistenza\Didacticus\MancateRepository::class));
$stud_pu = ['id' => 98923, 'nome' => 'Sara', 'cognome' => 'Studente', 'email' => 'stud-989@example.org'];
$ora_pu->t = new DateTimeImmutable('2026-10-22 12:00:00');
$finto->richieste = [];
[$cc_pu] = $srv_pu->apriDaUtente($stud_pu, 'tesi', 'Vorrei chiedere la tesi', null, '10.9.89.90');
prova(count($ricevuto($br1)) === 1 && ($ricevuto($br1)[0]['titolo'] ?? '') === 'Nuova richiesta di assistenza' && str_contains($ricevuto($br1)[0]['url'], '/admin/assistenza.php?c=' . $cc_pu['id']), "chat: una nuova richiesta fa comparire la finestrella all'operatore, con il link alla chat");
$push_pu->iscriviUtente($cc_pu, $br3['sott'], 'Chrome Android');
$finto->richieste = [];
$op_pu = ['id' => 98921, 'nome' => 'Olga Uno'];
$srv_pu->rispondi($srv_pu->perId((int) $cc_pu['id']), $op_pu, 'Ti rispondo subito');
prova($ricevuto($br3) === [], "chat: chi ha la chat aperta (era qui un attimo fa) non riceve la finestrella");
$ora_pu->t = new DateTimeImmutable('2026-10-22 12:10:00');
$srv_pu->rispondi($srv_pu->perId((int) $cc_pu['id']), $op_pu, 'Ci sei ancora?');
prova(count($ricevuto($br3)) === 1 && !str_contains(json_encode($ricevuto($br3)), 'Ci sei ancora'), "chat: chi non ha la chat aperta riceve «hai una risposta» senza il testo");
$finto->richieste = [];
$srv_pu->apertaDa($srv_pu->perId((int) $cc_pu['id']), 'operatore');
$ora_pu->t = new DateTimeImmutable('2026-10-22 12:10:30');
$srv_pu->scriviComeUtente($srv_pu->perId((int) $cc_pu['id']), 'Sì, eccomi');
prova($ricevuto($br1) === [], "chat: se l'operatore ha la chat aperta nessuna finestrella");
$ora_pu->t = new DateTimeImmutable('2026-10-22 12:30:00');
$srv_pu->scriviComeUtente($srv_pu->perId((int) $cc_pu['id']), 'Un altro dubbio');
prova(count($ricevuto($br1)) === 1 && ($ricevuto($br1)[0]['titolo'] ?? '') === 'Messaggio da Sara Studente' && str_contains($ricevuto($br1)[0]['corpo'], 'Un altro dubbio'), "chat: l'operatore assente riceve la finestrella con le prime parole del messaggio");
$finto->richieste = [];
$srv_pu->passaA($srv_pu->perId((int) $cc_pu['id']), $op_pu, ['id' => 98922, 'nome' => 'Piero Due']);
prova(count($ricevuto($br2)) === 1 && $ricevuto($br1) === [], "chat: passando la chat a un collega, la finestrella arriva a lui");

// Se il servizio push è guasto la chat funziona lo stesso
$guasto = new class implements ClienteHttp {
    public function post(string $url, array $intestazioni, string $corpo, int $secondi = 10): array { throw new RuntimeException('rete giù'); }
};
$push_guasto = new ServizioPush(new InvioPush($vapid_pu, $guasto), $rep_pu, $vapid_pu, $ora_pu);
$srv_g = new \App\Assistenza\ServizioAssistenza($cont_pu->get(\App\Assistenza\ConversazioniRepository::class), $cont_pu->get(\App\Assistenza\MessaggiAssistenzaRepository::class), $cont_pu->get(\App\Assistenza\OperatoriAssistenza::class),
    new \App\Assistenza\NotificheAssistenza($cont_pu->get(\App\Email\InvioEmail::class), $cont_pu->get(\App\Core\Sito::class), $push_guasto), new \App\Assistenza\OrariAssistenza(\App\Core\Database::per($conn), $ora_pu), $ora_pu, $cont_pu->get(\App\Assistenza\Didacticus\ServizioDidacticus::class), $cont_pu->get(\App\Assistenza\Didacticus\MancateRepository::class));
[$cg] = $srv_g->apriDaEsterno('Test Guasto', 'guasto989@example.org', 'altro', 'Funziona lo stesso?', '10.9.89.91');
prova($cg !== null && count($srv_g->messaggi($cg)) === 1, "servizio push guasto: la chat funziona lo stesso");

// Conservazione: spariscono anche i recapiti della conversazione
$push_pu->iscriviUtente($cc_pu, $br3['sott'], 'Chrome Android');
$q("UPDATE assistenza_conversazioni SET ultimo_il = '2025-01-01 10:00:00' WHERE id = " . (int) $cc_pu['id']);
(new \App\Assistenza\ConservazioneAssistenza(\App\Core\Database::per($conn), $ora_pu))->applica(12);
prova($rep_pu->perEndpoint($br3['sott']['endpoint']) === null && $rep_pu->perEndpoint($br1['sott']['endpoint']) !== null, "conservazione: con la chat si cancellano i recapiti di chi ha chiesto aiuto (non quelli degli operatori)");

foreach (["DELETE FROM push_sottoscrizioni", "DELETE FROM assistenza_messaggi", "DELETE FROM assistenza_conversazioni", "DELETE FROM utenti WHERE id IN (98921, 98922, 98923)"] as $sql) $q($sql);
