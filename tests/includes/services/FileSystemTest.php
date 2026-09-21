<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(FileSystem::class, 'copyRecursive')]
#[CoversMethod(FileSystem::class, 'copiedFiles')]
#[CoversMethod(FileSystem::class, 'failedFiles')]
#[CoversMethod(FileSystem::class, 'failures')]
#[CoversMethod(FileSystem::class, 'failureSummary')]
class FileSystemTest extends YesWikiTestCase
{
    private string $tmp;
    private FileSystem $files;

    protected function setUp(): void
    {
        self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-filesystem-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/source/tools/bazar', 0777, true);
        file_put_contents($this->tmp . '/source/index.php', '<?php');
        file_put_contents($this->tmp . '/source/tools/bazar/bazar.php', '<?php');
        $this->files = new FileSystem();
    }

    protected function tearDown(): void
    {
        @chmod($this->tmp . '/locked', 0777);
        @chmod($this->tmp . '/source/tools', 0777);
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testAWholeTreeArrivesAndIsCounted()
    {
        $this->assertTrue($this->files->copyRecursive($this->tmp . '/source', $this->tmp . '/wiki'));

        $this->assertFileExists($this->tmp . '/wiki/index.php');
        $this->assertFileExists($this->tmp . '/wiki/tools/bazar/bazar.php');
        $this->assertSame(2, $this->files->copiedFiles());
        $this->assertSame(0, $this->files->failedFiles());
        $this->assertSame('', $this->files->failureSummary());
    }

    public function testASourceThatIsNotThereIsAFailureAndNotAnEmptyCopy()
    {
        $this->assertFalse($this->files->copyRecursive($this->tmp . '/source/gone', $this->tmp . '/wiki/gone'));

        $this->assertDirectoryDoesNotExist($this->tmp . '/wiki/gone');
        $this->assertSame(1, $this->files->failedFiles());
        $this->assertStringContainsString('source/gone', $this->files->failureSummary());
    }

    public function testACopyThatLosesFilesSaysSoInsteadOfPassingForAGoodOne()
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root reads a folder it has no permission on');
        }

        chmod($this->tmp . '/source/tools', 0000);

        $this->assertFalse($this->files->copyRecursive($this->tmp . '/source', $this->tmp . '/wiki'));

        $this->assertFileExists($this->tmp . '/wiki/index.php', 'what could be read still arrives');
        $this->assertSame(1, $this->files->copiedFiles());
        $this->assertSame(1, $this->files->failedFiles());
        $this->assertStringContainsString('source/tools', $this->files->failureSummary());
    }

    public function testADestinationThatCannotBeCreatedIsAFailure()
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root writes into a folder it has no permission on');
        }

        mkdir($this->tmp . '/locked', 0500, true);

        $this->assertFalse($this->files->copyRecursive($this->tmp . '/source', $this->tmp . '/locked/wiki'));

        $this->assertDirectoryDoesNotExist($this->tmp . '/locked/wiki');
        $this->assertSame(0, $this->files->copiedFiles());
        $this->assertStringContainsString('locked/wiki', $this->files->failureSummary());
    }

    public function testAnEmptySourceFolderIsCopiedAsAnEmptyFolderAndNothingElse()
    {
        mkdir($this->tmp . '/source/files', 0777, true);

        $this->assertTrue($this->files->copyRecursive($this->tmp . '/source/files', $this->tmp . '/wiki/files'));

        $this->assertDirectoryExists($this->tmp . '/wiki/files');
        $this->assertSame(0, $this->files->copiedFiles());
        $this->assertSame(0, $this->files->failedFiles());
    }

    public function testEachCopyReportsOnItselfAndNotOnTheOneBefore()
    {
        $this->files->copyRecursive($this->tmp . '/source/gone', $this->tmp . '/wiki/gone');

        $this->assertTrue($this->files->copyRecursive($this->tmp . '/source', $this->tmp . '/wiki'));
        $this->assertSame(0, $this->files->failedFiles());
        $this->assertSame([], $this->files->failures());
    }

    public function testTheListOfLostFilesIsCappedButTheCountIsNot()
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root writes into a folder it has no permission on');
        }

        for ($i = 0; $i < FileSystem::FAILURES_KEPT + 3; $i++) {
            file_put_contents($this->tmp . '/source/file-' . $i . '.txt', 'x');
        }
        mkdir($this->tmp . '/wiki', 0500, true);

        $this->assertFalse($this->files->copyRecursive($this->tmp . '/source', $this->tmp . '/wiki'));

        $this->assertSame(FileSystem::FAILURES_KEPT, count($this->files->failures()));
        $this->assertGreaterThan(FileSystem::FAILURES_KEPT, $this->files->failedFiles());
        $this->assertStringEndsWith('…', $this->files->failureSummary());
    }
}
