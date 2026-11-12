<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Consultas;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Las consultas públicas de Hacienda (sin autenticación): contribuyentes, exoneraciones, CABYS y tipo de cambio.
 *
 * Límites de Hacienda: 10 req/s por IP; pasarse bloquea la IP 10 minutos, y repetir consultas del mismo dato lo provoca.
 * Este cliente no guarda nada: quien lo usa debe poner una caché y un limitador. Un dato que no existe (404) devuelve null; si la API
 * no responde se lanza {@see ConsultaNoDisponible} para que el llamador decida (por ejemplo, guardar «no verificado»).
 */
final class ConsultaPublicaClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $base = 'https://api.hacienda.go.cr',
        private readonly int $timeout = 5,
    ) {}

    /** La cédula sin guiones ni espacios, o null si no tiene 9 a 12 dígitos. */
    public static function normalizarIdentificacion(string $identificacion): ?string
    {
        $limpia = preg_replace('/[\s\-.]/', '', $identificacion) ?? '';

        return preg_match('/^\d{9,12}$/', $limpia) === 1 ? $limpia : null;
    }

    /** "al-00460853-20" se normaliza a mayúsculas; "AL-0460853-20" no es válido. */
    public static function normalizarAutorizacion(string $autorizacion): ?string
    {
        $a = strtoupper(trim($autorizacion));

        return preg_match('/^AL-\d{8}-\d{2}$/', $a) === 1 ? $a : null;
    }

    public function contribuyente(string $identificacion): ?Contribuyente
    {
        $id = self::normalizarIdentificacion($identificacion) ?? throw new InvalidArgumentException('La identificación debe tener de 9 a 12 dígitos.');
        $json = $this->obtener('/fe/ae', ['identificacion' => $id]);

        if (! is_array($json) || ! isset($json['nombre'])) {
            return null;
        }

        $situacion = is_array($json['situacion'] ?? null) ? $json['situacion'] : [];
        $actividades = [];

        foreach (is_array($json['actividades'] ?? null) ? $json['actividades'] : [] as $a) {
            if (is_array($a)) {
                $actividades[] = new Actividad((string) ($a['codigo'] ?? ''), (string) ($a['descripcion'] ?? ''), (string) ($a['estado'] ?? ''), (string) ($a['tipo'] ?? ''));
            }
        }

        $regimen = is_array($json['regimen'] ?? null) ? ($json['regimen']['descripcion'] ?? null) : null;
        $mensaje = $situacion['mensaje'] ?? null;

        return new Contribuyente(
            $id,
            (string) $json['nombre'],
            (string) ($json['tipoIdentificacion'] ?? ''),
            is_string($regimen) ? $regimen : null,
            (string) ($situacion['estado'] ?? ''),
            strtoupper((string) ($situacion['moroso'] ?? 'NO')) === 'SI',
            strtoupper((string) ($situacion['omiso'] ?? 'NO')) === 'SI',
            is_string($mensaje) && $mensaje !== '' ? $mensaje : null,
            $actividades,
        );
    }

    public function exoneracion(string $autorizacion): ?ExoneracionAutorizada
    {
        $numero = self::normalizarAutorizacion($autorizacion) ?? throw new InvalidArgumentException('La autorización debe tener el formato AL-00000000-00.');
        $j = $this->obtener('/fe/ex', ['autorizacion' => $numero]);

        if (! is_array($j) || ! isset($j['numeroDocumento'], $j['fechaVencimiento'], $j['fechaEmision'])) {
            return null;
        }

        $tipo = is_array($j['tipoDocumento'] ?? null) ? $j['tipoDocumento'] : [];

        return new ExoneracionAutorizada(
            (string) $j['numeroDocumento'],
            (string) ($j['identificacion'] ?? ''),
            (string) ($tipo['codigo'] ?? ''),
            (string) ($tipo['descripcion'] ?? ''),
            (string) ($j['CodigoInstitucion'] ?? $j['codigoInstitucion'] ?? ''),
            (string) ($j['nombreInstitucion'] ?? ''),
            (float) ($j['porcentajeExoneracion'] ?? 0),
            new DateTimeImmutable((string) $j['fechaEmision']),
            new DateTimeImmutable((string) $j['fechaVencimiento']),
            (bool) ($j['poseeCabys'] ?? false),
            array_values(array_map('strval', is_array($j['cabys'] ?? null) ? $j['cabys'] : [])),
            isset($j['tipoAutorizacion']) ? (string) $j['tipoAutorizacion'] : null,
        );
    }

    /**
     * Busca en el catálogo CABYS por texto (mínimo 3 caracteres).
     *
     * @return array{total: int, items: list<CabysItem>}
     */
    public function buscarCabys(string $texto, int $top = 20): array
    {
        if (mb_strlen(trim($texto)) < 3) {
            return ['total' => 0, 'items' => []];
        }

        $j = $this->obtener('/fe/cabys', ['q' => trim($texto), 'top' => max(1, min($top, 100))]);

        return [
            'total' => is_array($j) ? (int) ($j['total'] ?? 0) : 0,
            'items' => is_array($j) ? $this->cabysItems($j['cabys'] ?? []) : [],
        ];
    }

    public function cabys(string $codigo): ?CabysItem
    {
        if (preg_match('/^\d{13}$/', $codigo) !== 1) {
            throw new InvalidArgumentException('El código CABYS debe tener 13 dígitos.');
        }

        $j = $this->obtener('/fe/cabys', ['codigo' => $codigo]);

        return is_array($j) ? ($this->cabysItems($j)[0] ?? null) : null;
    }

    /** @return array{fecha: string, valor: float}|null el tipo de cambio de venta del dólar */
    public function tipoCambioVenta(): ?array
    {
        $j = $this->obtener('/indicadores/tc/dolar', []);
        $v = is_array($j) ? ($j['venta'] ?? null) : null;

        return is_array($v) && isset($v['valor']) ? ['fecha' => (string) ($v['fecha'] ?? ''), 'valor' => (float) $v['valor']] : null;
    }

    /** @return list<CabysItem> */
    private function cabysItems(mixed $filas): array
    {
        $items = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (is_array($f) && isset($f['codigo'])) {
                $items[] = new CabysItem((string) $f['codigo'], (string) ($f['descripcion'] ?? ''), (float) ($f['impuesto'] ?? 0), array_values(array_map('strval', is_array($f['categorias'] ?? null) ? $f['categorias'] : [])));
            }
        }

        return $items;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>|null null si el dato no existe
     */
    private function obtener(string $ruta, array $query): ?array
    {
        try {
            $resp = $this->http->timeout($this->timeout)->connectTimeout($this->timeout)->acceptJson()->get($this->base.$ruta, $query);
        } catch (ConnectionException $e) {
            throw new ConsultaNoDisponible('Hacienda no respondió: '.$e->getMessage(), 0, $e);
        }

        return $this->leer($resp);
    }

    /** @return array<mixed>|null */
    private function leer(Response $resp): ?array
    {
        if ($resp->status() === 429) {
            throw new LimiteExcedido('Hacienda limitó las consultas (HTTP 429); hay que esperar unos minutos.');
        }

        // 404: no existe. 400/403 (a veces con el cuerpo en HTML): el formato no es válido para Hacienda.
        if (in_array($resp->status(), [400, 403, 404], true)) {
            return null;
        }

        if (! $resp->successful()) {
            throw new ConsultaNoDisponible("Hacienda respondió HTTP {$resp->status()}.");
        }

        $json = $resp->json();

        return is_array($json) ? $json : null;
    }
}
