<?php

declare(strict_types=1);

namespace Tests\Integration\Tutorato;

use App\Tutorato\ArchivioIncarichi;
use App\Tutorato\Costanti;
use App\Tutorato\IncaricoRepository;
use App\Tutorato\ServizioDichiarazione;
use App\Tutorato\ServizioIncarichi;
use App\Tutorato\ServizioPagamenti;

/** «Iter per Uniticket»: presa di servizio verificata prima dell'invio e modello SCAFIS con i dati SPID; poi lo stesso percorso dell'iter nel portale. */
final class IterUniticketTest extends TutoratoBase
{
    private const UTENTE = ['id' => 7, 'codice_fiscale' => 'RSSLCU00A31D086X', 'email' => 'luca@x.it'];
    private const SPID = ['metodo' => 'spid', 'livello' => 2, 'idp' => 'https://idp.prova.it', 'spid_code' => 'SPID-PROVA-123'];

    private function s(): ServizioIncarichi
    {
        return $this->servizio(ServizioIncarichi::class);
    }

    private function bandoUniticket(): void
    {
        $this->bando();
        $this->servizio(\App\Tutorato\BandoRepository::class)->impostaIter(1, Costanti::ITER_UNITICKET);
    }

    private function abilitaCifratura(): void
    {
        file_put_contents($this->envFile, "INCARICHI_SOLO_SPID_CIE=1\nCIFRATURA_CHIAVE=una-frase-segreta-di-prova-2026\n");
    }

    private function compilaDichiarazione(int $id): void
    {
        $d = $this->servizio(ServizioDichiarazione::class);
        $p2 = ['telefono' => '347 1234567', 'email' => 'luca@x.it', 'residenza_invariata' => '1', 'non_dipendente' => '1', 'irpef' => '23', 'iban' => 'IT60X0542811101000000123456', 'banca' => 'Banca di prova'];
        $p3 = ['inps' => 'gs35', 'imponibile' => '', 'inail' => ['didattica']];
        $this->assertSame([[], []], [$d->salvaPasso($id, 2, $p2, $this->lettera($id))[0], $d->salvaPasso($id, 3, $p3, $this->lettera($id))[0]]);
    }

    public function testIlBandoDecideL_IterEUnaLetteraNuovaLoEredita(): void
    {
        $this->bando();
        $this->assertSame('portale', $this->db->valore('SELECT iter FROM tutorato_bandi WHERE id = 1'), 'di base tutto nel portale');
        [$a] = $this->s()->salva($this->campi());
        $this->assertSame('portale', $this->lettera($a)['iter']);

        [$bid, $err] = $this->s()->salvaBando(['id' => 1, 'titolo' => 'Tutorato I semestre', 'decreto_bando' => '123/2026', 'direttore_nome' => 'Mario Bianchi', 'direttore_email' => 'direttore@x.it', 'iter' => 'uniticket'], 1);
        $this->assertNull($err);
        $this->assertSame('uniticket', $this->db->valore('SELECT iter FROM tutorato_bandi WHERE id = ?', [$bid]));
        [$b] = $this->s()->salva($this->campi(['cognome' => 'Verdi', 'codice_fiscale' => 'vrdmra00a01d086x', 'email' => 'mv@x.it']));
        $this->assertSame('uniticket', $this->lettera($b)['iter'], 'la lettera nuova eredita l\'iter del bando');
        $this->assertSame('portale', $this->lettera($a)['iter'], 'quelle già fatte non cambiano');
        $this->assertSame('portale', Costanti::iterValido('inventato'));

        // si cambia finché è in bozza, poi no
        $this->assertNull($this->s()->cambiaIter($b, 'portale'));
        $this->assertSame('portale', $this->lettera($b)['iter']);
        $this->assertNull($this->s()->cambiaIter($b, 'uniticket'));
        $this->s()->presaServizio($b);
        $this->assertNull($this->s()->invia($b));
        $this->assertStringContainsString('solo finché', (string) $this->s()->cambiaIter($b, 'portale'));
    }

    public function testNonSiInviaLaLetteraSenzaLaPresaDiServizioSuUniticket(): void
    {
        $this->bandoUniticket();
        [$id] = $this->s()->salva($this->campi());
        $this->assertSame('uniticket', $this->lettera($id)['iter']);

        $this->assertStringContainsString('preso servizio', (string) $this->s()->invia($id));
        $this->assertSame('bozza', $this->lettera($id)['stato']);
        $this->assertSame([], $this->mailer->inviate);

        $this->assertNull($this->s()->presaServizio($id, 'Operatore Prova'));
        $r = $this->lettera($id);
        $this->assertSame('Operatore Prova', $r['presa_servizio_da']);
        $this->assertNotNull($r['presa_servizio_il']);

        $this->assertNull($this->s()->invia($id));
        $this->assertCount(1, $this->mailer->inviate);
        $this->assertSame('luca@x.it', strtolower($this->mailer->inviate[0]['a']));
        $this->assertStringContainsString('compila e accetta', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('presa di servizio su Uniticket', $this->mailer->inviate[0]['corpo']);
    }

    public function testNellIterNelPortaleNonServeLaPresaDiServizio(): void
    {
        $this->bando();
        [$id] = $this->s()->salva($this->campi());
        $this->assertStringContainsString('solo nell', (string) $this->s()->presaServizio($id));
        $this->assertNull($this->s()->invia($id));
        $this->assertStringContainsString('controlla e conferma', $this->mailer->inviate[0]['oggetto']);
    }

    public function testLaConfermaProduceIlModelloScafisConIDatiSpid(): void
    {
        $this->abilitaCifratura();
        $this->bandoUniticket();
        [$id] = $this->s()->salva($this->campi());
        $this->s()->presaServizio($id);
        $this->s()->invia($id);
        $this->compilaDichiarazione($id);
        $this->assertNull($this->s()->conferma($id, self::UTENTE, self::SPID));

        $r = $this->lettera($id);
        $this->assertNotEmpty($r['scafis_pdf']);
        $file = $this->servizio(ArchivioIncarichi::class)->scafisCorrente($r);
        $this->assertNotNull($file);
        $pdf = (string) file_get_contents($file);
        $this->assertStringStartsWith('%PDF', $pdf);
        $testo = (new \App\Infrastructure\Pdf\TestoPdf())->estrai($pdf);
        $this->assertStringContainsString('SPID-PROVA-123', $testo, 'i metadati SPID sono nel documento');
        $this->assertStringContainsString('identità digitale', $testo);

        // chi lo può aprire: il referente e il tutor (ogni apertura si registra); l'ufficio pagamenti no
        $pag = $this->servizio(ServizioPagamenti::class);
        $docs = array_column($pag->documenti($r), null, 'chiave');
        $this->assertTrue($docs['scafis']['disponibile']);
        $this->assertNotNull($pag->documento($r, 'scafis', ['id' => 1, 'email' => 'admin@x.it']));
        $this->assertGreaterThan(0, (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_accessi_dati WHERE incarico_id = ? AND azione = 'scafis_file'", [$id]));
        $this->assertSame('referente', $pag->ruolo(null, $r, true));

        // la dichiarazione normale (senza SPID, per il tutor e l'ufficio pagamenti) resta com'è
        $this->assertNotNull($this->servizio(ServizioDichiarazione::class)->pdf($r, self::UTENTE));
    }

    public function testSenzaDichiarazioneRestaSoloLaLettera(): void
    {
        $this->bandoUniticket();
        [$id] = $this->s()->salva($this->campi());
        $this->s()->presaServizio($id);
        $this->s()->invia($id);
        $this->assertNull($this->s()->conferma($id, self::UTENTE, self::SPID)); // cifratura non configurata: la dichiarazione non si richiede
        $r = $this->lettera($id);
        $this->assertEmpty($r['scafis_pdf']);
        $this->assertFalse(array_column($this->servizio(ServizioPagamenti::class)->documenti($r), 'disponibile', 'chiave')['scafis']);
        $this->assertNotNull($this->servizio(ArchivioIncarichi::class)->pdfCorrente($r), 'la lettera c\'è');
        $eventi = implode(' | ', array_column($this->s()->eventi($id), 'testo'));
        $this->assertStringContainsString('nessun file SCAFIS', $eventi);
    }

    public function testNelPortaleNonCeLoScafis(): void
    {
        $this->abilitaCifratura();
        $this->bando();
        [$id] = $this->s()->salva($this->campi());
        $this->s()->invia($id);
        $this->compilaDichiarazione($id);
        $this->assertNull($this->s()->conferma($id, self::UTENTE, self::SPID));
        $r = $this->lettera($id);
        $this->assertEmpty($r['scafis_pdf']);
        $this->assertNull($this->servizio(ServizioPagamenti::class)->documento($r, 'scafis', ['id' => 1]));
    }

    public function testDopoLaFirmaIlPercorsoEQuelloDelPortale(): void
    {
        $this->bando();
        $this->bandoUniticket();
        [$u] = $this->s()->salva($this->campi());
        [$p] = $this->s()->salva($this->campi(['cognome' => 'Neri', 'codice_fiscale' => 'nrimra00a01d086x', 'email' => 'n@x.it']));
        $this->servizio(IncaricoRepository::class)->impostaIter($p, 'portale');
        $this->db->esegui("UPDATE tutorato_incarichi SET stato = 'protocollata', fine_stato = 'firmata', ore_approvate = 10 WHERE id IN (?, ?)", [$u, $p]);

        $ids = array_map(static fn (array $x): int => (int) $x['id'], $this->servizio(IncaricoRepository::class)->daValidare());
        sort($ids);
        $this->assertSame([min($u, $p), max($u, $p)], $ids, 'anche quella per Uniticket è «da validare»: dopo la firma il percorso è lo stesso');
        $pag = $this->servizio(ServizioPagamenti::class);
        $this->assertStringNotContainsString('Uniticket', implode(' ', $pag->controlli($this->lettera($u))), 'l\'iter per Uniticket non blocca i pagamenti');
    }
}
