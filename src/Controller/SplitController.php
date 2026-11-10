<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SplitController extends AbstractController
{
    private const MAX_UPLOAD_BYTES = 200 * 1024 * 1024;

    #[Route('/split', name: 'split', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('split/index.html.twig', [
            'max_upload_bytes' => self::MAX_UPLOAD_BYTES,
            'api_split_url' => $this->generateUrl('api_pdf_split'),
        ]);
    }
}
