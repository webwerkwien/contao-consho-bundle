<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cancellation;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;

require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * Die Bestätigungs- und die Ergebnisseite (Abo-7, P61; § 312k Abs. 2 und 3
 * BGB), gerendert mit strikten Variablen.
 */
final class CancellationTemplatesTest extends TestCase
{
    public function testDieBestaetigungsseiteFragtWasDasGesetzVerlangt(): void
    {
        $html = $this->render('_consho_cancellation_form', [
            'element_id' => 44, 'identify' => true, 'contract' => '', 'subscription' => 0,
            'input' => ['subscriptionNumber' => 'WEB-1', 'email' => 'a@b.c', 'kind' => 'extraordinary', 'reason' => '<b>', 'name' => 'E', 'date' => ''],
            'error' => 'alreadyEnding', 'until' => '31. März 2027', 'earliest' => '',
        ]);

        // S1: ohne bekannten Vertrag der allgemeine Satz, kein Termin.
        self::assertStringContainsString('noch voll berechnet', $html);
        self::assertStringNotContainsString('Frühestmöglich bei', $html);

        self::assertStringContainsString('ordentliche Kündigung', $html);
        self::assertStringContainsString('value="extraordinary" checked', $html);
        self::assertStringContainsString('&lt;b&gt;', $html);
        self::assertStringContainsString('name="consho_cancellation_subscriptionNumber"', $html);
        self::assertStringContainsString('name="consho_cancellation_email"', $html);
        self::assertStringContainsString('type="date"', $html);
        self::assertStringContainsString('zum frühestmöglichen Zeitpunkt', $html);
        self::assertStringContainsString('>jetzt kündigen</button>', $html);
        self::assertStringContainsString('endet am 31. März 2027', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    public function testMitBekanntemVertragOhneIdentifizierung(): void
    {
        $html = $this->render('_consho_cancellation_form', [
            'element_id' => 44, 'identify' => false, 'contract' => 'WEB-00084-1 — E-Book', 'subscription' => 12,
            'input' => ['subscriptionNumber' => '', 'email' => '', 'kind' => 'ordinary', 'reason' => '', 'name' => '', 'date' => ''],
            'error' => null, 'until' => '', 'earliest' => '15. Januar 2027 um 10:00:00 MEZ',
        ]);

        self::assertStringContainsString('Frühestmöglich bei ordentlicher Kündigung: 15. Januar 2027', $html);
        self::assertStringNotContainsString('consho_cancellation_email', $html);
        self::assertStringContainsString('name="consho_cancellation_subscription" value="12"', $html);
        self::assertStringContainsString('WEB-00084-1 — E-Book', $html);
    }

    public function testDieErgebnisseiteZeigtDieErklaerungMitZeitpunkt(): void
    {
        $html = $this->render('_consho_cancellation_result', ['result' => [
            'number' => 'WEB-00084-1-K1', 'subscription' => 'WEB-00084-1', 'product' => 'E-Book',
            'receivedAt' => '2. Oktober 2026 um 14:03:05 MESZ', 'kind' => 'ordinary', 'reason' => '', 'name' => 'Erika Muster',
            'email' => 'kundin@example.org', 'requestedAt' => '', 'effectiveAt' => '15. Januar 2027 um 10:00:00 MEZ',
            'earliestApplied' => false, 'pending' => false, 'confirmed' => true,
            'earliestAt' => '15. Januar 2027 um 10:00:00 MEZ', 'laterThanEarliest' => false,
        ]]);

        self::assertStringNotContainsString('noch voll berechnet', $html);

        self::assertStringContainsString('14:03:05', $html);
        self::assertStringContainsString('WEB-00084-1-K1', $html);
        // Abnahme: Beschriftung als dt, nicht als th, das ein Theme ausblendet.
        self::assertStringContainsString('<dt>Eingang</dt>', $html);
        self::assertStringNotContainsString('<th', $html);
        self::assertStringContainsString('endet durch diese Kündigung am 15. Januar 2027', $html);
        self::assertStringContainsString('an kundin@example.org geschickt', $html);
        self::assertStringContainsString('speichern oder drucken', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    /**
     * S1: ein Ende nach dem frühestmöglichen kostet angebrochene Perioden —
     * die Ergebnisseite sagt es.
     */
    public function testEinSpaeteresEndeSagtWasEsKostet(): void
    {
        $html = $this->render('_consho_cancellation_result', ['result' => [
            'number' => 'K1', 'subscription' => 'WEB-1', 'product' => '', 'receivedAt' => 'x', 'kind' => 'ordinary', 'reason' => '',
            'name' => 'E', 'email' => 'e@x', 'requestedAt' => '1. Februar 2027', 'effectiveAt' => '1. Februar 2027 um 00:00:00 MEZ',
            'earliestApplied' => false, 'pending' => false, 'confirmed' => true,
            'earliestAt' => '31. Januar 2027 um 10:15:00 MEZ', 'laterThanEarliest' => true,
        ]]);

        self::assertStringContainsString('nach dem frühestmöglichen Ende (31. Januar 2027 um 10:15:00 MEZ)', $html);
        self::assertStringContainsString('noch voll berechnet', $html);
    }

    public function testAusserordentlichWartetUndOhneMailGesagt(): void
    {
        $html = $this->render('_consho_cancellation_result', ['result' => [
            'number' => 'K1', 'subscription' => 'WEB-1', 'product' => '', 'receivedAt' => 'x', 'kind' => 'extraordinary', 'reason' => 'Umzug',
            'name' => 'E', 'email' => 'e@x', 'requestedAt' => '31. Januar 2027', 'effectiveAt' => '', 'earliestApplied' => false,
            'pending' => true, 'confirmed' => false, 'earliestAt' => '', 'laterThanEarliest' => false,
        ]]);

        self::assertStringContainsString('Umzug', $html);
        self::assertStringContainsString('31. Januar 2027', $html);
        self::assertStringContainsString('entscheiden wir', $html);
        self::assertStringContainsString('konnte nicht versandt werden', $html);
        self::assertStringNotContainsString('endet durch diese Kündigung', $html);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $name, array $context): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(new ContaoLikeTranslator()));
        $twig->addGlobal('contao', ['request_token' => 'token']);

        return $twig->render('@Contao/component/'.$name.'.html.twig', $context);
    }
}
