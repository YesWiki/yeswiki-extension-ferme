<?php

namespace YesWiki\Ferme\Field;

use Psr\Container\ContainerInterface;
use YesWiki\Content\Attribute\Field;
use YesWiki\Content\Field\BazarField;
use YesWiki\Content\Service\EntryManager;
use YesWiki\Ferme\Exception\WikiCreationException;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\ModelLibrary;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Identity\Service\AclService;
use YesWiki\Kernel\Service\FlashMessageService;

/** The address of a farm wiki: saving a new entry creates the wiki, as a new Instance of the farm's Program. */
#[Field(['yeswiki'])]
class YesWikiField extends BazarField
{
    protected const FIELD_EMAIL_FIELD = 3;

    private const POSTED = ['_wikiname', '_email', '_password'];

    protected string $emailField;

    /** @var array{message?: string, field?: string|null} */
    private static array $refused = [];

    public function __construct(array $values, ContainerInterface $services)
    {
        parent::__construct($values, $services);
        $this->emailField = trim((string)($values[self::FIELD_EMAIL_FIELD] ?? 'bf_mail'));
        $this->maxChars = 50;
    }

    public function requiresTagBeforeFormatting()
    {
        return true;
    }

    protected function renderInput($entry)
    {
        $config = $this->farm();
        $value = (string)($this->getValue($entry) ?? '');
        $error = self::$refused['message'] ?? null;
        $blamed = self::$refused['field'] ?? null;
        $posted = is_array($entry) ? $entry : [];
        if ($blamed !== null) {
            unset($posted[$blamed]);
        }

        return $this->render('@ferme/inputs/yeswiki.twig', [
            'value' => $value,
            'created' => $value !== '' && $error === null && is_array($entry) && !empty($entry['tag']),
            'addressValue' => $blamed === $this->propertyName ? '' : $value,
            'error' => $error,
            'blamed' => $blamed,
            'posted' => $posted,
            'rootUrl' => $config->rootUrl(),
            'adminUsername' => $config->text('yeswiki-farm-default-WikiAdmin'),
            'adminEmail' => $config->text('yeswiki-farm-email-WikiAdmin', $this->emailField),
            'adminPassword' => $config->text('yeswiki-farm-password-WikiAdmin'),
            'farmThemes' => $config->themes(),
            'farmModels' => $this->getService(ModelLibrary::class)->choices(),
            'farmAcls' => $config->acls(),
            'farmOptions' => $config->options(),
            'lifetimes' => $this->lifetimeChoices(),
        ]);
    }

    public function formatValuesBeforeSaveIfEditable($entry): array
    {
        $entry = is_array($entry) ? $entry : [];
        $terms = $this->lifetimeTerms($entry);
        $values = parent::formatValuesBeforeSaveIfEditable(array_merge($entry, $terms));
        $values['fields-to-remove'] = array_merge(
            is_array($values['fields-to-remove'] ?? null) ? $values['fields-to-remove'] : [],
            ['yeswiki-farm-lifetime'],
            array_values(array_diff(WikiLifetime::KEYS, array_keys($terms)))
        );

        return array_merge($values, $terms);
    }

    public function formatValuesBeforeSave($entry)
    {
        $entry = is_array($entry) ? $entry : [];
        $value = trim((string)($this->getValue($entry) ?? ''));
        $tag = (string)($entry['tag'] ?? '');
        $isNew = empty($entry[$this->propertyName . '_exists']) && empty($entry[$this->propertyName . '-previous'])
            && !($tag !== '' && $this->getService(EntryManager::class)->isEntry($tag));

        if ($isNew && $this->canEdit($entry)) {
            $request = $this->getRequest()->request;
            try {
                $creator = $this->getService(WikiCreator::class);
                $creator->createFromEntry(
                    $entry,
                    (string)$this->propertyName,
                    (string)($entry['yeswiki-farm-theme'] ?? $request->get('yeswiki-farm-theme') ?? array_key_first($this->farm()->themes())),
                    (string)($entry['yeswiki-farm-model'] ?? $request->get('yeswiki-farm-model') ?? FarmConfig::BLANK_MODEL)
                );
                foreach ($creator->warnings() as $warning) {
                    $this->getService(FlashMessageService::class)->setMessage($warning);
                }
            } catch (WikiCreationException $refused) {
                self::$refused = ['message' => $refused->getMessage(), 'field' => $refused->getBlamedField()];

                throw $refused;
            } catch (\Throwable $failed) {
                self::$refused = ['message' => $failed->getMessage(), 'field' => null];

                throw new WikiCreationException($failed->getMessage(), null, $failed);
            }
        } elseif (!$isNew && $value === '') {
            $value = (string)($entry[$this->propertyName . '-previous'] ?? '');
        }

        $removed = [$this->propertyName . '-previous', $this->propertyName . '_exists', 'yeswiki-farm-theme', 'yeswiki-farm-model', 'yeswiki-farm-acls', 'yeswiki-farm-options', 'access-username', 'access-password'];
        foreach (self::POSTED as $suffix) {
            $removed[] = $this->propertyName . $suffix;
        }

        return [$this->propertyName => $value, 'fields-to-remove' => $removed];
    }

    protected function renderStatic($entry)
    {
        $value = (string)($this->getValue($entry) ?? '');
        if ($value === '' || !is_array($entry)) {
            return null;
        }
        $lifetime = $this->getService(WikiLifetime::class);
        $state = $lifetime->describe($entry, new \DateTimeImmutable('today'));
        $acl = $this->getService(AclService::class);
        $canManage = $acl->isAdmin() || $acl->isOwner((string)($entry['tag'] ?? ''));

        return $this->render('@ferme/fields/yeswiki.twig', [
            'url' => $state !== null && $state['archived'] ? null : $this->farm()->wikiUrl($value),
            'lifetime' => $state,
            'lifetimeLabel' => $state === null ? '' : $lifetime->label($state['kind']),
            'renewUrl' => $state !== null && $state['canRenew'] && $canManage ? $lifetime->renewUrl($entry) : null,
        ]);
    }

    /** @return array<string, string> */
    private function lifetimeChoices(): array
    {
        $lifetime = $this->getService(WikiLifetime::class);
        if (!$lifetime->isEnabled()) {
            return [];
        }
        $choices = [];
        foreach ($lifetime->choices($this->getService(AclService::class)->isAdmin()) as $kind) {
            $choices[$kind] = $kind === WikiLifetime::PERMANENT ? $lifetime->label($kind) : _t('FERME_LIFETIME_CHOICE_' . strtoupper($kind), ['days' => $lifetime->days($kind)]);
        }

        return $choices;
    }

    /**
     * The lifetime an entry keeps, or the one a new wiki starts: a wiki registered after the fact stays permanent.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, string>
     */
    private function lifetimeTerms(array $entry): array
    {
        $tag = (string)($entry['tag'] ?? '');
        $entryManager = $this->getService(EntryManager::class);
        if ($tag !== '' && $entryManager->isEntry($tag)) {
            $previous = $entryManager->getOne($tag, false, null, false, true) ?? [];

            return array_filter(array_intersect_key($previous, array_flip(WikiLifetime::KEYS)), 'is_string');
        }
        $lifetime = $this->getService(WikiLifetime::class);
        if (!$lifetime->isEnabled() || !empty($entry[$this->propertyName . '_exists'])) {
            return [];
        }
        $choices = $lifetime->choices($this->getService(AclService::class)->isAdmin());
        $kind = (string)($entry['yeswiki-farm-lifetime'] ?? $this->getRequest()->request->get('yeswiki-farm-lifetime') ?? $choices[0]);
        if (!in_array($kind, $choices, true)) {
            throw new WikiCreationException(_t('FERME_LIFETIME_INVALID') . ' "' . $kind . '"');
        }

        return $lifetime->start($kind, new \DateTimeImmutable('today'));
    }

    private function farm(): FarmConfig
    {
        return $this->getService(FarmConfig::class);
    }
}
