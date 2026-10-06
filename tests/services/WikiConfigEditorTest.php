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
        file_put_contents($dir . '/yeswiki.config.php', "<?php\n\n\$yeswikiConfig = ['base_url' => 'http://farm.test/edited/?', 'debug' => 'yes'];\n");
        $editor = $this->service(WikiConfigEditor::class);

        $editor->patch($dir, ['wiki_status' => 'hibernate'], ['debug']);
        $backup = $editor->backup($dir);

        $this->assertSame(['base_url' => 'http://farm.test/edited/?', 'wiki_status' => 'hibernate'], $editor->load($dir));
        $this->assertFileExists($this->scratch . '/farm/' . $backup);
    }
}
