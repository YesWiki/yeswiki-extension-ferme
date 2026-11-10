<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\ExtensionVersions;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(ExtensionVersions::class, 'toUpgrade')]
#[CoversMethod(ExtensionVersions::class, 'unpublished')]
#[CoversMethod(ExtensionVersions::class, 'installedRelease')]
class ExtensionVersionsTest extends YesWikiTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-extension-versions-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/tools', 0777, true);
    }

    protected function tearDown(): void
    {
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testAnExtensionAtThePublishedReleaseIsLeftAlone()
    {
        $this->install('bazarliste', '1.4.0');

        $this->assertSame([], $this->versions()->toUpgrade($this->tmp, ['bazarliste'], 'doryphore', 'doryphore'));
    }

    public function testAnExtensionBehindTheRepositoryIsUpgradedFirst()
    {
        $this->install('bazarliste', '1.3.9');

        $this->assertSame(['bazarliste'], $this->versions()->toUpgrade($this->tmp, ['bazarliste'], 'doryphore', 'doryphore'));
    }

    public function testAnExtensionAheadOfTheRepositoryIsLeftAlone()
    {
        $this->install('bazarliste', '1.5.0');

        $this->assertSame([], $this->versions()->toUpgrade($this->tmp, ['bazarliste'], 'doryphore', 'doryphore'));
    }

    public function testAnExtensionWithADatedReleaseIsBehindANumberedOne()
    {
        $this->install('bazarliste', '2025-02-04-1');

        $this->assertSame(['bazarliste'], $this->versions()->toUpgrade($this->tmp, ['bazarliste'], 'doryphore', 'doryphore'));
    }

    public function testDatedReleasesStillCompareWithEachOther()
    {
        $this->install('documents', '2024-03-14-1');
        $older = $this->versions(['documents' => '2024-05-01-1']);
        $this->assertSame(['documents'], $older->toUpgrade($this->tmp, ['documents'], 'doryphore', 'doryphore'));

        $same = $this->versions(['documents' => '2024-03-14-1']);
        $this->assertSame([], $same->toUpgrade($this->tmp, ['documents'], 'doryphore', 'doryphore'));
    }

    public function testANumberedReleaseIsNotBehindADatedOne()
    {
        $this->install('documents', '0.10.0');

        $this->assertSame([], $this->versions(['documents' => '2024-03-14-1'])->toUpgrade($this->tmp, ['documents'], 'doryphore', 'doryphore'));
    }

    public function testAnExtensionInstalledByHandIsUpgradedSinceNothingSaysWhereItIs()
    {
        mkdir($this->tmp . '/tools/bazarliste', 0777, true);

        $this->assertSame(['bazarliste'], $this->versions()->toUpgrade($this->tmp, ['bazarliste'], 'doryphore', 'doryphore'));
    }

    public function testAWikiChangingVersionUpgradesEveryExtensionItHasOfItsOwn()
    {
        $this->install('bazarliste', '1.4.0');

        $this->assertSame(['bazarliste'], $this->versions()->toUpgrade($this->tmp, ['bazarliste'], 'doryphore', 'cercopitheque'));
    }

    public function testAnExtensionTheTargetVersionDoesNotCarryIsNeitherUpgradedNorForgotten()
    {
        $this->install('abandonnee', '0.1.0');

        $versions = $this->versions();

        $this->assertSame([], $versions->toUpgrade($this->tmp, ['abandonnee'], 'doryphore', 'doryphore'));
        $this->assertSame(['abandonnee'], $versions->unpublished(['abandonnee'], 'doryphore'));
    }

    public function testAPublishedExtensionIsNotReportedAsMissing()
    {
        $this->assertSame([], $this->versions()->unpublished(['bazarliste', 'documents'], 'doryphore'));
    }

    public function testAWikiWithoutAnyExtensionOfItsOwnNeverAsksTheRepository()
    {
        $versions = $this->versions();

        $this->assertSame([], $versions->toUpgrade($this->tmp, [], 'doryphore', 'doryphore'));
        $this->assertSame([], $versions->unpublished([], 'doryphore'));
        $this->assertSame(0, $versions->calls);
    }

    public function testTheInstalledReleaseComesFromWhatTheWikiWroteNextToTheExtension()
    {
        $this->install('bazarliste', '1.4.0');

        $this->assertSame('1.4.0', $this->versions()->installedRelease($this->tmp, 'bazarliste'));
        $this->assertNull($this->versions()->installedRelease($this->tmp, 'jamais-installee'));
    }

    private function install(string $extension, string $release): void
    {
        mkdir($this->tmp . '/tools/' . $extension, 0777, true);
        file_put_contents(
            $this->tmp . '/tools/' . $extension . '/infos.json',
            json_encode(['name' => $extension, 'release' => $release])
        );
    }

    private function versions(array $published = ['bazarliste' => '1.4.0', 'documents' => '0.10.0']): ExtensionVersions
    {
        return new class($this->getWiki(), $published) extends ExtensionVersions {
            public $calls = 0;
            private $published;

            public function __construct($wiki, array $published)
            {
                parent::__construct($wiki);
                $this->published = $published;
            }

            protected function index(string $targetVersion): array
            {
                $this->calls++;

                return $this->published;
            }
        };
    }
}
