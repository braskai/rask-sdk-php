<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Acceptance;

/** The control API of rask-sdk-acceptance, over plain curl so that a bug in the SDK cannot hide in its own test tool. */
final class Harness
{
    public readonly string $control;

    public function __construct(?string $control = null)
    {
        $env = getenv('RASK_ACCEPTANCE_CONTROL_URL');
        $this->control = $control ?? (is_string($env) && $env !== '' ? $env : 'http://127.0.0.1:4010/__control');
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('POST', $path, $body);
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return $this->get('/health');
    }

    /** @return list<array<string, mixed>> */
    public function requests(): array
    {
        return $this->get('/requests')['requests'];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        $curl = curl_init($this->control . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR));
        }
        $text = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (!is_string($text)) {
            throw new \RuntimeException('The harness does not answer at ' . $this->control . ': ' . curl_error($curl) . '. Start it: make harness');
        }
        if ($status !== 200) {
            throw new \RuntimeException("Control call $method $path failed: $status $text");
        }
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }
}
