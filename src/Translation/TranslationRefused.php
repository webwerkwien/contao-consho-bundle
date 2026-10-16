<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Translation;

/**
 * A language block TranslationWriter::set() will not write; the message says
 * why and is meant for the caller's answer.
 */
final class TranslationRefused extends \RuntimeException
{
}
