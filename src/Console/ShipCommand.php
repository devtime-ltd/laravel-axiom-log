<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog\Console;

use DevtimeLtd\LaravelAxiomLog\IngestClient;
use DevtimeLtd\LaravelAxiomLog\SpoolShipper;
use Illuminate\Console\Command;

class ShipCommand extends Command
{
    protected $signature = 'axiom-log:ship
                            {--follow : Keep shipping on an interval instead of exiting}
                            {--interval=5 : Seconds between shipping passes in --follow mode}';

    protected $description = 'Ship spooled Axiom log events to the ingest API';

    public function handle(): int
    {
        $channels = config('logging.channels', []);
        $shippers = [];

        foreach ($channels as $name => $channel) {
            $with = $channel['handler_with'] ?? [];
            if (($with['transport'] ?? null) !== 'spool') {
                continue;
            }

            $shippers[$name] = new SpoolShipper(
                spoolDir: $with['spoolPath'] ?? storage_path('logs/axiom-spool'),
                client: new IngestClient(
                    apiToken: $with['apiToken'] ?? '',
                    dataset: $with['dataset'] ?? '',
                    host: $with['host'] ?? \DevtimeLtd\LaravelAxiomLog\AxiomHandler::DEFAULT_HOST,
                ),
                maxSpoolBytes: (int) ($with['spoolMaxBytes'] ?? 64 * 1024 * 1024),
            );
        }

        if ($shippers === []) {
            $this->warn('No logging channels configured with the spool transport.');

            return self::SUCCESS;
        }

        do {
            foreach ($shippers as $name => $shipper) {
                $result = $shipper->ship();
                if ($result['shipped'] || $result['failed'] || $result['dropped']) {
                    $this->line(sprintf(
                        '[%s] shipped=%d failed=%d dropped-files=%d',
                        $name, $result['shipped'], $result['failed'], $result['dropped'],
                    ));
                }
            }

            if ($this->option('follow')) {
                sleep(max(1, (int) $this->option('interval')));
            }
        } while ($this->option('follow'));

        return self::SUCCESS;
    }
}
