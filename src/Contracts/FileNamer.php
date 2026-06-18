<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Contracts;

interface FileNamer
{
    /** Stored filename (including extension) for the original file. */
    public function originalFileName(string $fileName): string;

    /** Stored filename (including extension) for a generated variant. */
    public function variantFileName(string $variantName, string $extension): string;
}
