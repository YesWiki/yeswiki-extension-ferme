<?php

namespace YesWiki\Test\Ferme\Commands;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use YesWiki\Ferme\Commands\ListCommand;
use YesWiki\Ferme\Commands\MigrateCommand;
use YesWiki\Ferme\Commands\StatsCommand;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** The ferme:* commands, run over a farm of one throwaway wiki. */
class FarmCommandsTest extends FarmTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm();
        $this->dir = $this->service(WikiCreator::class)->createFromEntry($this->entry('commanded'), 'bf_dossier-wiki');
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            $this->service(WikiStatsStore::class)->forget('commanded');
            if (is_dir($this->dir)) {
                $this->service(WikiRemover::class)->destroy('commanded', null);
            }
        }
        parent::tearDown();
    }

    public function testListShowsTheWikiOnDiskAndItsEntryState(): void
    {
        $tester = $this->tester(new ListCommand($this->scratchContainer()), 'ferme:list');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('commanded', $tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $json = json_decode($tester->getDisplay(), true);
        $this->assertSame('commanded', $json['wikis'][0]['folder'] ?? null);
        $this->assertTrue($json['wikis'][0]['tablesOk'] ?? false);
    }

    public function testADryRunOfTheStatisticsMeasuresNothing(): void
    {
        $tester = $this->tester(new StatsCommand($this->scratchContainer()), 'ferme:stats');

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--force' => true]));
        $this->assertStringContainsString('commanded', $tester->getDisplay());
    }

    public function testMigrateRunsInEveryWikiOrSaysWhatItWould(): void
    {
        $tester = $this->tester(new MigrateCommand($this->scratchContainer()), 'ferme:migrate');

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString('commanded', $tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $tester->execute(['--wiki' => 'commanded']));
    }

    private function tester(Command $command, string $name): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find($name));
    }
}
