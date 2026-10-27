<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Turns a mail template into a subject and a body, in the recipient's language.
 *
 * Subject and body live in two Twig blocks of the same file, so a shop owner
 * editing the mail in Template Studio has both in front of them instead of
 * hunting for the subject in a settings field somewhere else.
 */
final class MailRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @param MailFrame|null       $frame   head and foot of a customer mail (GARAN part 2, L3) —
     *                                      `frame` in the template; null for operator copies
     */
    public function render(string $template, array $context, string $locale, MailFrame|null $frame = null): RenderedMail
    {
        $context['frame'] = $frame?->view();
        $previous = $this->switchLocale($locale);

        try {
            $rendered = $this->twig->load($template);

            return new RenderedMail(
                // Trimmed: a subject that keeps the template's indentation
                // shows up in the mail client with it. The body may keep its
                // whitespace — it is markup — the subject may not.
                //
                // And decoded: the subject is a plain text header, but Twig
                // escapes it like HTML — "Wanderstock & Hülle" arrived as
                // "Wanderstock &amp; Hülle" (Block G).
                html_entity_decode(trim($rendered->renderBlock('subject', $context)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                $rendered->renderBlock('body', $context),
            );
        } finally {
            /*
             * Always restored, even when rendering threw. The operator copy is
             * rendered right after the customer's, and one mail in Portuguese
             * must not leave the rest of the request speaking Portuguese.
             */
            if (null !== $previous) {
                $this->switchLocale($previous);
            }
        }
    }

    /**
     * @return string|null the previous locale, or null if switching is not possible
     */
    private function switchLocale(string $locale): string|null
    {
        /*
         * ⚠️ Checked at run time rather than type-hinted as
         * `TranslatorInterface&LocaleAwareInterface`.
         *
         * An intersection type cannot be autowired: Symfony drops the service
         * definition and the class silently never gets built. The container
         * still shows the tag while nothing runs.
         */
        if (!$this->translator instanceof LocaleAwareInterface) {
            return null;
        }

        $previous = $this->translator->getLocale();
        $this->translator->setLocale($locale);

        return $previous;
    }
}
