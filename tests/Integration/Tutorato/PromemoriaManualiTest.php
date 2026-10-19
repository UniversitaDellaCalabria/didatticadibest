<?php

declare(strict_types=1);

namespace Tests\Integration\Tutorato;

use App\Email\ModelliEmail;
use App\Tutorato\ArchivioIncarichi;
use App\Tutorato\ServizioIncarichi;
use App\Tutorato\ServizioRegistroTutorato;

/** I due pulsanti manuali: invito a compilare il registro (singolo e in blocco) e fine attività da firmare al docente. */
final class PromemoriaManualiTest extends TutoratoBase
{
    private function reg(): ServizioRegistroTutorato
    {
        return $this->servizio(ServizioRegistroTutorato::class);
    }

    /** Una lettera firmata da tutti (il registro è aperto), con ore da 10; ritorna l'id. */
    private function firmata(array $campi = []): int
    {
        if ($this->db->valore('SELECT COUNT(*) FROM tutorato_bandi WHERE id = 1') === 0) {
            $this->bando();
        }
        $s = $this->servizio(ServizioIncarichi::class);
        [$id] = $s->salva($this->campi($campi));
        $s->invia($id);
        $s->conferma($id, ['id' => 7, 'codice_fiscale' => strtoupper((string) ($campi['codice_fiscale'] ?? 'RSSLCU00A31D086X'))], ['metodo' => 'spid']);
        foreach (['docente', 'direttore'] as $ruolo) {
            $pdf = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->pdfCorrente($this->lettera($id)));
            $this->assertNull($s->registraFirma($id, $ruolo, $this->firmato($pdf)));
        }
        $this->reg()->salvaDatiFine($id, ['docente_titolo' => 'Prof.ssa', 'insegnamento_docente' => 'Chimica', 'corso_laurea' => 'Biologia', 'data_inizio' => '2026-08-24', 'data_fine' => '2026-10-31']);
        $this->mailer->inviate = [];

        return $id;
    }

    public function testInvitoManualeAlTutor(): void
    {
        $id = $this->firmata();
        $this->assertNull($this->reg()->aggiungi($id, '2026-09-01', 4, 'Esercitazione'));
        $this->assertNull($this->reg()->invitaARegistro($id, 'Operatore Prova'));
        $this->assertCount(1, $this->mailer->inviate);
        $m = $this->mailer->inviate[0];
        $this->assertSame('luca@x.it', strtolower($m['a']));
        $this->assertSame('Tutorato: compila il registro delle attività', $m['oggetto']);
        $this->assertStringContainsString('Ore segnate: 4 su 10', $m['corpo']);
        $this->assertStringContainsString('registro_tutorato.php?id=' . $id, $m['corpo']);
        $this->assertStringContainsString('Apri il registro', $m['corpo']);
        $eventi = implode(' | ', array_column($this->servizio(ServizioIncarichi::class)->eventi($id), 'testo'));
        $this->assertStringContainsString('Invito a compilare il registro inviato a', $eventi);
    }

    public function testLInvitoSiPuoMandareSoloAdIncaricoAperto(): void
    {
        $this->bando();
        $s = $this->servizio(ServizioIncarichi::class);
        [$bozza] = $s->salva($this->campi());
        $this->assertStringContainsString('dopo la firma', (string) $this->reg()->invitaARegistro($bozza));
        $this->assertSame('Lettera non trovata.', $this->reg()->invitaARegistro(999));
        $id = $this->firmata(['cognome' => 'Neri', 'codice_fiscale' => 'nrimra00a01d086x', 'email' => 'n@x.it']);
        $this->db->esegui("UPDATE tutorato_incarichi SET fine_stato = 'da_firmare' WHERE id = ?", [$id]);
        $this->assertStringContainsString('dopo la firma', (string) $this->reg()->invitaARegistro($id), 'a fine attività dichiarata non serve');
        $this->assertSame([], $this->mailer->inviate);
    }

    public function testInvitoInBloccoSoloAChiHaIlRegistroIndietro(): void
    {
        $indietro = $this->firmata();
        $this->assertNull($this->reg()->aggiungi($indietro, '2026-09-01', 3, 'Lab'));
        $pari = $this->firmata(['cognome' => 'Neri', 'codice_fiscale' => 'nrimra00a01d086x', 'email' => 'n@x.it']);
        $this->assertNull($this->reg()->aggiungi($pari, '2026-09-01', 10, 'Tutto'));
        $zero = $this->firmata(['cognome' => 'Gialli', 'codice_fiscale' => 'glllgu00a01d086x', 'email' => 'g@x.it']);
        $this->servizio(ServizioIncarichi::class)->salva($this->campi(['cognome' => 'Verdi', 'codice_fiscale' => 'vrdmra00a01d086x', 'email' => 'v@x.it']));

        $this->assertEqualsCanonicalizing([$indietro, $zero], $this->reg()->idRegistroIndietro(1));
        $this->assertSame(2, $this->reg()->invitaARegistroIlBando(1, 'Op'));
        $this->assertEqualsCanonicalizing(['luca@x.it', 'g@x.it'], array_map(static fn (array $m): string => strtolower($m['a']), $this->mailer->inviate));
    }

    public function testFineAttivitaAlDocente(): void
    {
        $id = $this->firmata();
        $r = $this->reg();
        $this->assertNull($r->aggiungi($id, '2026-09-01', 6, 'Lab'));
        $this->assertStringContainsString('ore da approvare', (string) $r->inviaFineAlDocente($id), 'finché ci sono ore da approvare non parte');
        $this->assertSame([], $this->mailer->inviate);
        $this->assertSame(1, $r->decidi($id, 'approvata'));

        $this->assertNull($r->inviaFineAlDocente($id, 'Operatore Prova'));
        $l = $this->lettera($id);
        $this->assertSame('da_firmare', $l['fine_stato']);
        $this->assertNotEmpty($l['token_fine']);
        $this->assertCount(1, $this->mailer->inviate);
        $m = $this->mailer->inviate[0];
        $this->assertSame(strtolower($l['docente_email']), strtolower($m['a']));
        $this->assertStringContainsString('dichiarazione di fine attività', $m['oggetto']);
        $this->assertStringContainsString('<strong>6 ore</strong> su 10', $m['corpo']);
        $this->assertStringContainsString('firma_incarico.php?t=' . $l['token_fine'], $m['corpo']);
        $this->assertStringContainsString('Apri e firma la dichiarazione', $m['corpo']);

        // già pronta: si rimanda la stessa email con lo stesso link
        $this->assertNull($r->inviaFineAlDocente($id));
        $this->assertCount(2, $this->mailer->inviate);
        $this->assertSame($l['token_fine'], $this->lettera($id)['token_fine']);
        $eventi = implode(' | ', array_column($this->servizio(ServizioIncarichi::class)->eventi($id), 'testo'));
        $this->assertStringContainsString('Dichiarazione di fine attività inviata al docente', $eventi);
        $this->assertStringContainsString('Email di nuovo al docente', $eventi);
    }

    public function testIlTestoDeiNuoviModelliSiModificaDalPannello(): void
    {
        $this->servizio(ModelliEmail::class)->salva('tutorato_invito_registro', 'Registro, {{tutor}}!', 'Compila **ora**.', 'Vai', 'Admin');
        $id = $this->firmata();
        $this->assertNull($this->reg()->invitaARegistro($id));
        $this->assertSame('Registro, Luca Rossi!', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('<p>Compila <strong>ora</strong>.</p>', $this->mailer->inviate[0]['corpo']);
    }
}
