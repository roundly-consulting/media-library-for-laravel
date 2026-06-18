<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;

final class DefaultFileNamer implements FileNamer
{
    public function originalFileName(string $fileName): string
    {
        return $fileName;
    }

    public function variantFileName(string $variantName, string $extension): string
    {
        return $variantName.'.'.$extension;
    }
}
