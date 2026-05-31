<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Dtos;

use DateTimeInterface;
use Stea\FacturaElectronica\Dtos\Contracts\ComprobanteRequest;
use Stea\FacturaElectronica\Enums\Situacion;

/**
 * Factura Electrónica de Compra (FEC, tipo 08): a self-issued purchase invoice.
 *
 * STEA (the buyer) issues the document and OWNS the clave/consecutivo, but the
 * XML <Emisor> element is the foreign supplier and <Receptor> is STEA. Hence
 * {@see cedulaEmisor()}/{@see tipoIdentificacionEmisor()} return STEA's identity
 * (the clave owner), NOT the supplier's.
 */
final readonly class FacturaCompraDto implements ComprobanteRequest
{
    /** @param LineaDetalleDto[] $lineas */
    public function __construct(
        public string $consecutivo,           // 20 digits
        public DateTimeInterface $fechaEmision,
        public string $proveedorSistemas,
        public string $codigoActividadEmisor,
        public string $codigoActividadReceptor,
        public EmisorDto $emisor,             // foreign supplier
        public ReceptorDto $receptor,         // STEA física (clave owner)
        public array $lineas,
        public ?InformacionReferenciaDto $informacionReferencia = null,
        public string $condicionVenta = '01',
        public string $medioPago = '02',
        public string $moneda = 'USD',
        public float $tipoCambio = 1.0,
        public Situacion $situacion = Situacion::Normal,
    ) {}

    public function consecutivo(): string
    {
        return $this->consecutivo;
    }

    public function fechaEmision(): DateTimeInterface
    {
        return $this->fechaEmision;
    }

    /** STEA's cédula — the clave owner — NOT the supplier's. */
    public function cedulaEmisor(): string
    {
        return $this->receptor->identificacion->numero;
    }

    /** STEA's identification type ('01' física) — the clave owner. */
    public function tipoIdentificacionEmisor(): string
    {
        return $this->receptor->identificacion->tipo;
    }

    public function situacion(): Situacion
    {
        return $this->situacion;
    }

    /** STEA's identification, carried in the recepción payload. */
    public function receptorIdentificacion(): ?IdentificacionDto
    {
        return $this->receptor->identificacion;
    }
}
