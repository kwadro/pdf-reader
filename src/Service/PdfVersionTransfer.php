<?php

declare(strict_types=1);

namespace App\Service;

use PdfDecompressor\Normalizer;
use RuntimeException;

/**
 * Transfers PDF 1.5+ (compressed xref / object streams) to classic PDF 1.4
 * structure so FPDI's free parser can read them. Pure PHP — no shell exec.
 */
final class PdfVersionTransfer
{
    /**
     * Return a path FPDI can open: original file, or a normalized 1.4 copy in $workDir.
     */
    public function ensureFpdiCompatible(string $pdfPath, string $workDir): string
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException('PDF file is not readable for version transfer.');
        }

        $bytes = @file_get_contents($pdfPath);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Unable to read PDF for version transfer.');
        }

        if (!Normalizer::isCompressed($bytes)) {
            return $pdfPath;
        }

        return $this->forceToPdf14($pdfPath, $workDir);
    }

    /**
     * Always rewrite the PDF to classic 1.4 structure (no shell exec).
     */
    public function forceToPdf14(string $pdfPath, string $workDir): string
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException('PDF file is not readable for version transfer.');
        }

        $targetPath = rtrim($workDir, '/').'/input_pdf14.pdf';
        try {
            (new Normalizer())->normalizeFile($pdfPath, $targetPath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Unable to transfer PDF to version 1.4: '.$e->getMessage(),
                0,
                $e
            );
        }

        if (!is_file($targetPath) || filesize($targetPath) < 5) {
            throw new RuntimeException('PDF 1.4 transfer produced an empty file.');
        }

        return $targetPath;
    }
}
