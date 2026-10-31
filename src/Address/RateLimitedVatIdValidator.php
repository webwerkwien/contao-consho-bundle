<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Address;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * VAT ID checks from the front end, limited per IP: the checkout, and a
 * member setting their VAT ID at registration or in "Persönliche Daten"
 * (MemberVatId).
 *
 * Every check is a query to VIES, and with the shop's own VAT ID a qualified
 * one — in the shop's name. Without a limit these forms are a free VIES client
 * for anyone with a script, and a member state that throttles the requester
 * hits the shop's real customers. Over the limit the answer is "unavailable":
 * the order carries tax and "check again" stays open, exactly as when VIES
 * itself does not answer (C15). Never "invalid" — that would tell a customer
 * their VAT ID is wrong.
 *
 * Without a request (a command, a scheduled run) and in the back end nothing
 * is counted: an editor saving many members must not run into a limit meant
 * for strangers.
 */
final class RateLimitedVatIdValidator implements VatIdValidatorInterface
{
    public function __construct(
        private readonly VatIdValidatorInterface $inner,
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly RequestStack $requests,
        private readonly ScopeMatcher $scopes,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function check(string $vatId, string|null $requesterVatId = null): VatIdCheckResult
    {
        $request = $this->requests->getCurrentRequest();
        $ip = null === $request || $this->scopes->isBackendRequest($request) ? null : $request->getClientIp();

        if (null !== $ip && !$this->limiter->create('vat_check_'.$ip)->consume()->isAccepted()) {
            $this->logger->warning('Consho: VAT ID check refused, too many checks from one IP; treated as unavailable.');

            return new VatIdCheckResult(VatIdStatus::Unavailable, time());
        }

        return $this->inner->check($vatId, $requesterVatId);
    }
}
