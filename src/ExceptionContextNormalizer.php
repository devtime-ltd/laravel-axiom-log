<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog;

use JsonSerializable;
use Monolog\Formatter\NormalizerFormatter;
use Throwable;

/**
 * NormalizerFormatter that:
 *
 * 1. Captures `Throwable::context()` data alongside the standard normalised
 *    exception fields, mirroring Laravel's JsonFormatter
 *    (laravel/framework#59756). Override fires for every throwable encountered
 *    during normalisation, including nested throwables and previous chains.
 *
 * 2. Unwraps `JsonSerializable` objects to their `jsonSerialize()` value
 *    (matching native `json_encode()`), instead of Monolog's default
 *    `[ClassName => value]` wrap. The wrap produces field names containing
 *    backslashes for namespaced classes, which Axiom rejects, silently
 *    dropping the batch via `AxiomHandler::send()`'s catch-all.
 */
class ExceptionContextNormalizer extends NormalizerFormatter
{
    protected function normalize(mixed $data, int $depth = 0): mixed
    {
        if (
            is_object($data)
            && $data instanceof JsonSerializable
            && ! $data instanceof Throwable
        ) {
            return parent::normalize($data->jsonSerialize(), $depth + 1);
        }

        return parent::normalize($data, $depth);
    }

    /**
     * @return array<string, mixed>|string
     */
    protected function normalizeException(Throwable $e, int $depth = 0)
    {
        $data = parent::normalizeException($e, $depth);

        if (! is_array($data)) {
            return $data;
        }

        if (method_exists($e, 'context')) {
            try {
                $context = $e->context();
                if (is_array($context) && $context !== []) {
                    $data['context'] = $this->normalize($context, $depth + 1);
                }
            } catch (Throwable) {
            }
        }

        return $data;
    }
}
