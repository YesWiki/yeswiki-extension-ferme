<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Kernel\Service\UrlFormatter;

/** What the wikis screen draws: one page of rows, the summary above it and the state of its filters, read from the address. */
class FarmTable
{
    public const PAGE_SIZE = 50;
    public const SORT_WAY = ['title' => 'asc', 'referent' => 'asc', 'lastActivity' => 'desc', 'activity' => 'desc', 'entries' => 'desc', 'pages' => 'desc', 'users' => 'desc', 'forms' => 'desc', 'diskBytes' => 'desc'];
    public const CHIPS = [
        'toUpdate' => ['label' => 'FERME_CHIP_TO_UPDATE', 'kind' => 'danger', 'icon' => 'refresh'],
        'dormant' => ['label' => 'FERME_CHIP_DORMANT', 'kind' => 'default', 'icon' => 'moon'],
        'neverEdited' => ['label' => 'FERME_CHIP_UNCHANGED', 'kind' => 'default', 'icon' => 'seedling'],
        'heavyArchives' => ['label' => 'FERME_CHIP_HEAVY_ARCHIVES', 'kind' => 'warning', 'icon' => 'box'],
        'suspect' => ['label' => 'FERME_CHIP_SUSPECT', 'kind' => 'danger', 'icon' => 'ban'],
        'spammed' => ['label' => 'FERME_CHIP_SPAMMED', 'kind' => 'warning', 'icon' => 'link'],
        'failed' => ['label' => 'FERME_CHIP_FAILED', 'kind' => 'danger', 'icon' => 'alert-triangle'],
        'unmeasured' => ['label' => 'FERME_CHIP_UNMEASURED', 'kind' => 'default', 'icon' => 'help'],
        'hibernating' => ['label' => 'FERME_CHIP_HIBERNATING', 'kind' => 'default', 'icon' => 'moon'],
    ];
    public const LIFETIME_CHIPS = [
        'short' => ['label' => 'FERME_LIFETIME_SHORT', 'icon' => 'stopwatch'],
        'long' => ['label' => 'FERME_LIFETIME_LONG', 'icon' => 'calendar'],
        'permanent' => ['label' => 'FERME_LIFETIME_PERMANENT', 'icon' => 'repeat'],
    ];
    public const STATE_CHIPS = [
        'ongoing' => ['label' => 'FERME_CHIP_ONGOING', 'kind' => 'info', 'icon' => 'hourglass'],
        'expiring' => ['label' => 'FERME_CHIP_EXPIRING', 'kind' => 'warning', 'icon' => 'clock'],
        'archived' => ['label' => 'FERME_CHIP_ARCHIVED', 'kind' => 'default', 'icon' => 'box'],
    ];

    public function __construct(
        private readonly WikiRepository $repository,
        private readonly StatsPresenter $presenter,
        private readonly WikiLifetime $lifetime,
        private readonly UrlFormatter $urlFormatter,
        private readonly FarmConfig $config
    ) {
    }

    /**
     * @param array<string, mixed> $query the address's parameters
     *
     * @return array<string, mixed>
     */
    public function view(array $query): array
    {
        $state = $this->state($query);
        $page = $this->repository->getPaginated(($state['page'] - 1) * self::PAGE_SIZE, self::PAGE_SIZE, $state['search'], $state['sort'], $state['way'], $state['filter'], $state['lifetime']);
        $pages = max(1, (int)ceil($page['filtered'] / self::PAGE_SIZE));

        return [
            'state' => $state,
            'rows' => array_map(fn (array $fiche): array => $this->row($fiche), $page['fiches']),
            'total' => $page['total'],
            'filtered' => $page['filtered'],
            'counts' => $page['counts'],
            'totals' => $page['totals'] + ['disk' => $this->presenter->size((int)($page['totals']['diskBytes'] ?? 0))],
            'chips' => $this->chips(self::CHIPS, $page['counts'], $state, 'filter'),
            'lifetimeChips' => $this->lifetime->isEnabled() ? $this->chips(self::LIFETIME_CHIPS, $page['counts'], $state, 'lifetime') : [],
            'stateChips' => $this->lifetime->isEnabled() ? $this->chips(self::STATE_CHIPS, $page['counts'], $state, 'filter') : [],
            'sorts' => array_keys(self::SORT_WAY),
            'pages' => $pages,
            'pageLinks' => $pages > 1 ? array_map(fn (int $number): array => ['number' => $number, 'url' => $this->link($state, ['page' => $number]), 'current' => $number === $state['page']], range(1, $pages)) : [],
            'clearUrl' => $this->link($state, ['filter' => '', 'lifetime' => '', 'search' => '', 'page' => 1]),
            'lifetimeEnabled' => $this->lifetime->isEnabled(),
            'lifetimeKinds' => array_map(fn (string $kind): array => ['kind' => $kind, 'label' => $this->lifetime->label($kind)], [WikiLifetime::SHORT, WikiLifetime::LONG, WikiLifetime::PERMANENT]),
            'farmAdmin' => $this->config->text('yeswiki-farm-admin-name') !== '' && $this->config->text('yeswiki-farm-admin-pass') !== '',
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{search: string, filter: string, lifetime: string, sort: string, way: string, page: int}
     */
    public function state(array $query): array
    {
        $sort = (string)($query['ferme_sort'] ?? 'title');
        $sort = array_key_exists($sort, self::SORT_WAY) ? $sort : 'title';
        $way = (string)($query['ferme_way'] ?? '');
        $filter = (string)($query['ferme_filter'] ?? '');
        $lifetime = (string)($query['ferme_lifetime'] ?? '');

        return [
            'search' => trim((string)($query['ferme_search'] ?? '')),
            'filter' => in_array($filter, FarmDashboard::FILTERS, true) ? $filter : '',
            'lifetime' => in_array($lifetime, FarmDashboard::LIFETIMES, true) ? $lifetime : '',
            'sort' => $sort,
            'way' => in_array($way, ['asc', 'desc'], true) ? $way : self::SORT_WAY[$sort],
            'page' => max(1, (int)($query['ferme_page'] ?? 1)),
        ];
    }

    /**
     * The address of the screen with some of its state changed.
     *
     * @param array{search: string, filter: string, lifetime: string, sort: string, way: string, page: int} $state
     * @param array<string, string|int>                                                                     $changes
     */
    public function link(array $state, array $changes): string
    {
        $merged = array_merge($state, $changes);
        $params = array_filter([
            'ferme_search' => (string)$merged['search'],
            'ferme_filter' => (string)$merged['filter'],
            'ferme_lifetime' => (string)$merged['lifetime'],
            'ferme_sort' => $merged['sort'] === 'title' ? '' : (string)$merged['sort'],
            'ferme_way' => $merged['way'] === self::SORT_WAY[(string)$merged['sort']] ? '' : (string)$merged['way'],
            'ferme_page' => (int)$merged['page'] > 1 ? (string)$merged['page'] : '',
        ], static fn (string $value): bool => $value !== '');

        return $this->urlFormatter->href('', null, $params, false);
    }

    /**
     * @param array<string, array<string, string>>                                                          $chips
     * @param array<string, int>                                                                            $counts
     * @param array{search: string, filter: string, lifetime: string, sort: string, way: string, page: int} $state
     *
     * @return list<array<string, mixed>>
     */
    private function chips(array $chips, array $counts, array $state, string $dimension): array
    {
        $drawn = [];
        foreach ($chips as $key => $chip) {
            $count = (int)($counts[$key] ?? 0);
            $active = $state[$dimension] === $key;
            if ($count === 0 && !$active) {
                continue;
            }
            $drawn[] = [
                'key' => $key,
                'label' => _t($chip['label']),
                'kind' => $chip['kind'] ?? 'default',
                'icon' => $chip['icon'],
                'count' => $count,
                'active' => $active,
                'url' => $this->link($state, [$dimension => $active ? '' : $key, 'page' => 1]),
            ];
        }

        return $drawn;
    }

    /**
     * @param array<string, mixed> $fiche
     *
     * @return array<string, mixed>
     */
    private function row(array $fiche): array
    {
        $tag = (string)($fiche['tag'] ?? '');
        $status = (string)($fiche['status'] ?? '');
        $state = is_array($fiche['lifetimeState'] ?? null) ? $fiche['lifetimeState'] : null;

        return [
            'tag' => $tag,
            'folder' => (string)($fiche['bf_dossier-wiki'] ?? ''),
            'title' => FarmMailer::titleOf($fiche),
            'description' => (string)($fiche['bf_description'] ?? ''),
            'url' => (string)($fiche['url'] ?? ''),
            'referent' => (string)($fiche['bf_referent'] ?? ''),
            'mail' => (string)($fiche['bf_mail'] ?? ''),
            'admin' => is_array($fiche['admin'] ?? null) ? $fiche['admin'] : null,
            'version' => is_array($fiche['version'] ?? null) ? $fiche['version'] : null,
            'viewUrl' => $this->urlFormatter->href('', $tag, null, false),
            'editUrl' => $this->urlFormatter->href('edit', $tag, null, false),
            'error' => isset($fiche['error']) ? (string)$fiche['error'] : null,
            'status' => ['value' => $status, 'label' => WikiHibernator::label($status), 'asleep' => WikiHibernator::isAsleep($status)],
            'stats' => $this->stats(is_array($fiche['stats'] ?? null) ? $fiche['stats'] : null),
            'lifetime' => $state === null ? null : $state + [
                'label' => $this->lifetime->label((string)$state['kind']),
                'expiresLabel' => $state['expiresAt'] === '' ? '' : WelcomeMailer::day((string)$state['expiresAt']),
                'purgeLabel' => $state['purgeAt'] === '' ? '' : WelcomeMailer::day((string)$state['purgeAt']),
            ],
            'problems' => $this->problems(is_array($fiche['problems'] ?? null) ? $fiche['problems'] : []),
        ];
    }

    /**
     * @param array<string, mixed>|null $stats
     *
     * @return array<string, mixed>|null
     */
    private function stats(?array $stats): ?array
    {
        if ($stats === null) {
            return null;
        }
        $disk = (int)($stats['diskBytes'] ?? 0);

        return [
            'users' => (int)($stats['users'] ?? 0),
            'forms' => (int)($stats['forms'] ?? 0),
            'entries' => (int)($stats['entries'] ?? 0),
            'pages' => (int)($stats['pages'] ?? 0),
            'files' => (int)($stats['files'] ?? 0),
            'disk' => $this->presenter->size($disk),
            'diskDetail' => _t('FERME_STATS_DISK_DETAIL', [
                'files' => $this->presenter->size((int)($stats['filesBytes'] ?? 0)),
                'custom' => $this->presenter->size((int)($stats['customBytes'] ?? 0)),
                'private' => $this->presenter->size((int)($stats['privateBytes'] ?? 0)),
            ]),
            'spamPages' => (int)($stats['spamPages'] ?? 0),
            'spamHosts' => (string)($stats['spamHosts'] ?? ''),
            'spammed' => !empty($stats['spammed']),
            'spamApproved' => (int)($stats['approvedPages'] ?? 0),
            'heavyArchives' => !empty($stats['heavyArchives']),
            'dormant' => !empty($stats['dormant']),
            'suspect' => !empty($stats['suspect']),
            'suspectWhy' => array_map(static fn (string $reason): string => _t('FERME_SPAM_' . strtoupper($reason)), array_map('strval', (array)($stats['suspectWhy'] ?? []))),
            'toUpdate' => !empty($stats['toUpdate']),
            'failed' => !empty($stats['failed']),
            'error' => isset($stats['error']) ? (string)$stats['error'] : null,
            'neverEdited' => !empty($stats['neverEdited']),
            'installedAge' => $this->presenter->age(isset($stats['firstActivity']) ? (string)$stats['firstActivity'] : null),
            'lastActivityAge' => $this->presenter->age(isset($stats['lastActivity']) ? (string)$stats['lastActivity'] : null),
            'computedAge' => $this->presenter->age(isset($stats['computedAt']) ? (string)$stats['computedAt'] : null),
            'sparkline' => $this->presenter->sparkline(array_map('intval', (array)($stats['activity'] ?? []))),
        ];
    }

    /**
     * @param array<string, mixed> $problems
     *
     * @return list<array{kind: string, label: string}>
     */
    private function problems(array $problems): array
    {
        $found = [];
        foreach (['noFolder' => 'FERME_PROBLEM_NO_FOLDER', 'missingWiki' => 'FERME_PROBLEM_MISSING_WIKI', 'duplicateFolder' => 'FERME_PROBLEM_DUPLICATE_FOLDER'] as $problem => $key) {
            if (!empty($problems[$problem])) {
                $found[] = ['kind' => $problem, 'label' => _t($key)];
            }
        }

        return $found;
    }
}
