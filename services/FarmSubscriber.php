<?php

namespace YesWiki\Ferme\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use YesWiki\Kernel\Entity\Event;

/** What the farm does on the farm wiki's own events: its housekeeping, and an entry of the farm form going. */
class FarmSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ContainerInterface $services)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'maintenance.after' => 'onMaintenance',
            'entry.deleted' => 'onEntryDeleted',
        ];
    }

    /** Statistics, lifetimes and the migrations a new Program owes the wikis, on the wiki's housekeeping cadence. */
    public function onMaintenance(Event $event): void
    {
        try {
            $this->services->get(FarmMigrationWatch::class)->onMaintenance();
        } catch (\Throwable $throwable) {
            error_log('ferme: ' . $throwable->getMessage());
        }
        $this->services->get(StatsScheduler::class)->onMaintenance();
    }

    /** An entry of the farm form deleted by any path takes its wiki with it, archived first. */
    public function onEntryDeleted(Event $event): void
    {
        $data = $event->getData();
        $entry = is_array($data['data'] ?? null) ? $data['data'] : [];
        $config = $this->services->get(FarmConfig::class);
        if ((string)($entry['form_id'] ?? '') !== $config->farmId()) {
            return;
        }
        $remover = $this->services->get(WikiRemover::class);
        if ($remover->isDeleting((string)($entry['tag'] ?? ''))) {
            return;
        }

        try {
            $remover->afterEntryDeleted($entry);
        } catch (\Throwable $throwable) {
            error_log('ferme: ' . $throwable->getMessage());

            throw $throwable;
        }
    }
}
