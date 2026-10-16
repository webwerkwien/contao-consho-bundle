<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

use Symfony\Component\Mailer\Transport\TransportInterface;

interface MailTransportFactoryInterface
{
    /**
     * @param array<string, mixed> $row a tl_consho_mail_transport row
     */
    public function create(array $row): TransportInterface;
}
