<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Contao\CoreBundle\InsertTag\InsertTagParser;
use Doctrine\DBAL\Connection;

/**
 * Reads the terms and the withdrawal instruction a root page points at (C17).
 *
 * Only `headline` and `text` are read, and that is the whole rule about what
 * a legal text is: **a picture in the element stays out of the mail and the
 * PDF** (9). Nothing here tries to render the element — it takes the two
 * fields the operator typed into.
 *
 * ⚠️ Whether the element actually appears on the page it sits on is NOT
 * checked here. The spec says so in as many words, and it could not be checked
 * honestly: an element can be hidden by a template, a layout section or a
 * protected page in ways no query sees. DbalLegalReadinessIssueSource reports what can
 * be established (11.3); this class reads.
 */
class DbalLegalTextSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InsertTagParser $insertTags,
    ) {
    }

    /**
     * @return array<string, LegalText> keyed by LegalTextKind->value, missing
     *                                  texts simply absent
     */
    public function forRoot(int $rootId): array
    {
        $texts = [];

        foreach (LegalTextKind::cases() as $kind) {
            $text = $this->one($rootId, $kind);

            if (null !== $text) {
                $texts[$kind->value] = $text;
            }
        }

        return $texts;
    }

    public function one(int $rootId, LegalTextKind $kind): LegalText|null
    {
        if ($rootId <= 0) {
            return null;
        }

        /*
         * The same conditions as the finding (LegalReadiness): element
         * visible and inside its window, article published and inside its
         * window. This half was missing here too once.
         *
         * It has to be the same set, and that is not tidiness: the finding
         * decides whether the checkout opens, this decides what the checkout
         * links to. If one is stricter, either a shop is blocked over a text
         * it would happily serve, or it links to a text that is not there.
         *
         * `protected` is deliberately NOT among them: whether a login is
         * acceptable depends on the shop's guest checkout, and that is a
         * judgement about readiness, not about reading a row.
         */
        /** @var array{id: int, headline: string, text: string, pageId: int}|false $row */
        $row = $this->connection->fetchAssociative(
            \sprintf(
                "SELECT c.id, c.headline, c.text, a.pid AS pageId
                   FROM tl_page root
                   JOIN tl_content c ON c.id = root.%s AND c.ptable = 'tl_article' AND c.type = 'text'
                   JOIN tl_article a ON a.id = c.pid
                  WHERE root.id = :root
                    AND c.invisible = 0
                    AND (c.start = '' OR c.start <= :now)
                    AND (c.stop = '' OR c.stop > :now)
                    AND a.published = 1
                    AND (a.start = '' OR a.start <= :now)
                    AND (a.stop = '' OR a.stop > :now)",
                $kind->field(),
            ),
            ['root' => $rootId, 'now' => (string) (int) (floor(time() / 60) * 60)],
        );

        if (false === $row) {
            return null;
        }

        return LegalText::of(
            $kind,
            (int) $row['id'],
            (int) $row['pageId'],
            $this->resolve($this->headlineOf((string) $row['headline'])),
            $this->resolve((string) $row['text']),
        );
    }

    /**
     * A headline is stored serialised as `['unit' => 'h2', 'value' => '…']`.
     * Only the value is a legal text; the heading level is presentation and
     * would make the checksum depend on a template decision.
     */
    private function headlineOf(string $stored): string
    {
        $unserialized = unserialize($stored, ['allowed_classes' => false]);

        return \is_array($unserialized) ? (string) ($unserialized['value'] ?? '') : $stored;
    }

    private function resolve(string $value): string
    {
        return '' === $value ? '' : $this->insertTags->replaceInline($value);
    }
}
