<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

final class ConfigurationIssue
{
    /**
     * @param array<string, string> $context values for the translated sentence
     * @param int                   $shopId  the shop this is about, 0 for "every shop" or "no shop in particular"
     */
    public function __construct(
        public readonly ConfigurationFinding $finding,
        public readonly array $context = [],
        public readonly int $shopId = 0,
    ) {
    }

    /**
     * Identifies this finding in THIS state.
     *
     * ⚠️ The context is part of it, deliberately. An operator who takes note of
     * "GTIN X on 2 products" has judged that case; the same GTIN on three is a
     * different case and has to surface again. A fingerprint over the finding
     * alone would turn one click into a permanent blindfold.
     *
     * Sorted before hashing, so a changed order of the context keys does not
     * quietly invalidate an existing acknowledgement.
     *
     * ⚠️ `shopId` is deliberately NOT part of it. It was added in C-5 so the
     * checkout blocks the shop it belongs to (11.2), and every finding that
     * carries one already names the shop in its context. Hashing it too would
     * invalidate every acknowledgement anybody has ever given, for a value
     * that changes nothing about which case this is.
     */
    public function fingerprint(): string
    {
        $context = $this->context;
        ksort($context);

        return sha1($this->finding->value.'|'.json_encode($context, JSON_THROW_ON_ERROR));
    }
}
