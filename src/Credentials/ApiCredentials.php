<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Credentials;

use Stea\FacturaElectronica\Enums\Environment;

final readonly class ApiCredentials
{
    public function __construct(
        public string $username,
        public string $password,
        public Environment $environment,
    ) {}

    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => '***', 'environment' => $this->environment->value];
    }
}
