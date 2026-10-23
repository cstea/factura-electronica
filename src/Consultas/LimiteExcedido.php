<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Consultas;

/** HTTP 429: la IP superó los límites de Hacienda (se bloquea 10 minutos). Hay que esperar y cachear más. */
final class LimiteExcedido extends ConsultaNoDisponible {}
