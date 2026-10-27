<?php declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

/*
 * "Konten sofort freischalten" on Contao's registration module (Nachlese, KA1).
 *
 * Contao creates every new member disabled (ModuleRegistration::createNewUser,
 * `disable = 1`); the account opens through the activation mail or by hand.
 * A shop needs the customer in at once — a subscription is bought with an
 * account (P12), and a customer who registers in order to buy does not wait
 * for a mail first. Consho\Customer\RegistrationActivation does the work in the
 * `createNewUser` hook; Contao stays the way in.
 *
 * In the e-mail legend, AFTER `reg_activate`, and that order is functional: the
 * two exclude each other (KA2), and the save callback reads `reg_activate`
 * through getActiveRecord(), which only carries the submitted value once its
 * own field has been processed. See RegistrationActivationListener.
 */
PaletteManipulator::create()
    ->addField('consho_activateAtOnce', 'email_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('registration', 'tl_module')
;

$GLOBALS['TL_DCA']['tl_module']['fields']['consho_activateAtOnce'] = [
    'inputType' => 'checkbox',
    'exclude' => true,
    'eval' => ['tl_class' => 'w50 clr'],
    'sql' => ['type' => 'boolean', 'default' => false],
];
