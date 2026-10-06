<?php

namespace YesWiki\Test\Ferme;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use YesWiki\Content\Service\EntryManager;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FarmDashboard;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Ferme\Service\ImportFilter;
use YesWiki\Ferme\Service\InstanceConsole;
use YesWiki\Ferme\Service\InstanceDatabase;
use YesWiki\Ferme\Service\MattermostNotifier;
use YesWiki\Ferme\Service\ModelLibrary;
use YesWiki\Ferme\Service\SpamApprovals;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Ferme\Service\SpamFingerprints;
use YesWiki\Ferme\Service\SpamScore;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\WelcomeMailer;
use YesWiki\Ferme\Service\WikiArchiver;
use YesWiki\Ferme\Service\WikiConfigEditor;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Ferme\Service\WikiFinder;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Ferme\Service\WikiMigrator;
use YesWiki\Ferme\Service\WikiPages;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Ferme\Service\WikiRepository;
use YesWiki\Ferme\Service\WikiStats;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Files\Service\LocalFiles;
use YesWiki\Files\Service\RuntimeLock;
use YesWiki\Files\Service\Storage;
use YesWiki\Identity\Service\AclService;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Identity\Service\UserManager;
use YesWiki\Kernel\Service\ConfigurationService;
use YesWiki\Kernel\Service\ExtensionRegistry;
use YesWiki\Kernel\Service\Mailer;
use YesWiki\Kernel\Service\UrlFormatter;
use YesWiki\Test\Core\ForcedParameterBag;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';
require_once 'tests/ForcedParameterBag.php';
require_once 'src/autoload.inc.php';

/** A farm whose wikis live in a scratch folder of their own, built from ferme's services with the settings a test chooses. */
abstract class FarmTestCase extends YesWikiTestCase
{
    protected string $scratch = '';

    /** @var array<string, object> */
    protected array $built = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->services()->get(ExtensionRegistry::class)->all()['ferme'])) {
            $this->markTestSkipped('ferme is not switched on in this wiki');
        }
        $this->scratch = (string)realpath(sys_get_temp_dir()) . '/ferme-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->scratch . '/wikis', 0o755, true);
        mkdir($this->scratch . '/farm/private/backups', 0o755, true);
        mkdir($this->scratch . '/locks', 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            (new Filesystem())->remove($this->scratch);
        }
        $this->built = [];
        parent::tearDown();
    }

    protected function services(): ContainerInterface
    {
        return $this->getWiki()->services;
    }

    /** @param array<string, mixed> $settings */
    protected function farm(array $settings = []): FarmConfig
    {
        $bag = new ForcedParameterBag($this->services()->get(ParameterBagInterface::class), $settings + [
            'yeswiki-farm-root-folder' => $this->scratch . '/wikis',
            'yeswiki-farm-root-url' => 'http://farm.test/',
            'yeswiki-farm-db-driver' => 'sqlite',
            'yeswiki-farm-db-admin-user' => '',
            'yeswiki-farm-mattermost-webhook' => '',
            'yeswiki-farm-default-WikiAdmin' => '',
            'yeswiki-farm-password-WikiAdmin' => '',
            'yeswiki-farm-create-user' => false,
            'yeswiki-farm-extensions' => [],
            'yeswiki-farm-lifetime' => false,
        ]);

        $this->built = [];

        return $this->built[FarmConfig::class] = new FarmConfig($bag, new ConfigurationService());
    }

    /**
     * One of ferme's services, built on the test's farm.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        if (!isset($this->built[$class])) {
            $this->built[$class] = $this->make($class);
        }
        $service = $this->built[$class];
        if (!$service instanceof $class) {
            throw new \LogicException($class);
        }

        return $service;
    }

    private function make(string $class): object
    {
        $core = $this->services();
        $config = $this->built[FarmConfig::class] ?? null;
        $config = $config instanceof FarmConfig ? $config : $this->farm();
        $storage = Storage::rootedAt($this->scratch . '/farm');
        $lock = new FolderLock(new RuntimeLock());
        $lock->useDirectory($this->scratch . '/locks');

        return match ($class) {
            InstanceConsole::class => new InstanceConsole(),
            InstanceDatabase::class => new InstanceDatabase(),
            FolderLock::class => $lock,
            WikiConfigEditor::class => new WikiConfigEditor($config, new ConfigurationService(), $storage),
            WikiHibernator::class => new WikiHibernator($config, $this->service(WikiConfigEditor::class), $this->service(FolderLock::class)),
            WikiStatsStore::class => new WikiStatsStore($core->get(\YesWiki\Kernel\Service\DbService::class)),
            SpamApprovals::class => new SpamApprovals($this->service(WikiStatsStore::class)),
            WikiFinder::class => new WikiFinder($config),
            WikiPages::class => new WikiPages($config, $this->service(InstanceDatabase::class)),
            SpamFingerprints::class => new SpamFingerprints($config, $this->service(WikiFinder::class), $this->service(WikiPages::class), $storage),
            SpamScore::class => new SpamScore($config),
            WikiStats::class => new WikiStats($config, $this->service(InstanceDatabase::class), $this->service(WikiPages::class), $this->service(SpamFingerprints::class), $this->service(SpamApprovals::class)),
            StatsRefresher::class => new StatsRefresher($this->service(WikiStats::class), $this->service(WikiStatsStore::class), $this->service(SpamScore::class)),
            WikiArchiver::class => new WikiArchiver($config, $this->service(InstanceConsole::class), $this->service(FolderLock::class), $this->service(WikiHibernator::class), $this->service(StatsRefresher::class), new LocalFiles()),
            ModelLibrary::class => new ModelLibrary($config, $this->service(WikiArchiver::class), $storage, new LocalFiles()),
            WikiLifetime::class => new WikiLifetime($config, $core->get(\YesWiki\Content\Service\PageManager::class), $core->get(UrlFormatter::class), $storage),
            FarmMailer::class => new FarmMailer($config, $core->get(EntryManager::class), $core->get(Mailer::class), $this->service(WikiStatsStore::class)),
            WelcomeMailer::class => new WelcomeMailer($config, $this->service(FarmMailer::class), $this->service(WikiLifetime::class)),
            MattermostNotifier::class => new MattermostNotifier($config, $this->service(SpamScore::class), $core->get(UrlFormatter::class), $core->get(AuthenticationService::class)),
            WikiCreator::class => new WikiCreator($config, $this->service(InstanceConsole::class), $this->service(ModelLibrary::class), $this->service(WikiConfigEditor::class), $this->service(FolderLock::class), $this->service(MattermostNotifier::class), $this->service(WelcomeMailer::class), $this->service(StatsRefresher::class), $core->get(UserManager::class), $core->get(UrlFormatter::class), $this->service(InstanceDatabase::class)),
            WikiRemover::class => new WikiRemover($config, $this->service(InstanceConsole::class), $this->service(InstanceDatabase::class), $core->get(EntryManager::class), $this->service(WikiStatsStore::class), $this->service(MattermostNotifier::class), $this->service(FolderLock::class), $this->service(WikiHibernator::class), $core->get(AclService::class), $storage, new LocalFiles()),
            SpamCleaner::class => new SpamCleaner($config, $this->service(WikiPages::class), $this->service(InstanceConsole::class), $this->service(FolderLock::class), $this->service(WikiHibernator::class), $this->service(StatsRefresher::class), $this->service(SpamFingerprints::class), $this->service(SpamApprovals::class), $storage),
            WikiMigrator::class => new WikiMigrator($config, $this->service(InstanceConsole::class), $this->service(FolderLock::class), $this->service(WikiHibernator::class), $this->service(StatsRefresher::class)),
            FarmDashboard::class => new FarmDashboard(),
            ImportFilter::class => new ImportFilter(),
            RuntimeLock::class => $this->scratchLocks(),
            WikiRepository::class => new WikiRepository($config, $core->get(EntryManager::class), $this->service(WikiFinder::class), $this->service(InstanceDatabase::class), $this->service(WikiStatsStore::class), $this->service(FarmDashboard::class), $this->service(SpamScore::class), $this->service(WikiLifetime::class)),
            default => throw new \LogicException('no recipe for ' . $class),
        };
    }

    /** A container handing out the scratch farm's services, and core's for everything else. */
    protected function scratchContainer(): ContainerInterface
    {
        return new ScratchContainer($this->services(), function (string $id): ?object {
            if (!class_exists($id) || (!str_starts_with($id, 'YesWiki\\Ferme\\') && $id !== RuntimeLock::class)) {
                return null;
            }
            try {
                return $this->service($id);
            } catch (\LogicException $none) {
                return null;
            }
        });
    }

    /** Core's runtime locks, rooted in the scratch folder rather than in the dev wiki. */
    protected function scratchLocks(): RuntimeLock
    {
        $locks = new RuntimeLock();
        (new \ReflectionProperty(RuntimeLock::class, 'root'))->setValue($locks, $this->scratch . '/farm');

        return $locks;
    }

    /**
     * What the creation form posts for a new wiki.
     *
     * @return array<string, mixed>
     */
    protected function entry(string $folder, string $title = 'A farm test wiki'): array
    {
        return [
            'tag' => 'FermeTest' . ucfirst(str_replace('-', '', $folder)),
            'title' => $title,
            'bf_titre' => $title,
            'bf_description' => 'Made by the ferme test suite',
            'bf_referent' => 'Test Referent',
            'bf_mail' => '',
            'bf_dossier-wiki' => $folder,
            'bf_dossier-wiki_wikiname' => 'FarmTestAdmin',
            'bf_dossier-wiki_email' => 'farm-admin@example.tld',
            'bf_dossier-wiki_password' => 'FarmTestPassword1',
        ];
    }

    /**
     * Run a wiki's own console, the way its `yeswicli` does.
     *
     * @param list<string> $arguments
     *
     * @return array{status: int, output: string}
     */
    protected function console(string $wikiDir, array $arguments): array
    {
        return $this->service(InstanceConsole::class)->run($wikiDir, $arguments);
    }
}

/** Core's container, with some services answered by the test instead. */
class ScratchContainer implements ContainerInterface
{
    /** @param \Closure(string): ?object $override */
    public function __construct(private readonly ContainerInterface $core, private readonly \Closure $override)
    {
    }

    public function get(string $id): mixed
    {
        return ($this->override)($id) ?? $this->core->get($id);
    }

    public function has(string $id): bool
    {
        return $this->core->has($id);
    }
}
