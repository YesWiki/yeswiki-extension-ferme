<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Exception\WikiCreationException;
use YesWiki\Ferme\Service\InstanceConsole;
use YesWiki\Ferme\Service\InstanceDatabase;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Ferme\Service\WikiStats;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Kernel\Service\ConfigurationService;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** A farm entry makes an Instance of the shared Program, which boots, and destroying it leaves nothing behind. */
class InstanceLifecycleTest extends FarmTestCase
{
    private const MYSQL_PREFIX = 'fermetest_';

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            $store = $this->service(WikiStatsStore::class);
            foreach (['sqlite-wiki', 'mysql-wiki', 'shared-wiki', 'broken', 'taken'] as $folder) {
                $store->forget($folder);
            }
        }
        parent::tearDown();
    }

    public function testAnEntryMakesAnInstanceThatBootsAndDestroyingItRemovesEverything(): void
    {
        $this->farm();
        $dir = $this->service(WikiCreator::class)->createFromEntry($this->entry('sqlite-wiki'), 'bf_dossier-wiki');

        $this->assertSame($this->scratch . '/wikis/sqlite-wiki', $dir);
        foreach (['index.php', 'yeswicli', 'yeswiki.config.php', 'private/yeswiki.db'] as $file) {
            $this->assertFileExists($dir . '/' . $file);
        }
        foreach (['cache', 'custom', 'files', 'private'] as $folder) {
            $this->assertDirectoryExists($dir . '/' . $folder);
        }
        $this->assertStringContainsString(YESWIKI_PROGRAM_DIR, (string)file_get_contents($dir . '/index.php'), 'the Instance loads the shared Program, it holds no copy of it');
        $this->assertDirectoryDoesNotExist($dir . '/src/Kernel');
        $this->assertDirectoryDoesNotExist($dir . '/vendor');

        $config = (new ConfigurationService())->getConfiguration($dir . '/yeswiki.config.php');
        $config->load();
        $this->assertSame('http://farm.test/sqlite-wiki/?', $config['base_url']);
        $this->assertSame('sqlite', $config['db_driver']);
        $this->assertSame('A farm test wiki', $config['yeswiki_name']);

        $listed = $this->console($dir, ['list']);
        $this->assertSame(0, $listed['status'], $listed['output']);
        $this->assertStringContainsString('in ' . $dir, $listed['output'], 'the console boots inside the new wiki');
        $this->assertStringContainsString('core:destroy', $listed['output']);

        $users = $this->console($dir, ['user:list']);
        $this->assertStringContainsString('FarmTestAdmin', $users['output']);
        $this->assertStringContainsString('admins', $users['output']);

        $ping = $this->service(InstanceConsole::class)->task($dir, 'ping');
        $this->assertArrayHasKey('pages', $ping, 'a farm task boots the wiki with its own services');

        $stats = $this->service(WikiStats::class)->compute('sqlite-wiki');
        $this->assertSame(1, $stats['users']);
        $this->assertSame('A farm test wiki', $stats['name']);

        $this->service(WikiHibernator::class)->hibernate('sqlite-wiki');
        $this->assertSame('hibernate', $this->service(WikiHibernator::class)->statusOf('sqlite-wiki'));

        $kept = $this->service(WikiRemover::class)->destroy('sqlite-wiki', 'deleted');

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertNotNull($kept, 'core:destroy archived the wiki first');
        $this->assertFileExists($this->scratch . '/farm/' . $kept);
    }

    public function testAnAddressAlreadyTakenIsRefusedAndTheFailedCreationLeavesNothing(): void
    {
        $this->farm();
        mkdir($this->scratch . '/wikis/taken');

        try {
            $this->service(WikiCreator::class)->createFromEntry($this->entry('taken'), 'bf_dossier-wiki');
            $this->fail('a folder already there is refused');
        } catch (WikiCreationException $refused) {
            $this->assertSame('bf_dossier-wiki', $refused->getBlamedField());
        }

        $this->farm(['yeswiki-farm-db-driver' => 'nosuchdriver']);
        try {
            $this->service(WikiCreator::class)->createFromEntry($this->entry('broken'), 'bf_dossier-wiki');
            $this->fail('an install that fails is reported');
        } catch (WikiCreationException $refused) {
            $this->assertDirectoryDoesNotExist($this->scratch . '/wikis/broken', 'the half-made Instance is taken away');
        }
    }

    public function testAMysqlWikiGetsADatabaseAndAnAccountOfItsOwnWhichDestroyingDrops(): void
    {
        $admin = $this->mysqlAdmin();
        $this->farm([
            'yeswiki-farm-db-driver' => 'mysql',
            'yeswiki-farm-db-host' => '127.0.0.1',
            'yeswiki-farm-db-port' => '',
            'yeswiki-farm-db-admin-user' => 'root',
            'yeswiki-farm-db-admin-password' => 'secret',
            'yeswiki-farm-prefix' => self::MYSQL_PREFIX,
        ]);
        $dir = $this->service(WikiCreator::class)->createFromEntry($this->entry('mysql-wiki'), 'bf_dossier-wiki');
        $database = self::MYSQL_PREFIX . 'mysql_wiki';

        try {
            $config = $this->service(\YesWiki\Ferme\Service\FarmConfig::class)->readConfigIn($dir);
            $this->assertSame($database, $config['db_database']);
            $this->assertSame($database, $config['db_user'], 'the wiki holds credentials to its own database only');
            $this->assertNotSame('root', $config['db_password']);
            $this->assertContains($database, $this->databases($admin));

            $this->service(WikiRemover::class)->destroy('mysql-wiki', null);

            $this->assertDirectoryDoesNotExist($dir);
            $this->assertNotContains($database, $this->databases($admin), 'core:destroy dropped the database');
            $users = $admin->query("SELECT COUNT(*) FROM mysql.user WHERE User = '{$database}'");
            $this->assertSame(0, (int)($users === false ? 1 : $users->fetchColumn()), 'and the account');
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
            $admin->exec("DROP USER IF EXISTS '{$database}'@'%'");
        }
    }

    public function testAWikiSharingTheFarmDatabaseLosesOnlyItsOwnTables(): void
    {
        $admin = $this->mysqlAdmin();
        $database = self::MYSQL_PREFIX . 'shared_' . getmypid();
        $admin->exec("CREATE DATABASE `{$database}`");

        try {
            $this->farm([
                'yeswiki-farm-db-driver' => 'mysql',
                'db_host' => '127.0.0.1',
                'db_port' => '',
                'db_database' => $database,
                'db_user' => 'root',
                'db_password' => 'secret',
                'yeswiki-farm-prefix' => 'farm_',
            ]);
            $admin->exec("CREATE TABLE `{$database}`.`farm_zzz__pages` (id INT)");
            $dir = $this->service(WikiCreator::class)->createFromEntry($this->entry('shared-wiki'), 'bf_dossier-wiki');
            $config = $this->service(\YesWiki\Ferme\Service\FarmConfig::class)->readConfigIn($dir);
            $this->assertSame('farm_shared_wiki__', $config['table_prefix']);
            $db = $this->service(InstanceDatabase::class)->connect($config, $dir);
            $this->assertNotSame([], $this->service(InstanceDatabase::class)->ownTables($db, 'farm_shared_wiki__'));

            $this->service(WikiRemover::class)->destroy('shared-wiki', null);

            $tables = $admin->query("SHOW TABLES FROM `{$database}`");
            $left = $tables === false ? [] : array_map('strval', $tables->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame(['farm_zzz__pages'], $left, 'the other wiki of the database keeps its tables');
            $this->assertDirectoryDoesNotExist($dir);
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
    }

    private function mysqlAdmin(): \PDO
    {
        try {
            return new \PDO('mysql:host=127.0.0.1', 'root', 'secret', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $unreachable) {
            $this->markTestSkipped('no MySQL server reachable as root: ' . $unreachable->getMessage());
        }
    }

    /** @return list<string> */
    private function databases(\PDO $admin): array
    {
        $statement = $admin->query('SHOW DATABASES');

        return $statement === false ? [] : array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }
}
