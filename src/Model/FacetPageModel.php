<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * A released facet landing page.
 *
 * pid is the listing page (tl_page) it lives below — not the shop. Shop and
 * language follow from that page. See Block B, 4.5.
 *
 * @property int         $id
 * @property int         $pid         tl_page.id of the listing page
 * @property string|null $filterState
 * @property string|null $alias
 * @property string      $pageTitle
 * @property string|null $description
 *
 * @method static FacetPageModel|null findOneBy($col, $val, array $opt = [])
 */
class FacetPageModel extends Model
{
    protected static $strTable = 'tl_consho_facet_page';
}
