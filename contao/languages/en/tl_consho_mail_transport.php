<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['title'] = ['Name', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['type'] = ['Type', 'How mail is sent.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['host'] = ['Server', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['port'] = ['Port', 'Usually 587 with TLS, 465 with SSL.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['encryption'] = ['Encryption', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['username'] = ['User name', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['password'] = ['Password', 'Stored encrypted and therefore never shown again. Leaving it empty keeps the stored password; delete the transport to remove it.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['tenantId'] = ['Directory ID (tenant)', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['clientId'] = ['Application ID (client)', ''];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['clientSecret'] = ['Client secret', 'Stored encrypted and therefore never shown again. Leaving it empty keeps the stored value.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['senderAddress'] = ['Sender address', 'Must match the authenticated domain, or mail ends up in spam.'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['senderName'] = ['Sender name', ''];

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['title_legend'] = 'Name';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['server_legend'] = 'Server';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['oauth_legend'] = 'Microsoft 365 access';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['sender_legend'] = 'Sender';

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['types'] = [
    'smtp' => 'SMTP',
    'graph' => 'Microsoft Graph (Microsoft 365)',
    'sendmail' => 'sendmail (local) — only when the deliverability check passes',
];

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['encryptions'] = [
    'tls' => 'TLS (recommended, port 587)',
    'ssl' => 'SSL (port 465)',
    'none' => 'none — defensible only on your own network',
];

$GLOBALS['TL_LANG']['tl_consho_mail_transport']['new'] = ['New transport', 'Create a mail transport'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['edit'] = ['Edit', 'Edit transport ID %s'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['delete'] = ['Delete', 'Delete transport ID %s'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['show'] = ['Details', 'Show details of transport ID %s'];

// "Testmail senden" (C-9).
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testMail'] = ['Send test mail', 'Send a test mail through mail configuration ID %s'];
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testLegend'] = 'Test mail';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testRecipient'] = 'Recipient';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testHelp'] = 'The sender is %s — the same as for the order confirmations. An address at another provider is the best test.';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testSubmit'] = 'Send test mail';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testBack'] = 'Back';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testSent'] = 'The mail server accepted the test mail to %s. Whether it arrives, the mailbox will tell.';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testFailed'] = 'The test mail was not accepted. Answer: %s';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['testInvalid'] = '"%s" is not a valid e-mail address.';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['deliverabilityLegend'] = 'Deliverability';
$GLOBALS['TL_LANG']['tl_consho_mail_transport']['deliverabilityClean'] = 'SPF and DMARC are in place, and the sender matches the login. Whether a mail ends up in spam is still up to the receiving side.';
