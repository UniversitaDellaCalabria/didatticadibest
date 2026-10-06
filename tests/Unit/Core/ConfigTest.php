<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testLeggeIlFileEnvComeConfigPhpScartandoLeRigheConCancelletto(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# commento (con parentesi) che romperebbe parse_ini\nDB_HOST=localhost\nGIORNI=12\nSOLO_SPID=1\n");
        $config = Config::daFile($file);
        unlink($file);

        self::assertSame('localhost', $config->testo('DB_HOST'));
        self::assertSame(12, $config->intero('GIORNI'));
        self::assertTrue($config->booleano('SOLO_SPID'));
        self::assertFalse($config->booleano('ASSENTE'));
        self::assertSame('x', $config->testo('ASSENTE', 'x'));
    }

    public function testFileMancanteDaConfigurazioneVuota(): void
    {
        $config = Config::daFile('/percorso/che/non/esiste/.env');

        self::assertFalse($config->ha('DB_HOST'));
        self::assertSame(5, $config->intero('DB_PORT', 5));
    }
}
