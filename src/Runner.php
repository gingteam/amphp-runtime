<?php

declare(strict_types=1);

namespace GingTeam\AmphpRuntime;

use function Amp\ByteStream\getStdout;
use Amp\Cluster\Cluster;
use Amp\Http\Server\FormParser\ParsingMiddleware;
use Amp\Http\Server\HttpServer;
use function Amp\Http\Server\Middleware\stack;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
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

            $handler = stack(
                new CallableRequestHandler([$this, 'handle']),
                new ParsingMiddleware(),
                new SymfonyMiddleware()
            );

            $documentRoot->setFallback($handler);

            $httpServer = new HttpServer($sockets, $documentRoot, $logger);

            yield $httpServer->start();

            Cluster::onTerminate(function () use ($httpServer): Promise {
                return $httpServer->stop();
            });
        });

        return 0;
    }

    public function handle(Request $request)
    {
        $sfRequest = SymfonyRequest::create(
            (string) $request->getUri(),
            $request->getMethod(),
            $request->getAttribute('parameters'),
            $request->getAttribute('cookies'),
            [],
            static::prepareForServer($request),
            yield $request->getBody()->buffer()
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
