<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who legally operates this site, for the identity block the law requires.
 *
 * **The duty.** Dutch art. 3:15d BW, implementing Article 5 of the EU
 * e-Commerce Directive, makes every information-society service, not only a
 * shop, publish in a form that is directly and permanently accessible: its
 * legal name, its geographic address, an email address, its Chamber of Commerce
 * number, and its VAT identification number where it is VAT-registered. GDPR
 * Article 13(1)(a) separately wants the identity and contact details of the
 * data controller, which the privacy page names as BikeCoders without ever
 * saying where that is. One block answers both.
 *
 * **Missing is shown, not hidden.** {@see isComplete()} is false until the
 * legally required fields are set, and the page then says so. The alternative,
 * rendering whatever happens to be configured and staying quiet about the rest,
 * produces a block that looks like compliance and is not, which is worse than
 * an obviously empty one. Nothing here has a plausible-looking default for the
 * same reason: a placeholder KvK number is a false statement about a real
 * register.
 *
 * @see docs/specs/contact-and-support.md §2
 *
 * @api
 */
final readonly class OrganisationIdentity
{
    public function __construct(
        #[Autowire('%cc.organisation.name%')]
        private string $name,
        #[Autowire('%cc.organisation.legal_form%')]
        private string $legalForm,
        #[Autowire('%cc.organisation.street%')]
        private string $street,
        #[Autowire('%cc.organisation.postcode%')]
        private string $postcode,
        #[Autowire('%cc.organisation.city%')]
        private string $city,
        #[Autowire('%cc.organisation.country%')]
        private string $country,
        #[Autowire('%cc.organisation.registration_number%')]
        private string $registrationNumber,
        #[Autowire('%cc.organisation.vat_number%')]
        private string $vatNumber,
        #[Autowire('%cc.organisation.contact_email%')]
        private string $contactEmail,
        #[Autowire('%cc.organisation.phone%')]
        private string $phone,
    ) {
    }

    public function name(): ?string
    {
        return $this->clean($this->name);
    }

    /** "Eenmanszaak", "B.V.", "Stichting": the form the register records. */
    public function legalForm(): ?string
    {
        return $this->clean($this->legalForm);
    }

    /** @return list<string> address lines, in postal order, blanks dropped */
    public function addressLines(): array
    {
        $lines = [
            $this->clean($this->street),
            trim($this->clean($this->postcode).' '.$this->clean($this->city)),
            $this->clean($this->country),
        ];

        return array_values(array_filter($lines, static fn (?string $l): bool => null !== $l && '' !== $l));
    }

    public function registrationNumber(): ?string
    {
        return $this->clean($this->registrationNumber);
    }

    public function vatNumber(): ?string
    {
        return $this->clean($this->vatNumber);
    }

    public function contactEmail(): ?string
    {
        return $this->clean($this->contactEmail);
    }

    /** Optional everywhere: the Directive asks for an email, not a telephone. */
    public function phone(): ?string
    {
        return $this->clean($this->phone);
    }

    /**
     * Are the legally required fields all present?
     *
     * The VAT number is excluded on purpose. It is required only of an operator
     * that is VAT-registered, and a foundation that is not would be made
     * permanently "incomplete" by including it here.
     */
    public function isComplete(): bool
    {
        return null !== $this->name()
            && [] !== $this->addressLines()
            && null !== $this->registrationNumber()
            && null !== $this->contactEmail();
    }

    /**
     * @return list<string> the field labels still missing, as translation keys,
     *                      so the page can say exactly what is not configured
     */
    public function missing(): array
    {
        $missing = [];
        if (null === $this->name()) {
            $missing[] = 'support.legal.field_name';
        }
        if ([] === $this->addressLines()) {
            $missing[] = 'support.legal.field_address';
        }
        if (null === $this->registrationNumber()) {
            $missing[] = 'support.legal.field_registration';
        }
        if (null === $this->contactEmail()) {
            $missing[] = 'support.legal.field_email';
        }

        return $missing;
    }

    private function clean(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
