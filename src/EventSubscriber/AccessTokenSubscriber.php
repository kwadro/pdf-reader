<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AccessTokenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $apiAccessToken,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api')) {
            return;
        }

        $token = $this->extractToken($request->headers->get('Authorization'), $request->headers->get('X-Access-Token'));

        if ($token === null || $this->apiAccessToken === '' || !hash_equals($this->apiAccessToken, $token)) {
            $event->setResponse(new JsonResponse([
                'error' => 'Unauthorized',
                'message' => 'Valid access token is required. Send it via Authorization: Bearer <token> or X-Access-Token header.',
            ], JsonResponse::HTTP_UNAUTHORIZED));
        }
    }

    private function extractToken(?string $authorization, ?string $accessTokenHeader): ?string
    {
        if ($accessTokenHeader !== null && $accessTokenHeader !== '') {
            return trim($accessTokenHeader);
        }

        if ($authorization === null || $authorization === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
