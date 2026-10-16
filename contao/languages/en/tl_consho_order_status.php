<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_order_status']['title'] = ['Name', 'What this status is called in the back end. Freely chosen — the system reads only the flags below, never the name.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['colour'] = ['Signal in the list', 'How this status shows in the order overview. The title above is free text, so the signal has to be set — Consho cannot know whether "Awaiting clarification" is a problem or routine.'];

$GLOBALS['TL_LANG']['tl_consho_order_status']['colours'] = [
    'open' => 'Open (grey)',
    'progress' => 'In progress (orange)',
    'attention' => 'Needs attention (red)',
    'done' => 'Complete (green)',
];

$GLOBALS['TL_LANG']['tl_consho_order_status']['isPaid'] = ['Counts as paid', 'Can only be set once the payment is settled: nothing outstanding and nothing to refund. Locks the change of payment method.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['isCancelled'] = ['Counts as cancelled', 'Can only be set while nothing has shipped — after that it is a withdrawal or a return. The order then owes nothing; money already paid has to be refunded.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['isShipped'] = ['Counts as shipped', 'Can only be set once all goods are reported shipped.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['isCompleted'] = ['Completes the order', 'Can only be set once payment and delivery are settled — paid and delivered, or cancelled, nothing shipped and any payment refunded.'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['locksChanges'] = ['Locks changes', 'From this status the order is frozen.'];

$GLOBALS['TL_LANG']['tl_consho_order_status']['title_legend'] = 'Name';
$GLOBALS['TL_LANG']['tl_consho_order_status']['flag_legend'] = 'System behaviour';

$GLOBALS['TL_LANG']['tl_consho_order_status']['new'] = ['New status', 'Create a status'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['edit'] = ['Edit', 'Edit status ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['copy'] = ['Duplicate', 'Duplicate status ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['delete'] = ['Delete', 'Delete status ID %s'];
$GLOBALS['TL_LANG']['tl_consho_order_status']['show'] = ['Details', 'Show details of status ID %s'];
