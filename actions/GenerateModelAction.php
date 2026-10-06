<?php

namespace YesWiki\Ferme\Action;

use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\Exception\TokenNotFoundException;
use YesWiki\Core\YesWikiAction;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\ModelLibrary;
use YesWiki\Ferme\Service\StatsPresenter;
use YesWiki\Ferme\Service\WikiRepository;
use YesWiki\Identity\Service\AclService;
use YesWiki\Identity\Service\CsrfTokenChecker;
use YesWiki\Kernel\Component\Category;
use YesWiki\Kernel\Component\Component;
use YesWiki\Kernel\Component\ProvidesComponents;
use YesWiki\Kernel\Performable\RegisteredAction;

/** `{{generatemodel}}`: the farm's models, each a full archive of a wiki that new wikis are restored from. */
class GenerateModelAction extends YesWikiAction implements RegisteredAction, ProvidesComponents
{
    public static function performableName(): string
    {
        return 'generatemodel';
    }

    public function components(): array
    {
        return [
            Component::for('generatemodel')
                ->category(Category::Admin)
                ->label(_t('FERME_GENERATEMODEL_LABEL'))
                ->description(_t('FERME_GENERATEMODEL_DESCRIPTION'))
                ->icon('copy')
                ->previewHeight('200px')
                ->adminOnly(),
        ];
    }

    public function formatArguments($arg): array
    {
        return [];
    }

    public function run(): string
    {
        if (!$this->getService(AclService::class)->isAdmin()) {
            return $this->render('@core/alert-message.twig', ['type' => 'danger', 'message' => _t('FERME_ADMIN_REQUIRED')]);
        }
        $models = $this->getService(ModelLibrary::class);
        $messages = [];
        $post = $this->getRequest()->request;
        if ($post->has('ferme_model_action')) {
            $messages[] = $this->handle($models, (string)$post->get('ferme_model_action'));
        }

        $presenter = $this->getService(StatsPresenter::class);
        $wikis = [];
        foreach ($this->getService(WikiRepository::class)->entries() as $fiche) {
            $folder = (string)($fiche['bf_dossier-wiki'] ?? '');
            if ($folder !== '') {
                $wikis[$folder] = FarmMailer::titleOf($fiche) . ' (' . $folder . ')';
            }
        }
        asort($wikis);

        return $this->render('@ferme/generate-model.twig', [
            'messages' => $messages,
            'models' => array_map(static fn (array $model): array => $model + ['size' => $presenter->size($model['bytes'])], $models->all()),
            'wikis' => $wikis,
            'csrfToken' => $this->getService(CsrfTokenManager::class)->getToken('main')->getValue(),
        ]);
    }

    /** @return array{type: string, message: string} */
    private function handle(ModelLibrary $models, string $action): array
    {
        try {
            $this->getService(CsrfTokenChecker::class)->checkToken('main', 'POST', 'csrf-token', false);
        } catch (TokenNotFoundException $refused) {
            return ['type' => 'danger', 'message' => _t('FERME_INVALID_CSRF')];
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $post = $this->getRequest()->request;
        $name = trim((string)$post->get('name', ''));
        $label = trim((string)$post->get('label', ''));

        try {
            return match ($action) {
                'from-wiki' => ['type' => 'success', 'message' => _t('FERME_MODEL_CREATED', ['name' => $models->fromLocalWiki(trim((string)$post->get('folder', '')), $name, $label)])],
                'from-remote' => ['type' => 'success', 'message' => _t('FERME_MODEL_CREATED', ['name' => $models->fromRemoteWiki(trim((string)$post->get('url', '')), trim((string)$post->get('remote_admin', '')), (string)$post->get('remote_password', ''), $name, $label)])],
                'update' => $this->update($models, $name, $label, (bool)$post->get('offered')),
                'delete' => $this->delete($models, $name),
                default => ['type' => 'danger', 'message' => 'unknown action ' . $action],
            };
        } catch (\Throwable $failed) {
            return ['type' => 'danger', 'message' => $failed->getMessage()];
        }
    }

    /** @return array{type: string, message: string} */
    private function update(ModelLibrary $models, string $name, string $label, bool $offered): array
    {
        $models->setLabel($name, $label, $offered);

        return ['type' => 'success', 'message' => _t('FERME_MODEL_SAVED', ['name' => $name])];
    }

    /** @return array{type: string, message: string} */
    private function delete(ModelLibrary $models, string $name): array
    {
        $models->delete($name);

        return ['type' => 'success', 'message' => _t('FERME_MODEL_DELETED', ['name' => $name])];
    }
}
