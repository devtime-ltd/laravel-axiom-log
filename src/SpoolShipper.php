<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog;

/**
 * Ships spooled NDJSON event files to Axiom out-of-band. Web workers only
 * ever append to the live spool file; the shipper claims work by renaming,
 * so writers and the shipper never contend on the same file.
 */
class SpoolShipper
{
    public const LINES_PER_BATCH = 1000;

    public function __construct(
        private readonly string $spoolDir,
        private readonly IngestClient $client,
        private readonly int $maxSpoolBytes = 64 * 1024 * 1024,
    ) {}

    /**
     * @return array{shipped: int, failed: int, dropped: int}
     */
    public function ship(): array
    {
        $shipped = $failed = $dropped = 0;

        if (! is_dir($this->spoolDir)) {
            return ['shipped' => 0, 'failed' => 0, 'dropped' => 0];
        }

        $dropped += $this->enforceCap();

        // Claim the live spool by renaming it; writers recreate it on next append.
        foreach (glob($this->spoolDir.'/*.ndjson') ?: [] as $live) {
            $claim = $live.'.'.uniqid().'.shipping';
            @rename($live, $claim);
        }

        foreach (glob($this->spoolDir.'/*.shipping') ?: [] as $file) {
            [$ok, $count] = $this->shipFile($file);
            if ($ok) {
                $shipped += $count;
                @unlink($file);
            } else {
                $failed += $count;
            }
        }

        return ['shipped' => $shipped, 'failed' => $failed, 'dropped' => $dropped];
    }

    /**
     * @return array{0: bool, 1: int}
     */
    private function shipFile(string $file): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return [false, 0];
        }

        $events = [];
        $count = 0;
        $ok = true;

        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue; // torn or corrupt line; skip rather than block the spool
            }
            $events[] = $decoded;
            $count++;

            if (count($events) >= self::LINES_PER_BATCH) {
                $ok = $ok && $this->sendBatch($events);
                $events = [];
            }
        }
        fclose($handle);

        if ($events !== []) {
            $ok = $ok && $this->sendBatch($events);
        }

        return [$ok, $count];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function sendBatch(array $events): bool
    {
        try {
            $json = json_encode($events, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            return true; // unshippable; treat as done rather than retrying forever
        }

        ['status' => $status] = $this->client->send($json);

        return $status >= 200 && $status < 300;
    }

    /**
     * Oldest-first eviction when the spool exceeds the cap (e.g. Axiom is
     * down for an extended period). Losing old logs beats filling the disk.
     */
    private function enforceCap(): int
    {
        $files = glob($this->spoolDir.'/*') ?: [];
        usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));

        $total = array_sum(array_map(fn ($f) => (int) @filesize($f), $files));
        $droppedFiles = 0;

        foreach ($files as $file) {
            if ($total <= $this->maxSpoolBytes) {
                break;
            }
            $total -= (int) @filesize($file);
            @unlink($file);
            $droppedFiles++;
        }

        return $droppedFiles;
    }
}
