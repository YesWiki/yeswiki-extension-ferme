<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Content\Entity\PageBody;
use YesWiki\Ferme\Service\InstanceConsole;
use YesWiki\Ferme\Service\InstanceDatabase;
use YesWiki\Kernel\Service\ConfigurationService;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** Switching ferme on in a fresh wiki installs its form, list, menu and pages, once. */
class MigrationTest extends FarmTestCase
{
    public function testTheMigrationInstallsTheFarmAndRunningItAgainChangesNothing(): void
    {
        $console = $this->service(InstanceConsole::class);
        $dir = $this->scratch . '/wikis/fresh';
        $console->createInstance($dir);
        $console->mustRun($dir, ['core:install', '--driver=sqlite', '--table-prefix=yeswiki_', '--base-url=http://fresh.test/?', '--root-page=PagePrincipale', '--wiki-name=Fresh', '--language=fr', '--admin-name=WikiAdmin', '--admin-email=fresh@example.tld'], ['ADMIN_PASSWORD' => 'FreshPassword1']);
        $console->mustRun($dir, ['extension:enable', 'ferme']);

        $first = $console->mustRun($dir, ['migrate']);

        $this->assertStringContainsString('FermeInitialDatabaseAndPageSetup', $first);
        $rows = $this->rows($dir);
        $this->assertSame('form', $rows['form:1100']['type'] ?? null, 'the farm form, by its id');
        $this->assertSame('{{bf_titre}}', $this->body($rows, 'form:1100')['entry_title_template'] ?? null);
        $template = $this->body($rows, 'form:1100')['template'] ?? [];
        $fields = array_values(array_filter(is_array($template) ? $template : [], static fn ($field): bool => is_array($field) && ($field['type'] ?? '') === 'yeswiki'));
        $field = is_array($fields[0] ?? null) ? $fields[0] : [];
        $this->assertSame(['bf_dossier-wiki', 'bf_mail'], [$field['name'] ?? null, $field['email_field'] ?? null]);
        $this->assertStringNotContainsString('{UtilisationDonnees}', (string)json_encode($template), 'the links of the form point at this wiki');
        $this->assertSame('list', $rows['ListeOuiNon']['type'] ?? null);
        $this->assertSame(['oui', 'non'], array_column((array)($this->body($rows, 'ListeOuiNon')['nodes'] ?? []), 'id'));
        $this->assertSame('menu', $rows['MenuFerme']['type'] ?? null);
        foreach (['AdminWikis' => '{{adminwikis}}', 'AjouterWiki' => 'id="1100"', 'ModelesWiki' => '{{generatemodel}}'] as $tag => $call) {
            $this->assertSame('page', $rows[$tag]['type'] ?? null, $tag);
            $this->assertStringContainsString($call, PageBody::content($this->body($rows, $tag)));
        }
        $this->assertStringContainsString('AdminWikis', (string)json_encode($this->body($rows, 'MenuAccesRapide')), 'the quick menu leads to the farm');

        $before = count($this->revisions($dir));
        $again = $console->mustRun($dir, ['migrate']);
        $this->assertStringNotContainsString('FermeInitialDatabaseAndPageSetup', $again);
        $this->assertSame($before, count($this->revisions($dir)), 'no revision written the second time');
        $this->assertSame(1, substr_count((string)json_encode($this->body($this->rows($dir), 'MenuAccesRapide')), 'AdminWikis'));
    }

    /** @return array<string, array{type: string, body: array<string, mixed>}> */
    private function rows(string $dir): array
    {
        $config = (new ConfigurationService())->getConfiguration($dir . '/yeswiki.config.php');
        $config->load();
        $parameters = $config->__get('_parameters');
        $db = $this->service(InstanceDatabase::class)->connect(is_array($parameters) ? $parameters : [], $dir);
        $rows = [];
        foreach (InstanceDatabase::rows($db, "SELECT tag, type, body FROM yeswiki_pages WHERE latest = 'Y'") as $row) {
            $body = json_decode((string)$row['body'], true);
            $form = is_array($body) && isset($body['id']) && $row['type'] === 'form' ? 'form:' . $body['id'] : (string)$row['tag'];
            $rows[$form] = ['type' => (string)$row['type'], 'body' => is_array($body) ? $body : []];
        }

        return $rows;
    }

    /**
     * @param array<string, array{type: string, body: array<string, mixed>}> $rows
     *
     * @return array<string, mixed>
     */
    private function body(array $rows, string $tag): array
    {
        return isset($rows[$tag]) ? $rows[$tag]['body'] : [];
    }

    /** @return list<array<string, mixed>> */
    private function revisions(string $dir): array
    {
        $db = new \PDO('sqlite:' . $dir . '/private/yeswiki.db');
        $statement = $db->query('SELECT id FROM yeswiki_pages');

        return $statement === false ? [] : array_values(array_filter($statement->fetchAll(\PDO::FETCH_ASSOC), 'is_array'));
    }
}
