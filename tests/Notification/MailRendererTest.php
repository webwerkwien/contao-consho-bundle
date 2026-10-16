<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;

class MailRendererTest extends TestCase
{
    public function testSubjectAndBodyComeFromTheirOwnBlocks(): void
    {
        $mail = $this->renderer([
            'x.twig' => '{% block subject %}Bestellung {{ number }}{% endblock %}{% block body %}<p>Danke</p>{% endblock %}',
        ])->render('x.twig', ['number' => 'WEB-1'], 'de');

        self::assertSame('Bestellung WEB-1', $mail->subject);
        self::assertSame('<p>Danke</p>', $mail->body);
    }

    /**
     * A subject that arrives with the template's indentation in it shows up in
     * the mail client exactly like that. The body may keep its whitespace, the
     * subject may not.
     */
    public function testTheSubjectIsTrimmedButTheBodyIsLeftAlone(): void
    {
        $mail = $this->renderer([
            'x.twig' => "{% block subject %}\n   Betreff\n{% endblock %}{% block body %}\n<p>Text</p>\n{% endblock %}",
        ])->render('x.twig', [], 'de');

        self::assertSame('Betreff', $mail->subject);

        // Twig swallows the newline directly after a block tag, so the leading
        // one is gone either way — the trailing one proves the body is handed
        // over untouched.
        self::assertSame("<p>Text</p>\n", $mail->body);
    }

    /**
     * The whole point of carrying a language on the recipient: the mail is
     * rendered in the recipient's locale, not in whatever the request happened
     * to be set to.
     */
    public function testTheTemplateIsRenderedInTheGivenLocale(): void
    {
        $translator = new Translator('de');
        $translator->addLoader('array', new \Symfony\Component\Translation\Loader\ArrayLoader());
        $translator->addResource('array', ['greeting' => 'Guten Tag'], 'de');
        $translator->addResource('array', ['greeting' => 'Good day'], 'en');

        $renderer = $this->renderer(
            ['x.twig' => "{% block subject %}s{% endblock %}{% block body %}{{ 'greeting'|trans }}{% endblock %}"],
            $translator,
        );

        self::assertSame('Good day', $renderer->render('x.twig', [], 'en')->body);
        self::assertSame('Guten Tag', $renderer->render('x.twig', [], 'de')->body);
    }

    /**
     * Rendering one mail in Portuguese must not leave the rest of the request
     * speaking Portuguese — the operator copy is rendered right after the
     * customer's.
     */
    public function testTheLocaleIsRestoredAfterwards(): void
    {
        $translator = new Translator('de');

        $this->renderer(
            ['x.twig' => '{% block subject %}s{% endblock %}{% block body %}b{% endblock %}'],
            $translator,
        )->render('x.twig', [], 'pt');

        self::assertSame('de', $translator->getLocale());
    }

    /**
     * @param array<string, string> $templates
     */
    private function renderer(array $templates, Translator|null $translator = null): MailRenderer
    {
        $translator ??= new Translator('de');
        $twig = new Environment(new ArrayLoader($templates));
        $twig->addExtension(new \Symfony\Bridge\Twig\Extension\TranslationExtension($translator));

        return new MailRenderer($twig, $translator);
    }
}
