<?php

declare(strict_types=1);

namespace GingTeam\AmphpRuntime;

use function Amp\ByteStream\getStdout;
use Amp\Cluster\Cluster;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

class Runner implements RunnerInterface
{
    private $kernel;

    public function __construct(HttpKernelInterface $kernel)
    {
        $this->kernel = $kernel;
    }

    public function run(): int
    {
        Loop::run(function () {
            $sockets = yield [
                Cluster::listen('0.0.0.0:8000'),
                Cluster::listen('[::]:8000'),
            ];

            if (Cluster::isWorker()) {
                $handler = Cluster::createLogHandler();
            } else {
                $handler = new StreamHandler(getStdout());
                $handler->setFormatter(new ConsoleFormatter());
            }

            $logger = new Logger('worker-'.Cluster::getId());
            $logger->pushHandler($handler);

            $documentRoot = new DocumentRoot(getcwd());
            $documentRoot->setFallback(new CallableRequestHandler([$this, 'handle']));

            $httpServer = new HttpServer($sockets, $documentRoot, $logger);

            yield $httpServer->start();
        });

        return 0;
    }

    public function handle(Request $request)
    {
        $query   = $post   = $cookies   = [];
        $rawBody = yield $request->getBody()->buffer();

        parse_str($request->getUri()->getQuery(), $query);
        parse_str($rawBody, $post);

        foreach ($request->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        $sfRequest = new SymfonyRequest(
            $query,
            $post,
            [],
            $cookies,
            [], // not support files =((
            static::prepareForServer($request),
            $rawBody
        );

        $sfResponse = $this->kernel->handle($sfRequest);

        try {
            return new Response(
                $sfResponse->getStatusCode(),
                $sfResponse->headers->all(),
                $sfResponse->getContent()
            );
        } finally {
            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($sfRequest, $sfResponse);
            }
        }
    }

    public static function prepareForServer(Request $request)
    {
        $client = $request->getClient()->getRemoteAddress();

        $server = [
            'REQUEST_URI'     => (string) $request->getUri(),
            'REQUEST_METHOD'  => $request->getMethod(),
            'REMOTE_ADDR'     => $client->getHost(),
            'REMOTE_PORT'     => $client->getPort(),
            'HTTP_USER_AGENT' => '',
            'SERVER_PROTOCOL' => $request->getProtocolVersion(),
            'SERVER_SOFTWARE' => 'AmpHttp',
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
