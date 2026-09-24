<?php

namespace YesWiki\Ferme\Field;

use Psr\Container\ContainerInterface;
use YesWiki\Bazar\Field\BazarField;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Ferme\Exception\WikiCreationException;
use YesWiki\Ferme\Service\FarmService;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Wiki;

/**
 * add fields to create custom yeswiki instance on a yeswiki farm yeswiki***bf_dossier-wiki***L\'adresse du site wiki***bf_mail***.
 *
 * @Field({"yeswiki"})
 */
class YesWikiField extends BazarField
{
    protected $emailField;
    protected $wiki;

    protected const FIELD_EMAIL_FIELD = 3;

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
            'lifetimes' => $this->lifetimeChoices(),
        ]);
    }

    private function lifetimeChoices(): array
    {
        $lifetime = $this->getService(WikiLifetime::class);
        if (!$lifetime->isEnabled()) {
            return [];
        }

        $choices = [];
        foreach ($lifetime->choices($this->wiki->UserIsAdmin()) as $kind) {
            $choices[$kind] = $kind === WikiLifetime::PERMANENT
                ? $lifetime->label($kind)
                : _t('FERME_LIFETIME_CHOICE_' . strtoupper($kind), ['days' => $lifetime->days($kind)]);
        }

        return $choices;
    }

    public function formatValuesBeforeSaveIfEditable($entry)
    {
        $terms = $this->lifetimeTerms(is_array($entry) ? $entry : []);
        $values = parent::formatValuesBeforeSaveIfEditable(array_merge(is_array($entry) ? $entry : [], $terms));
        $values['fields-to-remove'] = array_merge(
            $values['fields-to-remove'] ?? [],
            ['yeswiki-farm-lifetime'],
            array_values(array_diff(WikiLifetime::KEYS, array_keys($terms)))
        );

        return array_merge($values, $terms);
    }

    private function lifetimeTerms(array $entry): array
    {
        $idFiche = (string)($entry['id_fiche'] ?? '');
        $entryManager = $this->getService(EntryManager::class);
        if ($idFiche !== '' && $entryManager->isEntry($idFiche)) {
            $previous = $entryManager->getOne($idFiche, false, null, false, true) ?? [];

            return array_filter(array_intersect_key($previous, array_flip(WikiLifetime::KEYS)), 'is_string');
        }

        $lifetime = $this->getService(WikiLifetime::class);
        if (!$lifetime->isEnabled()) {
            return [];
        }

        $choices = $lifetime->choices($this->wiki->UserIsAdmin());
        $kind = (string)($_POST['yeswiki-farm-lifetime'] ?? $choices[0]);
        if (!in_array($kind, $choices, true)) {
            throw new WikiCreationException(_t('FERME_LIFETIME_INVALID') . ' "' . $kind . '"');
        }

        return $lifetime->start($kind, new \DateTimeImmutable('today'));
    }

    /** The wiki is made once the entry has its tag, so what is told about it — a Mattermost message, for one — can link back to the entry that owns it. */
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
        if (!$value || empty($this->wiki->config['yeswiki-farm-root-url'])) {
            return null;
        }

        $lifetime = $this->getService(WikiLifetime::class);
        $state = is_array($entry) ? $lifetime->describe($entry, new \DateTimeImmutable('today')) : null;
        $canManage = is_array($entry) && ($this->wiki->UserIsAdmin() || $this->wiki->UserIsOwner($entry['id_fiche'] ?? null));

        return $this->render('@ferme/fields/yeswiki.twig', [
            'url' => $state !== null && $state['archived'] ? null : $this->wiki->config['yeswiki-farm-root-url'] . $value,
            'lifetime' => $state,
            'lifetimeLabel' => $state === null ? '' : $lifetime->label($state['kind']),
            'renewUrl' => $state !== null && $state['canRenew'] && $canManage ? $lifetime->renewUrl($entry) : null,
        ]);
    }
}
