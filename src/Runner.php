<?php

declare(strict_types=1);

namespace GingTeam\AmphpRuntime;

use function Amp\ByteStream\getStdout;
use Amp\ByteStream\IteratorStream;
use Amp\Cluster\Cluster;
use Amp\Http\Server\HttpServer;
use function Amp\Http\Server\Middleware\stack;
use Amp\Http\Server\Options;
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
use React\Http\Io\IniUtil;
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

    private $options;

    public function __construct(HttpKernelInterface $kernel, array $options)
    {
        $this->kernel                = $kernel;
        $this->httpFoundationFactory = new HttpFoundationFactory();
        $this->options               = $options;
    }

    public function run(): int
    {
        ReactLoop::set(ReactAdapter::get());
        Loop::run(function () {
            $socket = yield Cluster::listen(
                sprintf('0.0.0.0:%d', $this->options['port'] ?? 8000)
            );

            if (Cluster::isWorker()) {
                $handler = Cluster::createLogHandler();
            } else {
                $handler = new StreamHandler(getStdout());
                $handler->setFormatter(new ConsoleFormatter());
            }

            $logger = new Logger('cluster-'.Cluster::getId());
            $logger->pushHandler($handler);

            $documentRoot = new DocumentRoot($this->options['document_root'] ?? getcwd());

            $handler = stack(
                new CallableRequestHandler([$this, 'handle']),
                new PsrMiddleware(),
            );

            $documentRoot->setFallback($handler);

            $sizeLimit = \ini_get('post_max_size');
            $httpServer = new HttpServer(
                [$socket],
                $documentRoot,
                $logger,
                (new Options())
                    ->withoutCompression()
                    ->withBodySizeLimit(IniUtil::iniSizeToBytes($sizeLimit))
            );

            yield $httpServer->start();

            Cluster::onTerminate(function () use ($httpServer): Promise {
                return $httpServer->stop();
            });
        });

        return 0;
    }

    public function handle(Request $request)
    {
        $sfRequest = $this->httpFoundationFactory->createRequest(
            $request->getAttribute(ServerRequestInterface::class)
        );

        $sfResponse = $this->kernel->handle($sfRequest);

        try {
            $response = new Response(
                $sfResponse->getStatusCode(),
                $sfResponse->headers->all()
            );

            if ($sfResponse instanceof StreamedResponse
                || $sfResponse instanceof BinaryFileResponse
            ) {
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
