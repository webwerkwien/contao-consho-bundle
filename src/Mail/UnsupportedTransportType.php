<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

final class UnsupportedTransportType extends \RuntimeException
{
    public static function for(string $type): self
    {
        return new self(\sprintf(
            'Unsupported mail transport type "%s". Consho supports smtp, graph and sendmail. '
            .'native:// is deliberately excluded — Symfony advises against it because it has no error reporting.',
            $type,
        ));
    }
}
