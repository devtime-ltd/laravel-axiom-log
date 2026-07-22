<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog\Console;

use DevtimeLtd\LaravelAxiomLog\AxiomHandler;
use DevtimeLtd\LaravelAxiomLog\IngestClient;
use DevtimeLtd\LaravelAxiomLog\SpoolShipper;

trait ResolvesSpoolShippers
{
    /**
     * @return array<string, SpoolShipper> keyed by logging channel name
     */
    protected function spoolShippers(): array
    {
        $shippers = [];

        foreach (config('logging.channels', []) as $name => $channel) {
            $with = $channel['handler_with'] ?? [];
            if (($with['transport'] ?? null) !== 'spool') {
                continue;
            }

            $shippers[$name] = new SpoolShipper(
                spoolDir: $with['spoolPath'] ?? storage_path('logs/axiom-spool'),
                client: new IngestClient(
                    apiToken: $with['apiToken'] ?? '',
                    dataset: $with['dataset'] ?? '',
                    host: $with['host'] ?? AxiomHandler::DEFAULT_HOST,
                ),
                maxSpoolBytes: (int) ($with['spoolMaxBytes'] ?? 64 * 1024 * 1024),
            );
        }

        return $shippers;
    }

    /**
     * @param  array<string, SpoolShipper>  $shippers
     */
    protected function shipPass(array $shippers): void
    {
        foreach ($shippers as $name => $shipper) {
            $result = $shipper->ship();
            if ($result['shipped'] || $result['failed'] || $result['dropped']) {
                $this->line(sprintf(
                    '[%s] shipped=%d failed=%d dropped-files=%d',
                    $name, $result['shipped'], $result['failed'], $result['dropped'],
                ));
            }
        }
    }
}
