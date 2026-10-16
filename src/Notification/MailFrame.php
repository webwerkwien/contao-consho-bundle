<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Symfony\Component\Mime\Email;

/**
 * Head and foot of a mail to a customer (block GARAN, part 2, L1–L4): the
 * shop's logo above, who sends it below.
 *
 * Consho sets no legal wording (Michael, 2026-09-22). § 14 Abs. 1 UGB asks a
 * registered business for "die Firma, die Rechtsform, den Sitz und die
 * Firmenbuchnummer … sowie das Firmenbuchgericht" on its business letters,
 * § 63 Abs. 1 GewO a sole trader for name and location. The frame carries
 * what the shop configuration has — firm, legal form, address — and the
 * operator's own footer for the rest, the one the invoice prints (L2).
 *
 * The logo travels embedded (cid:), like the GARAN label: a remote image is
 * blocked until the reader allows it, and a link that loads on opening is
 * what spam filters and tracking-wary clients distrust (L4).
 */
final class MailFrame
{
    /** The width the logo is shown at, at most — never stretched beyond its own (L4). */
    public const LOGO_WIDTH = 200;

    /**
     * @param array{cid: string, contents: string, contentType: string, width: int}|null $logo
     */
    public function __construct(
        public readonly string $company,
        public readonly string $address,
        public readonly string $footer,
        public readonly array|null $logo = null,
    ) {
    }

    /**
     * What the templates get as `frame` (_frame_head, _frame_foot).
     *
     * @return array{company: string, address: string, footer: string, logo: array{cid: string, width: int}|null}
     */
    public function view(): array
    {
        return [
            'company' => $this->company,
            'address' => $this->address,
            'footer' => $this->footer,
            'logo' => null === $this->logo ? null : ['cid' => $this->logo['cid'], 'width' => $this->logo['width']],
        ];
    }

    /**
     * The logo as an inline part, under the name the template refers to.
     */
    public function embedInto(Email $email): Email
    {
        if (null !== $this->logo) {
            $email->embed($this->logo['contents'], $this->logo['cid'], $this->logo['contentType']);
        }

        return $email;
    }
}
