<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog;

/**
 * Posts event batches to the Axiom ingest API. Reuses one curl handle for
 * the lifetime of the client, so long-running shippers get connection
 * keep-alive across batches.
 */
class IngestClient
{
    /** @var \CurlHandle|null */
    private $handle = null;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $dataset,
        private readonly string $host = AxiomHandler::DEFAULT_HOST,
        private readonly int $timeout = AxiomHandler::DEFAULT_TIMEOUT,
    ) {}

    /**
     * @return array{status: int, body: string, error: string}
     */
    public function send(string $json): array
    {
        $url = rtrim($this->host, '/').'/v1/datasets/'.$this->dataset.'/ingest';

        $ch = $this->handle ??= curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->apiToken,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
            'error' => $error,
        ];
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
        }
    }
}
