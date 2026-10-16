<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_price_rule']['title'] = ['Title', 'Back end only, such as "Bergsport week". The customer sees the badge and the price struck through.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['percent'] = ['Discount in percent', 'Off the price the customer would pay without the rule. Where several rules match, the cheapest for the customer applies, not the sum.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['reduced'] = ['Also for goods already reduced', 'Unticked, the rule does not apply to articles already on promotion. Ticked, the promotion price is reduced further.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['products'] = ['Products', 'Empty: all. Variants belong to their product.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['manufacturers'] = ['Manufacturers', 'Empty: all.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['productTypes'] = ['Product types', 'Empty: all.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['propertyOptions'] = ['Properties', 'Empty: all. Otherwise the article must have one of them.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['memberGroups'] = ['Customer groups', 'Empty: everyone, guests included. Otherwise members of one of these groups only — then without a strike-through price.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['published'] = ['Active', 'Applies only while ticked and within its period.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['validFrom'] = ['Valid from', 'Date and time, inclusive. Empty: at once.'];
$GLOBALS['TL_LANG']['tl_consho_price_rule']['validTo'] = ['Valid to', 'Date and time, exclusive: to 30.09. 00:00 means the 29th is the last day. Empty: no end.'];

$GLOBALS['TL_LANG']['tl_consho_price_rule']['title_legend'] = 'Rule';
$GLOBALS['TL_LANG']['tl_consho_price_rule']['condition_legend'] = 'Applies to — every field filled in must match';
$GLOBALS['TL_LANG']['tl_consho_price_rule']['publish_legend'] = 'Period';
