<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Consultas;

use DateTimeImmutable;

/** Una autorización de exoneración tal como la publica Hacienda (GET /fe/ex). */
final readonly class ExoneracionAutorizada
{
    /** @param list<string> $cabys */
    public function __construct(
        public string $numeroDocumento,          // AL-00012345-23
        public string $identificacion,           // cédula del beneficiario
        public string $tipoDocumento,            // nota 10.1: 04, 11…
        public string $tipoDocumentoDescripcion,
        public string $codigoInstitucion,        // nota 23
        public string $nombreInstitucion,
        public float $porcentajeExoneracion,
        public DateTimeImmutable $fechaEmision,
        public DateTimeImmutable $fechaVencimiento,
        public bool $poseeCabys,
        public array $cabys,
        public ?string $tipoAutorizacion = null,
    ) {}

    public function vigente(?DateTimeImmutable $ahora = null): bool
    {
        $ahora ??= new DateTimeImmutable;

        return $ahora >= $this->fechaEmision && $ahora <= $this->fechaVencimiento->setTime(23, 59, 59);
    }

    /** ¿Cubre este código CABYS? Sin lista de CABYS (poseeCabys = false) cubre todo. */
    public function cubre(string $cabys): bool
    {
        return ! $this->poseeCabys || in_array($cabys, $this->cabys, true);
    }
}
