<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\SoapClient;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use SoapClient;
use Throwable;

class SoapClientInstrumentation
{
    public const NAME = 'soap-client';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.soap-client',
            null,
            'https://opentelemetry.io/schemas/1.30.0',
        );

        hook(
            SoapClient::class,
            '__doRequest',
            pre: function (SoapClient $soapClient, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                [$request, $location, $action, $version, $oneWay] = array_pad($params, 5, '');
                    
                $span = $instrumentation->tracer()->spanBuilder(sprintf('%s::%s', $class, $function))
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, strlen($request))
                    ->setAttribute(SoapClientAttributes::SOAP_LOCATION, $location)
                    ->setAttribute(SoapClientAttributes::SOAP_ACTION, $action)
                    ->setAttribute(SoapClientAttributes::SOAP_VERSION, $version)
                    ->setAttribute(SoapClientAttributes::SOAP_ONE_WAY, $oneWay)
                    ->startSpan();
                
                $headers = $soapClient->__getLastRequestHeaders();
                if ($headers) {
                    $span->setAttribute(TraceAttributes::HTTP_REQUEST_HEADER, $headers);
                }

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: function (SoapClient $soapClient, array $params, mixed $result, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                
                $span = Span::fromContext($scope->context())
                    ->setAttribute(TraceAttributes::HTTP_RESPONSE_HEADER, $soapClient->__getLastResponseHeaders())
                    ->setStatus(StatusCode::STATUS_OK);
                
                if ($result) {
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, strlen($result));
                }
                
                self::endSpan($exception);
            },
        );
    }

    private static function endSpan(?Throwable $exception): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());

        if ($exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}
