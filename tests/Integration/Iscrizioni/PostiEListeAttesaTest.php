<?php

declare(strict_types=1);

namespace Tests\Integration\Iscrizioni;

use App\Eventi\PresentazioneComune;
use App\Eventi\RegoleIscrizioni;
use App\Iscrizioni\NotifichePrenotazione;
use App\Iscrizioni\PrenotazioneRepository;
use App\Iscrizioni\ServizioDisponibilita;
use App\Iscrizioni\ServizioListaAttesa;
use App\Iscrizioni\ServizioVincoli;
use mysqli;

/** Posti occupati, ultimo posto, liste d'attesa, promozioni, scadenze, vincoli per area e disponibilità. */
final class PostiEListeAttesaTest extends IscrizioniBase
{
    public function testPostiOccupatiContanoSoloGliStatiCheTengonoUnPosto(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 20]]);
        foreach ([['confermata', 1], ['richiesta_conferma', 1], ['da_approvare', 2], ['NULL', 1], ['in_attesa', 4], ['annullata', 3], ['rifiutata', 3], ['scaduta', 3]] as [$stato, $n]) {
            $this->pren($t, $stato, ['num_posti' => $n]);
        }
        $repo = $this->servizio(PrenotazioneRepository::class);
        $this->assertSame(5, $repo->postiOccupati($t));
        $this->assertSame(5, $repo->postiOccupati($t, true), 'con il blocco il conteggio è lo stesso (fuori transazione il blocco si rilascia subito)');
        $this->assertSame(0, $repo->postiOccupati(99999));
        $this->assertSame(5, $this->servizio(RegoleIscrizioni::class)->postiOccupati($t));
    }

    public function testChiContaIPostiInTransazioneBloccaLeAltrePrenotazioniDelTurno(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 1]]);
        $repo = $this->servizio(PrenotazioneRepository::class);
        $altra = new mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''), getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit');
        $altra->query('SET SESSION innodb_lock_wait_timeout = 1');
        $inserisci = static fn (): bool => (bool) @$altra->query("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, num_posti, nome, cognome, email) VALUES ($t, 'ALTRA', 'confermata', 1, 'B', 'B', 'b@prova.it')");

        self::$conn->begin_transaction();
        $this->assertSame(0, $repo->postiOccupati($t, true));
        $this->assertFalse($inserisci(), 'la seconda prenotazione aspetta (e qui scade il tempo) finché la prima transazione non finisce');
        $this->assertSame(1205, $altra->errno);
        $this->pren($t, 'confermata');
        self::$conn->commit();

        $this->assertTrue($inserisci(), 'dopo il commit la seconda procede');
        $this->assertSame(2, $repo->postiOccupati($t), 'e vede il posto già preso');
        $altra->close();
    }

    public function testPromozioneInOrdineDiArrivoSoloSeIlPostoC(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 2, 'abilita_lista_attesa' => 1]]);
        $p1 = $this->pren($t, 'confermata');
        $p2 = $this->pren($t, 'in_attesa', ['email' => 'w1@prova.it']);
        $p3 = $this->pren($t, 'in_attesa', ['email' => 'w2@prova.it']);
        $p4 = $this->pren($t, 'in_attesa', ['num_posti' => 2, 'email' => 'w3@prova.it']);
        $coda = $this->servizio(ServizioListaAttesa::class);

        $coda->promuovi($t);
        $this->assertSame('richiesta_conferma', $this->stato($p2), 'il primo in coda ottiene il posto');
        $this->assertSame('in_attesa', $this->stato($p3));
        $scadenza = (string) $this->db->valore('SELECT scadenza_conferma FROM prenotazioni WHERE id = ?', [$p2]);
        $this->assertSame('2026-10-06 12:00:00', $scadenza, '24 ore per confermare');
        $this->assertCount(1, $this->mailer->inviate);
        $this->assertSame('w1@prova.it', $this->mailer->inviate[0]['a']);
        $this->assertSame('Azione Richiesta: Si è liberato un posto per Evento 10', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString("http://prova.it/eventi/area_personale.php?conferma_posto=$p2", $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('entro il 06/10/2026 12:00', $this->mailer->inviate[0]['corpo']);
        $this->assertSame('#0056B3', $this->mailer->inviate[0]['colore']);

        $coda->promuovi($t);
        $this->assertCount(1, $this->mailer->inviate, 'senza posti liberi non cambia nulla');

        $this->db->esegui("UPDATE prenotazioni SET stato = 'annullata' WHERE id = ?", [$p1]);
        $coda->promuovi($t);
        $this->assertSame('richiesta_conferma', $this->stato($p3));
        $this->assertSame('in_attesa', $this->stato($p4), 'chi chiede 2 posti aspetta');

        $this->db->esegui("UPDATE prenotazioni SET stato = 'annullata' WHERE id = ?", [$p2]);
        $mail = count($this->mailer->inviate);
        $coda->promuovi($t);
        $this->assertSame('in_attesa', $this->stato($p4), 'il primo in coda chiede più posti di quelli liberi: ci si ferma');
        $this->assertCount($mail, $this->mailer->inviate);

        $this->db->esegui("UPDATE prenotazioni SET stato = 'annullata' WHERE id = ?", [$p3]);
        $coda->promuovi($t);
        $this->assertSame('richiesta_conferma', $this->stato($p4), 'la richiesta da 2 posti entra quando ce ne sono 2');
    }

    public function testNessunaPromozioneASostaDi24OreDallEventoETurniSenzaData(): void
    {
        $ora = $this->orologio->adesso();
        [$imminente, $senzaData, $lontano] = $this->evento(10, [
            ['max_posti' => 1, 'data_turno' => $ora->modify('+10 hours')->format('Y-m-d'), 'orario_inizio' => $ora->modify('+10 hours')->format('H:i:s')],
            ['max_posti' => 1, 'data_turno' => null, 'orario_inizio' => null, 'orario_fine' => null],
            ['max_posti' => 1, 'data_turno' => $ora->modify('+25 hours')->format('Y-m-d'), 'orario_inizio' => $ora->modify('+25 hours')->format('H:i:s')],
        ]);
        $a = $this->pren($imminente, 'in_attesa');
        $b = $this->pren($senzaData, 'in_attesa');
        $c = $this->pren($lontano, 'in_attesa');
        $coda = $this->servizio(ServizioListaAttesa::class);
        foreach ([$imminente, $senzaData, $lontano, 99999] as $turno) {
            $coda->promuovi($turno);
        }
        $this->assertSame('in_attesa', $this->stato($a));
        $this->assertSame('richiesta_conferma', $this->stato($b));
        $this->assertSame('richiesta_conferma', $this->stato($c));
    }

    public function testScadenzeEChiusuraDelleCode(): void
    {
        $ora = $this->orologio->adesso();
        [$turno, $imminente] = $this->evento(10, [
            ['max_posti' => 1, 'abilita_lista_attesa' => 1],
            ['max_posti' => 5, 'data_turno' => $ora->modify('+5 hours')->format('Y-m-d'), 'orario_inizio' => $ora->modify('+5 hours')->format('H:i:s')],
        ]);
        $scaduta = $this->pren($turno, 'richiesta_conferma', ['scadenza_conferma' => '2026-10-05 11:00:00']);
        $valida = $this->pren($imminente, 'richiesta_conferma', ['scadenza_conferma' => '2026-10-06 11:00:00']);
        $prossimo = $this->pren($turno, 'in_attesa', ['email' => 'next@prova.it']);
        $codaImminente = $this->pren($imminente, 'in_attesa');
        $confermata = $this->pren($imminente, 'confermata');

        $this->servizio(ServizioListaAttesa::class)->controllaScadenze();
        $this->assertSame('scaduta', $this->stato($scaduta));
        $this->assertSame('richiesta_conferma', $this->stato($prossimo), "l'offerta scaduta passa al successivo");
        $this->assertSame('next@prova.it', $this->mailer->inviate[0]['a']);
        $this->assertSame('scaduta', $this->stato($valida), 'a meno di 24 ore dall\'evento la coda si chiude, anche per i posti offerti');
        $this->assertSame('scaduta', $this->stato($codaImminente));
        $this->assertSame('confermata', $this->stato($confermata));
    }

    public function testVincoloDiIscrizionePerArea(): void
    {
        $this->assertSame('e.pagina_id = 3 AND e.archiviato = 0', ServizioVincoli::condizioneAmbito('un_evento', 3, 7));
        $this->assertSame('t.evento_id = 7', ServizioVincoli::condizioneAmbito('un_turno', 3, 7));
        $this->assertNull(ServizioVincoli::condizioneAmbito('nessuno', 3, 7));

        [$a1] = $this->evento(10, [['max_posti' => 5]]);
        [$b1] = $this->evento(11, [['max_posti' => 5]]);
        $conf = $this->pren($a1, 'confermata', ['email' => 'v@prova.it', 'utente_id' => 9, 'matricola' => 'M1']);
        $v = $this->servizio(ServizioVincoli::class);

        $this->assertSame($conf, (int) $v->iscrizioneVincolata('un_evento', 1, 11, 0, 'V@Prova.it', '')['id'], "stessa email, in qualsiasi evento dell'area");
        $this->assertNull($v->iscrizioneVincolata('un_turno', 1, 11, 0, 'v@prova.it', ''), 'un_turno: solo lo stesso evento');
        $this->assertNotNull($v->iscrizioneVincolata('un_turno', 1, 10, 0, 'v@prova.it', ''));
        $this->assertNotNull($v->iscrizioneVincolata('un_evento', 1, 11, 9, 'altra@prova.it', ''), 'stesso utente');
        $this->assertNotNull($v->iscrizioneVincolata('un_evento', 1, 11, 0, 'altra@prova.it', 'M1'), 'stessa matricola');
        $this->assertNull($v->iscrizioneVincolata('un_evento', 1, 11, 0, 'altra@prova.it', ''));
        $this->assertNull($v->iscrizioneVincolata('nessuno', 1, 11, 0, 'v@prova.it', ''));

        $this->db->esegui("UPDATE prenotazioni SET stato = 'in_attesa' WHERE id = ?", [$conf]);
        $this->assertNull($v->iscrizioneVincolata('un_evento', 1, 11, 0, 'v@prova.it', ''), "le liste d'attesa non contano");
        $this->db->esegui("UPDATE prenotazioni SET stato = NULL WHERE id = ?", [$conf]);
        $this->assertNull($v->iscrizioneVincolata('un_evento', 1, 11, 0, 'v@prova.it', ''), 'le vecchie righe senza stato non bloccano (come prima)');
    }

    public function testMieIscrizioniDellArea(): void
    {
        [$t1] = $this->evento(10, [['max_posti' => 5]]);
        [$t2] = $this->evento(11, [['max_posti' => 5]]);
        $this->pren($t1, 'confermata', ['utente_id' => 4]);
        $this->pren($t2, 'in_attesa', ['email' => 'Mia@Prova.it']);
        $this->pren($t2, 'annullata', ['utente_id' => 4]);
        $this->pren($t1, 'confermata', ['email' => 'altri@prova.it', 'utente_id' => 8]);
        $mie = $this->servizio(ServizioVincoli::class)->mieIscrizioniArea(1, 4, 'mia@prova.it');
        $this->assertSame([10 => [$t1 => 'confermata'], 11 => [$t2 => 'in_attesa']], $mie);
        $this->assertSame([10 => [$t1 => 'confermata']], $this->servizio(ServizioVincoli::class)->mieIscrizioniArea(1, 4, ''));
    }

    public function testConfermaChiudeLeAltreRichiesteDellaPersonaNelLimiteDellArea(): void
    {
        $this->db->esegui("UPDATE pagine_eventi SET limite_iscrizioni = 'un_evento' WHERE id = 1");
        [$conf, $altro] = $this->evento(10, [['max_posti' => 5], ['max_posti' => 1, 'abilita_lista_attesa' => 1]]);
        [$b] = $this->evento(11, [['max_posti' => 5]]);
        $confermata = $this->pren($conf, 'confermata', ['email' => 'v@prova.it', 'utente_id' => 9]);
        $attesa = $this->pren($b, 'in_attesa', ['email' => 'v@prova.it', 'utente_id' => 9]);
        $offerta = $this->pren($altro, 'richiesta_conferma', ['email' => 'v@prova.it']);
        $terzo = $this->pren($altro, 'in_attesa', ['email' => 'terzo@prova.it']);
        $estraneo = $this->pren($b, 'in_attesa', ['email' => 'estraneo@prova.it']);

        $this->assertSame(2, $this->servizio(ServizioVincoli::class)->decadiAttese($confermata));
        $this->assertSame('annullata', $this->stato($attesa));
        $this->assertSame('annullata', $this->stato($offerta));
        $this->assertSame('in_attesa', $this->stato($estraneo));
        $this->assertSame('richiesta_conferma', $this->stato($terzo), 'il posto liberato dall\'offerta annullata va al successivo in coda');
        $this->assertSame('terzo@prova.it', $this->mailer->inviate[0]['a']);
        $ultima = end($this->mailer->inviate);
        $this->assertSame('v@prova.it', $ultima['a']);
        $this->assertSame("Liste d'attesa annullate: iscrizione confermata a Evento 10", $ultima['oggetto']);
        $this->assertStringContainsString('<strong>Evento 11</strong> — 01/03/2999 · 10:00–12:00', $ultima['corpo']);
        $this->assertSame("Decadenza liste d'attesa (limite iscrizioni)", $this->registro->voci[0]['azione']);
        $this->assertSame(['Prenotazione confermata' => $confermata, 'Annullate' => "$attesa,$offerta"], $this->registro->voci[0]['dettagli']);

        $this->assertSame(0, $this->servizio(ServizioVincoli::class)->decadiAttese($estraneo), 'solo dopo una conferma');
        $this->assertSame(0, $this->servizio(ServizioVincoli::class)->decadiAttese(99999));
        $this->db->esegui("UPDATE pagine_eventi SET limite_iscrizioni = 'nessuno' WHERE id = 1");
        $this->assertSame(0, $this->servizio(ServizioVincoli::class)->decadiAttese($confermata), 'area senza limite');
        $this->assertSame(0, $this->servizio(RegoleIscrizioni::class)->decadiAtteseVincolate($confermata));
    }

    public function testLimitiETestiPerGliEventi(): void
    {
        $regole = $this->servizio(RegoleIscrizioni::class);
        $this->assertSame(['min' => 8, 'max' => 25], $regole->limitiPartecipanti(['min_studenti' => 8, 'max_studenti' => 25]));
        $this->assertSame(['min' => 10, 'max' => 12], $regole->limitiPartecipanti(['min_studenti' => 8, 'max_studenti' => 25], ['min_partecipanti' => 10, 'max_partecipanti' => 12]));
        $p = $this->servizio(PresentazioneComune::class);
        $this->assertSame('3 posti liberi', $p->testoPostiLiberi(3));
        $this->assertSame('Posti disponibili', $p->testoPostiLiberi(5000));
        $this->assertSame('#0056B3', $p->coloreValido('boh', '#0056B3'));
        $this->assertSame('#112233', $p->coloreValido('#112233'));
        [$t] = $this->evento(10, [[]]);
        $this->db->esegui("UPDATE pagine_eventi SET colore_primario = '#AA0000' WHERE id = 1");
        $this->assertSame('#AA0000', $p->coloreAreaTurno($t));
    }

    public function testDisponibilitaDeiPosti(): void
    {
        [$t1, $t2] = $this->evento(10, [['max_posti' => 10], ['max_posti' => 6]]);
        $this->pren($t1, 'confermata', ['num_posti' => 3, 'utente_id' => 4]);
        $this->pren($t1, 'da_approvare', ['num_posti' => 2]);
        $a = $this->pren($t1, 'in_attesa', ['data_prenotazione' => '2026-02-01 10:00:00']);
        $b = $this->pren($t1, 'in_attesa', ['data_prenotazione' => '2026-02-01 10:00:00']);
        $c = $this->pren($t1, 'in_attesa', ['data_prenotazione' => '2026-01-01 10:00:00']);
        $this->pren($t2, 'confermata', ['num_posti' => 5]);
        $d = $this->servizio(ServizioDisponibilita::class);

        $this->assertSame([$a => ['posizione' => 2, 'totale' => 3], $b => ['posizione' => 3, 'totale' => 3], $c => ['posizione' => 1, 'totale' => 3]], $d->posizioniInCoda([$a, $b, $c, 0, 'x']));
        $this->assertSame([], $d->posizioniInCoda([]));

        $this->assertSame([10 => ['capienza' => 16, 'occupati' => 10, 'liberi' => 6]], $d->riepilogoPosti('evento', [10, 0]));
        $this->assertSame([1 => ['capienza' => 16, 'occupati' => 10, 'liberi' => 6]], $d->riepilogoPosti('pagina', [1]));
        $this->assertSame([], $d->riepilogoPosti('evento', []));

        $ultimi = $d->turniUltimiPosti([1]);
        $this->assertCount(1, $ultimi, 'un solo turno per evento');
        $this->assertSame($t2, (int) $ultimi[0]['turno_id']);
        $this->assertSame(1, $ultimi[0]['liberi']);
        $this->assertSame([], $d->turniUltimiPosti([]));

        $attive = $d->attiveDellUtente(4);
        $this->assertCount(1, $attive);
        $this->assertSame('confermata', $attive[0]['stato']);
        $this->assertSame([], $d->attiveDellUtente(0));
        $this->assertSame([], $d->attiveDellUtente(5));
    }

    public function testDestinatariEDatiDelleNotifiche(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 5, 'nome_turno' => 'Gruppo A']], ['email_notifiche_extra' => 'extra@prova.it, sbagliata, EXTRA@prova.it']);
        $this->db->esegui("INSERT INTO progetti_dettagli (evento_id, referenti_json) VALUES (10, ?)", [json_encode([['email' => 'Ref@prova.it', 'notifiche' => 1], ['email' => 'no@prova.it', 'notifiche' => 0], ['email' => 'rotta', 'notifiche' => 1]])]);
        $this->db->esegui("INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, ordine) VALUES (1, NULL, 'classe', 'Classe', 1), (1, 10, 'allegato', 'Documento', 2)");
        $p = $this->pren($t, 'confermata', ['num_posti' => 2, 'matricola' => 'M9', 'dati_custom_json' => json_encode(['classe' => '3A', 'allegato' => 'uploads/allegati_prenotazioni/x.pdf'])]);

        $n = $this->servizio(NotifichePrenotazione::class);
        $this->assertSame(['extra@prova.it', 'ref@prova.it'], $n->destinatari(10));
        $r = $n->riepilogo($p);
        $this->assertNotNull($r);
        $this->assertSame('Evento 10', $r['oggetto_evento']);
        $this->assertStringContainsString('Gruppo A · 01/03/2999 · 10:00–12:00', $r['html']);
        $this->assertStringContainsString('Classe</td>', $r['html']);
        $this->assertStringContainsString('<a href="http://prova.it/eventi/uploads/allegati_prenotazioni/x.pdf">Allegato 1</a>', $r['html']);
        $this->assertStringContainsString('http://prova.it/eventi/admin/iscritti.php?p_id=1&amp;f_turno=' . $t, $r['html']);
        $this->assertNull($n->riepilogo(99999));
    }
}
