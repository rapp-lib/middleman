<?php

namespace mindplay\middleman;

use Interop\Http\Middleware\DelegateInterface;
use Interop\Http\Middleware\MiddlewareInterface;
use Interop\Http\Middleware\ServerMiddlewareInterface;
use InvalidArgumentException;
use LogicException;
use mindplay\readable;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-7 / PSR-15 middleware dispatcher
 */
class Dispatcher implements MiddlewareInterface
{
    /**
     * @var callable middleware resolver
     */
    public $resolver;

    /**
     * @var mixed[] unresolved middleware stack
     */
    public $stack;

    /**
     * @param (callable|MiddlewareInterface|mixed)[] $stack middleware stack (with at least one middleware component)
     * @param callable|null $resolver optional middleware resolver:
     *                                function (string $name): MiddlewareInterface
     *
     * @throws InvalidArgumentException if an empty middleware stack was given
     */
    public function __construct($stack, callable $resolver = null)
    {
        if (count($stack) === 0) {
            throw new InvalidArgumentException("an empty middleware stack was given");
        }

        $this->stack = $stack;
        $this->resolver = $resolver;
    }

    /**
     * Dispatches the middleware stack and returns the resulting `ResponseInterface`.
     *
     * @param RequestInterface $request
     *
     * @return ResponseInterface
     *
     * @throws LogicException on unexpected result from any middleware on the stack
     */
    public function dispatch(RequestInterface $request)
    {
        $resolved = $this->resolve(0);

        return $resolved($request);
    }

    /**
     * @inheritdoc
     */
    public function process(RequestInterface $request, DelegateInterface $delegate)
    {
        $this->stack[] = function (RequestInterface $request) use ($delegate) {
            return $delegate->process($request);
        };

        $response = $this->dispatch($request);

        array_pop($this->stack);

        return $response;
    }

    /**
     * @param int $index middleware stack index
     *
     * @return Delegate
     */
    public function resolve($index)
    {
        if (isset($this->stack[$index])) {
            $_this = $this;
            return new Delegate(function (RequestInterface $request) use ($index, $_this) {
                $middleware = $_this->resolver
                    ? call_user_func($_this->resolver, $_this->stack[$index])
                    : $_this->stack[$index]; // as-is

                switch (true) {
                    case $middleware instanceof MiddlewareInterface:
                    case $middleware instanceof ServerMiddlewareInterface:
                        $result = $middleware->process($request, $_this->resolve($index + 1));
                        break;

                    case is_callable($middleware):
                        $result = $middleware($request, $_this->resolve($index + 1));
                        break;

                    default:
                        $given = readable::callback($middleware);

                        throw new LogicException("unsupported middleware type: {$given}");
                }

                if (! $result instanceof ResponseInterface) {
                    $given = readable::value($result);
                    $source = readable::callback($middleware);

                    throw new LogicException("unexpected middleware result: {$given} returned by: {$source}");
                }

                return $result;
            });
        }

        return new Delegate(function () {
            throw new LogicException("unresolved request: middleware stack exhausted with no result");
        });
    }
}
