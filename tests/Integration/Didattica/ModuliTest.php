<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Didattica\CampiModulo;
use App\Didattica\HtmlCampi;
use App\Didattica\LetturaRisposte;
use App\Didattica\ServizioModuli;

/** Moduli online: chi li compila e quando, salvataggio dal costruttore, lettura delle risposte e HTML dei campi. */
final class ModuliTest extends DidatticaBase
{
    public function testPeriodoDiApertura(): void
    {
        $s = $this->servizio(ServizioModuli::class);
        $this->assertSame([true, ''], $s->periodo([]));
        $this->assertSame([true, 'Aperto fino al 31/12/2026'], $s->periodo(['aperto_al' => '2026-12-31']));
        $this->assertSame([true, 'Aperto fino al 06/10/2026'], $s->periodo(['aperto_al' => '2026-10-06']), "l'ultimo giorno è ancora aperto");
        $this->assertSame([false, 'Chiuso il 05/10/2026'], $s->periodo(['aperto_al' => '2026-10-05']));
        $this->assertSame([false, 'Si compila dal 07/10/2026 al 30/10/2026'], $s->periodo(['aperto_dal' => '2026-10-07', 'aperto_al' => '2026-10-30']));
        $this->assertSame([false, 'Si compila dal 07/10/2026'], $s->periodo(['aperto_dal' => '2026-10-07']));
        $this->assertSame([true, ''], $s->periodo(['aperto_dal' => '2026-10-01']));
    }

    public function testChiPuoCompilare(): void
    {
        $s = $this->servizio(ServizioModuli::class);
        $this->assertFalse($s->destinatario(['destinatari' => 'tutti'], null));
        $this->assertTrue($s->destinatario(['destinatari' => 'tutti'], ['id' => 2]));
        $this->assertTrue($s->destinatario(['destinatari' => 'studenti'], ['id' => 1, 'ruolo_id' => 1]), 'chi gestisce la didattica può sempre');
    }

    public function testSalvataggioDalCostruttore(): void
    {
        $s = $this->servizio(ServizioModuli::class);
        $u = $this->ufficio('Carriere', '', 0, 'carriere');
        $m = $this->ufficio('Manager', '', 1, 'manager');
        $this->assertSame('titolo', $s->salva(['titolo' => '  '], [])['errore']);
        $post = [
            'titolo' => 'Modulo di prova', 'tipo' => 'online', 'categoria' => '', 'descrizione' => '<p>Ciao <script>x</script><strong>mondo</strong></p>', 'destinatari' => 'boh',
            'email_ufficio' => 'a@x.it; boh, b@x.it', 'link' => 'www.example.org/doc', 'attivo' => '1', 'ordine' => '3', 'aperto_dal' => '2026-01-01', 'aperto_al' => 'ieri', 'giorni_promemoria' => '500',
            'c_etichetta' => ['Corso', '', 'Hai esami', 'Dettaglio'], 'c_tipo' => ['corso_studio', 'text', 'radio', 'text'], 'c_opzioni' => ['', '', 'Sì, No', ''], 'c_obbl' => ['1', '0', '1', '0'],
            'c_uff' => ['0', '0', '0', '1'], 'c_aiuto' => ['', '', '', 'Aiuto'], 'c_cond_campo' => [3 => 'Hai esami'], 'c_cond_op' => [3 => 'uguale'], 'c_cond_val' => [3 => 'Sì'],
            'v_sezione' => 'Sezione', 'v_stile' => 'elenco', 'v_decisione' => 'convalide', 'iter' => [(string) $u, '@cdl', (string) $m, '999', '@segreteria'],
            'd_pdf' => '1', 'd_oggetto' => 'Oggetto', 'd_destinatario' => 'Al Direttore',
        ];
        $e = $s->salva($post, []);
        $this->assertSame(['Modulo di prova', 'online', null, false], [$e['titolo'], $e['tipo'], $e['errore'], $e['file_rifiutato']]);
        $r = $this->db->riga('SELECT * FROM didattica_moduli WHERE id = ?', [$e['id']]);
        $this->assertSame(['Altro', $r['descrizione'], 'tutti', 'a@x.it, b@x.it', 'https://www.example.org/doc', 1, 3, '2026-01-01', null, 90], [$r['categoria'], $r['descrizione'], $r['destinatari'], $r['email_ufficio'], $r['link'], $r['attivo'], $r['ordine'], $r['aperto_dal'], $r['aperto_al'], $r['giorni_promemoria']]);
        $this->assertStringContainsString('<strong>mondo</strong>', $r['descrizione']);
        $this->assertStringNotContainsString('<script', $r['descrizione']);
        $this->assertSame([$u, '@cdl', '@segreteria'], json_decode($r['iter_json'], true), 'gli uffici che smistano e quelli inesistenti non entrano nell\'iter');
        $campi = CampiModulo::da($r['campi_json']);
        $this->assertSame(['Corso', 'Hai esami', 'Dettaglio'], array_column($campi, 'etichetta'));
        $this->assertTrue($campi[2]['ufficio']);
        $this->assertSame(['Hai esami', 'uguale', 'Sì'], [$campi[2]['cond']['campo'], $campi[2]['cond']['op'], $campi[2]['cond']['valore']]);
        $this->assertSame(['sezione' => 'Sezione', 'stile' => 'elenco', 'decisione' => 'convalide'], array_intersect_key(json_decode($r['verbale_json'], true), ['sezione' => 1, 'stile' => 1, 'decisione' => 1]));
        $d = json_decode($r['domanda_json'], true);
        $this->assertSame([1, 0, 'Oggetto', 'Al Direttore'], [$d['pdf'], $d['bollo'], $d['oggetto'], $d['destinatario']]);

        // Modifica: stesso id, i campi si sostituiscono
        $e2 = $s->salva(['modulo_id' => (string) $e['id'], 'titolo' => 'Rinominato', 'c_etichetta' => ['Solo uno'], 'c_tipo' => ['text']], []);
        $this->assertSame($e['id'], $e2['id']);
        $r = $this->db->riga('SELECT titolo, tipo, campi_json, iter_json FROM didattica_moduli WHERE id = ?', [$e['id']]);
        $this->assertSame(['Rinominato', 'documento', null], [$r['titolo'], $r['tipo'], $r['iter_json']]);
        $this->assertSame(['Solo uno'], array_column(CampiModulo::da($r['campi_json']), 'etichetta'));
    }

    public function testEliminazione(): void
    {
        $s = $this->servizio(ServizioModuli::class);
        $con = $this->modulo('Con pratiche');
        $senza = $this->modulo('Senza pratiche');
        $this->db->esegui("INSERT INTO pratiche (modulo_id, codice) VALUES (?, 'PR-1'), (?, 'PR-2')", [$con, $con]);
        $this->assertSame(2, $s->elimina($con));
        $this->assertSame(0, (int) $this->db->valore('SELECT attivo FROM didattica_moduli WHERE id = ?', [$con]), 'nascosto, non eliminato');
        $this->assertSame(0, $s->elimina($senza));
        $this->assertNull($this->db->valore('SELECT id FROM didattica_moduli WHERE id = ?', [$senza]));
    }

    public function testLetturaDelleRisposte(): void
    {
        $l = $this->servizio(LetturaRisposte::class);
        $campi = CampiModulo::da(json_encode([
            ['etichetta' => 'Dove', 'tipo' => 'radio', 'opzioni' => 'qui, altrove', 'obbligatorio' => 1],
            ['etichetta' => 'Ateneo', 'tipo' => 'text', 'obbligatorio' => 1, 'cond' => ['campo' => 'Dove', 'op' => 'uguale', 'valore' => 'altrove']],
            ['etichetta' => 'Email', 'tipo' => 'email'],
            ['etichetta' => 'CF', 'tipo' => 'codice_fiscale'],
            ['etichetta' => 'Sito', 'tipo' => 'url'],
            ['etichetta' => 'Numero', 'tipo' => 'number'],
            ['etichetta' => 'Giorno', 'tipo' => 'date'],
            ['etichetta' => 'Scelte', 'tipo' => 'multicheck', 'opzioni' => 'a, b, c'],
            ['etichetta' => 'Esami', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento, CFU, Data:data, Piano:piano'],
            ['etichetta' => 'Sezione', 'tipo' => 'titolo'],
            ['etichetta' => 'Cat', 'tipo' => 'insegnamento_ateneo'],
            ['etichetta' => 'Accetto', 'tipo' => 'dichiarazione', 'obbligatorio' => 1],
            ['etichetta' => 'Auto', 'tipo' => 'text', 'auto' => ['campo' => 'Dove', 'op' => 'uguale', 'valore' => 'qui', 'imposta' => 'Impostato']],
        ]));
        // Tutto vuoto: mancano la domanda obbligatoria e la dichiarazione (l'Ateneo è nascosto, non obbligatorio)
        [$ris, $err] = $l->leggi($campi, [], [], false);
        $this->assertSame(['Dove: campo obbligatorio', 'Accetto: devi accettare la dichiarazione'], $err);
        $this->assertTrue($ris[1]['nascosto']);
        $this->assertNull($ris[0]['file']);

        $post = ['campo_c1' => 'qui', 'campo_c3' => 'non-una-mail', 'campo_c4' => 'rss mra 80a01 d086x', 'campo_c5' => 'example.org', 'campo_c6' => '1,5x', 'campo_c7' => '06/10/2026',
                 'campo_c8' => ['a', 'z', 'c'], 'campo_c9' => [['Fisica', '', 'Chimica', ''], ['6', '', '9', ''], ['2025-07-01', '', '', ''], ['A scelta; elimina: X', '1', '', 'si']],
                 'campo_c11' => 'Chimica', 'campo_c11_meta' => '{"id":3,"nome":"Chimica","corso":"Corso X","aa":"2026/2027","cfu":"9","ssd":"CHIM/06"}', 'campo_c12' => '1', 'campo_c13' => 'ignorato'];
        [$ris, $err] = $l->leggi($campi, $post, [], false);
        $this->assertSame(['Email: email non valida'], $err);
        $v = array_column($ris, 'valore', 'etichetta');
        $this->assertSame('qui', $v['Dove']);
        $this->assertSame('RSSMRA80A01D086X', $v['CF']);
        $this->assertSame('https://example.org', $v['Sito']);
        $this->assertSame('', $v['Numero'], 'numero non valido: vuoto');
        $this->assertSame('', $v['Giorno'], 'data non valida: vuota');
        $this->assertSame('a, c', $v['Scelte'], 'solo le scelte previste');
        $this->assertSame('Sì', $v['Accetto']);
        $this->assertSame('Impostato', $v['Auto'], 'valore automatico dal server');
        $tab = $ris[8];
        $this->assertSame(['Insegnamento', 'CFU', 'Data', 'Piano'], $tab['colonne']);
        $this->assertSame([['Fisica', '6', '01/07/2025', 'A scelta; elimina: X'], ['Chimica', '9', '', '']], $tab['righe'], 'le righe vuote o con la sola casella del piano si scartano');
        $this->assertSame("Fisica | 6 | 01/07/2025 | A scelta; elimina: X\nChimica | 9 |  | ", $tab['valore']);
        $this->assertSame(['id' => 3, 'nome' => 'Chimica', 'corso' => 'Corso X', 'aa' => '2026/2027', 'cfu' => 9.0, 'ssd' => 'CHIM/06'], $ris[9]['meta']);
        $this->assertArrayNotHasKey('Sezione', $v, 'il solo testo non è una risposta');
    }

    public function testHtmlDeiCampi(): void
    {
        $h = $this->servizio(HtmlCampi::class);
        $this->assertSame(['2027/2028', '2026/2027', '2025/2026', '2024/2025', '2023/2024'], $h->anniAccademici());
        $this->assertSame(2026, $h->annoCorrente());
        $campi = CampiModulo::da(json_encode([
            ['etichetta' => 'Che <b>cosa</b>', 'tipo' => 'text', 'obbligatorio' => 1, 'aiuto' => 'Scrivi'],
            ['etichetta' => 'Dove', 'tipo' => 'radio', 'opzioni' => 'qui, là'],
            ['etichetta' => 'Esami', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento, CFU'],
        ]));
        $t = $h->campo($campi[0], 'x"y', false, true);
        $this->assertStringContainsString('class="campo-pratica col-md-6"', $t);
        $this->assertStringContainsString('data-nome="c1" data-tipo="text"', $t);
        $this->assertStringContainsString('Che &lt;b&gt;cosa&lt;/b&gt; <span class="text-danger">*</span>', $t);
        $this->assertStringContainsString('value="x&quot;y" required', $t);
        $this->assertStringContainsString('<div class="form-text">Scrivi</div>', $t);
        $this->assertStringContainsString('class="campo-pratica col-md-6"', $h->campo($campi[1], 'qui', false, true), 'una scelta con poche opzioni sta in mezza riga');
        $this->assertStringContainsString('name="campo_c3[0][]"', $h->campo($campi[2], [['Fisica', '6']]));
        $this->assertStringContainsString('<datalist id="dl_insegnamento">', $h->datalist($campi));
        $this->assertSame('<script src="/eventi/assets/js/campi-pratica.js?v=' . (filemtime(dirname(__DIR__, 3) . '/assets/js/campi-pratica.js') ?: 1) . '" data-base="/eventi"></script>', $h->scriptTabelle());
        $this->assertSame('—', $h->risposta(['tipo' => 'tabella', 'righe' => []]));
        $this->assertSame('06/10/2026', $h->risposta(['tipo' => 'date', 'valore' => '2026-10-06']));
        $this->assertSame('a&lt;b<br />' . "\n" . 'c', $h->risposta(['valore' => "a<b\nc"]));
        $this->assertSame('a, b', $h->valoriPostCampo(['nome' => 'c1', 'tipo' => 'multicheck'], ['campo_c1' => ['a', 'b']]));
        $this->assertSame([['A', 'B'], ['C', 'D']], $h->valoriPostCampo(['nome' => 'c2', 'tipo' => 'tabella'], ['campo_c2' => [['A', 'C'], ['B', 'D']]]), 'le colonne in array paralleli tornano come righe');
    }
}
