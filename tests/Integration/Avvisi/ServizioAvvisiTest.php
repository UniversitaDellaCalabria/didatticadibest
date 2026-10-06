<?php

declare(strict_types=1);

namespace Tests\Integration\Avvisi;

use App\Avvisi\IscrizioneRepository;
use App\Avvisi\ServizioAvvisi;
use App\Core\Sito;
use App\Eventi\FonteEventiAgenda;
use Tests\Doppi\MailerFinto;
use Tests\Integration\DatabaseDiProva;

final class ServizioAvvisiTest extends DatabaseDiProva
{
    private MailerFinto $mailer;
    /** @var list<array<string, mixed>> */
    private array $agenda = [];
    private ServizioAvvisi $avvisi;

    protected function tabelle(): array
    {
        return [
            'avvisi_iscrizioni' => "CREATE TABLE avvisi_iscrizioni (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150) NOT NULL, ambiti VARCHAR(200) DEFAULT '', scuole TINYINT(1) DEFAULT 0,
                token VARCHAR(40) NOT NULL, utente_id INT NULL, confermata_il DATETIME NULL, creata_il DATETIME DEFAULT CURRENT_TIMESTAMP, ultimo_invio_il DATETIME NULL, UNIQUE KEY (email))",
            'avvisi_eventi' => 'CREATE TABLE avvisi_eventi (evento_id INT PRIMARY KEY, destinatari INT DEFAULT 0)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new MailerFinto();
        $fonte = new class ($this) implements FonteEventiAgenda {
            public function __construct(private ServizioAvvisiTest $t)
            {
            }

            public function eventiInProgramma(): array
            {
                return $this->t->agenda();
            }
        };
        $this->avvisi = new ServizioAvvisi(new IscrizioneRepository($this->db), $this->mailer, $fonte, new Sito('/srv/sito', 'https://portale.test/didattica'));
    }

    /** @return list<array<string, mixed>> */
    public function agenda(): array
    {
        return $this->agenda;
    }

    private function evento(int $id, array $ambiti, bool $scuole = false): array
    {
        return ['id' => $id, 'titolo' => "Evento $id", 'url' => "evento.php?id=$id", 'ambiti' => $ambiti, 'per_scuole' => $scuole,
                'prossima_data' => '2026-11-03', 'prossimo_orario' => '10:00', 'luogo' => 'Aula Magna', 'relatore' => ''];
    }

    public function testIscrizioneConConfermaPerEmail(): void
    {
        self::assertFalse($this->avvisi->iscrivi('non-email', ['ricerca'], false)->riuscito);
        self::assertSame('Scegli almeno un argomento.', $this->avvisi->iscrivi('a@b.it', ['inventato'], false)->messaggio);

        $esito = $this->avvisi->iscrivi(' Lia@Example.org ', ['ricerca', 'boh'], false);
        $riga = $this->avvisi->rigaPerEmail('lia@example.org');

        self::assertTrue($esito->riuscito);
        self::assertSame('ricerca', $riga['ambiti']);
        self::assertNull($riga['confermata_il']);
        self::assertCount(1, $this->mailer->inviate);
        self::assertStringContainsString('/avvisi.php?conferma=' . $riga['token'], $this->mailer->inviate[0]['corpo']);
        self::assertTrue($this->avvisi->conferma($riga['token']));
        self::assertFalse($this->avvisi->conferma(str_repeat('f', 40)));
    }

    public function testStessaEmailDellUtenteConfermataSubito(): void
    {
        $this->avvisi->iscrivi('rita@unical.it', ['orientamento'], true, ['id' => 9, 'email' => 'Rita@Unical.it']);

        self::assertSame([], $this->mailer->inviate);
        self::assertNotNull($this->avvisi->rigaPerEmail('rita@unical.it')['confermata_il']);
        self::assertSame(1, $this->avvisi->contaIscritti()['orientamento']);
        self::assertSame(1, $this->avvisi->contaIscritti()['scuole']);
    }

    public function testRiepilogoDeiNuoviEventiUnaVoltaSola(): void
    {
        $this->avvisi->iscrivi('a@unical.it', ['ricerca'], false, ['id' => 1, 'email' => 'a@unical.it']);
        $this->avvisi->iscrivi('b@unical.it', ['didattica'], false, ['id' => 2, 'email' => 'b@unical.it']);
        $this->agenda = [$this->evento(1, ['ricerca'])];

        self::assertSame(0, $this->avvisi->inviaNovita(), 'primo avvio: niente invii');
        $this->agenda[] = $this->evento(2, ['ricerca', 'didattica']);
        $this->agenda[] = $this->evento(3, ['orientamento']);

        self::assertSame(2, $this->avvisi->inviaNovita());
        self::assertSame(['a@unical.it', 'b@unical.it'], array_column($this->mailer->inviate, 'a'));
        self::assertSame('Nuovo evento: Evento 2', $this->mailer->inviate[0]['oggetto']);
        self::assertStringContainsString('3 nov 2026, ore 10:00', $this->mailer->inviate[0]['corpo']);
        self::assertSame(0, $this->avvisi->inviaNovita(), 'già annunciati');
    }

    public function testCancellazioneDalLink(): void
    {
        $this->avvisi->iscrivi('c@unical.it', ['ricerca'], false);
        $tok = $this->avvisi->rigaPerEmail('c@unical.it')['token'];

        self::assertTrue($this->avvisi->cancella($tok));
        self::assertNull($this->avvisi->rigaPerToken($tok));
    }
}
