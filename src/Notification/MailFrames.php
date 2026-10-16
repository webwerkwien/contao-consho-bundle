<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Countries;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;

/**
 * The frame of a shop's customer mails (MailFrame), read when the mail goes
 * out — a mail is sent once, nothing to freeze.
 *
 * From the fields the invoice already has (L1, L2): `invoiceLogo`,
 * `invoiceCompany`, `invoiceLegalForm`, the address, and `invoiceFooter` in
 * the mail's language (TranslationStorage, the rule of DbalDocumentSource).
 * One logo and one footer for invoice and mail, not a second pair that drifts.
 *
 * Operator copies get none (L3): they go to the shop itself.
 */
class MailFrames
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationStorage $translations,
        private readonly ShopLanguages $languages,
        private readonly MailImageFile $images,
    ) {
    }

    public function of(int $shopId, string $language): MailFrame
    {
        $shop = $shopId > 0 ? $this->connection->fetchAssociative(
            'SELECT invoiceCompany, invoiceLegalForm, invoiceStreet, invoicePostal, invoiceCity, invoiceCountry, invoiceLogo, invoiceFooter FROM tl_consho_shop WHERE id = ?',
            [$shopId],
        ) : false;

        if (false === $shop) {
            return new MailFrame('', '', '');
        }

        $company = trim(self::plain($shop['invoiceCompany']).' '.self::plain($shop['invoiceLegalForm']));
        $place = trim(self::plain($shop['invoicePostal']).' '.self::plain($shop['invoiceCity']));
        $country = strtoupper((string) $shop['invoiceCountry']);
        $address = implode(', ', array_filter([
            self::plain($shop['invoiceStreet']),
            $place,
            '' !== $country && Countries::exists($country) ? Countries::getName($country, '' !== $language ? str_replace('-', '_', $language) : 'en') : '',
        ], static fn (string $part): bool => '' !== $part));

        return new MailFrame($company, $address, $this->footer($shopId, (string) ($shop['invoiceFooter'] ?? ''), $language), $this->logo($shop['invoiceLogo'] ?? null));
    }

    private function footer(int $shopId, string $main, string $language): string
    {
        $text = $main;

        // The rule of DbalDocumentSource::translated(): the translation if there is one.
        if ('' !== $language && $language !== $this->languages->fallbackOf($shopId)) {
            $translated = $this->translations->read('tl_consho_shop', $shopId, $language, 'invoiceFooter');
            $text = '' !== $translated ? $translated : $main;
        }

        return trim(self::plain($text));
    }

    /**
     * @return array{cid: string, contents: string, contentType: string, width: int}|null
     */
    private function logo(mixed $binary): array|null
    {
        if (!\is_string($binary) || 16 !== \strlen($binary)) {
            return null;
        }

        $file = $this->images->read($binary);

        if (null === $file) {
            return null;
        }

        // Its own width up to LOGO_WIDTH — a small logo is not blown up.
        $size = @getimagesizefromstring($file['contents']);
        $width = false === $size ? MailFrame::LOGO_WIDTH : min(MailFrame::LOGO_WIDTH, max(1, (int) $size[0]));

        return [
            // With the extension: forwarded, it is an image, not a nameless part.
            'cid' => 'logo-'.bin2hex($binary).('image/jpeg' === $file['contentType'] ? '.jpg' : '.png'),
            'contents' => $file['contents'],
            'contentType' => $file['contentType'],
            'width' => $width,
        ];
    }

    /**
     * Input-encoded in the database ("Müller &amp; Söhne"); the template escapes.
     */
    private static function plain(mixed $value): string
    {
        return trim(html_entity_decode((string) $value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
