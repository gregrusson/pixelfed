<?php

namespace App\Services\WebPush;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;

/** CurlFactory can retry a failed rewind internally, even without retry middleware. */
final class SingleRequestCurlFactory implements CurlFactoryInterface
{
    private bool $used = false;

    private CurlFactory $factory;

    public function __construct()
    {
        $this->factory = new CurlFactory(0);
    }

    public function create(#[\SensitiveParameter] RequestInterface $request, #[\SensitiveParameter] array $options): EasyHandle
    {
        if ($this->used) {
            throw new DeliveryException('transport_retry_refused', true);
        }
        $this->used = true;

        return $this->factory->create($request, $options);
    }

    public function release(EasyHandle $easy): void
    {
        $this->factory->release($easy);
    }
}
