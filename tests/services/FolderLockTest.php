<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Exception\FolderBusyException;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Files\Service\RuntimeLock;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** One wiki folder, one worker at a time. */
class FolderLockTest extends FarmTestCase
{
    public function testAFolderIsHeldByOneWorkerAtATime(): void
    {
        $first = $this->lock();
        $second = $this->lock();

        $this->assertTrue($first->acquire('/farm/monwiki', 'suppression'));
        $this->assertFalse($second->acquire('/farm/monwiki', 'mise à jour'));

        $first->release('/farm/monwiki');
        $this->assertTrue($second->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testAnotherFolderIsNotHeldAtAll(): void
    {
        $first = $this->lock();
        $second = $this->lock();

        $first->acquire('/farm/monwiki');

        $this->assertTrue($second->acquire('/farm/unautre', 'suppression'));
    }

    public function testTheSameWorkerCanNestAndOnlyTheOutermostReleaseLetsGo(): void
    {
        $held = $this->lock();
        $other = $this->lock();

        $held->acquire('/farm/monwiki');
        $held->acquire('/farm/monwiki');
        $held->release('/farm/monwiki');

        $this->assertTrue($held->isHeldHere('/farm/monwiki'));
        $this->assertFalse($other->acquire('/farm/monwiki', 'mise à jour'));

        $held->release('/farm/monwiki');
        $this->assertTrue($other->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testTheWorkRunsWithTheFolderHeldAndLetsGoAfterwards(): void
    {
        $lock = $this->lock();
        $other = $this->lock();

        $seen = $lock->during('/farm/monwiki', 'suppression', function () use ($lock, $other): bool {
            $this->assertFalse($other->acquire('/farm/monwiki', 'mise à jour'));

            return $lock->isHeldHere('/farm/monwiki');
        });

        $this->assertTrue($seen);
        $this->assertTrue($other->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testTheFolderIsLetGoEvenWhenTheWorkBlowsUp(): void
    {
        $lock = $this->lock();
        $other = $this->lock();

        try {
            $lock->during('/farm/monwiki', 'suppression', function (): void {
                throw new \RuntimeException('la base est tombée');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('la base est tombée', $exception->getMessage());
        }

        $this->assertTrue($other->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testABusyFolderIsRefusedWithWhatTheOtherWorkerIsDoing(): void
    {
        $held = $this->lock();
        $held->acquire('/farm/monwiki', 'suppression du wiki');
        $waiting = $this->lock();

        $this->expectException(FolderBusyException::class);

        try {
            $waiting->during('/farm/monwiki', 'mise à jour du wiki', function (): void {
                $this->fail('the work must not run');
            });
        } catch (FolderBusyException $busy) {
            $this->assertStringContainsString('suppression du wiki', $busy->getHeldFor());
            $this->assertStringContainsString('monwiki', $busy->getMessage());

            throw $busy;
        }
    }

    public function testTheSameFolderNamedWithOrWithoutItsTrailingSlashIsOneFolder(): void
    {
        $first = $this->lock();
        $second = $this->lock();

        $first->acquire('/farm/monwiki/');

        $this->assertFalse($second->acquire('/farm/monwiki', 'mise à jour'));
    }

    public function testAFolderNameThatIsNotAFileNameStillGetsItsOwnLock(): void
    {
        $first = $this->lock();
        $second = $this->lock();

        $this->assertTrue($first->acquire('/farm/sous/dossier', 'suppression'));
        $this->assertTrue($second->acquire('/farm/sous-dossier', 'suppression'));

        $third = $this->lock();
        $this->assertFalse($third->acquire('/farm/sous/dossier', 'mise à jour'));
    }

    public function testAFinishedJobSaysNothingAnyMore(): void
    {
        $lock = $this->lock();

        $lock->acquire('/farm/monwiki', 'suppression');
        $this->assertStringContainsString('suppression', $lock->heldFor('/farm/monwiki'));

        $lock->release('/farm/monwiki');
        $this->assertSame('', $lock->heldFor('/farm/monwiki'));
    }

    private function lock(): FolderLock
    {
        $lock = new FolderLock(new RuntimeLock());
        $lock->useDirectory($this->scratch . '/locks');

        return $lock;
    }
}
