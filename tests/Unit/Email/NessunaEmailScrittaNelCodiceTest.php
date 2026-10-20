<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Email\CatalogoEmail;
use PHPUnit\Framework\TestCase;

/**
 * Tutte le email del portale hanno il testo nel catalogo (modificabile dalla pagina «Testi delle email»): nessuna classe di src/ parla direttamente
 * con il Mailer, salvo i casi in cui il testo lo scrive chi manda l'email (convocazioni, email massive) o che non sono email del portale.
 */
final class NessunaEmailScrittaNelCodiceTest extends TestCase
{
    /** File di src/ ammessi a usare il Mailer. */
    private const AMMESSI = [
        'Infrastructure/Mail/',              // il Mailer stesso
        'Email/InvioEmail.php',              // l'unico ponte tra i modelli e il Mailer
        'Core/App.php',                      // collegamento delle classi
        'Tutorato/NotificheIncarichi.php',   // ponte del tutorato (modelli del catalogo + frase «Il link è personale»)
        'Didattica/ServizioConvocazioni.php', // la convocazione la scrive per ogni seduta chi la manda
        'Iscritti/ServizioEmailMassiva.php', // il messaggio lo scrive chi lo manda
        'Sistema/LogEmailRepository.php',    // registro degli invii
    ];

    public function testSoloIPontiAmmessiUsanoIlMailer(): void
    {
        $radice = dirname(__DIR__, 3) . '/src';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($radice, \FilesystemIterator::SKIP_DOTS));
        $fuori = [];
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($radice) + 1));
            $testo = (string) file_get_contents($f->getPathname());
            if (!preg_match('/\bMailer\b/', $testo)) {
                continue;
            }
            foreach (self::AMMESSI as $ok) {
                if (str_starts_with($rel, $ok)) {
                    continue 2;
                }
            }
            $fuori[] = $rel;
        }
        $this->assertSame([], $fuori, 'Queste classi parlano direttamente con il Mailer: usino un modello del catalogo (InvioEmail)');
    }

    public function testOgniModelloHaTuttoQuelloCheServeAllaPagina(): void
    {
        $chiavi = [];
        foreach (CatalogoEmail::modelli() as $k => $m) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $k);
            $this->assertNotSame('', trim((string) $m['gruppo']), $k);
            foreach (['nome', 'quando', 'destinatario', 'oggetto'] as $campo) {
                $this->assertNotSame('', trim((string) $m[$campo]), "$k: manca $campo");
            }
            $this->assertIsArray($m['segnaposto'], $k);
            foreach ($m['segnaposto'] as $nome => $info) {
                $this->assertArrayHasKey('d', $info, "$k: $nome senza descrizione");
                $this->assertArrayHasKey('esempio', $info, "$k: $nome senza esempio");
            }
            $chiavi[] = $k;
        }
        $this->assertSame(count($chiavi), count(array_unique($chiavi)));
        $this->assertGreaterThan(100, count($chiavi), 'il catalogo copre tutte le email del portale');
    }
}
