<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

use DateTimeInterface;
use Stea\FacturaElectronica\Dtos\Contracts\ComprobanteRequest;
use Stea\FacturaElectronica\Enums\Situacion;

final readonly class FacturaDto implements ComprobanteRequest
{
    /** @param LineaDetalleDto[] $lineas */
    public function __construct(
        public string $consecutivo,           // 20 digits
        public DateTimeInterface $fechaEmision,
        public string $proveedorSistemas,
        public string $codigoActividadEmisor,
        public EmisorDto $emisor,
        public ReceptorDto $receptor,
        public array $lineas,
        public ?string $codigoActividadReceptor = null,
        public string $condicionVenta = '01',
        public string $medioPago = '04',
        public string $moneda = 'CRC',
        public float $tipoCambio = 1.0,
        public Situacion $situacion = Situacion::Normal,
        /**
         * Optional reference to another comprobante: a factura that replaces
         * one voided for a material error (codigo 15) or one Hacienda rejected
         * (codigo 16) must point at it. See nota 9 and nota 10 of the Anexos y
         * Estructuras v4.4.
         */
        public ?InformacionReferenciaDto $informacionReferencia = null,
    ) {}

    public function consecutivo(): string
    {
        return $this->consecutivo;
    }

    public function fechaEmision(): DateTimeInterface
    {
        return $this->fechaEmision;
    }

    public function cedulaEmisor(): string
    {
        return $this->emisor->identificacion->numero;
    }

    public function tipoIdentificacionEmisor(): string
    {
        return $this->emisor->identificacion->tipo;
    }

    public function situacion(): Situacion
    {
        return $this->situacion;
    }

    public function receptorIdentificacion(): ?IdentificacionDto
    {
        return $this->receptor->identificacion;
    }
}
