<?php

namespace YesWiki\Ferme\Field;

use Psr\Container\ContainerInterface;
use YesWiki\Bazar\Field\BazarField;
use YesWiki\Ferme\Exception\WikiCreationException;
use YesWiki\Ferme\Service\FarmService;
use YesWiki\Wiki;

/**
 * add fields to create custom yeswiki instance on a yeswiki farm
 * yeswiki***bf_dossier-wiki***L\'adresse du site wiki***bf_mail***.
 *
 * @Field({"yeswiki"})
 */
class YesWikiField extends BazarField
{
    protected $emailField;
    protected $wiki;

    protected const FIELD_EMAIL_FIELD = 3;

    /** @var array{message:string,field:?string}|array{} what a failed creation refused, for the render that follows */
    private static $refused = [];

    public function __construct(array $values, ContainerInterface $services)
    {
        parent::__construct($values, $services);

        $this->emailField = $values[self::FIELD_EMAIL_FIELD];
        $this->getService(FarmService::class)->initFarmConfig();
        $this->wiki = $this->getService(Wiki::class);
    }

    public function renderInput($entry)
    {
        $models = $this->getService(FarmService::class)->getModelLabels();
        $value = $this->getValue($entry);
        $error = self::$refused['message'] ?? null;
        $blamed = self::$refused['field'] ?? null;

        $posted = is_array($entry) ? $entry : [];
        if ($blamed !== null) {
            unset($posted[$blamed]);
        }

        return $this->render('@ferme/inputs/yeswiki.twig', [
            'value' => $value,
            'created' => !empty($value) && is_null($error),
            'addressValue' => $blamed === $this->propertyName ? '' : $value,
            'error' => $error,
            'blamed' => $blamed,
            'posted' => $posted,
            'rootUrl' => $this->wiki->config['yeswiki-farm-root-url'],
            'adminUsername' => $this->wiki->config['yeswiki-farm-default-WikiAdmin'] ?? null,
            'adminEmail' => $this->wiki->config['yeswiki-farm-email-WikiAdmin'] ?? null,
            'adminPassword' => $this->wiki->config['yeswiki-farm-password-WikiAdmin'] ?? null,
            'farmThemes' => $this->wiki->config['yeswiki-farm-themes'] ?? null,
            'farmModels' => $models ?? null,
            'farmAcls' => $this->wiki->config['yeswiki-farm-acls'] ?? null,
            'farmOptions' => $this->wiki->config['yeswiki-farm-options'] ?? null,
        ]);
    }

    /**
     * The wiki is made once the entry has its tag, so what is told about it — a
     * Mattermost message, for one — can link back to the entry that owns it.
     */
    public function requireIDFiche()
    {
        return true;
    }

    public function formatValuesBeforeSave($entry)
    {
        $value = $this->getValue($entry);
        if (empty($entry[$this->propertyName . '_exists']) && empty($entry[$this->propertyName . '-previous']) && $this->canEdit($entry)) {
            if (!empty($value) && preg_match('/^[0-9a-zA-Z-_]*$/', $value)) {
                $farm = $this->getService(FarmService::class);

                try {
                    $farm->createWikiFromEntry(
                        $entry,
                        $this->propertyName,
                        (string)($_POST['yeswiki-farm-theme'] ?? '0'),
                        (string)($_POST['yeswiki-farm-model'] ?? 'default-content')
                    );
                } catch (WikiCreationException $e) {
                    self::$refused = ['message' => $e->getMessage(), 'field' => $e->getBlamedField()];

                    throw $e;
                } catch (\Throwable $th) {
                    self::$refused = ['message' => $th->getMessage(), 'field' => null];

                    throw new WikiCreationException($th->getMessage(), null);
                }
            } else {
                $value = $entry[$this->propertyName . '-previous'] ?? null;
            }
        }

        return [
            $this->propertyName => $value ?? null,
            'fields-to-remove' => [
                $this->propertyName . '-previous',
                'bf_dossier-wiki_wikiname',
                'bf_dossier-wiki_email',
                'bf_dossier-wiki_password',
                $this->propertyName . '_wikiname',
                $this->propertyName . '_email',
                $this->propertyName . '_password',
                'yeswiki-farm-theme',
                'yeswiki-farm-model',
                'yeswiki-farm-acls',
            ],
        ];
    }

    public function renderStatic($entry)
    {
        $value = $this->getValue($entry);
        if ($value && !empty($this->wiki->config['yeswiki-farm-root-url'])) {
            $url = $this->wiki->config['yeswiki-farm-root-url'] . $value;

            return $this->render('@ferme/fields/yeswiki.twig', [
                'url' => $url,
            ]);
        }

        return null;
    }
}
