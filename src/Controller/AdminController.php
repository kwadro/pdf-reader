<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminGuard;
use App\Service\AppSettings;
use App\Service\PdfBarcodeScanner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin_asl23')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly PdfBarcodeScanner $barcodeScanner,
        private readonly AdminGuard $adminGuard,
    ) {
    }

    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $session = $request->getSession();

        if ($this->adminGuard->isAuthenticated($session)) {
            return $this->redirectToRoute('admin_settings');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            if ($this->adminGuard->attemptLogin($password, $session)) {
                return $this->redirectToRoute('admin_settings');
            }
            $error = 'Invalid password.';
        }

        return $this->render('admin/login.html.twig', [
            'error' => $error,
            'client_ip' => $request->getClientIp(),
            'password_configured' => $this->adminGuard->isPasswordConfigured(),
        ]);
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST', 'GET'])]
    public function logout(Request $request): Response
    {
        $this->adminGuard->logout($request->getSession());

        return $this->redirectToRoute('admin_login');
    }

    #[Route('', name: 'admin_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $saved = false;
        $error = null;

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('api_access_token', '');
            if ($request->request->getBoolean('generate_token')) {
                $token = bin2hex(random_bytes(24));
            }

            $methods = $request->request->all('enabled_scan_methods');
            if (!is_array($methods)) {
                $methods = [];
            }

            $extraIps = (string) $request->request->get('admin_allowed_ips', '');

            try {
                $this->settings->save([
                    'api_access_token' => $token,
                    'api_enabled' => $request->request->getBoolean('api_enabled'),
                    'enabled_scan_methods' => array_values(array_map('strval', $methods)),
                    'admin_allowed_ips' => $extraIps,
                ]);
                $saved = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $settings = $this->settings->all();

        return $this->render('admin/settings.html.twig', [
            'settings' => $settings,
            'effective_token' => $this->settings->getApiAccessToken(),
            'available_methods' => AppSettings::SCAN_METHODS,
            'supported_methods' => $this->barcodeScanner->getAvailableMethods(false),
            'saved' => $saved,
            'error' => $error,
            'client_ip' => $request->getClientIp(),
            'env_allowed_ips' => $this->adminGuard->getEnvAllowedIps(),
            'effective_allowed_ips' => $this->adminGuard->getAllowedIps(),
        ]);
    }
}
