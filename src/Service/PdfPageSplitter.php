<?php

declare(strict_types=1);

namespace App\Service;

use Random\RandomException;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use ZipArchive;

/**
 * Splits PDF pages using Composer packages only (FPDI + FPDF), no qpdf/gs CLI.
 * Modern PDFs are transferred to classic 1.4 structure before FPDI when needed.
 */
final class PdfPageSplitter
{
    public const PAGE_CHUNK_SIZE = 100;

    /** How many page PDFs to keep pending in a ZIP before flushing to disk. */
    private const ZIP_FLUSH_PAGES = 5;

    public function __construct(
        private readonly string $storageDir,
        private readonly PdfVersionTransfer $versionTransfer,
    ) {
    }

    /**
     * Default API behaviour: one ZIP with every page as a separate PDF.
     * With $bundleChunks and more than 100 pages: one ZIP that contains
     * chunk ZIPs named `{base}_{from}_{to}.zip` (100 pages each).
     *
     * @return array{id: string, pages: int, filename: string, path: string, archives: int}
     */
    public function splitToArchive(UploadedFile $pdf, bool $bundleChunks = false): array
    {
        $this->assertPdf($pdf);

        $originalFilename = basename(str_replace('\\', '/', (string) $pdf->getClientOriginalName())) ?: 'document.pdf';
        $workDir = $this->createWorkDir('split');

        try {
            $pdf->move($workDir, 'input.pdf');
            $sourcePdf = $workDir.'/input.pdf';
            $pdfPath = $this->versionTransfer->ensureFpdiCompatible($sourcePdf, $workDir);

            try {
                $pageCount = $this->countPages($pdfPath);
            } catch (Throwable) {
                $pdfPath = $this->versionTransfer->forceToPdf14($sourcePdf, $workDir);
                $pageCount = $this->countPages($pdfPath);
            }

            if ($bundleChunks && $pageCount > self::PAGE_CHUNK_SIZE) {
                return $this->splitToBundledChunkArchive($pdfPath, $originalFilename, $pageCount);
            }

            // API / single-chunk UI: one archive with all pages.
            $archives = $this->createPagedArchives($pdfPath, $originalFilename, $pageCount, $pageCount);
            if ($archives === []) {
                throw new RuntimeException('No pages were produced from the PDF.');
            }

            $first = $archives[0];

            return [
                'id' => $first['id'],
                'pages' => $pageCount,
                'filename' => $first['filename'],
                'path' => $first['path'],
                'archives' => 1,
            ];
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * Build a parent ZIP that contains chunk archives of PAGE_CHUNK_SIZE pages each.
     * Builds one chunk at a time and flushes the parent ZIP after each add so peak
     * disk use is ~ parent + one chunk (not parent + all chunks).
     *
     * @return array{id: string, pages: int, filename: string, path: string, archives: int}
     */
    private function splitToBundledChunkArchive(string $pdfPath, string $originalFilename, int $pageCount): array
    {
        $chunkWorkDir = $this->createWorkDir('splitchunks');

        $folderName = $this->sanitizeZipEntryName($originalFilename);
        $archiveBase = pathinfo($folderName, \PATHINFO_FILENAME);
        if ($archiveBase === '' || $archiveBase === '.' || $archiveBase === '..') {
            $archiveBase = 'document';
        }

        $archivesDir = $this->ensureDirectory(
            rtrim($this->storageDir, '/').'/archives',
            'Unable to create archives directory.'
        );

        $id = bin2hex(random_bytes(16));
        $parentPath = $archivesDir.'/'.$id.'.zip';
        $downloadName = $archiveBase.'.zip';
        $chunkCount = 0;
        $parentZip = null;

        try {
            for ($from = 1; $from <= $pageCount; $from += self::PAGE_CHUNK_SIZE) {
                $to = min($pageCount, $from + self::PAGE_CHUNK_SIZE - 1);
                $chunkName = sprintf('%s_%d_%d.zip', $archiveBase, $from, $to);
                $chunkPath = $chunkWorkDir.'/'.bin2hex(random_bytes(8)).'.zip';

                try {
                    $this->writePagesZip($pdfPath, $folderName, $from, $to, $chunkPath, $chunkWorkDir);

                    if ($parentZip === null) {
                        $parentZip = new ZipArchive();
                        $flags = ($chunkCount === 0)
                            ? (ZipArchive::CREATE | ZipArchive::OVERWRITE)
                            : 0;
                        if ($parentZip->open($parentPath, $flags) !== true) {
                            throw new RuntimeException(
                                $chunkCount === 0
                                    ? 'Unable to create bundled ZIP archive.'
                                    : 'Unable to reopen bundled ZIP archive.'
                            );
                        }
                    }

                    if (!$parentZip->addFile($chunkPath, $chunkName)) {
                        throw new RuntimeException('Unable to add chunk archive to parent ZIP.');
                    }

                    if (!$this->closeZip($parentZip)) {
                        throw $this->zipWriteException('Unable to finalize bundled ZIP archive.');
                    }
                    $parentZip = null;
                    ++$chunkCount;
                } finally {
                    @unlink($chunkPath);
                }
            }

            if ($chunkCount < 1) {
                throw new RuntimeException('No pages were produced from the PDF.');
            }

            $this->writeArchiveMeta($id, [
                'download_name' => $downloadName,
                'pages' => $pageCount,
                'archives' => $chunkCount,
                'bundled' => true,
                'folder' => $folderName,
            ]);

            return [
                'id' => $id,
                'pages' => $pageCount,
                'filename' => $downloadName,
                'path' => $parentPath,
                'archives' => $chunkCount,
            ];
        } catch (Throwable $e) {
            if (is_file($parentPath)) {
                @unlink($parentPath);
            }
            throw $e;
        } finally {
            if ($parentZip instanceof ZipArchive) {
                $this->closeZip($parentZip);
            }
            $this->removeDirectory($chunkWorkDir);
        }
    }

    public function countPages(string $pdfPath): int
    {
        $reader = new Fpdi();
        $pageCount = $reader->setSourceFile($pdfPath);
        if ($pageCount < 1) {
            throw new RuntimeException('No pages were produced from the PDF.');
        }

        return $pageCount;
    }

    /**
     * Build ZIP archive(s) with per-page PDFs.
     *
     * Layout inside each ZIP: `{originalFilename}/1.pdf`, `2.pdf`, …
     * Download / entry name: `{basename}_{from}_{to}.zip`.
     * On disk every archive is always `{id}.zip`.
     *
     * - `$targetDir === null` — write to storage/archives and register meta for /api/downloads
     * - `$targetDir` set — write only into that temp dir (for nesting into a parent ZIP); no download meta
     *
     * @param string $pdfPath
     * @param string $originalFilename
     * @param int $pageCount
     * @param int $chunkSize
     * @param string|null $targetDir
     * @return list<array{id: string, pages: int, filename: string, path: string, from: int, to: int}>
     * @throws RandomException
     * @throws Throwable
     */
    public function createPagedArchives(
        string $pdfPath,
        string $originalFilename,
        int $pageCount,
        int $chunkSize = self::PAGE_CHUNK_SIZE,
        ?string $targetDir = null,
    ): array {
        if ($pageCount < 1) {
            throw new RuntimeException('No pages were produced from the PDF.');
        }
        if ($chunkSize < 1) {
            throw new RuntimeException('Invalid archive page chunk size.');
        }

        // null = public downloadable archive(s); non-null = temp chunk files for a parent ZIP.
        $forDownload = $targetDir === null;
        $archivesDir = $forDownload
            ? $this->ensureDirectory(
                rtrim($this->storageDir, '/').'/archives',
                'Unable to create archives directory.'
            )
            : $this->ensureDirectory(
                rtrim($targetDir, '/'),
                'Unable to create chunk archive directory.'
            );

        $folderName = $this->sanitizeZipEntryName($originalFilename);
        $archiveBase = pathinfo($folderName, \PATHINFO_FILENAME);
        if ($archiveBase === '' || $archiveBase === '.' || $archiveBase === '..') {
            $archiveBase = 'document';
        }

        $archives = [];
        $pageWorkDir = $this->createWorkDir('pagesplit');

        try {
            // Disk + addFile, flush every ZIP_FLUSH_PAGES so neither memory nor temp disk grows with all pages.
            for ($from = 1; $from <= $pageCount; $from += $chunkSize) {
                $to = min($pageCount, $from + $chunkSize - 1);
                $pagesInZip = $to - $from + 1;
                $downloadName = sprintf('%s_%d_%d.zip', $archiveBase, $from, $to);

                $id = bin2hex(random_bytes(16));
                $archivePath = $archivesDir.'/'.$id.'.zip';

                try {
                    $this->writePagesZip($pdfPath, $folderName, $from, $to, $archivePath, $pageWorkDir);
                } catch (Throwable $e) {
                    @unlink($archivePath);
                    throw $e;
                }

                if ($forDownload) {
                    $this->writeArchiveMeta($id, [
                        'download_name' => $downloadName,
                        'pages' => $pagesInZip,
                        'from' => $from,
                        'to' => $to,
                        'folder' => $folderName,
                    ]);
                }

                $archives[] = [
                    'id' => $id,
                    'pages' => $pagesInZip,
                    'filename' => $downloadName,
                    'path' => $archivePath,
                    'from' => $from,
                    'to' => $to,
                ];
            }

            return $archives;
        } finally {
            $this->removeDirectory($pageWorkDir);
        }
    }

    /**
     * Write pages $from..$to into $archivePath, flushing the ZIP every ZIP_FLUSH_PAGES
     * so FPDF/ZipArchive buffers and temp page files stay bounded.
     */
    private function writePagesZip(
        string $pdfPath,
        string $folderName,
        int $from,
        int $to,
        string $archivePath,
        string $pageWorkDir,
    ): void {
        $zip = null;
        $pendingFiles = [];

        try {
            for ($page = $from; $page <= $to; ++$page) {
                if ($zip === null) {
                    $zip = new ZipArchive();
                    $flags = ($page === $from)
                        ? (ZipArchive::CREATE | ZipArchive::OVERWRITE)
                        : 0;
                    if ($zip->open($archivePath, $flags) !== true) {
                        throw new RuntimeException(
                            $page === $from
                                ? 'Unable to create ZIP archive.'
                                : 'Unable to reopen ZIP archive for append.'
                        );
                    }
                }

                $pageFile = sprintf('%s/%d.pdf', $pageWorkDir, $page);

                $this->renderPageToFile($pdfPath, $page, $pageFile);

                if (!$zip->addFile($pageFile, sprintf('%s/%d.pdf', $folderName, $page))) {
                    throw new RuntimeException(sprintf('Unable to add page %d to ZIP.', $page));
                }
                $pendingFiles[] = $pageFile;

                $isLast = $page === $to;
                $shouldFlush = $isLast || count($pendingFiles) >= self::ZIP_FLUSH_PAGES;
                if (!$shouldFlush) {
                    continue;
                }

                if (!$this->closeZip($zip)) {
                    throw $this->zipWriteException('Unable to finalize ZIP archive.');
                }
                $zip = null;

                foreach ($pendingFiles as $file) {
                    @unlink($file);
                }
                $pendingFiles = [];
            }
        } finally {
            if ($zip instanceof ZipArchive) {
                $this->closeZip($zip);
            }
            foreach ($pendingFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function closeZip(ZipArchive $zip): bool
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $ok = $zip->close();
        } catch (\ValueError) {
            // Already closed / uninitialized.
            $ok = false;
        } finally {
            restore_error_handler();
        }

        if (is_string($warning) && $warning !== '' && $this->isDiskQuotaMessage($warning)) {
            throw $this->zipWriteException('ZIP write failed.');
        }

        return $ok === true;
    }

    private function zipWriteException(string $prefix): RuntimeException
    {
        return new RuntimeException(
            $prefix.' Disk quota exceeded while writing the archive. '
            .'Free space in the hosting account (delete old files under var/storage) or raise the account quota.'
        );
    }

    private function isDiskQuotaMessage(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'quota')
            || str_contains($message, 'disk full')
            || str_contains($message, 'no space');
    }

    private function renderPageToFile(string $pdfPath, int $page, string $destPath): void
    {
        $pagePdf = new Fpdi();
        $pagePdf->setSourceFile($pdfPath);
        $templateId = $pagePdf->importPage($page);
        $size = $pagePdf->getTemplateSize($templateId);

        $pagePdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pagePdf->useTemplate($templateId);

        // Write straight to disk — Output('S') keeps the full page PDF in memory and
        // together with ZipArchive::addFromString quickly exhausts shared-hosting limits.
        $pagePdf->Output('F', $destPath);
        unset($pagePdf);

        if (!is_file($destPath) || filesize($destPath) === 0) {
            throw new RuntimeException(sprintf(
                'Unable to write page %d (disk full, quota exceeded, or render failed).',
                $page
            ));
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

    public function getArchiveDownloadName(string $id): string
    {
        $meta = $this->readArchiveMeta($id);
        if (isset($meta['download_name']) && is_string($meta['download_name']) && $meta['download_name'] !== '') {
            return $meta['download_name'];
        }

        return 'pages_'.$id.'.zip';
    }

    public function deleteArchiveMeta(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return;
        }

        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeArchiveMeta(string $id, array $meta): void
    {
        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.json';
        file_put_contents($path, json_encode($meta, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function readArchiveMeta(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return [];
        }

        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function sanitizeZipEntryName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = str_replace(["\0", '..'], '', $name);
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'document.pdf';
        }

        return $name;
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

    private function ensureDirectory(string $dir, string $errorMessage): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException($errorMessage);
        }

        return $dir;
    }

    private function createWorkDir(string $prefix): string
    {
        // Prefer system temp when it is writable — on shared hosting the account
        // quota often applies only to the home/web tree, not to /tmp.
        $candidates = array_values(array_unique([
            rtrim(sys_get_temp_dir(), '/'),
            rtrim($this->storageDir, '/').'/tmp',
        ]));

        $base = null;
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (!is_dir($candidate) && !@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
                continue;
            }
            if (!is_writable($candidate)) {
                continue;
            }
            $base = $candidate;
            break;
        }

        if ($base === null) {
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
