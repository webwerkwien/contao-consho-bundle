<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Address;

/**
 * An address, independent of where it is stored.
 *
 * The same shape serves the member record and the frozen copy on an order.
 * Immutable: normalising returns a new instance rather than mutating, so
 * "normalise, then freeze" cannot be got the wrong way round by accident.
 */
final class Address
{
    public function __construct(
        public readonly string $company = '',
        public readonly string $salutation = '',
        public readonly string $firstname = '',
        public readonly string $lastname = '',
        public readonly string $street = '',
        public readonly string $postal = '',
        public readonly string $city = '',
        public readonly string $state = '',
        public readonly string $country = '',
        public readonly string $phone = '',
        public readonly string $email = '',
    ) {
    }

    /**
     * Nothing filled in at all.
     *
     * Used to tell "the visitor has not touched the address yet" from "the
     * visitor has been here and cleared a field" — the first is the only state
     * in which the checkout fills in what the member record holds. A single
     * remaining character is enough to make it theirs: somebody who deletes
     * their first name because they order as a company must not get it back on
     * the next page view.
     */
    public function isEmpty(): bool
    {
        return '' === $this->company.$this->salutation.$this->firstname.$this->lastname
            .$this->street.$this->postal.$this->city.$this->state.$this->country
            .$this->phone.$this->email;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function with(array $values): self
    {
        return new self(
            (string) ($values['company'] ?? $this->company),
            (string) ($values['salutation'] ?? $this->salutation),
            (string) ($values['firstname'] ?? $this->firstname),
            (string) ($values['lastname'] ?? $this->lastname),
            (string) ($values['street'] ?? $this->street),
            (string) ($values['postal'] ?? $this->postal),
            (string) ($values['city'] ?? $this->city),
            (string) ($values['state'] ?? $this->state),
            (string) ($values['country'] ?? $this->country),
            (string) ($values['phone'] ?? $this->phone),
            (string) ($values['email'] ?? $this->email),
        );
    }
}
