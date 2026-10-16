<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_purchase_list']['list_legend'] = 'Purchase list';
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['number'] = ['Number', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['createdAt'] = ['Created', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['user'] = ['User', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['suppliers'] = ['Suppliers', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['lineCount'] = ['Lines', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['state'] = ['State', ''];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['states'] = ['open' => 'open', 'partial' => 'partial', 'done' => 'done'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['items'] = ['Lines', 'Lines of purchase list ID %s'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['receive'] = ['Receipt', 'Confirm the receipt of purchase list ID %s'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['output'] = ['Output', 'Hand out purchase list ID %s again'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['show'] = ['Details', 'Show the details of purchase list ID %s'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['purchaseOutput'] = ['Output', 'Hand out the purchase list again'];
$GLOBALS['TL_LANG']['tl_consho_purchase_list']['purchaseReceive'] = ['Receipt', 'Confirm the receipt of the purchase list'];
// array_replace, not +=: Contao loads English first as the fallback, and +=
// keeps every key that is already there (Abnahme G-8).
$GLOBALS['TL_LANG']['tl_consho_purchase_list'] = array_replace($GLOBALS['TL_LANG']['tl_consho_purchase_list'] ?? [], [
    'back' => 'Back',
    'colTake' => 'Take',
    'colArticle' => 'Article',
    'colSupplier' => 'Supplier',
    'colSupplierSku' => 'Supplier item number',
    'colSku' => 'Item number',
    'colStock' => 'Stock',
    'colOrdered' => 'on order',
    'colWarning' => 'Reorder level',
    'colTarget' => 'Target stock',
    'colPieces' => 'Pieces',
    'colPackUnit' => 'Pack',
    'colPacks' => 'Packs',
    'colOrderedPieces' => 'ordered',
    'colReceived' => 'received',
    'colReceiveNow' => 'received now',
    'colUnavailable' => 'Rest not deliverable',
    'perPack' => '%s per pack',
    'noArticles' => 'No article keeps stock. Switch on "Keep stock" at the product.',
    'outputHint' => 'Each of the three outputs saves the list under "Purchase lists"; the articles then count as on order.',
    'toClipboard' => 'Clipboard',
    'toPrint' => 'Print view',
    'toCsv' => 'CSV export',
    'printTitle' => 'Purchase list',
    'saved' => 'Purchase list %s is saved.',
    'copy' => 'Copy to clipboard',
    'copied' => 'Copied.',
    'toLists' => 'To the purchase lists',
    'receiveHint' => 'Prefilled with what is still open. "Rest not deliverable" closes the line; the article shows up in the next purchase again.',
    'received' => 'Receipt booked for %s.',
    'invalidQuantity' => 'Please enter valid quantities — whole packs, never more than is open.',
    'invalidItem' => 'A line does not belong to this list.',
    'receiveButton' => 'Book receipt',
    'unavailableWithRest' => 'With "Rest not deliverable" ticked, the whole rest cannot be entered as received as well. Enter what came (0 is fine) and tick it.',
    'noStock' => 'An article on the list no longer keeps stock; its receipt cannot be booked.',
    'nothingToOrder' => 'Nothing is chosen.',
]);
