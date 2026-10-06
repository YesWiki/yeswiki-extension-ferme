<?php

namespace YesWiki\Test\Ferme\Fields;

use YesWiki\Content\Service\FieldFactory;
use YesWiki\Ferme\Exception\WikiCreationException;
use YesWiki\Ferme\Field\YesWikiField;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** The field holding a farm wiki's address, as the form's template declares it. */
class YesWikiFieldTest extends FarmTestCase
{
    public function testTheNamedAttributeEmailFieldIsTheFieldsOwnPosition(): void
    {
        $map = $this->factory()->getAttributeKeyToIndexMap('yeswiki');

        $this->assertSame(3, $map['email_field'] ?? null);
        $this->assertSame(1, $map['name'] ?? null);
        $this->assertInstanceOf(YesWikiField::class, $this->field());
    }

    public function testTheInputAsksForTheAddressUnderTheFarmsRoot(): void
    {
        $html = $this->field()->renderInputIfPermitted(null);

        $this->assertStringContainsString('name="bf_dossier-wiki"', $html);
        $this->assertStringContainsString('bf_dossier-wiki_password', $html, 'a blank wiki asks for its first account');
    }

    public function testAnExistingWikiKeepsItsAddressAndLosesWhatTheFormPostedForTheCreation(): void
    {
        $values = $this->field()->formatValuesBeforeSave([
            'bf_dossier-wiki' => 'monwiki',
            'bf_dossier-wiki_exists' => '1',
            'bf_dossier-wiki_password' => 'secret',
        ]);

        $this->assertSame('monwiki', $values['bf_dossier-wiki']);
        $this->assertContains('bf_dossier-wiki_password', $values['fields-to-remove']);
        $this->assertContains('bf_dossier-wiki_exists', $values['fields-to-remove']);
    }

    public function testANewEntryWithAnAddressNoWikiMayTakeIsRefusedBeforeAnythingIsMade(): void
    {
        try {
            $this->field()->formatValuesBeforeSave(['bf_dossier-wiki' => '../ailleurs']);
            $this->fail('a folder outside the farm is refused');
        } catch (WikiCreationException $refused) {
            $this->assertSame('bf_dossier-wiki', $refused->getBlamedField());
        }
    }

    private function factory(): FieldFactory
    {
        return self::getWiki()->services->get(FieldFactory::class);
    }

    private function field(): YesWikiField
    {
        $field = $this->factory()->create(['yeswiki', 'bf_dossier-wiki', 'Adresse du wiki', 'bf_mail', '', '', '', '', '1', '', '', '', '']);
        if (!$field instanceof YesWikiField) {
            throw new \LogicException('no yeswiki field');
        }

        return $field;
    }
}
