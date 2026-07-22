<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog\Console;

use Illuminate\Console\Command;

class DaemonCommand extends Command
{
    use ResolvesSpoolShippers;

    protected $signature = 'axiom-log:daemon
                            {--interval=5 : Seconds between shipping passes}';

    protected $description = 'Continuously ship spooled Axiom log events to the ingest API';

    public function handle(): int
    {
        $shippers = $this->spoolShippers();

        if ($shippers === []) {
            $this->warn('No logging channels configured with the spool transport.');

            return self::SUCCESS;
        }

        while (true) {
            $this->shipPass($shippers);
            sleep(max(1, (int) $this->option('interval')));
        }
    }
}
