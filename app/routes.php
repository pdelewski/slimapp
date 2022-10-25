<?php
declare(strict_types=1);

use OpenTelemetry\API\Trace\AbstractSpan;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\Tracer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Log\LoggerInterface;

function calculateQuote($jsonObject, Tracer $tracer): float
{
    $quote = 0.0;
    $childSpan = $tracer
        ->spanBuilder('calculate-quote-sumo')
        ->setSpanKind(SpanKind::KIND_INTERNAL)
        ->startSpan();
    $childSpan->addEvent('Calculating quote');
    try {
        $numberOfItems = 5; //intval($jsonObject['numberOfItems']);
        $quote = 8.90 * $numberOfItems;

        $childSpan->setAttribute('app.quote.items.count', $numberOfItems);
        $childSpan->setAttribute('app.quote.cost.total', $quote);

        $childSpan->addEvent('Quote calculated, returning its value');
    } catch (\Exception $exception) {
        $childSpan->recordException($exception);
    } finally {
        $childSpan->end();
        return $quote;
    }
}

return function (App $app) {
    $container = $app->getContainer();
    $app->get('/getquote', function (Request $request, Response $response, Tracer $tracer) use ($container) {
        $logger = $container->get(LoggerInterface::class);
        $logger->info('getquote');
        $span = AbstractSpan::getCurrent();
        $span->addEvent('Received get quote request, processing it');

        $body = $request->getBody()->getContents();
        $jsonObject = json_decode($body, true);

        $data = calculateQuote($jsonObject, $tracer);

        $payload = json_encode($data);
        $response->getBody()->write($payload);

        $span->addEvent('Quote processed, response sent back', [
            'app.quote.cost.total' => $data
        ]);

        return $response
            ->withHeader('Content-Type', 'application/json');
    });
};
