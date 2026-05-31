<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function clientId(): string
    {
        return $this === self::Production ? 'api-prod' : 'api-stag';
    }

    // Hacienda IDP + recepción endpoints (v4.4). Both environments share the IDP host;
    // only the Keycloak realm (rut vs rut-stag) differs. recepción base carries a
    // trailing slash (consultar appends the clave directly). Endpoints sourced from
    // the official Hacienda API documentation and verified against the live service.
    public function idpUrl(): string
    {
        return $this === self::Production
            ? 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/token'
            : 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token';
    }

    public function recepcionUrl(): string
    {
        return $this === self::Production
            ? 'https://api.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/'
            : 'https://api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/';
    }
}
