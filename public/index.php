<?php
declare(strict_types=1);

use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\Tracer;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Routing\RouteContext;
use Psr\Log\LoggerInterface;
use OpenTelemetry\Contrib\Jaeger\Exporter as JaegerExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

require __DIR__ . '/../vendor/autoload.php';

// Instantiate PHP-DI ContainerBuilder
$containerBuilder = new ContainerBuilder();

// Set up settings
$settings = require __DIR__ . '/../app/settings.php';
$settings($containerBuilder);

// Set up dependencies
$dependencies = require __DIR__ . '/../app/dependencies.php';
$dependencies($containerBuilder);

// Add OTel
//$tracerProvider = (new TracerProviderFactory('quoteservice'))->create();
$exporter = JaegerExporter::fromConnectionString('http://localhost:9412/api/v2/spans', 'QuoteService manually instrumented');
$tracerProvider = new TracerProvider(
    new SimpleSpanProcessor($exporter)
);

ShutdownHandler::register([$tracerProvider, 'shutdown']);
$tracer = $tracerProvider->getTracer('io.opentelemetry.contrib.php');

$containerBuilder->addDefinitions([
    Tracer::class => $tracer
]);

// Build PHP-DI Container instance
$container = $containerBuilder->build();

// Instantiate the app
AppFactory::setContainer($container);
$app = Bridge::create($container);
// Register middleware
//middleware starts root span based on route pattern, sets status from http code
$app->add(function (Request $request, RequestHandler $handler) use ($tracer, $container) {
    $logger = $container->get(LoggerInterface::class);
    $logger->info('add');

    $parent = TraceContextPropagator::getInstance()->extract($request->getHeaders());
    $routeContext = RouteContext::fromRequest($request);
    $route = $routeContext->getRoute();
    $root = $tracer->spanBuilder($route->getPattern())
        ->setStartTimestamp((int) ($request->getServerParams()['REQUEST_TIME_FLOAT'] * 1e9))
        ->setParent($parent)
        ->setSpanKind(SpanKind::KIND_SERVER)
        ->startSpan();
    $scope = $root->activate();

    try {
        $response = $handler->handle($request);
        $root->setStatus($response->getStatusCode() < 500 ? StatusCode::STATUS_OK : StatusCode::STATUS_ERROR);
    } finally {
        $root->end();
        $scope->detach();
    }

    return $response;
});
$app->addRoutingMiddleware();

// Register routes
$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

// Create Request object from globals
$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Run App
$app->run();
$tracerProvider->shutdown();
