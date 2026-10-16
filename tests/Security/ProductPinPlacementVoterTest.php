<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Security;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Webwerkwien\ContaoConshoBundle\Security\ProductPinPlacementVoter;

/**
 * Elternelemente: 10 Produktliste, 11 Akkordeon (verschachtelbar, ohne
 * Typliste — der Kern ließe dort jeden Typ zu), 12 Text.
 */
class ProductPinPlacementVoterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement("CREATE TABLE tl_content (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '')");
        $this->connection->insert('tl_content', ['id' => 10, 'type' => 'consho_product_list']);
        $this->connection->insert('tl_content', ['id' => 11, 'type' => 'accordion']);
        $this->connection->insert('tl_content', ['id' => 12, 'type' => 'text']);
    }

    public function testAPinMayBeCreatedInAListing(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new CreateAction('tl_content', $this->pin('tl_content', 10))));
    }

    public function testAPinMayNotBeCreatedInAnArticle(): void
    {
        // Genau das lässt der Kern durch: Er prüft nur ptable = tl_content.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new CreateAction('tl_content', $this->pin('tl_article', 10))));
    }

    public function testAPinMayNotBeNestedInAnyOtherElement(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new CreateAction('tl_content', $this->pin('tl_content', 11))));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new CreateAction('tl_content', $this->pin('tl_content', 12))));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new CreateAction('tl_content', $this->pin('tl_content', 99))));
    }

    public function testOtherTypesAreNotThisVotersBusiness(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new CreateAction('tl_content', ['type' => 'text', 'ptable' => 'tl_article', 'pid' => 5])));
    }

    public function testSwitchingAnElementInAnArticleToAPinIsDenied(): void
    {
        $action = new UpdateAction('tl_content', ['id' => 20, 'type' => 'text', 'ptable' => 'tl_article', 'pid' => 5], ['type' => 'consho_product_pin']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($action));
    }

    public function testMovingAPinOutOfItsListingIsDenied(): void
    {
        $action = new UpdateAction('tl_content', $this->pin('tl_content', 10) + ['id' => 20], ['ptable' => 'tl_article', 'pid' => 5]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($action));
    }

    public function testEditingAPinInItsListingIsAllowed(): void
    {
        // Umschalten der Sichtbarkeit: new trägt nur das geänderte Feld.
        $action = new UpdateAction('tl_content', $this->pin('tl_content', 10) + ['id' => 20], ['invisible' => 1]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($action));
    }

    public function testAMisplacedPinStaysReadableAndDeletable(): void
    {
        $misplaced = $this->pin('tl_article', 5) + ['id' => 20];

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new ReadAction('tl_content', $misplaced)));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new DeleteAction('tl_content', $misplaced)));
    }

    public function testWithoutAParentTableThereIsNothingToJudge(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new CreateAction('tl_content', ['type' => 'consho_product_pin'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function pin(string $ptable, int $pid): array
    {
        return ['type' => 'consho_product_pin', 'ptable' => $ptable, 'pid' => $pid];
    }

    private function vote(object $action): int
    {
        return (new ProductPinPlacementVoter($this->connection))->vote(
            $this->createMock(TokenInterface::class),
            $action,
            [ContaoCorePermissions::DC_PREFIX.'tl_content'],
        );
    }
}
