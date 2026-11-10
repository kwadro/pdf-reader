<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PdfCodesService;
use App\Service\PdfPageMerger;
use App\Service\PdfPageSplitter;
use App\Service\PdfBarcodeScanner;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/api')]
final class PdfApiController extends AbstractController
{
    private const MAX_UPLOAD_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private readonly PdfCodesService $codesService,
        private readonly PdfPageSplitter $pageSplitter,
        private readonly PdfPageMerger $pageMerger,
        private readonly PdfBarcodeScanner $barcodeScanner
    ) {
    }

    #[Route('/pdf/codes', name: 'api_pdf_codes', methods: ['POST'])]
    public function detectCodes(Request $request): JsonResponse
    {
        $file = $request->files->get('file') ?? $request->files->get('pdf');
        if ($file === null) {
            return $this->json([
                'error' => 'Bad Request',
                'message' => 'Multipart field "file" (or "pdf") with a PDF is required.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->codesService->processUpload($file);
            return $this->json([
                'result' => $result,
            ], Response::HTTP_OK);

        } catch (RuntimeException $e) {
            return $this->json([
                'error' => 'Processing Error',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/image/codes', name: 'api_image_codes', methods: ['POST'])]
    public function detectImageCodes(Request $request): JsonResponse
    {
        $file = $request->files->get('file') ?? $request->files->get('image');
        if ($file === null) {
            return $this->json([
                'error' => 'Bad Request',
                'message' => 'Multipart field "file" (or "image") with an image is required.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $codes = $this->barcodeScanner->scanImage($file);
        } catch (RuntimeException $e) {
            return $this->json([
                'error' => 'Processing Error',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'count' => count($codes),
            'codes' => $codes,
        ]);
    }

    #[Route('/pdf/split', name: 'api_pdf_split', methods: ['POST'])]
    public function splitPages(Request $request): JsonResponse
    {
        $file = $request->files->get('file') ?? $request->files->get('pdf');
        if ($file === null) {
            return $this->json([
                'error' => 'Bad Request',
                'message' => 'Multipart field "file" (or "pdf") with a PDF is required.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() !== null && $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->json([
                'error' => 'Bad Request',
                'message' => 'File is too large. Maximum size is 200 MB.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->pageSplitter->splitToArchive($file);
        } catch (RuntimeException $e) {
            return $this->json([
                'error' => 'Processing Error',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $downloadUrl = $this->generateUrl(
            'api_download_archive',
            ['id' => $result['id']],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->json([
            'id' => $result['id'],
            'pages' => $result['pages'],
            'filename' => $result['filename'],
            'download_url' => $downloadUrl,
        ]);
    }

    #[Route('/pdf/merge', name: 'api_pdf_merge', methods: ['POST'])]
    public function mergePages(Request $request): JsonResponse
    {
        $file = $request->files->get('file') ?? $request->files->get('archive') ?? $request->files->get('zip');
        if ($file === null) {
            return $this->json([
                'error' => 'Bad Request',
                'message' => 'Multipart field "file" (or "archive" / "zip") with a ZIP of PDFs is required.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() !== null && $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->json([
                'error' => 'Bad Request',
                'message' => 'File is too large. Maximum size is 200 MB.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->pageMerger->mergeArchiveToPdf($file);
        } catch (RuntimeException $e) {
            return $this->json([
                'error' => 'Processing Error',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $downloadUrl = $this->generateUrl(
            'api_download_archive',
            ['id' => $result['id']],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->json([
            'id' => $result['id'],
            'pages' => $result['pages'],
            'files' => $result['files'],
            'filename' => $result['filename'],
            'download_url' => $downloadUrl,
        ]);
    }

    #[Route('/downloads/{id}', name: 'api_download_archive', methods: ['GET'], requirements: ['id' => '[a-f0-9]{32}'])]
    public function downloadArchive(string $id): Response
    {
        $path = $this->pageSplitter->getArchivePath($id);
        $downloadName = null;

        if ($path !== null) {
            $downloadName = $this->pageSplitter->getArchiveDownloadName($id);
            $this->pageSplitter->deleteArchiveMeta($id);
        } else {
            $path = $this->pageMerger->getResultPath($id);
            if ($path === null) {
                return $this->json([
                    'error' => 'Not Found',
                    'message' => 'File not found.',
                ], Response::HTTP_NOT_FOUND);
            }
            $downloadName = $this->pageMerger->getDownloadName($id);
            $this->pageMerger->deleteMeta($id);
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $downloadName
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
