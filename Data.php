<?php

namespace Omnisong\Odesli;

use Omnisense\Contracts\Http\Data as KernelData;

/**
 * Odesli's decoded answers read without trusting their
 * shape: the kernel's Data, and the reads that keep PHP's casts of a scalar
 * as they are ("" kept, nothing trimmed), for the values the catalogue
 * turned into strings with (string) before.
 *
 * @internal
 */
final readonly class Data extends KernelData
{
    /** (string) of a scalar or a Stringable, "" for anything else. */
    public static function cast(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /**
     * A decoded JSON object as it is, its keys as PHP keeps them (an empty
     * array when it is not an array).
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

}
