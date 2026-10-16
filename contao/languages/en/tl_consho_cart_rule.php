<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_cart_rule']['title'] = ['Title', 'Shown in the basket and on the invoice as the discount\'s name, such as "Spring discount".'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['code'] = ['Code', 'Typed by the customer in the basket. Case counts. Empty: the rule applies by itself, without a code.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['effect'] = ['Effect', ''];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['effects'] = ['percent' => 'Percent of the goods', 'amount' => 'Fixed amount', 'freeShipping' => 'Free shipping'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['percent'] = ['Discount in percent', 'On the goods the rule applies to.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['amount'] = ['Amount', 'As the customer reads prices: gross, net for customers shown net prices. Never more than the goods the rule applies to. Needs a currency.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['reduced'] = ['Also for goods already reduced', 'Unticked, articles on promotion or under a price rule do not count.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['shops'] = ['Shops', 'Empty: all.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['currency'] = ['Currency', 'ISO code, such as EUR. Needed for a fixed amount or a minimum order value — the rule then applies in this currency only.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['minimumOrder'] = ['Minimum order value', 'Goods without shipping, payment and subscriptions, as the customer reads them. 0: none.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['products'] = ['Products', 'Empty: all. The discount applies to these goods only.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['manufacturers'] = ['Manufacturers', 'Empty: all.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['productTypes'] = ['Product types', 'Empty: all.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['memberGroups'] = ['Customer groups', 'Empty: everyone, guests included.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['maxUses'] = ['Uses in total', '0: unlimited. Counted at the purchase; a cancellation gives the use back.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['maxUsesPerCustomer'] = ['Uses per customer', '0: unlimited. Members by account, guests by e-mail address.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['published'] = ['Active', 'Applies only while ticked and within its period.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['validFrom'] = ['Valid from', 'Date and time, inclusive. Empty: at once.'];
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['validTo'] = ['Valid to', 'Date and time, exclusive. Empty: no end.'];

$GLOBALS['TL_LANG']['tl_consho_cart_rule']['title_legend'] = 'Rule';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['effect_legend'] = 'Effect';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['condition_legend'] = 'Applies to — every field filled in must match';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['limit_legend'] = 'Uses';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['publish_legend'] = 'Period';
$GLOBALS['TL_LANG']['tl_consho_cart_rule']['currencyMissing'] = 'A fixed amount or a minimum order value needs a currency — without one the rule applies to no basket.';
