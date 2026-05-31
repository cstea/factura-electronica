<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Enums;

enum Situacion: string
{
    case Normal = '1';
    case Contingencia = '2';
    case SinInternet = '3';
}
