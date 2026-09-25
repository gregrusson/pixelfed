<?php

namespace App\Services\WebPush;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Single request, single destination, no Laravel HTTP middleware or client discovery. */
class PinnedHttpClient implements ClientInterface
{
    private bool $used = false;

    public function __construct(
        private readonly string $endpoint,
        private readonly BoundedDnsResolver $resolver,
        private readonly array $settings = [],
        private readonly PublicAddressPolicy $addressPolicy = new PublicAddressPolicy,
    ) {}

    public static function assertAvailable(): void
    {
        foreach (['curl_init', 'curl_setopt', 'curl_exec', 'curl_version'] as $function) {
            if (! function_exists($function)) {
                throw new DeliveryException('transport_unavailable');
            }
        }
        if (! extension_loaded('curl') || ! class_exists(CurlHandler::class)
            || ! defined('CURLOPT_CONNECT_TO') || ! defined('CURL_VERSION_SSL')
            || ! (curl_version()['features'] & CURL_VERSION_SSL)
            || ! in_array('https', curl_version()['protocols'], true)) {
            throw new DeliveryException('transport_unavailable');
        }
    }

    public function sendRequest(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        if ($this->used) {
            throw new DeliveryException('transport_already_used');
        }
        $this->used = true;
        self::assertAvailable();
        $canonical = (new EndpointPolicy)->canonicalize((string) $request->getUri());
        if ($canonical !== $this->endpoint || (string) $request->getUri() !== $canonical || $request->getMethod() !== 'POST'
            || $request->getHeaderLine('Host') !== $request->getUri()->getHost()) {
            throw new DeliveryException('request_mismatch');
        }
        $connect = (float) ($this->settings['connect_timeout'] ?? 3);
        $timeout = (float) ($this->settings['request_timeout'] ?? 10);
        if (! is_finite($connect) || ! is_finite($timeout) || $connect < 0.001 || $connect > 3 || $timeout < $connect || $timeout > 10) {
            throw new DeliveryException('invalid_transport_configuration');
        }
        $addresses = $this->resolver->resolve($request->getUri()->getHost(), (float) ($this->settings['dns_timeout'] ?? 2), $this->settings['blocked_cidrs'] ?? []);
        if ($addresses === []) {
            throw new DeliveryException('dns_empty', true);
        }
        // Recheck the complete result at the transport boundary as well.
        foreach ($addresses as $ip) {
            $this->addressPolicy->assertPublic($ip, $this->settings['blocked_cidrs'] ?? []);
        }
        $ip = $addresses[0];
        $target = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $curl = [
            CURLOPT_CONNECT_TO => ['::'.$target.':443'],
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
        ];
        if (defined('CURLOPT_PREREQFUNCTION')) {
            $curl[CURLOPT_PREREQFUNCTION] = static function ($handle, $peer, $local, $port, $localPort) use ($ip) {
                return @inet_pton($peer) === inet_pton($ip) && $port === 443 ? CURL_PREREQFUNC_OK : CURL_PREREQFUNC_ABORT;
            };
        }
        // A user-controlled HTTPS server must not fill memory or temporary disk with a response.
        $sink = Utils::streamFor('');
        $received = 0;
        $limitedSink = FnStream::decorate($sink, [
            'write' => static function (#[\SensitiveParameter] string $data) use ($sink, &$received): int {
                $received += strlen($data);
                if ($received > 65536) {
                    throw new DeliveryException('response_too_large');
                }

                return $sink->write($data);
            },
        ]);
        $options = [
            'handler' => new CurlHandler(['handle_factory' => new SingleRequestCurlFactory]),
            'connect_timeout' => $connect,
            'timeout' => $timeout,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
            'proxy' => '',
            'idn_conversion' => false,
            'cookies' => false,
            'protocols' => ['https'],
            'decode_content' => false,
            'sink' => $limitedSink,
            'curl' => $curl,
        ];
        try {
            $client = $this->createClient($options);

            return $client->sendRequest($request);
        } catch (DeliveryException $e) {
            throw new DeliveryException($e->category, $e->retryable);
        } catch (ConnectException|RequestException $e) {
            // Do not retain the exception/request/context: endpoint and auth headers are secrets.
            $errno = $e->getHandlerContext()['errno'] ?? null;
            $transient = in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_SEND_ERROR, CURLE_RECV_ERROR, CURLE_GOT_NOTHING, CURLE_PARTIAL_FILE, CURLE_SSL_CONNECT_ERROR], true);
            throw new DeliveryException($transient ? 'network_temporary' : 'transport_failure', $transient);
        } catch (Throwable) {
            throw new DeliveryException('transport_failure');
        }
    }

    protected function createClient(#[\SensitiveParameter] array $options): Client
    {
        return new Client($options);
    }
}
