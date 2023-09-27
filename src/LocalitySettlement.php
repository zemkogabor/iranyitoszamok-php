<?php

declare(strict_types = 1);

namespace App;

use LogicException;

class LocalitySettlement
{
    /**
     * @param string $name
     * @param string|null $regionName - Speciális esetekben null, pl. Budapest
     * @param array<int, string> $postalCodes - Egy településhez több irányítószám is tartozhat
     */
    public function __construct(
        public string $name,
        public ?string $regionName,
        public array $postalCodes = [],
    )
    {

    }

    /**
     * Hozzáad egy irányítószámot, ha még nem lett hozzáadva
     *
     * @param string $postalCode
     * @return void
     */
    public function addPostalCode(string $postalCode): void
    {
        if (!is_numeric($postalCode) || strlen($postalCode) !== 4) {
            throw new LogicException('Postal code invalid foramt: ' . $postalCode);
        }

        if (in_array($postalCode, $this->postalCodes, true)) {
            return;
        }

        $this->postalCodes[] = $postalCode;
    }
}
