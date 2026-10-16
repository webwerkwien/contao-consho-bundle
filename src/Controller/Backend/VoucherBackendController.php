<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Contao\BackendUser;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherBalanceTooLow;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherBookingType;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherCode;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger;

/**
 * "Gutschein ausstellen" and "Saldo korrigieren" (block Wertgutscheine,
 * WG13) — `key` actions of the module consho_voucher (config.php).
 *
 * Both go through VoucherLedger, never through the mask: a voucher is its
 * first booking, and a balance changes only with a booking and a reason. A
 * voucher issued here is a goodwill one — nothing was paid for it, so its
 * `paidAmount` is 0.
 *
 * The module right is asked here as well: a `key` action runs after
 * Backend::getBackendModule() checked it, but this is the one place a request
 * of its own would reach without that (the lesson of SubscriptionPause, B2).
 */
#[Autoconfigure(public: true)]
final class VoucherBackendController
{
    public const MODULE = 'consho_voucher';

    public function __construct(
        private readonly VoucherLedger $ledger,
        private readonly ShopCurrencies $currencies,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly MoneyFormatter $money,
    ) {
    }

    public function issue(DataContainer $dc): string
    {
        $this->denyUnlessAllowed();
        $this->loadLanguage();

        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_voucher'] ?? [];
        $targets = $this->targets();

        if (null !== $request && $request->isMethod('POST')) {
            $post = $request->request;
            $target = $targets[$post->getString('consho_voucher_target')] ?? null;
            $value = self::amount($post->getString('consho_voucher_value'));
            $validUntil = self::day($post->getString('consho_voucher_valid_until'));

            if (null === $target || null === $value || !BigDecimal::of($value)->isPositive() || false === $validUntil) {
                $this->message()->addError((string) ($lang['issueInvalid'] ?? 'Please check the entries.'));

                throw new RedirectResponseException($request->getUri());
            }

            $id = $this->ledger->issue($target['shop'], $target['currency'], $value, '0', $validUntil, 'manual', time(), user: $this->userId(), note: trim($post->getString('consho_voucher_note')));
            $code = (string) $this->connection->fetchOne('SELECT code FROM tl_consho_voucher WHERE id = ?', [$id]);
            $this->message()->addConfirmation(\sprintf((string) ($lang['issued'] ?? 'Voucher %s issued.'), VoucherCode::format($code)));

            throw new RedirectResponseException($this->router->generate('contao_backend', ['do' => self::MODULE]));
        }

        return $this->twig->render('@Contao/backend/consho_voucher_issue.html.twig', [
            'messages' => (string) $this->message()->generate(),
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
            'targets' => array_map(static fn (array $target): string => $target['label'], $targets),
        ]);
    }

    /**
     * A correction of the balance, up or down, with the reason as note.
     */
    public function correct(DataContainer $dc): string
    {
        $id = 'tl_consho_voucher' === $dc->table ? (int) $dc->id : 0;
        $voucher = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_voucher WHERE id = ?', [$id]) : false;

        if (false === $voucher) {
            throw new NotFoundHttpException();
        }

        $this->denyUnlessAllowed();
        $this->loadLanguage();

        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_voucher'] ?? [];

        if (null !== $request && $request->isMethod('POST')) {
            $post = $request->request;
            $amount = self::amount($post->getString('consho_voucher_amount'));
            $note = trim($post->getString('consho_voucher_note'));

            if (null === $amount || BigDecimal::of($amount)->isZero() || '' === $note) {
                $this->message()->addError((string) ($lang['correctInvalid'] ?? 'Please enter an amount and a reason.'));
            } else {
                try {
                    $after = $this->ledger->book($id, $amount, VoucherBookingType::Manual, time(), user: $this->userId(), note: $note);
                    $this->message()->addConfirmation(\sprintf((string) ($lang['corrected'] ?? 'New balance: %s'), $this->money($after, (string) $voucher['currency'])));
                } catch (VoucherBalanceTooLow) {
                    $this->message()->addError((string) ($lang['correctTooLow'] ?? 'The balance would fall below zero.'));
                }
            }

            throw new RedirectResponseException($request->getUri());
        }

        return $this->twig->render('@Contao/backend/consho_voucher_correct.html.twig', [
            'messages' => (string) $this->message()->generate(),
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
            'code' => VoucherCode::format((string) $voucher['code']),
            'balance' => $this->money((string) $voucher['balance'], (string) $voucher['currency']),
        ]);
    }

    /**
     * An amount as typed in the back end — "12,50", "12.50", "-5" — in the
     * stored shape, or null when it is not one. Two decimals at most: money,
     * not a quantity.
     *
     * @internal public for the test only
     */
    public static function amount(string $typed): string|null
    {
        $typed = str_replace([' ', "\u{a0}"], '', trim($typed));

        if (1 !== preg_match('/^-?\d{1,9}([.,]\d{1,2})?$/', $typed)) {
            return null;
        }

        try {
            return (string) BigDecimal::of(str_replace(',', '.', $typed))->toScale(4);
        } catch (MathException) {
            return null;
        }
    }

    /**
     * The day of an `<input type="date">` as Contao stores a date — midnight
     * in the installation's zone; null for an empty field (unlimited, WG10),
     * false for anything else.
     *
     * @internal public for the test only
     */
    public static function day(string $typed): int|false|null
    {
        $typed = trim($typed);

        if ('' === $typed) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $typed, new \DateTimeZone(date_default_timezone_get()));

        return false === $day || $day->format('Y-m-d') !== $typed ? false : $day->getTimestamp();
    }

    /**
     * Every shop with every currency it sells in — a voucher holds one
     * currency (WG9), and only one the shop takes.
     *
     * @return array<string, array{shop: int, currency: string, label: string}>
     */
    private function targets(): array
    {
        $targets = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title FROM tl_consho_shop ORDER BY title') as $shop) {
            foreach ($this->currencies->of((int) $shop['id']) as $currency) {
                $targets[$shop['id'].'|'.$currency] = ['shop' => (int) $shop['id'], 'currency' => $currency, 'label' => $shop['title'].' · '.$currency];
            }
        }

        return $targets;
    }

    private function money(string $amount, string $currency): string
    {
        return $this->money->format($amount, $currency, (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de'));
    }

    private function denyUnlessAllowed(): void
    {
        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, self::MODULE)) {
            throw new AccessDeniedHttpException();
        }
    }

    private function userId(): int
    {
        $user = $this->security->getUser();

        return $user instanceof BackendUser ? (int) $user->id : 0;
    }

    private function loadLanguage(): void
    {
        $this->framework->getAdapter(System::class)->loadLanguageFile('tl_consho_voucher');
    }

    /**
     * @return Adapter<Message>
     */
    private function message(): Adapter
    {
        return $this->framework->getAdapter(Message::class);
    }
}
