<?php

declare(strict_types=1);

namespace GingTeam\AmphpRuntime;

use function Amp\call;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Promise;

class SymfonyMiddleware implements Middleware
{
    public function handleRequest(Request $request, RequestHandler $requestHandler): Promise
    {
        return call(function () use ($request, $requestHandler) {
            /** Form $form */
            $form       = $request->getAttribute(Form::class);
            $cookies    = $request->getCookies();
            $parameters = $form->getValues();
            unset($parameters['']);

            array_walk($cookies, function (&$item, $_) {
                $item = $item->getValue();
            });
            array_walk($parameters, function (&$item, $_) {
                $item = 1 === \count($item) ? $item[0] : $item;
            });

            $request->setAttribute('parameters', $parameters);
            $request->setAttribute('cookies', $cookies);

            return yield $requestHandler->handleRequest($request);
        });
    }
}
