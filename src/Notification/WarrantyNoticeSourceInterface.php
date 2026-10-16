<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

interface WarrantyNoticeSourceInterface
{
    /**
     * The harmonised guarantee notice chosen at this start point, ready to
     * attach — or null when none is chosen or the file is gone.
     *
     * @return array{name: string, contents: string, contentType: string}|null
     */
    public function forRoot(int $rootId): array|null;
}
