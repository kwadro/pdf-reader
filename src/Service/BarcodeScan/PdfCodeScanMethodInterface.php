<?php

declare(strict_types=1);

namespace App\Service\BarcodeScan;

/**
 * One detection strategy for PDF codes (text / imagick / CLI).
 */
interface PdfCodeScanMethodInterface
{
    public function getName(): string;

    public function isSupported(): bool;

    /**
     * @return list<array{page: int, type: string, data: string, method: string}>
     */
    public function scan(string $pdfPath, string $workDir, ?int $maxPages = null): array;
}
