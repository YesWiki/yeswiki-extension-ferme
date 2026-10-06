<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\InstanceConsole;
use YesWiki\Ferme\Service\ModelLibrary;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Ferme\Service\WikiPages;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** A model is a wiki's full archive, taken by its core and restored by core's installer into each wiki made from it. */
class ModelLibraryTest extends FarmTestCase
{
    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            foreach (['modele', 'copie'] as $folder) {
                $this->service(WikiStatsStore::class)->forget($folder);
            }
        }
        parent::tearDown();
    }

    public function testAWikiMadeFromAModelHoldsTheModelsPagesAndItsOwnAdmin(): void
    {
        $this->farm();
        $source = $this->service(WikiCreator::class)->createFromEntry($this->entry('modele', 'Le modèle'), 'bf_dossier-wiki');
        $this->service(InstanceConsole::class)->task($source, 'append-to-page', ['tag' => 'PageDuModele', 'content' => 'Ce texte vient du modèle']);
        $models = $this->service(ModelLibrary::class);

        $name = $models->fromLocalWiki('modele', 'Association', 'Un wiki d\'association');

        $this->assertSame('association', $name);
        $this->assertSame([], glob($source . '/private/backups/*.zip'), 'the archive moved into the farm, it is not left in the wiki');
        $model = $models->describe('association');
        $this->assertNotNull($model);
        $this->assertSame('Un wiki d\'association', $model['label']);
        $this->assertSame('http://farm.test/modele/', $model['source']);
        $this->assertFileExists($this->scratch . '/farm/' . FarmConfig::DATA_DIR . '/models/association/' . $model['archive']);
        $this->assertSame([FarmConfig::BLANK_MODEL, 'association'], array_keys($models->choices()));

        $copyEntry = $this->entry('copie', 'La copie');
        $copyEntry['bf_dossier-wiki_wikiname'] = 'CopieAdmin';
        $copyEntry['bf_dossier-wiki_email'] = 'copie-admin@example.tld';
        $copy = $this->service(WikiCreator::class)->createFromEntry($copyEntry, 'bf_dossier-wiki', '0', 'association');

        $this->assertSame('Ce texte vient du modèle', $this->service(WikiPages::class)->content('copie', 'PageDuModele'));
        $config = $this->service(FarmConfig::class)->readConfigIn($copy);
        $this->assertSame('http://farm.test/copie/?', $config['base_url'], 'the copy keeps its own address');
        $users = $this->console($copy, ['user:list'])['output'];
        $this->assertStringContainsString('CopieAdmin', $users);
        $this->assertMatchesRegularExpression('/CopieAdmin[^\n]*admins/', $users);

        $models->setLabel('association', 'Renommé', false);
        $this->assertSame([FarmConfig::BLANK_MODEL], array_keys($models->choices()), 'a model not offered is not offered');
        $models->delete('association');
        $this->assertSame([], $models->all());

        $this->service(WikiRemover::class)->destroy('copie', null);
        $this->service(WikiRemover::class)->destroy('modele', null);
        $this->assertDirectoryDoesNotExist($copy);
        $this->assertDirectoryDoesNotExist($source);
    }

    public function testAModelNameIsASlugNobodyHasYet(): void
    {
        $this->farm();

        $this->expectException(\RuntimeException::class);
        $this->service(ModelLibrary::class)->fromLocalWiki('nowhere', FarmConfig::BLANK_MODEL, 'Vide');
    }
}
