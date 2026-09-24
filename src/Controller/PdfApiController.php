<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PdfBarcodeScanner;
use App\Service\PdfPageSplitter;
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
    public function __construct(
        private readonly PdfBarcodeScanner $barcodeScanner,
        private readonly PdfPageSplitter $pageSplitter,
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
            $codes = $this->barcodeScanner->scan($file);
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
            'download_url' => $downloadUrl,
        ]);
    }

    #[Route('/downloads/{id}', name: 'api_download_archive', methods: ['GET'], requirements: ['id' => '[a-f0-9]{32}'])]
    public function downloadArchive(string $id): Response
    {
        $path = $this->pageSplitter->getArchivePath($id);
        if ($path === null) {
            return $this->json([
                'error' => 'Not Found',
                'message' => 'Archive not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'pages_'.$id.'.zip'
        );

        return $response;
    }
}
