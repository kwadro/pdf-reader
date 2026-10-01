<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\AdminGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdminAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AdminGuard $adminGuard,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/admin_asl23')) {
            return;
        }

        if (!$this->adminGuard->isIpAllowed($request)) {
            $event->setResponse(new Response('Access denied', Response::HTTP_FORBIDDEN));

            return;
        }

        $route = $request->attributes->get('_route');
        if ($route === 'admin_login') {
            return;
        }

        if (!$this->adminGuard->isAuthenticated($request->getSession())) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_login')));
        }
    }
}
