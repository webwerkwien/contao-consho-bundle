<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * A tax class such as "Normalsatz" or "ermäßigt".
 *
 * It carries no percentage of its own — those live in tl_consho_tax_rate, one
 * per country and validity period.
 *
 * @property int    $id
 * @property int    $tstamp
 * @property string $title
 *
 * @method static TaxClassModel|null           findByPk($id, array $opt = [])
 * @method static Collection<TaxClassModel>|null findAll(array $opt = [])
 */
class TaxClassModel extends Model
{
    protected static $strTable = 'tl_consho_tax_class';
}
