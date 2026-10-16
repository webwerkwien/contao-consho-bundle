<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Doctrine\DBAL\Connection;

/**
 * What a command says when it is given a shop ID that does not exist: the
 * ones that do (Michael, 2026-10-12: "in den Paketen sollte verhindert werden,
 * dass Agenten Eingaben raten").
 *
 * "There is no shop configuration 7." leaves an agent to try 5, 6, 8. The list
 * is the shortest correct answer, and it needs no tool beside this command.
 * Titles are stored encoded by Contao ("&#40;") and decoded for the console.
 */
final class KnownShops
{
    /**
     * @param array<int, string> $shops id => title
     */
    public static function sentence(array $shops): string
    {
        if ([] === $shops) {
            return 'There is none yet.';
        }

        $named = [];

        foreach ($shops as $id => $title) {
            $named[] = \sprintf('%d (%s)', $id, html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return 'Shop configurations: '.implode(', ', $named).'.';
    }

    /**
     * @return array<int, string> id => title, in id order
     */
    public static function of(Connection $connection): array
    {
        return array_map(strval(...), $connection->fetchAllKeyValue('SELECT id, title FROM tl_consho_shop ORDER BY id'));
    }
}
