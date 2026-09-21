<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Exception\FolderBusyException;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(FolderLock::class, 'acquire')]
#[CoversMethod(FolderLock::class, 'release')]
#[CoversMethod(FolderLock::class, 'during')]
#[CoversMethod(FolderLock::class, 'heldFor')]
#[CoversMethod(FolderLock::class, 'isHeldHere')]
#[CoversMethod(FolderLock::class, 'prune')]
class FolderLockTest extends YesWikiTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-folder-lock-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testAFolderIsHeldByOneWorkerAtATime()
    {
        $first = $this->lock();
        $second = $this->lock();

        $this->assertTrue($first->acquire('/farm/monwiki', 'suppression'));
        $this->assertFalse($second->acquire('/farm/monwiki', 'mise à jour'));

        $first->release('/farm/monwiki');
        $this->assertTrue($second->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testAnotherFolderIsNotHeldAtAll()
    {
        $first = $this->lock();
        $second = $this->lock();

        $first->acquire('/farm/monwiki', 'suppression');

        $this->assertTrue($second->acquire('/farm/unautre', 'suppression'));
    }

    public function testTheSameWorkerCanNestAndOnlyTheOutermostReleaseLetsGo()
    {
        $held = $this->lock();
        $other = $this->lock();

        $held->acquire('/farm/monwiki', 'suppression');
        $held->acquire('/farm/monwiki', 'suppression');
        $held->release('/farm/monwiki');

        $this->assertTrue($held->isHeldHere('/farm/monwiki'));
        $this->assertFalse($other->acquire('/farm/monwiki', 'mise à jour'));

        $held->release('/farm/monwiki');
        $this->assertTrue($other->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testTheWorkRunsWithTheFolderHeldAndLetsGoAfterwards()
    {
        $lock = $this->lock();
        $other = $this->lock();

        $seen = $lock->during('/farm/monwiki', 'suppression', function () use ($lock, $other) {
            $this->assertFalse($other->acquire('/farm/monwiki', 'mise à jour'), 'nobody else gets in meanwhile');

            return $lock->isHeldHere('/farm/monwiki');
        });

        $this->assertTrue($seen);
        $this->assertTrue($other->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testTheFolderIsLetGoEvenWhenTheWorkBlowsUp()
    {
        $lock = $this->lock();
        $other = $this->lock();

        try {
            $lock->during('/farm/monwiki', 'suppression', function () {
                throw new \RuntimeException('la base est tombée');
            });
            $this->fail('the exception should have come back');
        } catch (\RuntimeException $exception) {
            $this->assertSame('la base est tombée', $exception->getMessage());
        }

        $this->assertTrue($other->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testABusyFolderIsRefusedWithWhatTheOtherWorkerIsDoing()
    {
        $held = $this->lock();
        $held->acquire('/farm/monwiki', 'suppression du wiki');
        $waiting = $this->lock();

        $this->expectException(FolderBusyException::class);

        try {
            $waiting->during('/farm/monwiki', 'mise à jour du wiki', function () {
                $this->fail('the work must not run');
            });
        } catch (FolderBusyException $busy) {
            $this->assertStringContainsString('suppression du wiki', $busy->getHeldFor());
            $this->assertStringContainsString('monwiki', $busy->getMessage());

            throw $busy;
        }
    }

    public function testTheSameFolderNamedWithOrWithoutItsTrailingSlashIsOneFolder()
    {
        $first = $this->lock();
        $second = $this->lock();

        $first->acquire('/farm/monwiki/', 'suppression');

        $this->assertFalse($second->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testAFolderNameThatIsNotAFileNameStillGetsItsOwnLock()
    {
        $first = $this->lock();
        $second = $this->lock();

        $this->assertTrue($first->acquire('/farm/sous/dossier', 'suppression'));
        $this->assertTrue($second->acquire('/farm/sous-dossier', 'suppression'));

        $third = $this->lock();
        $this->assertFalse($third->acquire('/farm/sous/dossier', 'mise à jour'));
    }

    public function testTheLockFileGoesWhenTheWorkIsDone()
    {
        $lock = $this->lock();

        $lock->acquire('/farm/monwiki', 'suppression');
        $this->assertCount(1, glob($this->tmp . '/locks/*.lock'));

        $lock->release('/farm/monwiki');
        $this->assertSame([], glob($this->tmp . '/locks/*.lock'), 'a finished job leaves nothing behind');
    }

    public function testAForgottenLockFileIsSweptOnceItIsOldAndNobodyHoldsIt()
    {
        $killed = $this->lock();
        $killed->acquire('/farm/monwiki', 'suppression interrompue');
        $file = glob($this->tmp . '/locks/*.lock')[0];
        unset($killed);
        touch($file, time() - 172800);

        $this->assertSame(1, $this->lock()->prune());
        $this->assertFileDoesNotExist($file);
    }

    public function testASweepLeavesTheRecentAndTheHeldAlone()
    {
        $working = $this->lock();
        $working->acquire('/farm/enCours', 'mise à jour');
        $old = $this->lock();
        $old->acquire('/farm/vieux', 'suppression');
        touch(glob($this->tmp . '/locks/*enCours*')[0], time() - 172800);
        touch(glob($this->tmp . '/locks/*vieux*')[0], time() - 60);

        $this->assertSame(0, $this->lock()->prune(), 'neither the held nor the recent is swept');
        $this->assertCount(2, glob($this->tmp . '/locks/*.lock'));
    }

    public function testHoldingAFileThatIsNoLongerTheOneAtThatPathIsHoldingNothing()
    {
        $stale = new class() extends FolderLock {
            protected function open(string $key)
            {
                $path = sys_get_temp_dir() . '/ferme-stale-' . bin2hex(random_bytes(6)) . '.lock';
                $handle = fopen($path, 'c+');
                unlink($path);

                return $handle;
            }
        };
        $stale->useDirectory($this->tmp . '/locks');

        $this->assertFalse($stale->acquire('/farm/monwiki', 'suppression'), 'it refuses rather than claim a folder by a name it no longer answers to');

        $sound = $this->lock();
        $this->assertTrue($sound->acquire('/farm/monwiki', 'mise à jour'));
    }

    private function lock(): FolderLock
    {
        $lock = new FolderLock();
        $lock->useDirectory($this->tmp . '/locks');

        return $lock;
    }
}
