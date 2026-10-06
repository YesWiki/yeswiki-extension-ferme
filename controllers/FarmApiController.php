<?php

namespace YesWiki\Ferme\Controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\Exception\TokenNotFoundException;
use YesWiki\Core\ApiResponse;
use YesWiki\Core\YesWikiController;
use YesWiki\Ferme\Exception\WikiStatsException;
use YesWiki\Ferme\Service\FarmAdminAccount;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\SpamApprovals;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Ferme\Service\StatsPresenter;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\WikiArchiver;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Ferme\Service\WikiMigrator;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Ferme\Service\WikiRepository;
use YesWiki\Ferme\Service\WikiStats;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Identity\Service\CsrfTokenChecker;
use YesWiki\Kernel\Service\UrlFormatter;
use YesWiki\Render\Service\TemplateEngine;

/** The farm's routes: what the wikis screen does to one wiki at a time, and the public renewal link of a wiki's lifetime. */
class FarmApiController extends YesWikiController
{
    private const ACTIONS = ['migrate', 'refresh-stats', 'archive', 'hibernate', 'wake', 'admin-add', 'admin-remove', 'clean-spam', 'clean-spam-dry'];

    #[Route('/api/ferme', methods: ['GET'], options: ['acl' => ['public']])]
    public function onlineDoc(): Response
    {
        return new Response($this->getService(TemplateEngine::class)->renderPage($this->render('@ferme/api-doc.twig', [
            'base' => $this->getService(UrlFormatter::class)->href('', 'api/ferme'),
        ])));
    }

    #[Route('/api/ferme/wikis/select', methods: ['GET'], options: ['acl' => ['@admins']])]
    public function selectWikis(Request $request): ApiResponse
    {
        $this->denyAccessUnlessAdmin();

        return new ApiResponse($this->getService(WikiRepository::class)->listForSelection(
            trim((string)$request->query->get('ferme_search', '')),
            (string)$request->query->get('ferme_filter', ''),
            (string)$request->query->get('ferme_lifetime', '')
        ));
    }

    #[Route('/api/ferme/wikis/run/{action}', methods: ['POST'], options: ['acl' => ['@admins']])]
    public function run(Request $request, string $action): ApiResponse
    {
        if (($refused = $this->refuse()) !== null) {
            return $refused;
        }
        if (!in_array($action, self::ACTIONS, true)) {
            return new ApiResponse(['error' => 'unknown action ' . $action], Response::HTTP_BAD_REQUEST);
        }
        $folder = $this->askedFolder($request);
        if ($folder === null) {
            return new ApiResponse(['error' => _t('FERME_INVALID_FOLDER_NAME')], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new ApiResponse(['folder' => $folder] + $this->perform($action, $folder));
        } catch (\Throwable $failed) {
            return new ApiResponse(['folder' => $folder, 'status' => 'failed', 'error' => $failed->getMessage()], Response::HTTP_OK);
        }
    }

    #[Route('/api/ferme/wikis/delete', methods: ['POST'], options: ['acl' => ['@admins']])]
    public function deleteWikis(Request $request): ApiResponse
    {
        if (($refused = $this->refuse()) !== null) {
            return $refused;
        }
        $tags = array_values(array_filter(array_map('strval', (array)$request->request->all('tags')), static fn (string $tag): bool => $tag !== ''));

        return new ApiResponse(['results' => $this->getService(WikiRemover::class)->deleteMany($tags)]);
    }

    #[Route('/api/ferme/wikis/mail', methods: ['POST'], options: ['acl' => ['@admins']])]
    public function mail(Request $request): ApiResponse
    {
        if (($refused = $this->refuse()) !== null) {
            return $refused;
        }
        $tag = trim((string)$request->request->get('tag', ''));

        try {
            $address = $this->getService(FarmMailer::class)->sendToReferent($tag, (string)$request->request->get('subject', ''), (string)$request->request->get('body', ''));

            return new ApiResponse(['tag' => $tag, 'status' => 'sent', 'messages' => [_t('FERME_MAIL_SENT') . ' ' . $address]]);
        } catch (\Throwable $failed) {
            return new ApiResponse(['tag' => $tag, 'status' => 'failed', 'error' => $failed->getMessage()]);
        }
    }

    #[Route('/api/ferme/wikis/lifetime', methods: ['POST'], options: ['acl' => ['@admins']])]
    public function changeLifetime(Request $request): ApiResponse
    {
        if (($refused = $this->refuse()) !== null) {
            return $refused;
        }
        $tag = trim((string)$request->request->get('tag', ''));

        try {
            $lifetime = $this->getService(WikiLifetime::class);
            $entry = $lifetime->change($tag, (string)$request->request->get('kind', ''), new \DateTimeImmutable('today'));

            return new ApiResponse(['tag' => $tag, 'status' => 'changed', 'lifetime' => $lifetime->describe($entry, new \DateTimeImmutable('today'))]);
        } catch (\Throwable $failed) {
            return new ApiResponse(['tag' => $tag, 'status' => 'failed', 'error' => $failed->getMessage()]);
        }
    }

    #[Route('/api/ferme/wikis/search', methods: ['GET'], options: ['acl' => ['@admins']])]
    public function searchWikis(): ApiResponse
    {
        $this->denyAccessUnlessAdmin();

        try {
            return new ApiResponse($this->getService(WikiRepository::class)->searchOnServer());
        } catch (\Throwable $failed) {
            return new ApiResponse(['error' => $failed->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/ferme/wikis/import', methods: ['POST'], options: ['acl' => ['@admins']])]
    public function importWikis(Request $request): ApiResponse
    {
        if (($refused = $this->refuse()) !== null) {
            return $refused;
        }
        $folders = array_values(array_map('strval', (array)$request->request->all('folders')));
        $email = trim((string)$request->request->get('email', ''));

        try {
            return new ApiResponse(['imported' => $this->getService(WikiRepository::class)->importFolders($folders, $email !== '' ? $email : $this->getService(FarmConfig::class)->adminEmail())]);
        } catch (\Throwable $failed) {
            return new ApiResponse(['error' => $failed->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/ferme/wikis/detail', methods: ['GET'], options: ['acl' => ['@admins']])]
    public function detail(Request $request): Response
    {
        $this->denyAccessUnlessAdmin();
        $folder = trim((string)$request->query->get('folder', ''));
        if (!FarmConfig::isSafeName($folder, true)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }
        $presenter = $this->getService(StatsPresenter::class);
        $config = $this->getService(FarmConfig::class);
        $calendar = '';
        $spam = [];
        $error = null;

        try {
            $calendar = $presenter->calendar($this->getService(WikiStats::class)->daily($folder));
            $approvals = $this->getService(SpamApprovals::class);
            $cleaner = $this->getService(SpamCleaner::class);
            foreach ($cleaner->inspect($folder) as $page) {
                $spam[] = ['tag' => (string)$page['tag'], 'action' => (string)$page['action'], 'words' => (int)$page['words'], 'links' => (int)$page['links'], 'url' => $config->wikiUrl($folder) . '?' . rawurlencode((string)$page['tag'])];
            }
            $approved = array_keys($approvals->all($folder));
        } catch (WikiStatsException $failed) {
            $error = $failed->getReason();
            $approved = [];
        } catch (\Throwable $failed) {
            $error = $failed->getMessage();
            $approved = [];
        }
        $archives = $config->hasWiki($folder) ? $this->getService(WikiArchiver::class)->archives($folder) : [];

        return new Response($this->render('@ferme/wiki-detail.twig', [
            'folder' => $folder,
            'calendar' => $calendar,
            'calendarTitle' => $presenter->calendarTitle(),
            'spam' => $spam,
            'approved' => $approved,
            'archives' => array_map(fn (array $archive): array => $archive + [
                'size' => $presenter->size($archive['bytes']),
                'url' => $this->getService(UrlFormatter::class)->href('', 'api/ferme/wikis/archive', ['folder' => $folder, 'file' => $archive['file']], false),
            ], $archives),
            'stats' => $this->getService(WikiStatsStore::class)->read($folder),
            'error' => $error,
        ]));
    }

    #[Route('/api/ferme/wikis/approve-spam-page', methods: ['POST'], options: ['acl' => ['@admins']])]
    public function approveSpamPage(Request $request): ApiResponse
    {
        if (($refused = $this->refuse()) !== null) {
            return $refused;
        }
        $folder = $this->askedFolder($request);
        $tag = trim((string)$request->request->get('tag', ''));
        if ($folder === null || $tag === '') {
            return new ApiResponse(['error' => _t('FERME_INVALID_FOLDER_NAME')], Response::HTTP_BAD_REQUEST);
        }
        $approvals = $this->getService(SpamApprovals::class);

        try {
            if ($request->request->get('undo')) {
                $approvals->forget($folder, $tag);
            } else {
                $body = $this->getService(SpamCleaner::class)->bodyOf($folder, $tag);
                if ($body === null) {
                    return new ApiResponse(['error' => _t('FERME_SPAM_PAGE_GONE')], Response::HTTP_NOT_FOUND);
                }
                $approvals->approve($folder, $tag, $body);
            }
            $this->getService(StatsRefresher::class)->remeasure($folder, false);

            return new ApiResponse(['folder' => $folder, 'tag' => $tag, 'status' => 'done']);
        } catch (\Throwable $failed) {
            return new ApiResponse(['error' => $failed->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/ferme/wikis/archive', methods: ['GET'], options: ['acl' => ['@admins']])]
    public function downloadArchive(Request $request): Response
    {
        $this->denyAccessUnlessAdmin();
        $folder = trim((string)$request->query->get('folder', ''));
        $path = FarmConfig::isSafeName($folder, true) && $this->getService(FarmConfig::class)->hasWiki($folder)
            ? $this->getService(WikiArchiver::class)->pathOf($folder, (string)$request->query->get('file', ''))
            : null;
        if ($path === null) {
            return new Response(_t('FERME_ARCHIVE_NOT_FOUND'), Response::HTTP_NOT_FOUND);
        }

        return (new BinaryFileResponse($path))->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, str_replace('/', '-', $folder) . '-' . basename($path));
    }

    #[Route('/api/ferme/lifetime/renew', methods: ['GET', 'POST'], options: ['acl' => ['public']])]
    public function renewLifetime(Request $request): Response
    {
        $lifetime = $this->getService(WikiLifetime::class);
        $tag = trim((string)$request->get('id', ''));
        $token = trim((string)$request->get('token', ''));
        $today = new \DateTimeImmutable('today');
        $data = ['error' => null, 'done' => false, 'entry' => null, 'state' => null, 'token' => $token, 'label' => '', 'stats' => null];

        try {
            $entry = $lifetime->entry($tag);
            if (!$lifetime->tokenIsValid($entry, $token)) {
                throw new \RuntimeException(_t('FERME_LIFETIME_BAD_LINK'));
            }
            if ($request->isMethod('POST')) {
                $entry = $lifetime->renew($tag, $today);
                $data['done'] = true;
                $data['token'] = $lifetime->token($tag, (string)($entry[WikiLifetime::EXPIRES_AT] ?? ''));
            }
            $state = $lifetime->describe($entry, $today);
            $folder = (string)($entry['bf_dossier-wiki'] ?? '');
            $data = array_merge($data, [
                'entry' => $entry + ['title' => FarmMailer::titleOf($entry)],
                'state' => $state,
                'label' => $state === null ? '' : $lifetime->label($state['kind']),
                'stats' => $folder === '' ? null : $this->getService(WikiStatsStore::class)->read($folder),
            ]);
        } catch (\Throwable $failed) {
            $data['error'] = $failed->getMessage();
        }

        return new Response($this->getService(TemplateEngine::class)->renderPage($this->render('@ferme/lifetime-renew.twig', $data + [
            'action' => $this->getService(UrlFormatter::class)->href('', 'api/ferme/lifetime/renew'),
            'donateUrl' => $this->getService(FarmConfig::class)->text('yeswiki-farm-donate-url'),
        ])));
    }

    /** @return array<string, mixed> */
    private function perform(string $action, string $folder): array
    {
        return match ($action) {
            'migrate' => $this->getService(WikiMigrator::class)->migrate($folder),
            'refresh-stats' => $this->refreshStats($folder),
            'archive' => $this->archive($folder),
            'hibernate' => $this->getService(WikiHibernator::class)->hibernate($folder) + ['status' => 'hibernate'],
            'wake' => $this->getService(WikiHibernator::class)->wake($folder) + ['status' => 'running'],
            'admin-add' => $this->getService(FarmAdminAccount::class)->add($folder),
            'admin-remove' => $this->getService(FarmAdminAccount::class)->remove($folder),
            'clean-spam', 'clean-spam-dry' => $this->cleanSpam($folder, $action === 'clean-spam-dry'),
            default => ['status' => 'failed', 'error' => 'unknown action ' . $action],
        };
    }

    /** @return array<string, mixed> */
    private function refreshStats(string $folder): array
    {
        $result = $this->getService(StatsRefresher::class)->refresh($folder, ['force' => true]);
        $this->getService(StatsRefresher::class)->close();
        if ($result['failed'] !== null) {
            return ['status' => 'failed', 'error' => $result['failed']];
        }
        $stats = $this->getService(WikiStatsStore::class)->read($folder) ?? [];

        return ['status' => 'measured', 'messages' => [_t('FERME_STATS_REFRESHED', ['entries' => (int)($stats['entries'] ?? 0), 'pages' => (int)($stats['pages'] ?? 0), 'users' => (int)($stats['users'] ?? 0)])]];
    }

    /** @return array<string, mixed> */
    private function archive(string $folder): array
    {
        $made = $this->getService(WikiArchiver::class)->create($folder);

        return [
            'status' => 'archived',
            'file' => $made['file'],
            'size' => $this->getService(StatsPresenter::class)->size($made['bytes']),
            'url' => $this->getService(UrlFormatter::class)->href('', 'api/ferme/wikis/archive', ['folder' => $folder, 'file' => $made['file']], false),
        ];
    }

    /** @return array<string, mixed> */
    private function cleanSpam(string $folder, bool $dryRun): array
    {
        $report = $this->getService(SpamCleaner::class)->clean($folder, $dryRun);

        return [
            'status' => $dryRun ? 'planned' : 'cleaned',
            'messages' => [_t($dryRun ? 'FERME_CLEAN_WOULD' : 'FERME_CLEAN_DID', ['deleted' => $report['deleted'], 'stripped' => $report['stripped']])],
            'pages' => $report['pages'],
        ];
    }

    private function askedFolder(Request $request): ?string
    {
        $folder = trim((string)$request->request->get('folder', ''));

        return FarmConfig::isSafeName($folder, true) ? $folder : null;
    }

    /** Refuse whoever is no administrator or carries no valid token, and let the session go before the long work. */
    private function refuse(): ?ApiResponse
    {
        $this->denyAccessUnlessAdmin();

        try {
            $this->getService(CsrfTokenChecker::class)->checkToken('main', 'POST', 'csrf-token', false);
        } catch (TokenNotFoundException $refused) {
            return new ApiResponse(['error' => $refused->getMessage()], Response::HTTP_FORBIDDEN);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return null;
    }
}
