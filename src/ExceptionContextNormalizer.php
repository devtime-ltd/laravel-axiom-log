<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog;

use Monolog\Formatter\NormalizerFormatter;
use Throwable;

/**
 * NormalizerFormatter that captures `Throwable::context()` data alongside the
 * standard normalised exception fields, mirroring Laravel's JsonFormatter
 * (laravel/framework#59756). Override fires for every throwable encountered
 * during normalisation, including nested throwables and previous chains.
 */
class ExceptionContextNormalizer extends NormalizerFormatter
{
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
                    $data['context'] = $this->normalizeValue($context);
                }
            } catch (Throwable) {
            }
        }

        return $data;
    }
}
