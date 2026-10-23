<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

use DateTimeInterface;
use Stea\FacturaElectronica\Dtos\Contracts\ComprobanteRequest;
use Stea\FacturaElectronica\Enums\Situacion;

final readonly class NotaCreditoDto implements ComprobanteRequest
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
        public InformacionReferenciaDto $informacionReferencia,
        public ?string $codigoActividadReceptor = null,
        public string $condicionVenta = '01',
        public ?string $medioPago = null,
        public string $moneda = 'CRC',
        public float|string $tipoCambio = 1.0,
        public Situacion $situacion = Situacion::Normal,
        /** @var MedioPagoDto[] hasta 4; vacío en ventas a crédito */
        public array $mediosPago = [],
        public ?int $plazoCredito = null,
        public ?string $condicionVentaOtros = null,
        public ?ResumenDto $resumen = null,
        /** @var InformacionReferenciaDto[] referencias adicionales (hasta 10 en total) */
        public array $referencias = [],
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
