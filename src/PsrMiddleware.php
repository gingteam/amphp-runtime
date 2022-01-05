<?php

declare(strict_types=1);

namespace GingTeam\AmphpRuntime;

use function Amp\call;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Promise;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;
use React\Http\Middleware\RequestBodyParserMiddleware;

class PsrMiddleware implements Middleware
{
    public function handleRequest(Request $request, RequestHandler $requestHandler): Promise
    {
        return call(function () use ($request, $requestHandler) {
            $client = $request->getClient()->getRemoteAddress();
            $severParams = [
                'REMOTE_ADDR' => $client->getHost(),
                'REMOTE_PORT' => $client->getPort(),
            ];

            $serverRequest = new ServerRequest(
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                yield $request->getBody()->buffer(),
                $request->getProtocolVersion(),
                $severParams + $_SERVER
            );

            $next = function (ServerRequestInterface $psrRequest) use ($request) {
                $request->setAttribute(ServerRequestInterface::class, $psrRequest);
            };
            $requestBodyParser = new RequestBodyParserMiddleware();
            $requestBodyParser($serverRequest, $next);

            return yield $requestHandler->handleRequest($request);
        });
    }
}
