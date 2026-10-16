<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

final class ArchiveFolderIsPublic extends \RuntimeException
{
    public function __construct(public readonly string $publicFolder)
    {
        parent::__construct(\sprintf('The folder "%s" is public, and with it the invoice archive inside it.', $publicFolder));
    }

    public static function becauseOf(string $publicFolder): self
    {
        return new self($publicFolder);
    }
}
