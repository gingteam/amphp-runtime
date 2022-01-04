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
            $serverRequest = new ServerRequest(
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                yield $request->getBody()->buffer(),
                $request->getProtocolVersion(),
                $this->prepareForServer($request)
            );

            $next = function (ServerRequestInterface $psrRequest) use ($request) {
                $request->setAttribute(ServerRequestInterface::class, $psrRequest);
            };
            $requestBodyParser = new RequestBodyParserMiddleware();
            $requestBodyParser($serverRequest, $next);

            return yield $requestHandler->handleRequest($request);
        });
    }

    public function prepareForServer(Request $request): array
    {
        $client = $request->getClient()->getRemoteAddress();

        $server = [
            'REMOTE_ADDR'     => $client->getHost(),
            'REMOTE_PORT'     => $client->getPort(),
            'SERVER_PROTOCOL' => 'HTTP/'.$request->getProtocolVersion(),
            'SERVER_SOFTWARE' => 'Amphp HTTP Server',
        ];

        foreach ($request->getHeaders() as $key => $value) {
            $key = \strtoupper(\str_replace('-', '_', (string) $key));
            if (\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $server[$key] = \implode(', ', $value);
            } else {
                $server['HTTP_'.$key] = \implode(', ', $value);
            }
        }

        return \array_merge($server, $_SERVER);
    }
}
