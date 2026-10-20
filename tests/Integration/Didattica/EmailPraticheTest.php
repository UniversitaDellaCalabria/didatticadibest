<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Didattica\NotifichePratiche;
use App\Didattica\PraticaRepository;
use App\Email\ModelliEmail;

/** Le email delle pratiche vengono dal catalogo: senza modifiche il testo è quello di sempre, le modifiche del pannello cambiano l'email che parte. */
final class EmailPraticheTest extends DidatticaBase
{
    protected function tabelle(): array
    {
        return ['email_modelli' => "CREATE TABLE email_modelli (chiave VARCHAR(80) NOT NULL PRIMARY KEY, oggetto VARCHAR(300) NOT NULL DEFAULT '', corpo MEDIUMTEXT DEFAULT NULL, bottone VARCHAR(120) DEFAULT '', aggiornato_il DATETIME DEFAULT NULL, aggiornato_da VARCHAR(150) DEFAULT '')"] + parent::tabelle();
    }

    private function pratica(): array
    {
        $m = $this->modulo('Richiesta tesi');
        $id = $this->db->inserisci("INSERT INTO pratiche (modulo_id, codice, nome, cognome, email, matricola, stato, passo) VALUES (?, 'P1', 'Luca', 'Rossi', 'l@x.it', '223456', 'in_lavorazione', 1)", [$m]);

        return $this->servizio(PraticaRepository::class)->perId($id);
    }

    private function n(): NotifichePratiche
    {
        return $this->servizio(NotifichePratiche::class);
    }

    public function testPraticaRicevutaEStatoComeSempre(): void
    {
        $p = $this->pratica();
        $this->n()->email($p, 'nuova');
        $m = $this->mailer->inviate[0];
        $this->assertSame('l@x.it', $m['a']);
        $this->assertSame('Pratica ricevuta: Richiesta tesi', $m['oggetto']);
        $this->assertStringContainsString('<p>Gentile Luca,</p><p>abbiamo ricevuto la tua richiesta.</p><p><strong>Richiesta tesi</strong> · pratica <strong>P1</strong></p>', $m['corpo']);
        $this->assertStringContainsString('Puoi seguirne lo stato e scrivere all\'ufficio dalla tua Area personale.', $m['corpo']);
        $this->assertStringContainsString('pratiche.php?id=' . $p['id'], $m['corpo']);
        $this->assertStringContainsString('>Vedi la pratica</a>', $m['corpo']);

        $this->mailer->inviate = [];
        $this->n()->email($p, 'stato', "Serve un\ndocumento <b>in più</b>");
        $m = $this->mailer->inviate[0];
        $this->assertSame('Pratica P1: In lavorazione', $m['oggetto']);
        $this->assertStringContainsString('la tua pratica è ora: <strong>In lavorazione</strong>', $m['corpo']);
        $this->assertStringContainsString("<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>Serve un<br>documento &lt;b&gt;in più&lt;/b&gt;</p>", $m['corpo']);
    }

    public function testSenzaNotaNonCeIlRiquadro(): void
    {
        $this->n()->email($this->pratica(), 'stato');
        $this->assertStringNotContainsString('background:#f1f5f9', $this->mailer->inviate[0]['corpo']);
    }

    public function testMessaggioDelloStudenteConMatricolaEColore(): void
    {
        $p = $this->pratica();
        $op = $this->operatore('Anna Verdi', 'anna@x.it');
        $this->db->esegui('UPDATE pratiche SET assegnata_a = ? WHERE id = ?', [$op, $p['id']]);
        $this->n()->email($this->servizio(PraticaRepository::class)->perId((int) $p['id']), 'msg_studente', 'Buongiorno');
        $m = $this->mailer->inviate[0];
        $this->assertSame('anna@x.it', $m['a']);
        $this->assertSame('Messaggio sulla pratica P1 – Rossi Luca', $m['oggetto']);
        $this->assertStringContainsString('Nuovo messaggio dello studente:', $m['corpo']);
        $this->assertStringContainsString('Buongiorno', $m['corpo']);
        $this->assertSame('#047857', $m['colore'] ?? '#047857');
    }

    public function testUnaModificaDalPannelloCambiaLEmail(): void
    {
        $this->servizio(ModelliEmail::class)->salva('pratica_ricevuta', 'Ricevuta {{codice}}', "Ciao {{nome}}!\n\nLa tua pratica **{{modulo}}** è arrivata.", 'Guarda', 'Admin');
        $this->n()->email($this->pratica(), 'nuova');
        $m = $this->mailer->inviate[0];
        $this->assertSame('Ricevuta P1', $m['oggetto']);
        $this->assertStringContainsString('<p>Ciao Luca!</p><p>La tua pratica <strong>Richiesta tesi</strong> è arrivata.</p>', $m['corpo']);
        $this->assertStringContainsString('>Guarda</a>', $m['corpo']);
    }
}
