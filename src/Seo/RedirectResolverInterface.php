<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

interface RedirectResolverInterface
{
    /**
     * @param string $path the requested path, exactly as the request carries it
     * @param string $host the requested host, to tell two sites of one installation apart
     */
    public function find(string $path, string $host): ?RedirectTarget;
}
