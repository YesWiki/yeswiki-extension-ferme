<?php

namespace YesWiki\Ferme\Task;

use Psr\Container\ContainerInterface;
use YesWiki\Content\Entity\PageBody;
use YesWiki\Content\Entity\PageType;
use YesWiki\Content\Service\PageManager;
use YesWiki\Identity\Service\AclService;
use YesWiki\Kernel\Service\ProgramVersion;

/** What the farm asks of one of its wikis, done with that wiki's own Content services. */
class InstanceTasks
{
    public const WRITERS_AFTER_CLEANING = '@admins';
    private const CLEANABLE = [PageType::PAGE, PageType::COMMENT];

    public function __construct(private readonly ContainerInterface $services)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function run(string $task, array $arguments): array
    {
        $tag = trim((string)($arguments['tag'] ?? ''));

        return match ($task) {
            'ping' => $this->ping(),
            'delete-page' => $this->deletePage($tag),
            'strip-page' => $this->stripPage($tag, (string)($arguments['content'] ?? '')),
            'close-to-writers' => $this->closeToWriters($tag),
            'append-to-page' => $this->appendToPage($tag, (string)($arguments['content'] ?? '')),
            'clean' => $this->clean(is_array($arguments['pages'] ?? null) ? $arguments['pages'] : []),
            default => ['error' => 'unknown task ' . $task],
        };
    }

    /** @return array<string, mixed> */
    private function ping(): array
    {
        return ['version' => (new ProgramVersion())->version(), 'pages' => count($this->pages()->tagsOfType(PageType::PAGE))];
    }

    /**
     * Delete or strip each page the farm condemned, closing a stripped page to writers.
     *
     * @param array<mixed> $pages
     *
     * @return array<string, mixed>
     */
    private function clean(array $pages): array
    {
        $done = ['deleted' => 0, 'stripped' => 0];
        foreach ($pages as $page) {
            $tag = is_array($page) ? trim((string)($page['tag'] ?? '')) : '';
            if ($tag === '') {
                continue;
            }
            if (($page['action'] ?? '') === 'delete') {
                $done['deleted'] += $this->deletePage($tag)['deleted'] ? 1 : 0;
                continue;
            }
            if ($this->stripPage($tag, (string)($page['content'] ?? ''))['stripped']) {
                $this->closeToWriters($tag);
                $done['stripped']++;
            }
        }

        return $done;
    }

    /** @return array<string, mixed> */
    private function deletePage(string $tag): array
    {
        if (!$this->isCleanable($tag)) {
            return ['deleted' => false];
        }
        $this->pages()->deleteOrphaned($tag);

        return ['deleted' => true];
    }

    /** @return array<string, mixed> */
    private function stripPage(string $tag, string $content): array
    {
        $page = $this->isCleanable($tag) ? $this->pages()->getOne($tag, null, false, true) : null;
        if ($page === null) {
            return ['stripped' => false];
        }
        $body = is_array($page['body'] ?? null) ? $page['body'] : [];
        $body[PageBody::CONTENT] = $content;
        $this->pages()->save($tag, $body, (string)($page['parent'] ?? ''), true);

        return ['stripped' => true];
    }

    /** @return array<string, mixed> */
    private function closeToWriters(string $tag): array
    {
        if (!$this->isCleanable($tag)) {
            return ['closed' => false];
        }
        $this->services->get(AclService::class)->save($tag, 'write', self::WRITERS_AFTER_CLEANING);

        return ['closed' => true];
    }

    /** @return array<string, mixed> */
    private function appendToPage(string $tag, string $content): array
    {
        if ($tag === '' || $content === '') {
            return ['appended' => false];
        }
        $page = $this->pages()->getOne($tag, null, false, true);
        if ($page === null) {
            $this->pages()->save($tag, [PageBody::CONTENT => $content], '', true, null, PageType::PAGE);

            return ['appended' => true, 'created' => true];
        }
        $body = is_array($page['body'] ?? null) ? $page['body'] : [];
        $body[PageBody::CONTENT] = rtrim(PageBody::content($body)) . "\n" . $content;
        $this->pages()->save($tag, $body, (string)($page['parent'] ?? ''), true);

        return ['appended' => true, 'created' => false];
    }

    private function isCleanable(string $tag): bool
    {
        return $tag !== '' && in_array($this->pages()->typeOf($tag), self::CLEANABLE, true);
    }

    private function pages(): PageManager
    {
        return $this->services->get(PageManager::class);
    }
}
