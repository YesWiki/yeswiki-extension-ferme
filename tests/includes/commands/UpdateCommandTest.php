<?php

namespace YesWiki\Test\Ferme\Commands;

use PHPUnit\Framework\Attributes\CoversMethod;
use Symfony\Component\Console\Input\ArrayInput;
use YesWiki\Ferme\Commands\UpdateCommand;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(UpdateCommand::class, 'workerCommand')]
class UpdateCommandTest extends YesWikiTestCase
{
    public function testAWorkerKeepsTheVersionItMigratesFrom()
    {
        $this->assertContains('--migratedev', $this->workerCommand(['--migratedev' => true]));
        $this->assertContains('--migratecerco', $this->workerCommand(['--migratecerco' => true]));
    }

    public function testAWorkerOfANormalUpdateMigratesFromNothing()
    {
        $command = $this->workerCommand([]);

        $this->assertNotContains('--migratedev', $command);
        $this->assertNotContains('--migratecerco', $command);
    }

    private function workerCommand(array $options): array
    {
        $wiki = self::getWiki();
        $update = new UpdateCommand($wiki);
        $input = new ArrayInput($options, $update->getDefinition());
        $method = new \ReflectionMethod(UpdateCommand::class, 'workerCommand');

        return $method->invoke($update, $input, ['PATH' => '/tmp/wiki'], '/tmp/source');
    }
}
