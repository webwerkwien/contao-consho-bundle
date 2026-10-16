<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

/**
 * "Datei" (F-5, F18): the entitlement is redeemed by downloading the
 * article's file on the order page.
 *
 * Nothing to hand out per piece — the file is the same for every holder. The
 * release only waits while there is no file to give: releasing a line whose
 * download then fails would count as a delivery that did not happen. The
 * back end names such articles (DigitalFileIssueSource).
 */
final class FileDeliveryHandler implements DeliveryHandlerInterface
{
    public function __construct(private readonly DigitalFiles $files)
    {
    }

    public static function getType(): string
    {
        return 'file';
    }

    public function release(Entitlement $entitlement): bool
    {
        return null !== $this->files->of($entitlement->productId);
    }
}
