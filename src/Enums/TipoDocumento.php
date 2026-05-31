<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Enums;

enum TipoDocumento: string
{
    case Factura = 'FE';
    case NotaDebito = 'ND';
    case NotaCredito = 'NC';
    case Tiquete = 'TE';
    case MensajeReceptorAceptado = 'MRA';
    case MensajeReceptorParcial = 'MRP';
    case MensajeReceptorRechazado = 'MRR';
    case FacturaCompra = 'FEC';
    case FacturaExportacion = 'FEE';

    /** Two-digit Hacienda tipo code used in clave + consecutivo. */
    public function codigo(): string
    {
        return match ($this) {
            self::Factura => '01',
            self::NotaDebito => '02',
            self::NotaCredito => '03',
            self::Tiquete => '04',
            self::MensajeReceptorAceptado => '05',
            self::MensajeReceptorParcial => '06',
            self::MensajeReceptorRechazado => '07',
            self::FacturaCompra => '08',
            self::FacturaExportacion => '09',
        };
    }
}
