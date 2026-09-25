<?php

namespace App\Services\WebPush;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class BoundedDnsResolver
{
    public function resolve(#[\SensitiveParameter] string $host, float $timeout = 2, array $blocked = []): array
    {
        if ((new EndpointPolicy)->hostname($host) !== $host || ! is_finite($timeout) || $timeout < 0.001 || $timeout > 2) {
            throw new DeliveryException('invalid_dns_configuration');
        }
        $process = $this->process($host);
        $process->setTimeout($timeout);
        $process->disableOutput();
        $output = '';
        $invalidOutput = false;
        try {
            $process->start(function ($type, $data) use (&$output, &$invalidOutput) {
                // Never throw from a pipe callback: stop() drains pipes too, and must
                // always be able to kill/reap the child after an invalid response.
                if ($invalidOutput || $type !== Process::OUT || strlen($output) + strlen($data) > 4096) {
                    $invalidOutput = true;

                    return;
                }
                $output .= $data;
            });
            while ($process->isRunning()) {
                if ($invalidOutput) {
                    throw new DeliveryException('dns_invalid_output');
                }
                $process->checkTimeout();
                usleep(1000);
            }
            if ($invalidOutput) {
                throw new DeliveryException('dns_invalid_output');
            }
            if (! $process->isSuccessful()) {
                throw new DeliveryException('dns_error', true);
            }

            return $this->parse($output, $blocked);
        } catch (ProcessTimedOutException) {
            throw new DeliveryException('dns_timeout', true);
        } catch (DeliveryException $e) {
            throw $e;
        } catch (Throwable) {
            throw new DeliveryException('dns_unavailable');
        } finally {
            // No grace period: do not let an unresponsive resolver outlive its attempt.
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
        }
    }

    protected function process(#[\SensitiveParameter] string $host): Process
    {
        return new Process([PHP_BINARY, __DIR__.'/resolve-host.php', $host]);
    }

    private function parse(string $output, array $blocked): array
    {
        try {
            $data = json_decode($output, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DeliveryException('dns_invalid_output');
        }
        // Only fixed helper categories cross the process boundary, never exception text.
        if (is_array($data) && array_keys($data) === ['error'] && is_string($data['error'])) {
            $retryable = match ($data['error']) {
                'dns_error', 'dns_empty' => true,
                'dns_chain_error', 'dns_excessive', 'invalid_endpoint', 'idna_unavailable' => false,
                default => null,
            };
            if ($retryable !== null) {
                throw new DeliveryException($data['error'], $retryable);
            }
        }
        if (! is_array($data) || array_keys($data) !== ['addresses'] || ! is_array($data['addresses'])
            || ! array_is_list($data['addresses']) || count($data['addresses']) > 32) {
            throw new DeliveryException('dns_invalid_output');
        }
        if ($data['addresses'] === []) {
            throw new DeliveryException('dns_empty', true);
        }
        $addresses = [];
        foreach ($data['addresses'] as $ip) {
            if (! is_string($ip)) {
                throw new DeliveryException('dns_invalid_output');
            }
            (new PublicAddressPolicy)->assertPublic($ip, $blocked);
            $canonical = inet_ntop(inet_pton($ip));
            $addresses[$canonical] = $canonical;
        }

        return array_values($addresses);
    }
}
