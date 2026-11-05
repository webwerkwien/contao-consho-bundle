<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\DeliveryReportMessages;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * A delivery in one mask: what is in the parcel, and whether the customer
 * hears of it now.
 *
 * Until now a partial delivery took three places — the delivery, its items
 * in a list of their own, the report button in the delivery list. The mask
 * of a delivery not reported yet now shows:
 *
 *  - "Lieferung vollständig", ticked: everything still available goes in
 *    (DeliveryContents), as a new delivery is prefilled anyway. Unticked,
 *    the available items appear with a quantity each; 0 leaves one out.
 *    The item list stays, for corrections.
 *  - "Als versandt melden und Kunden verständigen", not ticked: saving
 *    reports the delivery right away (DeliveryReported — mail, lock,
 *    documents, status). Without it the delivery stays a draft and is
 *    reported later with the button in the list.
 *
 * Neither field has a column. They are drawn by input_field callbacks and
 * read from the request on submit, only in the single edit mask
 * (`act=edit`) of a delivery not reported yet: a reported delivery is
 * locked, and "Mehrere bearbeiten" names its fields per record.
 */
final class DeliveryMaskListener
{
    public const CONTENTS = 'consho_contents';
    public const COMPLETE = 'consho_complete';
    public const QUANTITY = 'consho_quantity';
    public const NOTIFY = 'consho_notify';

    /**
     * A single checkbox in Contao's own markup (the span around box and
     * label), so the tl_class `consho-toggle` draws it as the slide switch
     * of the other Consho masks (backend.css). %2$s name, %3$s checked,
     * %4$s label. The help below stays a plain tl_help: tl_tip cuts it to
     * one line, and what reporting does must be readable in full.
     */
    private const TOGGLE = '<div class="tl_checkbox_single_container"><span><input type="checkbox" name="%2$s" id="ctrl_%2$s" class="tl_checkbox" value="1"%3$s> <label for="ctrl_%2$s">%4$s</label></span></div>';

    public function __construct(
        private readonly Connection $connection,
        private readonly DeliveryContents $contents,
        private readonly DeliveryReported $reported,
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly ContaoFramework $framework,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
    ) {
    }

    #[AsCallback(table: 'tl_consho_delivery', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        if (null !== $this->draft($dc)) {
            return;
        }

        $palette = (string) ($GLOBALS['TL_DCA']['tl_consho_delivery']['palettes']['default'] ?? '');
        $GLOBALS['TL_DCA']['tl_consho_delivery']['palettes']['default'] = (string) preg_replace('/;\{(?:contents|report)_legend\}[^;]*/', '', $palette);
    }

    #[AsCallback(table: 'tl_consho_delivery', target: 'fields.contents.input_field')]
    public function contentsField(DataContainer $dc): string
    {
        $delivery = $this->draft($dc);

        if (null === $delivery) {
            return '';
        }

        $lang = $this->lang();
        $available = $this->contents->available((int) $delivery['pid'], (int) $delivery['id']);
        $current = $this->contents->current((int) $delivery['id']);

        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $names = $this->names((int) $delivery['pid']);

        if ([] === $available && [] === $current) {
            return '<div class="widget clr"><p class="tl_info">'.$this->nothingOpen((int) $delivery['pid'], (int) $delivery['id'], $names, $language).'</p></div>';
        }

        // Drawn again after a failed save: what was entered, not the stored state.
        $posted = $this->posted();
        $complete = null !== $posted ? '1' === ($posted[self::COMPLETE] ?? null) : DeliveryContents::same($available, $current);
        $typed = null !== $posted && \is_array($posted[self::QUANTITY] ?? null) ? $posted[self::QUANTITY] : null;
        $rows = '';

        foreach (array_keys($available + $current) as $itemId) {
            $rows .= \sprintf(
                '<tr><td style="white-space:normal">%s</td><td style="width:1%%; white-space:nowrap; text-align:right">%s</td><td style="width:1%%; white-space:nowrap"><input type="text" name="%s[%d]" value="%s" class="tl_text" style="width:6em" inputmode="decimal" aria-label="%s"></td></tr>',
                self::e($names[$itemId] ?? '#'.$itemId),
                self::e($this->money->quantity($available[$itemId] ?? '0', $language)),
                self::QUANTITY,
                $itemId,
                self::e(null !== $typed && \is_scalar($typed[$itemId] ?? null) ? (string) $typed[$itemId] : $this->money->quantity($current[$itemId] ?? '0', $language)),
                self::e(\sprintf((string) ($lang['contentsQuantityLabel'] ?? '%s'), $names[$itemId] ?? '#'.$itemId)),
            );
        }

        return \sprintf(
            '<div class="widget clr consho-toggle">'
            .'<input type="hidden" name="%1$s" value="1">'
            .self::TOGGLE
            .'<p class="tl_help">%5$s</p>'
            .'<table class="tl_show with-padding" id="ctrl_%6$s"%7$s><thead><tr><th>%8$s</th><th style="text-align:right">%9$s</th><th>%10$s</th></tr></thead><tbody>%11$s</tbody></table>'
            .'<script>(() => { const c = document.getElementById("ctrl_%2$s"), t = document.getElementById("ctrl_%6$s"); if (c && t) { c.addEventListener("change", () => { t.hidden = c.checked; }); } })();</script>'
            .'</div>',
            self::CONTENTS,
            self::COMPLETE,
            $complete ? ' checked' : '',
            self::e($lang['contentsComplete'] ?? ''),
            self::e($lang['contentsCompleteHelp'] ?? ''),
            self::QUANTITY,
            $complete ? ' hidden' : '',
            self::e($lang['contentsItem'] ?? ''),
            self::e($lang['contentsOpen'] ?? ''),
            self::e($lang['contentsInParcel'] ?? ''),
            $rows,
        );
    }

    #[AsCallback(table: 'tl_consho_delivery', target: 'fields.notify.input_field')]
    public function notifyField(DataContainer $dc): string
    {
        $delivery = $this->draft($dc);

        // Nothing to carry, nothing to report: the report would refuse "empty".
        if (null === $delivery || ([] === $this->contents->current((int) $delivery['id']) && [] === $this->contents->available((int) $delivery['pid'], (int) $delivery['id']))) {
            return '';
        }

        $lang = $this->lang();

        return \sprintf(
            '<div class="widget clr consho-toggle">'.self::TOGGLE.'<p class="tl_help">%5$s</p></div>',
            '',
            self::NOTIFY,
            '1' === ($this->posted()[self::NOTIFY] ?? null) ? ' checked' : '',
            self::e($lang['notify'] ?? ''),
            self::e($lang['notifyHelp'] ?? ''),
        );
    }

    #[AsCallback(table: 'tl_consho_delivery', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $request = $this->requests->getCurrentRequest();
        $delivery = $this->draft($dc);

        // Only from the mask that drew the fields.
        if (null === $request || null === $delivery || '1' !== $request->request->getString(self::CONTENTS)) {
            return;
        }

        $message = $this->framework->getAdapter(Message::class);
        $lang = $this->lang();
        $available = $this->contents->available((int) $delivery['pid'], (int) $delivery['id']);

        if ('1' === $request->request->getString(self::COMPLETE)) {
            $items = $available;
        } else {
            $items = $this->entered($request->request->all(self::QUANTITY), $available);

            if (null === $items) {
                $message->addError((string) ($lang['contentsInvalid'] ?? 'contentsInvalid'));

                return;
            }
        }

        $now = time();

        if (!DeliveryContents::same($items, $this->contents->current((int) $delivery['id']))) {
            $this->contents->replace((int) $delivery['id'], $items, $now);
        }

        if ('1' !== $request->request->getString(self::NOTIFY)) {
            return;
        }

        // As the button in the list: reporting changes the order.
        $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [(int) $delivery['pid']]);

        if (false === $order || !$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
            $message->addError((string) ($lang['notifyDenied'] ?? 'notifyDenied'));

            return;
        }

        DeliveryReportMessages::add($message, $this->reported->report((int) $delivery['id'], $now), (string) $order['orderNumber']);
    }

    /**
     * The mask's own fields as just submitted, where the mask is drawn again
     * in the same request — which DC_Table does only when the save failed on
     * another field (a successful save redirects). Null otherwise, and then
     * the stored state is shown.
     *
     * @return array<string, mixed>|null
     */
    private function posted(): array|null
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request || !$request->isMethod('POST') || '1' !== $request->request->getString(self::CONTENTS)) {
            return null;
        }

        return $request->request->all();
    }

    /**
     * Why there is nothing to pack, per good: "Kräutersalz: bestellt 1,
     * widerrufen 1" — not a bare "nothing open", which reads as "all shipped".
     *
     * @param array<int, string> $names
     */
    private function nothingOpen(int $orderId, int $deliveryId, array $names, string $language): string
    {
        $lang = $this->lang();
        $account = $this->contents->account($orderId, $deliveryId);

        if ($account['cancelled']) {
            return self::e((string) ($lang['contentsCancelled'] ?? ''));
        }

        if ([] === $account['items']) {
            return self::e((string) ($lang['contentsNoGoods'] ?? ''));
        }

        $parts = $lang['contentsAccount'] ?? [];
        $lines = [];

        foreach ($account['items'] as $item) {
            $figures = [];

            foreach (['ordered', 'shipped', 'withdrawn', 'planned'] as $key) {
                if ('ordered' === $key || !BigDecimal::of($item[$key])->isZero()) {
                    $figures[] = \sprintf((string) ($parts[$key] ?? $key.' %s'), $this->money->quantity($item[$key], $language));
                }
            }

            $lines[] = self::e(($names[$item['item']] ?? '#'.$item['item']).': '.implode(', ', $figures));
        }

        return self::e((string) ($lang['contentsNothingOpen'] ?? '')).'<br>'.implode('<br>', $lines);
    }

    /**
     * The quantities typed, each between 0 and what is available; null if
     * one is not a number or exceeds it.
     *
     * @param array<mixed>        $entered
     * @param array<int, string> $available
     *
     * @return array<int, string>|null
     */
    private function entered(array $entered, array $available): array|null
    {
        $items = [];

        foreach ($entered as $itemId => $value) {
            $text = str_replace(',', '.', trim(\is_scalar($value) ? (string) $value : ''));

            try {
                $quantity = BigDecimal::of('' === $text ? '0' : $text);
            } catch (MathException) {
                return null;
            }

            if ($quantity->isZero()) {
                continue;
            }

            if ($quantity->isNegative() || $quantity->isGreaterThan($available[(int) $itemId] ?? '0')) {
                return null;
            }

            $items[(int) $itemId] = (string) $quantity;
        }

        return $items;
    }

    /**
     * The delivery while the single edit mask shows it and it is not reported.
     *
     * @return array<string, mixed>|null
     */
    private function draft(DataContainer|null $dc): array|null
    {
        if (null === $dc || 'edit' !== $this->requests->getCurrentRequest()?->query->get('act')) {
            return null;
        }

        $delivery = $this->connection->fetchAssociative('SELECT id, pid, reportedAt FROM tl_consho_delivery WHERE id = ?', [(int) $dc->id]);

        return false === $delivery || null !== $delivery['reportedAt'] ? null : $delivery;
    }

    /**
     * @return array<int, string>
     */
    private function names(int $orderId): array
    {
        $names = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, productName, sku FROM tl_consho_order_item WHERE pid = ?', [$orderId]) as $row) {
            $names[(int) $row['id']] = (string) $row['productName'].('' !== (string) $row['sku'] ? ' ('.$row['sku'].')' : '');
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function lang(): array
    {
        return $GLOBALS['TL_LANG']['tl_consho_delivery'] ?? [];
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}
