<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\WikiConfigEditor;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** Another wiki's settings, read and rewritten through core. */
class WikiConfigEditorTest extends FarmTestCase
{
    public function testLockingAddsTheKeysToTheOnesAlreadyLocked(): void
    {
        $config = [WikiConfigEditor::LOCKED_PARAMS => ['favorite_theme', 'contact_from']];

        $this->assertSame(
            ['favorite_theme', 'contact_from', 'contact_smtp_host'],
            WikiConfigEditor::lockParams($config, ['contact_from', 'contact_smtp_host'])
        );
    }

    public function testTheLockedListNeverLocksItself(): void
    {
        $this->assertSame(
            ['contact_from'],
            WikiConfigEditor::lockParams([], ['contact_from', WikiConfigEditor::LOCKED_PARAMS])
        );
    }

    public function testSmtpSettingsPushedToAWikiAreLockedThere(): void
    {
        $this->farm(['contact_mail_func' => 'smtp', 'contact_smtp_host' => '127.0.0.1', 'contact_smtp_user' => 'yeswiki', 'contact_smtp_port' => '', 'contact_smtp_pass' => '', 'contact_smtp_secure' => '', 'contact_from' => '']);

        $set = $this->service(WikiConfigEditor::class)->smtpChangesFor([WikiConfigEditor::LOCKED_PARAMS => ['favorite_theme']]);

        $this->assertSame(['favorite_theme', 'contact_mail_func', 'contact_smtp_host', 'contact_smtp_user'], $set[WikiConfigEditor::LOCKED_PARAMS]);
    }

    public function testAFarmThatSendsNoSmtpPushesNothing(): void
    {
        $this->farm(['contact_mail_func' => 'mail', 'contact_smtp_host' => '']);

        $this->expectException(\RuntimeException::class);
        $this->service(WikiConfigEditor::class)->smtpFromFarm();
    }

    public function testNestedKeysAreWrittenWithDots(): void
    {
        $config = ['yeswiki-farm-extra-config' => ['contact_from' => 'a@example.tld']];
        $changes = $this->service(WikiConfigEditor::class)->apply($config, ['yeswiki-farm-extra-config.contact_from' => 'b@example.tld', 'debug' => 'yes'], ['missing.key']);

        $this->assertSame('b@example.tld', WikiConfigEditor::getPath($config, 'yeswiki-farm-extra-config.contact_from'));
        $this->assertSame(['yeswiki-farm-extra-config.contact_from', 'debug'], array_keys($changes));
        $this->assertSame('(missing)', $changes['debug']['old']);
        $this->assertSame([], $this->service(WikiConfigEditor::class)->apply($config, ['debug' => 'yes']), 'nothing changes twice');
    }

    public function testCommandLineValuesAndMaskedPasswords(): void
    {
        $this->assertTrue(WikiConfigEditor::cast('true'));
        $this->assertNull(WikiConfigEditor::cast('null'));
        $this->assertSame(3, WikiConfigEditor::cast('int:3'));
        $this->assertSame(['a' => 1], WikiConfigEditor::cast('json:{"a":1}'));
        $this->assertSame("'********'", WikiConfigEditor::mask('contact_smtp_pass', 'secret'));
        $this->assertSame("'visible'", WikiConfigEditor::mask('contact_from', 'visible'));
    }

    public function testAWikisWholeConfigurationIsWrittenBackKeysRemovedIncluded(): void
    {
        $dir = $this->scratch . '/wikis/edited';
        mkdir($dir);
        $this->wikiWithDatabase($dir, 'http://farm.test/edited/?', ['debug' => 'yes', 'meta_description' => 'Kept']);
        $editor = $this->service(WikiConfigEditor::class);

        $editor->patch($dir, ['wiki_status' => 'hibernate'], ['debug']);
        $backup = $editor->backup($dir);

        $loaded = $editor->load($dir);
        $this->assertSame('http://farm.test/edited/?', $loaded['base_url'], 'the host settings stay in private/.env');
        $this->assertSame('hibernate', $loaded['wiki_status'], 'the wiki\'s own go to its database');
        $this->assertSame('Kept', $loaded['meta_description']);
        $this->assertArrayNotHasKey('debug', $loaded, 'and a key taken away is gone');
        $this->assertStringNotContainsString('DEBUG', (string)file_get_contents($dir . '/private/.env'));
        $this->assertFileExists($this->scratch . '/farm/' . $backup);
        $this->assertSame('hibernate', json_decode((string)file_get_contents($this->scratch . '/farm/' . $backup), true)['settings']['wiki_status'] ?? null, 'the backup holds what the database stores');
    }
}
