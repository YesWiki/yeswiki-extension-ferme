<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Exception\WikiStatsException;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Ferme\Service\SpamApprovals;
use YesWiki\Ferme\Service\SpamFingerprints;
use YesWiki\Ferme\Service\WikiDatabase;
use YesWiki\Ferme\Service\WikiStats;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(WikiStats::class, 'compute')]
#[CoversMethod(WikiStats::class, 'fromDatabase')]
#[CoversMethod(WikiStats::class, 'fromDisk')]
#[CoversMethod(WikiStats::class, 'probe')]
#[CoversMethod(WikiStats::class, 'diskProbe')]
#[CoversMethod(WikiStats::class, 'daily')]
class WikiStatsTest extends YesWikiTestCase
{
    private string $tmp;
    private string $prefix;
    private array $wakkaConfig;
    private \mysqli $db;

    protected function setUp(): void
    {
        $wiki = self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-wiki-stats-' . bin2hex(random_bytes(6));
        $this->prefix = 'ferme_test_' . bin2hex(random_bytes(4)) . '_';
        mkdir($this->tmp . '/monwiki', 0777, true);

        $this->wakkaConfig = [
            'mysql_host' => $wiki->config['mysql_host'],
            'mysql_user' => $wiki->config['mysql_user'],
            'mysql_password' => $wiki->config['mysql_password'],
            'mysql_database' => $wiki->config['mysql_database'],
            'table_prefix' => $this->prefix,
        ];

        $this->db = (new WikiDatabase())->connect($this->wakkaConfig);
        $this->db->query('CREATE TABLE `' . $this->prefix . 'pages` (id int unsigned NOT NULL AUTO_INCREMENT, tag varchar(191) NOT NULL, time datetime NOT NULL, body longtext NOT NULL, latest enum(\'Y\',\'N\') NOT NULL DEFAULT \'N\', comment_on varchar(191) NOT NULL DEFAULT \'\', PRIMARY KEY (id)) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE `' . $this->prefix . 'users` (name varchar(191) NOT NULL, PRIMARY KEY (name)) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE `' . $this->prefix . 'nature` (bn_id_nature int unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (bn_id_nature)) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE `' . $this->prefix . 'triples` (id int unsigned NOT NULL AUTO_INCREMENT, resource varchar(255) NOT NULL, property varchar(255) NOT NULL, value text NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        foreach (['pages', 'users', 'nature', 'triples'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->prefix . $table . '`');
        }
        $this->db->close();
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testAWikiIsCountedAsItsTablesAndItsFoldersSayIt()
    {
        $this->fillWiki();
        $this->fillFolders();

        $stats = $this->stats()->compute('monwiki');

        $this->assertSame(3, $stats['users']);
        $this->assertSame(2, $stats['forms']);
        $this->assertSame(4, $stats['entries']);
        $this->assertSame(5, $stats['pages']);
        $this->assertSame(3, $stats['files']);
        $this->assertSame(3072, $stats['filesBytes']);
        $this->assertSame(10, $stats['customBytes']);
        $this->assertSame(500, $stats['privateBytes']);
    }

    public function testOnlyCurrentNonCommentPagesAreCountedAsPages()
    {
        $this->insertPage('PageAccueil', 'Y', '');
        $this->insertPage('PageAccueil', 'N', '');
        $this->insertPage('Commentaire1', 'Y', 'PageAccueil');

        $this->assertSame(1, $this->stats()->fromDatabase('monwiki')['pages']);
    }

    public function testEntriesAreCountedOnTheirTripleNotOnTheirBody()
    {
        $this->insertTriple('FicheUne', 'http://outils-reseaux.org/_vocabulary/type', 'fiche_bazar');
        $this->insertTriple('PageDeux', 'http://outils-reseaux.org/_vocabulary/type', 'autre_chose');
        $this->insertTriple('FicheUne', 'http://outils-reseaux.org/_vocabulary/sourceUrl', 'fiche_bazar');
        $this->insertPage('FicheUne', 'Y', '', '{"id_typeannonce":"1"}');

        $this->assertSame(1, $this->stats()->fromDatabase('monwiki')['entries']);
    }

    public function testActivityHasTwelveMonthsOldestFirstWithHolesAtZero()
    {
        $now = new \DateTimeImmutable('first day of this month 12:00:00');
        $this->insertPage('Page1', 'Y', '', 'texte', $now->format('Y-m-d H:i:s'));
        $this->insertPage('Page1', 'N', '', 'texte', $now->format('Y-m-d H:i:s'));
        $this->insertPage('Page2', 'Y', '', 'texte', $now->modify('-2 month')->format('Y-m-d H:i:s'));
        $this->insertPage('Vieux', 'Y', '', 'texte', $now->modify('-3 year')->format('Y-m-d H:i:s'));

        $activity = $this->stats()->fromDatabase('monwiki')['activity'];

        $this->assertCount(12, $activity);
        $this->assertSame(2, $activity[11], 'the current month comes last');
        $this->assertSame(1, $activity[9]);
        $this->assertSame(0, $activity[10], 'a month without an edit is a zero, not a hole');
        $this->assertSame(3, array_sum($activity), 'the three year old revision is outside the window');
    }

    public function testTheDailySeriesHoldsOnlyTheDaysSomethingHappened()
    {
        $this->insertPage('Page1', 'Y', '', 'texte', date('Y-m-d 10:00:00'));
        $this->insertPage('Page1', 'N', '', 'texte', date('Y-m-d 11:00:00'));
        $this->insertPage('Page2', 'Y', '', 'texte', date('Y-m-d 09:00:00', strtotime('-3 days')));
        $this->insertPage('Vieux', 'Y', '', 'texte', date('Y-m-d 09:00:00', strtotime('-3 years')));

        $daily = $this->stats()->daily('monwiki');

        $this->assertSame(2, $daily[date('Y-m-d')]);
        $this->assertSame(1, $daily[date('Y-m-d', strtotime('-3 days'))]);
        $this->assertArrayNotHasKey(date('Y-m-d', strtotime('-1 day')), $daily, 'a quiet day is absent, not a zero');
        $this->assertCount(2, $daily, 'and what is older than the window is out');
    }

    public function testAnEmptyWikiIsZerosAndNotAFailure()
    {
        $stats = $this->stats()->compute('monwiki');

        $this->assertSame(0, $stats['users']);
        $this->assertSame(0, $stats['pages']);
        $this->assertSame(0, $stats['files']);
        $this->assertSame(0, $stats['lastPageId']);
        $this->assertNull($stats['lastActivity']);
        $this->assertSame(array_fill(0, 12, 0), $stats['activity']);
    }

    public function testTheLastRevisionIsReportedByIdAndByDate()
    {
        $this->insertPage('Page1', 'N', '', 'texte', '2026-01-02 10:00:00');
        $this->insertPage('Page1', 'Y', '', 'texte', '2026-02-03 11:00:00');

        $stats = $this->stats()->fromDatabase('monwiki');

        $this->assertSame('2026-02-03 11:00:00', $stats['lastActivity']);
        $this->assertSame($this->lastInsertedPageId(), $stats['lastPageId']);
        $this->assertSame($stats['lastPageId'], $this->stats()->probe('monwiki'));
    }

    public function testAWikiThatCannotBeReadFailsWithAReasonRatherThanZeros()
    {
        $broken = $this->stats(['table_prefix' => 'pas_de_tables_']);

        try {
            $broken->fromDatabase('monwiki');
            $this->fail('an unreadable wiki should not answer with zeros');
        } catch (WikiStatsException $exception) {
            $this->assertSame('monwiki', $exception->getFolder());
            $this->assertNotEmpty($exception->getReason());
        }

        $this->assertNull($broken->probe('monwiki'), 'the probe stays a cheap best effort');
    }

    public function testAWikiWithoutAConfigIsAFailureToo()
    {
        $this->expectException(WikiStatsException::class);
        $this->stats([])->fromDatabase('monwiki');
    }

    public function testMissingDataFoldersWeighNothing()
    {
        mkdir($this->tmp . '/monwiki/files');
        file_put_contents($this->tmp . '/monwiki/files/photo.jpg', str_repeat('x', 42));

        $stats = $this->stats()->fromDisk('monwiki');

        $this->assertSame(1, $stats['files']);
        $this->assertSame(42, $stats['filesBytes']);
        $this->assertSame(0, $stats['customBytes']);
        $this->assertSame(0, $stats['privateBytes']);
    }

    public function testASymlinkedFolderIsNotWeighedInEveryWikiThatSharesIt()
    {
        mkdir($this->tmp . '/partage', 0777, true);
        file_put_contents($this->tmp . '/partage/custom.css', str_repeat('x', 900));
        symlink($this->tmp . '/partage', $this->tmp . '/monwiki/custom');
        mkdir($this->tmp . '/monwiki/files');
        file_put_contents($this->tmp . '/monwiki/files/vrai.jpg', str_repeat('x', 12));
        symlink($this->tmp . '/partage/custom.css', $this->tmp . '/monwiki/files/lien.css');

        $stats = $this->stats()->fromDisk('monwiki');

        $this->assertSame(0, $stats['customBytes']);
        $this->assertSame(1, $stats['files']);
        $this->assertSame(12, $stats['filesBytes']);
    }

    public function testSubfoldersOfFilesAreWeighedToo()
    {
        mkdir($this->tmp . '/monwiki/files/backgrounds', 0777, true);
        file_put_contents($this->tmp . '/monwiki/files/photo.jpg', str_repeat('x', 100));
        file_put_contents($this->tmp . '/monwiki/files/backgrounds/fond.jpg', str_repeat('x', 200));

        $stats = $this->stats()->fromDisk('monwiki');

        $this->assertSame(2, $stats['files']);
        $this->assertSame(300, $stats['filesBytes']);
    }

    public function testAMissingWikiFolderIsAFailure()
    {
        $this->expectException(WikiStatsException::class);
        $this->stats()->fromDisk('disparu');
    }

    public function testTheDiskProbeFollowsWhatFilesHolds()
    {
        $this->assertNull($this->stats()->diskProbe('monwiki'), 'no files folder, nothing to probe');

        mkdir($this->tmp . '/monwiki/files');
        $before = $this->stats()->diskProbe('monwiki');
        $this->assertIsInt($before);

        $moved = $before + 10;
        touch($this->tmp . '/monwiki/files', $moved);
        clearstatcache();

        $this->assertSame($moved, $this->stats()->diskProbe('monwiki'));
    }

    public function testOneConnectionServesEveryWikiOfTheSameDatabase()
    {
        $database = $this->createMock(WikiDatabase::class);
        $database->expects($this->once())->method('connect')->willReturn($this->db);
        $database->method('table')->willReturnCallback(function (string $prefix, string $name) {
            return $prefix . $name;
        });

        $stats = new WikiStats(self::getWiki(), $this->farmConfig(), $database, $this->createStub(SpamFingerprints::class), $this->createStub(SpamApprovals::class));
        $stats->fromDatabase('monwiki');
        $stats->fromDatabase('monwiki');
        $stats->probe('monwiki');
    }

    private function fillWiki(): void
    {
        foreach (['Alice', 'Bob', 'Carole'] as $name) {
            $this->db->query('INSERT INTO `' . $this->prefix . 'users` (name) VALUES ("' . $name . '")');
        }
        $this->db->query('INSERT INTO `' . $this->prefix . 'nature` (bn_id_nature) VALUES (1), (2)');
        foreach (['F1', 'F2', 'F3', 'F4'] as $tag) {
            $this->insertTriple($tag, 'http://outils-reseaux.org/_vocabulary/type', 'fiche_bazar');
        }
        foreach (['P1', 'P2', 'P3', 'P4', 'P5'] as $tag) {
            $this->insertPage($tag, 'Y', '');
        }
        $this->insertPage('P1', 'N', '');
    }

    private function fillFolders(): void
    {
        mkdir($this->tmp . '/monwiki/files', 0777, true);
        mkdir($this->tmp . '/monwiki/custom', 0777, true);
        mkdir($this->tmp . '/monwiki/private/backups', 0777, true);
        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $name) {
            file_put_contents($this->tmp . '/monwiki/files/' . $name, str_repeat('x', 1024));
        }
        file_put_contents($this->tmp . '/monwiki/custom/custom.css', str_repeat('x', 10));
        file_put_contents($this->tmp . '/monwiki/private/backups/dump.sql', str_repeat('x', 500));
    }

    private function insertPage(string $tag, string $latest, string $commentOn, string $body = 'texte', ?string $time = null): void
    {
        $time = $time ?? date('Y-m-d H:i:s');
        $statement = $this->db->prepare('INSERT INTO `' . $this->prefix . 'pages` (tag, time, body, latest, comment_on) VALUES (?, ?, ?, ?, ?)');
        $statement->bind_param('sssss', $tag, $time, $body, $latest, $commentOn);
        $statement->execute();
        $statement->close();
    }

    private function insertTriple(string $resource, string $property, string $value): void
    {
        $statement = $this->db->prepare('INSERT INTO `' . $this->prefix . 'triples` (resource, property, value) VALUES (?, ?, ?)');
        $statement->bind_param('sss', $resource, $property, $value);
        $statement->execute();
        $statement->close();
    }

    private function lastInsertedPageId(): int
    {
        return (int)$this->db->query('SELECT MAX(id) FROM `' . $this->prefix . 'pages`')->fetch_row()[0];
    }

    private function stats(?array $configOverride = null): WikiStats
    {
        return new WikiStats(self::getWiki(), $this->farmConfig($configOverride), new WikiDatabase(), $this->createStub(SpamFingerprints::class), $this->createStub(SpamApprovals::class));
    }

    private function farmConfig(?array $configOverride = null): FarmConfig
    {
        $wakkaConfig = $configOverride === null ? $this->wakkaConfig : array_merge($this->wakkaConfig, $configOverride);
        if ($configOverride === []) {
            $wakkaConfig = [];
        }

        $config = $this->createStub(FarmConfig::class);
        $config->method('wikiDir')->willReturnCallback(function (string $folder) {
            return $this->tmp . '/' . $folder . '/';
        });
        $config->method('readWikiConfig')->willReturn($wakkaConfig);

        return $config;
    }
}
