<?php

namespace YesWiki\Test\Ferme\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use YesWiki\Ferme\Service\InstanceConsole;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** Another wiki's console runs on the command-line PHP of the farm's own version, also when the farm is served by php-fpm. */
class InstanceConsolePhpTest extends FarmTestCase
{
    private string|false $asyncBinary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asyncBinary = getenv('ASYNC_PHP_BINARY');
        putenv('ASYNC_PHP_BINARY');
    }

    protected function tearDown(): void
    {
        putenv($this->asyncBinary === false ? 'ASYNC_PHP_BINARY' : 'ASYNC_PHP_BINARY=' . $this->asyncBinary);
        parent::tearDown();
    }

    public static function sapis(): array
    {
        return ['cli' => ['cli'], 'php-fpm' => ['fpm-fcgi'], 'apache' => ['apache2handler']];
    }

    #[DataProvider('sapis')]
    public function testTheBinaryRunsTheCommandLinePhpOfTheSameVersion(string $sapi): void
    {
        $process = new Process([InstanceConsole::php($sapi), '-r', 'echo PHP_SAPI, " ", PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;']);
        $process->run();

        $this->assertSame('cli ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $process->getOutput(), $process->getErrorOutput());
    }

    public function testAConfiguredBinaryWins(): void
    {
        putenv('ASYNC_PHP_BINARY=/opt/php/bin/php');

        $this->assertSame('/opt/php/bin/php', InstanceConsole::php('fpm-fcgi'));
    }
}
