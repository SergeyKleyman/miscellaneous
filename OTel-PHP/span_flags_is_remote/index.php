<?php

/** @noinspection PhpInternalEntityUsedInspection, PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace MyTestApp;

use OpenTelemetry\Contrib\Otlp\ProtobufSerializer;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Trace\V1\ResourceSpans;
use Opentelemetry\Proto\Trace\V1\ScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span as OTelProtoSpan;
use Opentelemetry\Proto\Trace\V1\Span\SpanKind as OTelProtoSpanKind;
use Opentelemetry\Proto\Trace\V1\SpanFlags as OTelProtoSpanFlags;
use RuntimeException;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/vendor/autoload.php';

function myAssert(bool $cond, string $message = ''): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

function printLine(string $text): void
{
    /** @var ?bool $isDefined */
    static $isDefined = null;

    if ($isDefined === null) {
        if (defined('STDOUT')) {
            $isDefined = true;
        } else {
            define('STDOUT', fopen('php://stdout', 'w'));
            $isDefined = defined('STDOUT');
        }
    }

    if ($isDefined) {
        fwrite(STDOUT, $text . PHP_EOL);
        fflush(STDOUT);
    }
}

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @phpstan-param TKey                $key
 * @phpstan-param array<TKey, TValue> $array
 *
 * @param-out TValue                  $valueOut
 *
 * @phpstan-assert-if-true TValue     $valueOut
 */
function getValueIfKeyExists(int|string $key, array $array, /* out */ mixed &$valueOut): bool
{
    if (!array_key_exists($key, $array)) {
        return false;
    }

    $valueOut = $array[$key];
    return true;
}

/**
 * @template T
 * @param    T[] $array
 * @return   T
 */
function getArrayFirstValue(array $array): mixed
{
    return $array[array_key_first($array)];
}

/**
 * @template T
 * @param    T[] $array
 * @return   T
 */
function getArraySingleValue(array $array): mixed
{
    myAssert(count($array) === 1);
    return getArrayFirstValue($array);
}

function getSingleHeaderValue(string $headerName, array $headers): string
{
    myAssert(getValueIfKeyExists($headerName, $headers, /* out */ $values));
    return getArraySingleValue($values);
}

function jsonEncode(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}

function convertIdToString(string $binaryId): ?string
{
    if (empty($binaryId)) {
        return null;
    }

    /** @var int[] $idAsBytesSeq */
    $idAsBytesSeq = [];
    for ($i = 0; $i < strlen($binaryId); ++$i) {
        $idAsBytesSeq[] = ord($binaryId[$i]);
    }

    $result = '';
    foreach ($idAsBytesSeq as $byte) {
        $result .= sprintf('%02x', $byte);
    }
    return $result;
}

const FROM_OTEL_PROTO_SPAN_KIND = [
    OTelProtoSpanKind::SPAN_KIND_UNSPECIFIED => 'unspecified',
    OTelProtoSpanKind::SPAN_KIND_INTERNAL => 'internal',
    OTelProtoSpanKind::SPAN_KIND_CLIENT => 'client',
    OTelProtoSpanKind::SPAN_KIND_SERVER => 'server',
    OTelProtoSpanKind::SPAN_KIND_PRODUCER => 'producer',
    OTelProtoSpanKind::SPAN_KIND_CONSUMER => 'consumer',
];

function fromOTelProtoSpanKind(int $otelProtoSpanKind): string
{
    if (getValueIfKeyExists($otelProtoSpanKind, FROM_OTEL_PROTO_SPAN_KIND, /* out */ $result)) {
        return $result;
    }

    throw new RuntimeException('Unexpected span kind: ' . $otelProtoSpanKind);
}

const SPAN_FLAGS_MASKS_TO_NAME = [
    OTelProtoSpanFlags::SPAN_FLAGS_CONTEXT_HAS_IS_REMOTE_MASK => 'HAS_IS_REMOTE',
    OTelProtoSpanFlags::SPAN_FLAGS_CONTEXT_IS_REMOTE_MASK     => 'IS_REMOTE',
];

function convertSpanFlagsToString(int $flags): string
{
    $result = strval($flags);

    $foundFlagNames = [];
    foreach (SPAN_FLAGS_MASKS_TO_NAME as $mask => $name) {
        if (($flags & $mask) !== 0) {
            $foundFlagNames[] = $name;
        }
    }
    $foundFlagNamesAsString = implode(' | ', $foundFlagNames);

    if ($foundFlagNamesAsString !== '') {
        $result .= ' (' . $foundFlagNamesAsString . ')';
    }
    return $result;
}

function printSpan(OTelProtoSpan $span): void
{
    $spanProps = [
        'name'         => $span->getName(),
        'kind'         => fromOTelProtoSpanKind($span->getKind()),
        'traceId'      => convertIdToString($span->getTraceId()),
        'id'           => convertIdToString($span->getSpanId()),
        'parentSpanId' => convertIdToString($span->getParentSpanId()),
        'flags'        => convertSpanFlagsToString($span->getFlags()),
    ];
    printLine(jsonEncode(['Span' => $spanProps]));
}

function printTraces(Request $request): void
{
    myAssert($request->getBody()->getSize() !== 0, 'Intake API request should not have empty body');

    $body = $request->getBody()->getContents();
    $contentLength = intval(getSingleHeaderValue('Content-Length', $request->getHeaders()));
    myAssert(strlen($body) === $contentLength);

    $contentType = getSingleHeaderValue('Content-Type', $request->getHeaders());
    myAssert($contentType === 'application/x-protobuf');

    $serializer = ProtobufSerializer::getDefault();
    $exportTraceServiceRequest = new ExportTraceServiceRequest();
    $serializer->hydrate($exportTraceServiceRequest, $body);

    foreach ($exportTraceServiceRequest->getResourceSpans() as $resourceSpans) {
        myAssert($resourceSpans instanceof ResourceSpans);
        $scopeSpansRepeatedField = $resourceSpans->getScopeSpans();
        foreach ($scopeSpansRepeatedField as $scopeSpans) {
            myAssert($scopeSpans instanceof ScopeSpans);
            $spansRepeatedField = $scopeSpans->getSpans();
            foreach ($spansRepeatedField as $span) {
                myAssert($span instanceof OTelProtoSpan);
                printSpan($span);
            }
        }
    }
}

function printRequestTarget(Request $request, Response $response): Response
{
    printLine("Received request for {$request->getRequestTarget()}");
    return $response;
}

function main(): void
{
    $app = AppFactory::create();

    $app->get('/before_fix_without_traceparent', printRequestTarget(...));
    $app->get('/before_fix_with_traceparent', printRequestTarget(...));
    $app->get('/after_fix_without_traceparent', printRequestTarget(...));
    $app->get('/after_fix_with_traceparent', printRequestTarget(...));

    $app->post(
        '/v1/traces',
        function (Request $request, Response $response): Response {
            printLine('Received request for /v1/traces');
            printTraces($request);
            $response->withStatus(202);
            return $response;
        }
    );

    $app->run();
}

main();
