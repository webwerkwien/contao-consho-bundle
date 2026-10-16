<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Address;

interface VatIdValidatorInterface
{
    /**
     * @param string      $vatId           including the country code, e.g. ATU12345678
     * @param string|null $requesterVatId  the shop's own VAT ID. Supplying it turns
     *                                     the query into a qualified confirmation,
     *                                     which is the only kind that yields a
     *                                     consultation number — the evidence a tax
     *                                     audit asks for
     */
    public function check(string $vatId, string|null $requesterVatId = null): VatIdCheckResult;
}
