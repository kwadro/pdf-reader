<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

/**
 * Splits PDF pages using Composer packages only (FPDI + FPDF), no qpdf CLI.
 */
final class PdfPageSplitter
{
    public function __construct(
        private readonly string $storageDir,
    ) {
    }

    /**
     * @return array{id: string, pages: int, filename: string, path: string}
     */
    public function splitToArchive(UploadedFile $pdf): array
    {
        $this->assertPdf($pdf);

        $workDir = $this->createWorkDir('split');
        $pagesDir = $workDir.'/pages';

        try {
            if (!mkdir($pagesDir, 0775, true) && !is_dir($pagesDir)) {
                throw new RuntimeException('Unable to create pages directory.');
            }

            $pdf->move($workDir, 'input.pdf');
            $pdfPath = $workDir.'/input.pdf';

            $reader = new Fpdi();
            $pageCount = $reader->setSourceFile($pdfPath);

            if ($pageCount < 1) {
                throw new RuntimeException('No pages were produced from the PDF.');
            }

            $pageFiles = [];
            for ($page = 1; $page <= $pageCount; ++$page) {
                $pagePdf = new Fpdi();
                $pagePdf->setSourceFile($pdfPath);
                $templateId = $pagePdf->importPage($page);
                $size = $pagePdf->getTemplateSize($templateId);

                $pagePdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pagePdf->useTemplate($templateId);

                $pageFile = sprintf('%s/page_%04d.pdf', $pagesDir, $page);
                $pagePdf->Output('F', $pageFile);
                $pageFiles[] = $pageFile;
            }

            $id = bin2hex(random_bytes(16));
            $archivesDir = rtrim($this->storageDir, '/').'/archives';
            if (!is_dir($archivesDir) && !mkdir($archivesDir, 0775, true) && !is_dir($archivesDir)) {
                throw new RuntimeException('Unable to create archives directory.');
            }

            $archiveName = $id.'.zip';
            $archivePath = $archivesDir.'/'.$archiveName;

            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create ZIP archive.');
            }

            foreach ($pageFiles as $index => $pageFile) {
                $zip->addFile($pageFile, sprintf('page_%04d.pdf', $index + 1));
            }
            $zip->close();

            return [
                'id' => $id,
                'pages' => count($pageFiles),
                'filename' => $archiveName,
                'path' => $archivePath,
            ];
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    public function getArchivePath(string $id): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }

        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.zip';

        return is_file($path) ? $path : null;
    }

    private function assertPdf(UploadedFile $pdf): void
    {
        if ($pdf->getError() !== \UPLOAD_ERR_OK) {
            throw new RuntimeException('PDF upload failed.');
        }

        $mime = $pdf->getMimeType() ?? '';
        $ext = strtolower((string) $pdf->getClientOriginalExtension());

        if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true) && $ext !== 'pdf') {
            throw new RuntimeException('Uploaded file must be a PDF.');
        }
    }

    private function createWorkDir(string $prefix): string
    {
        $base = rtrim($this->storageDir, '/').'/tmp';
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new RuntimeException('Unable to create temp storage directory.');
        }

        $dir = $base.'/'.$prefix.'_'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create work directory.');
        }

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
