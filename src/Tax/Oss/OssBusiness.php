<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax\Oss;

/**
 * The business behind one or more shops (block R, O2, O3): the shops that
 * share a VAT ID. A shop without one stands for itself.
 */
final class OssBusiness
{
    /**
     * @param list<array{id: int, title: string, country: string, taxAtDestination: bool}> $shops
     */
    public function __construct(
        public readonly string $key,
        public readonly string $vatId,
        public readonly array $shops,
    ) {
    }

    /**
     * @return list<int>
     */
    public function shopIds(): array
    {
        return array_map(static fn (array $shop): int => $shop['id'], $this->shops);
    }

    /**
     * Whether it taxes at destination — the listener keeps the shops of a
     * VAT ID alike; should they differ anyway, "on" wins, since then OSS
     * lines exist to report.
     */
    public function taxesAtDestination(): bool
    {
        return [] !== array_filter($this->shops, static fn (array $shop): bool => $shop['taxAtDestination']);
    }

    public function label(): string
    {
        return '' !== $this->vatId ? $this->vatId : implode(', ', array_map(static fn (array $shop): string => $shop['title'], $this->shops));
    }
}
