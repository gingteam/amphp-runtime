<?php

declare(strict_types=1);

namespace GingTeam\AmphpRuntime;

use function Amp\ByteStream\getStdout;
use Amp\ByteStream\IteratorStream;
use Amp\Cluster\Cluster;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Options;

use function Amp\Http\Server\Middleware\stack;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Producer;
use Amp\Promise;
use Amp\ReactAdapter\ReactAdapter;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop as ReactLoop;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

class Runner implements RunnerInterface
{
    private $kernel;

    private $httpFoundationFactory;

    public function __construct(HttpKernelInterface $kernel)
    {
        $this->kernel                = $kernel;
        $this->httpFoundationFactory = new HttpFoundationFactory();
    }

    public function run(): int
    {
        ReactLoop::set(ReactAdapter::get());
        Loop::run(function () {
            $sockets = yield Cluster::listen('0.0.0.0:8000');

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
                new PsrMiddleware(),
            );

            $documentRoot->setFallback($handler);

            $httpServer = new HttpServer($sockets, $documentRoot, $logger, (new Options())->withCompression());

            yield $httpServer->start();

            Cluster::onTerminate(function () use ($httpServer): Promise {
                return $httpServer->stop();
            });
        });

        return 0;
    }

    public function handle(Request $request)
    {
        $sfRequest = $this->httpFoundationFactory
            ->createRequest(
                $request->getAttribute(ServerRequestInterface::class)
            );

        $sfResponse = $this->kernel->handle($sfRequest);

        try {
            $response = new Response(
                $sfResponse->getStatusCode(),
                $sfResponse->headers->all()
            );

            if ($sfResponse instanceof StreamedResponse || $sfResponse instanceof BinaryFileResponse) {
                $response->setBody(
                    new IteratorStream(
                        new Producer(function (callable $emit) use ($sfResponse) {
                            ob_start(function ($buffer) use ($emit) {
                                yield $emit($buffer);

                                return '';
                            });
                            $sfResponse->sendContent();
                            ob_end_clean();
                        })
                    )
                );
            } else {
                $response->setBody($sfResponse->getContent());
            }

            return $response;
        } finally {
            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($sfRequest, $sfResponse);
            }
        }
    }
}
