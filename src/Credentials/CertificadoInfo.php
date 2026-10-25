<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Credentials;

use DateTimeImmutable;

/** What can be known about a signing certificate without using it. */
final readonly class CertificadoInfo
{
    public function __construct(
        public string $sujeto,
        public DateTimeImmutable $desde,
        public DateTimeImmutable $vence,
        public int $bits,
    ) {}

    public function vencido(?DateTimeImmutable $ahora = null): bool
    {
        return ($ahora ?? new DateTimeImmutable) > $this->vence;
    }

    /** Whole days until it expires (negative when it already did). */
    public function diasRestantes(?DateTimeImmutable $ahora = null): int
    {
        $ahora ??= new DateTimeImmutable;

        return (int) floor(($this->vence->getTimestamp() - $ahora->getTimestamp()) / 86400);
    }
}
