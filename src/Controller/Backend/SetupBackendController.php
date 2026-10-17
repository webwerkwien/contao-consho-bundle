<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Contao\StringUtil;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\EventListener\BackendSystemMessagesListener;
use Webwerkwien\ContaoConshoBundle\Setup\CacheProbe;
use Webwerkwien\ContaoConshoBundle\Setup\NumberRangeSetup;
use Webwerkwien\ContaoConshoBundle\Setup\PageCacheSetup;
use Webwerkwien\ContaoConshoBundle\Setup\ShopPageSetup;
use Webwerkwien\ContaoConshoBundle\Setup\SetupGuide;
use Webwerkwien\ContaoConshoBundle\Setup\SetupStep;
use Webwerkwien\ContaoConshoBundle\Setup\ShopSetup;
use Webwerkwien\ContaoConshoBundle\Setup\StepState;

/**
 * The page "Einrichtung" (block I, I5): per shop the steps up to the first
 * sale, a view of the dashboard like the tax report.
 *
 * The steps link into the masks that put them right (I3a, I1a): the shop
 * configuration, the start point and the methods carry save callbacks that a
 * write from here would skip.
 */
#[Autoconfigure(public: true)]
final class SetupBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_setup.html.twig';

    /** A view of the dashboard, see TaxReportBackendController::ROUTE. */
    public const ROUTE = ['do' => 'consho_dashboard', 'view' => 'setup'];

    public const FORM = 'consho_setup';

    /** Seconds "Jetzt messen" may take in one request. */
    public const PROBE_BUDGET = 15;

    public function __construct(
        private readonly SetupGuide $guide,
        private readonly BackendSystemMessagesListener $findings,
        private readonly NumberRangeSetup $ranges,
        private readonly ShopPageSetup $pages,
        private readonly PageCacheSetup $cache,
        private readonly CacheProbe $probe,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly Security|null $security = null,
        #[Autowire(service: 'contao.csrf.token_manager')]
        private readonly ContaoCsrfTokenManager|null $tokens = null,
        // Seconds; a parameter so a test can reach the branch past it.
        private readonly float $probeBudget = self::PROBE_BUDGET,
    ) {
    }

    public function __invoke(): string
    {
        $this->framework->initialize();

        $lang = $GLOBALS['TL_LANG']['CONSHO']['setup'] ?? [];
        // The steps read every start point of a shop, published or not (N1) —
        // "ready" stays with the published ones, as the checkout decides it.
        $issues = $this->findings->issuesForSetup();
        $live = $this->findings->issues();
        $now = time();

        $this->handleAction($issues, $lang, $now);

        // "Jetzt messen" is a link, not a form: it changes nothing. Only for
        // administrators all the same — it sends requests from the server.
        $probed = $this->isAdmin() ? $this->requests->getCurrentRequest()?->query->getInt('probe') ?? 0 : 0;

        // Finding and shop the start page reports too: there a published start
        // point is involved. What only the setup page sees concerns unpublished
        // ones alone, and the start page's sentence ("… der Checkout ist
        // gesperrt") is not true for it (acceptance N1 on c5).
        $published = [];

        foreach ($live as $issue) {
            $published[$issue->finding->value.'|'.$issue->shopId] = true;
        }

        $shops = [];

        foreach ($this->guide->shops() as $shopId => $title) {
            $setup = $this->guide->forShop($shopId, $title, $issues, $now, $live);
            $shops[] = [
                ...$this->shop($setup, $issues, $lang, $published),
                'cache' => $this->cache($setup, $probed === $shopId, $lang),
            ];
        }

        return $this->twig->render(self::TEMPLATE, [
            'lang' => $lang,
            'shops' => $shops,
            'showShopTitles' => \count($shops) > 1,
            // act=create without rt ends in Contao's "invalid request token" page.
            'createShopUrl' => $this->url(['do' => 'consho_shop', 'act' => 'create'] + (null === $this->tokens ? [] : ['rt' => $this->tokens->getDefaultTokenValue()])),
            'dashboardUrl' => $this->url(['do' => 'consho_dashboard']),
            'isAdmin' => $this->isAdmin(),
            'requestToken' => $this->tokens?->getDefaultTokenValue() ?? '',
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
        ]);
    }

    /**
     * A button of this page: POST, so Contao checks the request token
     * (RequestTokenListener) — then a message and a redirect, so a reload
     * does not post again. Administrators only (spec I, Auslegung).
     *
     * @param list<ConfigurationIssue> $issues
     * @param array<string, mixed>     $lang
     */
    private function handleAction(array $issues, array $lang, int $now): void
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request || !$request->isMethod('POST') || self::FORM !== $request->request->getString('FORM_SUBMIT')) {
            return;
        }

        if (!$this->isAdmin()) {
            throw new AccessDeniedHttpException();
        }

        $target = $request->request->getInt('consho_setup_target');

        $shopId = match ($request->request->getString('consho_setup_action')) {
            'ranges' => $this->createRanges($target, $issues, $lang, $now),
            'pages' => $this->createPages($target, $issues, $lang, $now),
            'cache' => $this->enableCache($target, $lang, $now),
            default => throw new BadRequestHttpException(),
        };

        // The anchor of the shop: with several, the page would otherwise open
        // at the top, away from the message's subject.
        throw new RedirectResponseException($this->url(self::ROUTE).'#shop-'.$shopId);
    }

    /**
     * @param array<string, mixed> $lang
     *
     * @return int the shop of the start point
     */
    private function enableCache(int $rootId, array $lang, int $now): int
    {
        $root = $this->guide->root($rootId, $now);

        if (null === $root) {
            throw new BadRequestHttpException();
        }

        $this->cache->enable($rootId, $now);
        $this->framework->getAdapter(Message::class)->addConfirmation(\sprintf(
            (string) ($lang['cacheEnabled'] ?? '%s'),
            StringUtil::specialchars(StringUtil::decodeEntities($root['title'])),
        ));

        return $root['shop'];
    }

    /**
     * The page cache per start point (I4b): the setting at once, the
     * measurement on request. Never part of "ready".
     *
     * @param array<string, mixed> $lang
     *
     * @return array<string, mixed>|null
     */
    private function cache(ShopSetup $setup, bool $probe, array $lang): array|null
    {
        if ([] === $setup->roots) {
            return null;
        }

        $results = $lang['probeResults'] ?? [];
        $roots = [];

        // Review I, L8: a host that cannot reach itself costs the full timeout
        // per request — with several start points that outlasts a proxy's
        // patience. Past the budget the rest is said, not measured.
        $deadline = microtime(true) + $this->probeBudget;

        foreach ($setup->roots as $root) {
            $state = $this->cache->state($root['id']);
            $title = StringUtil::decodeEntities($root['title']);
            $measured = [];

            if ($probe) {
                foreach ($this->cache->targets($root['id']) as $target) {
                    $outcome = microtime(true) < $deadline
                        ? $this->probe->measure($target['url'])
                        : ['result' => CacheProbe::FAILED, 'detail' => (string) ($lang['probeBudget'] ?? 'budget')];
                    $measured[] = [
                        'title' => StringUtil::decodeEntities($target['title']),
                        'url' => $target['url'],
                        'result' => $outcome['result'],
                        'text' => \sprintf((string) ($results[$outcome['result']] ?? $outcome['result'].' (%s)'), $outcome['detail']),
                    ];
                }
            }

            $roots[] = [
                'id' => $root['id'],
                'text' => $state['enabled']
                    ? \sprintf((string) ($lang['cacheOn'] ?? '%s: %d'), $title, $state['seconds'])
                    : \sprintf((string) ($lang['cacheOff'] ?? '%s'), $title),
                'canEnable' => !$state['enabled'] && $this->isAdmin(),
                'measured' => $measured,
            ];
        }

        return [
            'roots' => $roots,
            'probed' => $probe,
            'probeUrl' => $this->isAdmin() ? $this->url([...self::ROUTE, 'probe' => $setup->shopId]).'#shop-'.$setup->shopId : '',
        ];
    }

    /**
     * @param list<ConfigurationIssue> $issues
     * @param array<string, mixed>     $lang
     *
     * @return int the shop of the start point
     */
    private function createPages(int $rootId, array $issues, array $lang, int $now): int
    {
        $root = $this->guide->root($rootId, $now, assigned: true);

        if (null === $root) {
            throw new BadRequestHttpException();
        }

        $user = $this->security?->getUser();
        $titles = $this->pages->create($rootId, $this->pages->missing($rootId, $root['shop'], $issues), $user instanceof BackendUser ? (int) $user->id : 0, $now);
        $message = $this->framework->getAdapter(Message::class);

        if ([] === $titles) {
            $message->addInfo((string) ($lang['pagesNone'] ?? 'pagesNone'));
        } else {
            $message->addConfirmation(\sprintf(
                (string) ($lang['pagesCreated'] ?? '%s: %s'),
                StringUtil::specialchars(StringUtil::decodeEntities($root['title'])),
                StringUtil::specialchars(implode(', ', $titles)),
            ));
        }

        return $root['shop'];
    }

    /**
     * @param list<ConfigurationIssue> $issues
     * @param array<string, mixed>     $lang
     */
    private function createRanges(int $shopId, array $issues, array $lang, int $now): int
    {
        $created = $this->ranges->create($shopId, NumberRangeSetup::missing($shopId, $issues), $now);
        $message = $this->framework->getAdapter(Message::class);

        if ([] === $created) {
            $message->addInfo((string) ($lang['rangesNone'] ?? 'rangesNone'));

            return $shopId;
        }

        $names = [];

        foreach ($created as $type => $prefix) {
            $names[] = \sprintf('%s (%s)', $GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes'][$type] ?? $type, $prefix);
        }

        $message->addConfirmation(\sprintf((string) ($lang['rangesCreated'] ?? '%s'), StringUtil::specialchars(implode(', ', $names))));

        return $shopId;
    }

    /**
     * @param list<ConfigurationIssue> $issues
     * @param array<string, mixed>     $lang
     * @param array<string, true>      $published "finding|shop" the start page reports as well
     *
     * @return array<string, mixed>
     */
    private function shop(ShopSetup $setup, array $issues, array $lang, array $published = []): array
    {
        $note = (string) ($lang['unpublishedOnly'] ?? 'Only start points that are not published yet are concerned; this blocks nothing.');
        $sentence = fn (ConfigurationIssue $issue): string => $this->findings->sentence($issue)
            .(isset($published[$issue->finding->value.'|'.$issue->shopId]) ? '' : ' '.StringUtil::specialchars($note));

        $steps = [];

        foreach ($setup->steps as $index => $state) {
            $text = $lang['steps'][$state->step->value] ?? [$state->step->value, ''];

            $steps[] = [
                'key' => $state->step->value,
                'number' => $index + 1,
                'title' => $text[0],
                'help' => $text[1],
                'status' => $state->status,
                // Only who may put a finding right reads it (H2), as on the
                // dashboard; the status stays for everyone.
                'sentences' => array_map(
                    $sentence,
                    array_values(array_filter($state->issues, $this->findings->maySee(...))),
                ),
                'links' => $this->links($state, $setup, $lang['links'] ?? []),
                'actions' => $this->isAdmin() ? $this->actions($state->step, $setup, $issues, $lang) : [],
            ];
        }

        return [
            'id' => $setup->shopId,
            'title' => StringUtil::decodeEntities($setup->title),
            'ready' => $setup->ready,
            'roots' => $setup->roots,
            'steps' => $steps,
            'others' => array_map(
                $this->findings->sentence(...),
                array_values(array_filter($setup->others, fn (ConfigurationIssue $issue): bool => $this->findings->maySee($issue))),
            ),
        ];
    }

    /**
     * The buttons of a step — only where there is something to create, so a
     * button never answers "nothing was missing".
     *
     * @param list<ConfigurationIssue> $issues
     * @param array<string, mixed>     $lang
     *
     * @return list<array{name: string, target: int, label: string}>
     */
    private function actions(SetupStep $step, ShopSetup $setup, array $issues, array $lang): array
    {
        return match ($step) {
            SetupStep::NumberRanges => [] === NumberRangeSetup::missing($setup->shopId, $issues)
                ? []
                : [['name' => 'ranges', 'target' => $setup->shopId, 'label' => (string) ($lang['createRanges'] ?? 'createRanges')]],
            // One button per start point that lacks a page; with several
            // start points the button names its own.
            SetupStep::ShopPages => array_values(array_map(
                static fn (array $root): array => [
                    'name' => 'pages',
                    'target' => $root['id'],
                    'label' => (string) ($lang['createPages'] ?? 'createPages').(\count($setup->assigned) > 1 ? ' — '.StringUtil::decodeEntities($root['title']) : ''),
                ],
                // Unpublished ones too (N1): a shop in preparation has nothing else.
                array_filter($setup->assigned, fn (array $root): bool => [] !== $this->pages->missing($root['id'], $setup->shopId, $issues)),
            )),
            default => [],
        };
    }

    /**
     * Where each step is put right. Shown with every step, done or not — a
     * finished step is also where one goes to change it.
     *
     * @param array<string, string> $lang
     *
     * @return list<array{label: string, url: string}>
     */
    private function links(StepState $state, ShopSetup $setup, array $lang): array
    {
        $shopEdit = ['label' => $lang['shopEdit'] ?? 'Shop-Konfiguration bearbeiten', 'url' => $this->url(['do' => 'consho_shop', 'act' => 'edit', 'id' => $setup->shopId])];
        $pages = ['label' => $lang['pages'] ?? 'Seitenstruktur', 'url' => $this->url(['do' => 'page'])];

        $rootEdits = array_map(
            fn (array $root): array => [
                // Decoded: Contao stores brackets encoded, and Twig would escape
                // the ampersand — "Shop &#40;DE&#41;" on screen (review I, L4).
                'label' => \sprintf($lang['rootEdit'] ?? 'Startpunkt „%s" bearbeiten', StringUtil::decodeEntities($root['title'])),
                'url' => $this->url(['do' => 'page', 'act' => 'edit', 'id' => $root['id']]),
            ],
            // Unpublished ones too (N1): the findings name them, and publishing
            // one is itself a step of the setup.
            $setup->assigned,
        );

        return match ($state->step) {
            SetupStep::Shop => [] === $rootEdits ? [$pages] : $rootEdits,
            SetupStep::Basics => [$shopEdit],
            SetupStep::Mail => [['label' => $lang['mail'] ?? 'Mailversand', 'url' => $this->url(['do' => 'consho_mail'])], $shopEdit],
            SetupStep::Shipping => [['label' => $lang['shipping'] ?? 'Versandarten', 'url' => $this->url(['do' => 'consho_shipping'])], $shopEdit],
            SetupStep::Payment => [['label' => $lang['payment'] ?? 'Zahlungsarten', 'url' => $this->url(['do' => 'consho_payment'])], $shopEdit],
            SetupStep::NumberRanges => [['label' => $lang['numberRanges'] ?? 'Nummernkreise', 'url' => $this->url(['do' => 'consho_shop', 'table' => 'tl_consho_number_range', 'id' => $setup->shopId])]],
            SetupStep::ShopPages => [$pages, ...$rootEdits],
            SetupStep::LegalTexts => [] === $rootEdits ? [$pages] : $rootEdits,
        };
    }

    /**
     * @param array<string, int|string> $query
     */
    private function url(array $query): string
    {
        return $this->router->generate('contao_backend', $query);
    }

    private function isAdmin(): bool
    {
        return null === $this->security || $this->security->isGranted('ROLE_ADMIN');
    }
}
