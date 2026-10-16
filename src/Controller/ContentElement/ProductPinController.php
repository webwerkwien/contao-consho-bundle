<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinRepository;

/**
 * A pin: one product a listing puts first (B-7).
 *
 * It renders nothing in the front end, on purpose. The listing reads its pins
 * itself (DbalPinRepository) and shows the products as tiles among the others;
 * the pin is a position, not a piece of content. Only the back end shows it,
 * so that the editor sees which product each row stands for while dragging.
 *
 * Allowed inside a product listing only — ProductPinPlacementVoter.
 */
#[AsContentElement(type: DbalPinRepository::TYPE, category: 'consho')]
class ProductPinController extends AbstractContentElementController
{
    public function __construct(private readonly DbalPinRepository $pins)
    {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        if (!$this->isBackendScope($request)) {
            return new Response('');
        }

        $productId = (int) ($model->row()['conshoProduct'] ?? 0);
        $label = $this->pins->labelOf($productId);

        $template->set('productId', $productId);

        // Titles are stored entity-encoded, as Contao stores input; Twig
        // escapes again on output.
        $template->set('label', null === $label ? null : html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $template->getResponse();
    }
}
