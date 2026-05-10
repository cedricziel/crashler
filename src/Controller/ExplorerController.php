<?php

declare(strict_types=1);

namespace App\Controller;

use App\Explorer\SignalProfileRegistry;
use App\Explorer\UnknownSignalException;
use App\Read\Criteria\TimeWindow;
use App\Repository\TenantRepository;
use App\Security\Voter\TenantVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ExplorerController extends AbstractController
{
    public function __construct(
        private readonly SignalProfileRegistry $profiles,
        private readonly ClockInterface $clock,
        private readonly int $maxTimeWindowDays,
    ) {
    }

    #[Route(
        path: '/tenants/{slug}/explore/{signal}',
        name: 'app_explorer_index',
        requirements: ['signal' => '[a-z]+'],
        methods: ['GET'],
    )]
    public function index(
        string $slug,
        string $signal,
        Request $request,
        TenantRepository $tenants,
    ): Response {
        $tenant = $tenants->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(TenantVoter::VIEW, $tenant);

        try {
            $profile = $this->profiles->get($signal);
        } catch (UnknownSignalException) {
            throw new NotFoundHttpException(\sprintf('Unknown signal "%s".', $signal));
        }

        // Resolve the time window only — the heavyweight scans (KPI bundle,
        // result table, autocomplete) are pushed into deferred Live Components
        // so the browser parallelises them and the initial paint is fast.
        try {
            $window = TimeWindow::parse(
                [
                    'since' => self::nullIfBlank($request->query->get('since')),
                    'until' => self::nullIfBlank($request->query->get('until')),
                ],
                $this->clock,
                $this->maxTimeWindowDays,
            );
            $windowError = null;
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            $window = null;
            $windowError = $e->getMessage();
        }

        return $this->render('explorer/index.html.twig', [
            'tenant' => $tenant,
            'profile' => $profile,
            'signal' => $signal,
            'window_since_ns' => null === $window ? 0 : $window->sinceUnixNano,
            'window_until_ns' => null === $window ? 0 : $window->untilUnixNano,
            'window_error' => $windowError,
        ]);
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        return '' === trim($value) ? null : $value;
    }
}
