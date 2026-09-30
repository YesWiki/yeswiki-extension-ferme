<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\WikiConfigEditor;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(WikiConfigEditor::class, 'lockParams')]
#[CoversMethod(WikiConfigEditor::class, 'smtpChangesFor')]
class WikiConfigEditorTest extends YesWikiTestCase
{
    protected function setUp(): void
    {
        self::getWiki();
    }

    public function testLockingAddsTheKeysToTheOnesAlreadyLocked()
    {
        $config = [WikiConfigEditor::LOCKED_PARAMS => ['favorite_theme', 'contact_from']];

        $this->assertSame(
            ['favorite_theme', 'contact_from', 'contact_smtp_host'],
            WikiConfigEditor::lockParams($config, ['contact_from', 'contact_smtp_host'])
        );
    }

    public function testTheLockedListNeverLocksItself()
    {
        $this->assertSame(
            ['contact_from'],
            WikiConfigEditor::lockParams([], ['contact_from', WikiConfigEditor::LOCKED_PARAMS])
        );
    }

    public function testSmtpSettingsPushedToAWikiAreLockedThere()
    {
        $wiki = self::getWiki();
        $smtp = [
            'contact_mail_func' => 'smtp',
            'contact_smtp_host' => '127.0.0.1',
            'contact_smtp_user' => 'yeswiki',
        ];
        $saved = [];
        foreach (WikiConfigEditor::SMTP_KEYS as $key) {
            $saved[$key] = $wiki->config[$key] ?? null;
            $wiki->config[$key] = $smtp[$key] ?? '';
        }

        try {
            $set = $wiki->services->get(WikiConfigEditor::class)->smtpChangesFor([WikiConfigEditor::LOCKED_PARAMS => ['favorite_theme']]);

            $this->assertSame(
                ['favorite_theme', 'contact_mail_func', 'contact_smtp_host', 'contact_smtp_user'],
                $set[WikiConfigEditor::LOCKED_PARAMS]
            );
        } finally {
            foreach ($saved as $key => $value) {
                $wiki->config[$key] = $value;
            }
        }
    }
}
