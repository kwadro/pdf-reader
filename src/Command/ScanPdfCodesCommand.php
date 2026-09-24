<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PdfBarcodeScanner;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

#[AsCommand(
    name: 'app:pdf:scan-codes',
    description: 'Scan QR/barcodes in all PDF files inside a directory and write results to a log file',
)]
final class ScanPdfCodesCommand extends Command
{
    private const TEST_MAX_PAGES = 2;

    public function __construct(
        private readonly PdfBarcodeScanner $barcodeScanner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Root directory with PDF files to scan')
            ->addArgument('log', InputArgument::REQUIRED, 'Path to the output log file')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Max PDF files per nested subdirectory only (root path is never limited). Also limits each PDF to first 2 pages.',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $directory = $this->resolveDirectory((string) $input->getArgument('path'));
        $logPath = $this->resolveLogPath((string) $input->getArgument('log'));
        $limitPerNestedDir = $this->resolveLimit($input->getOption('limit'));
        $maxPages = $limitPerNestedDir !== null ? self::TEST_MAX_PAGES : null;

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name('*.pdf')
            ->name('*.PDF')
            ->sortByName();

        if (!$finder->hasResults()) {
            $io->warning(sprintf('No PDF files found in: %s', $directory));
            $this->writeLog($logPath, [
                $this->line('INFO', sprintf('No PDF files found in: %s', $directory)),
            ]);

            return Command::SUCCESS;
        }

        $filesByDirectory = $this->groupFilesByDirectory($finder, $directory, $limitPerNestedDir);

        $lines = [
            $this->line('INFO', sprintf('Scan started. Directory: %s', $directory)),
            $this->line('INFO', sprintf('Log file: %s', $logPath)),
            $this->line('INFO', 'Recursive: yes (all nested folders)'),
            $this->line('INFO', sprintf(
                'Limit per nested subdirectory: %s (root folder unlimited)',
                $limitPerNestedDir === null ? 'none' : (string) $limitPerNestedDir
            )),
            $this->line('INFO', sprintf(
                'Max pages per PDF: %s',
                $maxPages === null ? 'all' : (string) $maxPages
            )),
            $this->line('INFO', sprintf(
                'Scan methods: %s',
                implode(', ', $this->barcodeScanner->getAvailableMethods()) ?: 'none'
            )),
        ];

        $filesScanned = 0;
        $codesFound = 0;
        $errors = 0;
        $directoriesUsed = 0;

        foreach ($filesByDirectory as $dirPath => $files) {
            ++$directoriesUsed;
            $isRoot = $this->isSameDirectory($dirPath, $directory);
            $io->writeln(sprintf(
                '<info>Directory:</info> %s (%d file(s)%s)',
                $dirPath,
                count($files),
                $isRoot ? ', root — no limit' : ''
            ));
            $lines[] = $this->line(
                'DIR',
                sprintf('%s (%d file(s)%s)', $dirPath, count($files), $isRoot ? ', root' : '')
            );

            foreach ($files as $file) {
                $pdfPath = $file->getRealPath() ?: $file->getPathname();
                ++$filesScanned;

                $io->section($pdfPath);
                $lines[] = $this->line('FILE', $pdfPath);

                try {
                    $codes = $this->barcodeScanner->scanFile($pdfPath, $maxPages);
                } catch (RuntimeException $e) {
                    ++$errors;
                    $message = 'ERROR: '.$e->getMessage();
                    $io->error($message);
                    $lines[] = $this->line('ERROR', $message);
                    continue;
                }

                if ($codes === []) {
                    $io->writeln('  (no codes found)');
                    $lines[] = $this->line('RESULT', 'no codes found');
                    continue;
                }

                foreach ($codes as $code) {
                    ++$codesFound;
                $entry = sprintf(
                    'page=%d type=%s data=%s method=%s',
                    $code['page'],
                    $code['type'],
                    $code['data'],
                    $code['method'] ?? '?'
                );
                    $io->writeln('  '.$entry);
                    $lines[] = $this->line('CODE', $entry);
                }
            }
        }

        $summary = sprintf(
            'Done. directories=%d files=%d codes=%d errors=%d',
            $directoriesUsed,
            $filesScanned,
            $codesFound,
            $errors
        );
        $lines[] = $this->line('INFO', $summary);
        $this->writeLog($logPath, $lines);

        $io->success($summary);
        $io->writeln(sprintf('Log saved to: %s', $logPath));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Root path from the command argument is never limited.
     * Nested subdirectories are limited to $limitPerNestedDir files each.
     *
     * @return array<string, list<SplFileInfo>>
     */
    private function groupFilesByDirectory(Finder $finder, string $rootDirectory, ?int $limitPerNestedDir): array
    {
        /** @var array<string, list<SplFileInfo>> $grouped */
        $grouped = [];

        foreach ($finder as $file) {
            $dir = $file->getPath();
            if (!isset($grouped[$dir])) {
                $grouped[$dir] = [];
            }

            $isRoot = $this->isSameDirectory($dir, $rootDirectory);
            if (
                !$isRoot
                && $limitPerNestedDir !== null
                && count($grouped[$dir]) >= $limitPerNestedDir
            ) {
                continue;
            }

            $grouped[$dir][] = $file;
        }

        ksort($grouped);

        return $grouped;
    }

    private function isSameDirectory(string $a, string $b): bool
    {
        $ra = realpath($a) ?: rtrim($a, '/\\');
        $rb = realpath($b) ?: rtrim($b, '/\\');

        return $ra === $rb;
    }

    private function resolveDirectory(string $path): string
    {
        $directory = realpath($path) ?: $path;

        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory does not exist: %s', $path));
        }

        if (!is_readable($directory)) {
            throw new RuntimeException(sprintf('Directory is not readable: %s', $directory));
        }

        return $directory;
    }

    private function resolveLimit(mixed $limit): ?int
    {
        if ($limit === null || $limit === '') {
            return null;
        }

        if (!is_numeric($limit) || (int) $limit < 1) {
            throw new RuntimeException('Option --limit must be a positive integer.');
        }

        return (int) $limit;
    }

    private function resolveLogPath(string $path): string
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Unable to create log directory: %s', $dir));
            }
        }

        return $path;
    }

    /**
     * @param list<string> $lines
     */
    private function writeLog(string $logPath, array $lines): void
    {
        $content = implode(\PHP_EOL, $lines).\PHP_EOL;
        if (file_put_contents($logPath, $content) === false) {
            throw new RuntimeException(sprintf('Unable to write log file: %s', $logPath));
        }
    }

    private function line(string $level, string $message): string
    {
        return sprintf('[%s] [%s] %s', date('Y-m-d H:i:s'), $level, $message);
    }
}
