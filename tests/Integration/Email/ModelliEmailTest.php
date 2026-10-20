<?php

declare(strict_types=1);

namespace Tests\Integration\Email;

use App\Email\CatalogoEmail;
use App\Email\ModelliEmail;
use Tests\Integration\DatabaseDiProva;

/** I testi delle email: originali dal catalogo, modifiche dal pannello, segnaposto sempre «ripuliti». */
final class ModelliEmailTest extends DatabaseDiProva
{
    protected function tabelle(): array
    {
        return [
            'impostazioni_sistema' => "CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, email_conferma_oggetto VARCHAR(255) DEFAULT '', email_conferma_corpo TEXT NULL, email_attestato_oggetto VARCHAR(255) DEFAULT '', email_attestato_corpo TEXT NULL)",
            'email_modelli' => "CREATE TABLE email_modelli (chiave VARCHAR(80) NOT NULL PRIMARY KEY, oggetto VARCHAR(300) NOT NULL DEFAULT '', corpo MEDIUMTEXT DEFAULT NULL, bottone VARCHAR(120) DEFAULT '', aggiornato_il DATETIME DEFAULT NULL, aggiornato_da VARCHAR(150) DEFAULT '')",
        ];
    }

    private function m(): ModelliEmail
    {
        return new ModelliEmail($this->db);
    }

    public function testOgniSegnapostoUsatoESempreDichiarato(): void
    {
        foreach (CatalogoEmail::modelli() as $chiave => $mod) {
            $this->assertSame([], $this->m()->controlla($chiave, $mod['oggetto'], $mod['corpo'], (string) $mod['bottone']), "il testo originale di $chiave deve essere valido");
            $this->assertNotSame('', $mod['nome'] . $mod['quando'] . $mod['destinatario'], $chiave);
        }
    }

    public function testOgniModelloSiComponeConIDatiDEsempio(): void
    {
        foreach (CatalogoEmail::modelli() as $chiave => $mod) {
            $r = $this->m()->render($chiave, $this->m()->datiDEsempio($chiave));
            $this->assertNotSame('', $r['oggetto'], $chiave);
            $this->assertNotSame('', $r['corpo'], $chiave);
            $this->assertStringNotContainsString('{{', $r['oggetto'] . $r['corpo'] . (string) $r['bottone'], "segnaposto rimasto in $chiave");
            $this->assertSame($mod['bottone'] === null, $r['bottone'] === null, $chiave);
        }
    }

    public function testUnIndirizzoEmailDiventaUnCollegamento(): void
    {
        $r = $this->m()->render('fsl_convenzione_pronta', ['scuola' => 'Liceo', 'pec' => 'dibest@pec.unical.it']);
        $this->assertStringContainsString("via PEC a <a href='mailto:dibest@pec.unical.it'>dibest@pec.unical.it</a>.", $r['corpo']);
        $x = $this->m()->render('fsl_convenzione_pronta', ['scuola' => 'Liceo', 'pec' => "x'><script>"]);
        $this->assertStringNotContainsString('<script>', $x['corpo']);
    }

    public function testIlTestoDegliEventiGiaScrittoInSistemaVieneImportatoUnaVolta(): void
    {
        $this->db->esegui("INSERT INTO impostazioni_sistema (id, email_conferma_oggetto, email_conferma_corpo) VALUES (1, 'Ok {TITOLO_EVENTO}', '<p>Ciao {NOME}!</p>')");
        $r = $this->m()->renderHtml('evento_conferma', ['{NOME}' => 'Maria', '{TITOLO_EVENTO}' => 'Giornata']);
        $this->assertSame(['oggetto' => 'Ok Giornata', 'corpo' => '<p>Ciao Maria!</p>'], $r);
        $mod = $this->m()->modello('evento_conferma');
        $this->assertTrue($mod['modificato']);
        $this->assertStringContainsString('Impostazioni di sistema', $mod['aggiornato_da']);
        // dopo il ripristino torna il testo del catalogo, non quello vecchio di Sistema
        $this->m()->ripristina('evento_conferma');
        $r = $this->m()->renderHtml('evento_conferma', ['{NOME}' => 'Maria', '{COGNOME}' => 'Verdi', '{TITOLO_EVENTO}' => 'Giornata']);
        $this->assertStringContainsString('Prenotazione confermata per <strong>Giornata</strong>', $r['corpo']);
        $this->assertSame('Conferma Prenotazione Eventi', $r['oggetto']);
    }

    public function testUnEmailFacoltativaParteSoloSeAttivata(): void
    {
        $this->assertNull($this->m()->renderHtml('evento_attestato', ['{NOME}' => 'Maria']), 'mai attivata: non parte');
        $this->assertFalse($this->m()->modello('evento_attestato')['attiva']);
        $this->assertSame([], $this->m()->salva('evento_attestato', 'Attestato pronto', '<p>Ciao {NOME}</p>', '', 'Admin', true));
        $this->assertSame('<p>Ciao Maria</p>', $this->m()->renderHtml('evento_attestato', ['{NOME}' => 'Maria'])['corpo']);
        $this->assertSame([], $this->m()->salva('evento_attestato', 'Attestato pronto', '<p>Ciao {NOME}</p>', '', 'Admin', false));
        $this->assertNull($this->m()->renderHtml('evento_attestato', ['{NOME}' => 'Maria']), 'tolta la spunta: non parte più');
    }

    public function testControlliSulTestoHtml(): void
    {
        $this->assertStringContainsString('{INVENTATO}', implode(' ', $this->m()->controlla('evento_conferma', 'o', '<p>{INVENTATO}</p>', '')));
        $this->assertSame([], $this->m()->controlla('evento_conferma', 'o', '<p>{NOME} {LINK_RICEVUTA}</p>', ''));
        $this->m()->salva('evento_conferma', 'o', "<p>Ciao</p><script>alert(1)</script><a href=\"javascript:x()\" onclick=\"y()\">z</a>", '', 'A');
        $c = $this->m()->modello('evento_conferma')['corpo'];
        $this->assertStringNotContainsString('<script', $c);
        $this->assertStringNotContainsString('javascript:', $c);
        $this->assertStringNotContainsString('onclick', $c);
    }

    public function testSenzaModificheValeIlTestoOriginale(): void
    {
        $r = $this->m()->render('tutorato_invito_studente', ['tutor' => 'Maria Verdi', 'bando' => 'Tutorato I semestre']);
        $this->assertSame('Lettera di incarico di tutorato: controlla e conferma', $r['oggetto']);
        $this->assertSame('Controlla e conferma la lettera', $r['bottone']);
        $this->assertStringStartsWith('<p>Gentile Maria Verdi,</p><p>è pronta la tua <strong>lettera di incarico</strong> per le attività di tutorato (Tutorato I semestre).</p>', $r['corpo']);
        $this->assertStringContainsString('<strong>con SPID o CIE</strong>', $r['corpo']);
        $this->assertFalse($this->m()->modello('tutorato_invito_studente')['modificato']);
    }

    public function testIDatiNonPossonoIntrodurreHtmlNeFormattazione(): void
    {
        $r = $this->m()->render('tutorato_docente_da_firmare', ['tutor' => '<script>alert(1)</script> **x**', 'tutor_cn' => "Verdi\nMaria", 'bando' => 'B', 'docente' => 'A']);
        $this->assertStringNotContainsString('<script>', $r['corpo']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; **x**', $r['corpo']);
        $this->assertSame('Lettera di incarico da firmare: Verdi Maria', $r['oggetto'], "l'oggetto è una riga sola");
    }

    public function testListeCitazioniEPezziDiHtmlDelPortale(): void
    {
        $r = $this->m()->render('tutorato_pagamenti_da_pagare', ['ufficio' => 'Ufficio', 'referente' => 'Rif', 'quante' => '2 pratiche', 'dettaglio' => '', 'elenco' => '<ul><li>uno</li></ul>']);
        $this->assertStringContainsString('<p>Gentile Ufficio,</p><p>Rif ha validato e passato <strong>2 pratiche</strong> all\'ufficio pagamenti:</p><ul><li>uno</li></ul><p>Nella scheda', $r['corpo']);
        $this->assertSame('Tutorato: 2 pratiche da pagare', $r['oggetto']);
        $q = $this->m()->render('tutorato_fine_da_rivedere', ['docente_con_titolo' => 'Prof. A', 'tutor' => 'T', 'tutor_cn' => 'T', 'bando' => 'B', 'motivo' => "riga 1\nriga 2 <b>"]);
        $this->assertStringContainsString('<blockquote', $q['corpo']);
        $this->assertStringContainsString('riga 1<br>riga 2 &lt;b&gt;</blockquote>', $q['corpo']);
        $l = $this->m()->componi('x', "- uno {{a}}\n- due", null, ['a' => '&']);
        $this->assertSame('<ul><li>uno &amp;</li><li>due</li></ul>', $l['corpo']);
        $this->assertNull($l['bottone']);
    }

    public function testSalvaRipristinaERegistraChiHaModificato(): void
    {
        $s = $this->m();
        $this->assertSame([], $s->salva('tutorato_attivita_completate', 'Attività finite', "Ciao {{tutor}},\n\nhai fatto **{{ore}} ore**.", '', 'Admin Prova'));
        $r = $this->m()->render('tutorato_attivita_completate', ['tutor' => 'Maria', 'ore' => '38']);
        $this->assertSame('Attività finite', $r['oggetto']);
        $this->assertSame('<p>Ciao Maria,</p><p>hai fatto <strong>38 ore</strong>.</p>', $r['corpo']);
        $mod = $this->m()->modello('tutorato_attivita_completate');
        $this->assertTrue($mod['modificato']);
        $this->assertSame('Admin Prova', $mod['aggiornato_da']);
        $this->assertNull($mod['bottone'], 'un\'email senza pulsante non ne ha uno nemmeno dopo la modifica');

        $this->m()->ripristina('tutorato_attivita_completate');
        $this->assertSame('Tutorato: attività completate', $this->m()->render('tutorato_attivita_completate', ['tutor' => 'M', 'ore' => '1'])['oggetto']);
    }

    public function testControlliSuiTestiModificati(): void
    {
        $m = $this->m();
        $this->assertNotSame([], $m->controlla('tutorato_attivita_completate', '', 'x', ''), 'oggetto vuoto');
        $this->assertNotSame([], $m->controlla('tutorato_attivita_completate', 'o', '  ', ''), 'testo vuoto');
        $this->assertStringContainsString('{{inventato}}', implode(' ', $m->controlla('tutorato_attivita_completate', 'o', 'ciao {{inventato}}', '')));
        $this->assertStringContainsString('scritto male', implode(' ', $m->controlla('tutorato_attivita_completate', 'o', 'ciao {{tutor}', '')));
        $this->assertStringContainsString('da solo su una riga', implode(' ', $m->controlla('tutorato_fine_firmata_operatore', 'o', 'ecco {{!tabella}} qui', 'Apri')));
        $this->assertStringContainsString('pulsante', implode(' ', $m->controlla('tutorato_invito_studente', 'o', 'x', '')));
        $this->assertNotSame([], $m->salva('chiave_che_non_esiste', 'o', 'x', '', 'A'));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM email_modelli'), 'un testo non valido non si salva');
        // l'HTML scritto a mano nel modello non passa: si vede come testo
        $this->m()->salva('tutorato_attivita_completate', 'o', '<img src=x onerror=alert(1)> {{tutor}}', '', 'A');
        $this->assertStringNotContainsString('<img', $this->m()->render('tutorato_attivita_completate', ['tutor' => 'M', 'ore' => '1'])['corpo']);
    }

    public function testDatiDEsempioPerLAnteprima(): void
    {
        $d = $this->m()->datiDEsempio('tutorato_fine_firmata_operatore');
        $this->assertSame(['tutor', 'tutor_cn', 'bando', 'ore', 'ore_previste', 'tabella'], array_keys($d));
        $r = $this->m()->render('tutorato_fine_firmata_operatore', $d);
        $this->assertStringContainsString('<table', $r['corpo']);
        $this->assertStringContainsString('Verdi Maria', $r['oggetto']);
    }
}
