<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use YesWiki\Ferme\Service\PhpCli;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(PhpCli::class, 'binary')]
class PhpCliTest extends YesWikiTestCase
{
    private string|false $asyncBinary;

    protected function setUp(): void
    {
        self::getWiki();
        $this->asyncBinary = getenv('ASYNC_PHP_BINARY');
        putenv('ASYNC_PHP_BINARY');
    }

    protected function tearDown(): void
    {
        putenv($this->asyncBinary === false ? 'ASYNC_PHP_BINARY' : 'ASYNC_PHP_BINARY=' . $this->asyncBinary);
    }

    public static function sapis(): array
    {
        return ['cli' => ['cli'], 'php-fpm' => ['fpm-fcgi'], 'apache' => ['apache2handler']];
    }

    #[DataProvider('sapis')]
    public function testTheBinaryRunsTheCommandLinePhpOfTheSameVersion(string $sapi)
    {
        $process = new Process([PhpCli::binary($sapi), '-r', 'echo PHP_SAPI, " ", PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;']);
        $process->run();

        $this->assertSame('cli ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $process->getOutput(), $process->getErrorOutput());
    }

    public function testAConfiguredBinaryWins()
    {
        putenv('ASYNC_PHP_BINARY=/opt/php/bin/php');

        $this->assertSame('/opt/php/bin/php', PhpCli::binary('fpm-fcgi'));
    }
}
