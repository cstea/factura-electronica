<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Signing;

final class SignaturePolicy
{
    public const XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';

    public const SIGNED_PROPERTIES_TYPE = 'http://uri.etsi.org/01903#SignedProperties';

    public const POLICY_URL = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/Resoluci%C3%B3n_General_sobre_disposiciones_t%C3%A9cnicas_comprobantes_electr%C3%B3nicos_para_efectos_tributarios.pdf';

    public const POLICY_DIGEST_SHA256_B64 = 'DWxin1xWOeI8OuWQXazh4VjLWAaCLAA954em7DMh0h8=';
}
