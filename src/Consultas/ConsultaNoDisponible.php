<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Consultas;

use Stea\FacturaElectronica\Exceptions\FacturaElectronicaException;

/** La API pública de Hacienda no respondió (red, 5xx) o no se pudo leer su respuesta. */
class ConsultaNoDisponible extends FacturaElectronicaException {}
