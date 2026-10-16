<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

final class RelatedDocumentRequired extends \RuntimeException
{
    public static function for(DocumentType $type): self
    {
        return new self(\sprintf(
            'A document of type "%s" must refer to the document it corrects.',
            $type->value,
        ));
    }
}
