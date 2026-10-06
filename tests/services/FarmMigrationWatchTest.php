<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\FarmMigrationWatch;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Ferme\Service\InstanceConsole;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Ferme\Service\WikiFinder;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Ferme\Service\WikiMigrator;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Kernel\Service\ProgramVersion;
use YesWiki\Kernel\Service\TripleStore;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** A new Program owes every wiki its migrations, once per version, and each wiki runs its own. */
class FarmMigrationWatchTest extends FarmTestCase
{
    private ?string $seen = null;

    protected function setUp(): void
    {
        parent::setUp();
        putenv(InstanceConsole::MIGRATING);
    }

    protected function tearDown(): void
    {
        putenv(InstanceConsole::MIGRATING);
        if ($this->scratch !== '') {
            $this->service(WikiStatsStore::class)->forget('migrated');
        }
        parent::tearDown();
    }

    public function testTheVersionIsTheProgramsOwn(): void
    {
        $program = new ProgramVersion();

        $this->assertSame(trim($program->releaseLine() . ' ' . $program->version()), $this->watch()->release());
    }

    public function testTheFirstLookOnlyWritesDownTheVersionItFound(): void
    {
        $watch = $this->watch();

        $this->assertFalse($watch->isDue());
        $this->assertSame($watch->release(), $this->seen);
        $this->assertFalse($this->watch()->isDue());
    }

    public function testAProgramThatChangedVersionIsDueOnceAndOnlyOnce(): void
    {
        $this->seen = 'ectoplasme 0.0.1';

        $this->assertTrue($this->watch()->isDue());
        $this->assertSame($this->watch()->release(), $this->seen);
        $this->assertFalse($this->watch()->isDue());
    }

    public function testTheFarmMigratingItsOwnWikisIsNeverDue(): void
    {
        $this->seen = 'ectoplasme 0.0.1';
        putenv(InstanceConsole::MIGRATING . '=1');

        $this->assertFalse($this->watch()->isDue());
    }

    public function testAFarmThatTurnedItOffOrHasNoWikiIsNeverDue(): void
    {
        $this->seen = 'ectoplasme 0.0.1';

        $this->assertFalse($this->watch(['yeswiki-farm-migrate-on-update' => 'false'])->isDue());
        $this->assertFalse($this->watch([], false)->isDue());
        $this->assertSame('ectoplasme 0.0.1', $this->seen);
    }

    public function testEachWikiRunsItsOwnMigrateEvenAsleep(): void
    {
        $this->farm();
        $dir = $this->service(WikiCreator::class)->createFromEntry($this->entry('migrated'), 'bf_dossier-wiki');
        $this->service(WikiHibernator::class)->hibernate('migrated');
        $migrator = new WikiMigrator($this->service(\YesWiki\Ferme\Service\FarmConfig::class), $this->service(InstanceConsole::class), $this->service(FolderLock::class), $this->service(WikiHibernator::class), $this->service(StatsRefresher::class));

        $this->assertSame('planned', $migrator->migrate('migrated', true)['status']);
        $result = $migrator->migrate('migrated');

        $this->assertSame('migrated', $result['status']);
        $this->assertSame('hibernate', $this->service(WikiHibernator::class)->statusOf('migrated'), 'it goes back to sleep');
        $this->service(\YesWiki\Ferme\Service\WikiRemover::class)->destroy('migrated', null);
        $this->assertDirectoryDoesNotExist($dir);
    }

    /** @param array<string, mixed> $settings */
    private function watch(array $settings = [], bool $withWikis = true): FarmMigrationWatch
    {
        $triples = $this->createStub(TripleStore::class);
        $triples->method('getOne')->willReturnCallback(fn (): ?string => $this->seen);
        $triples->method('create')->willReturnCallback(function ($resource, $property, $value): int {
            $this->seen = (string)$value;

            return 0;
        });
        $triples->method('update')->willReturnCallback(function ($resource, $property, $old, $new): int {
            $this->seen = (string)$new;

            return 0;
        });
        $finder = $this->createStub(WikiFinder::class);
        $finder->method('find')->willReturn($withWikis ? [['PATH' => '/farm/a', 'FOLDER' => 'a', 'URL' => 'KO', 'VERSION' => 'KO', 'RELEASE' => 'KO']] : []);

        return new FarmMigrationWatch($this->farm($settings), $triples, $this->scratchLocks(), $finder);
    }
}
