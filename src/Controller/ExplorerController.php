<?php

declare(strict_types=1);

namespace App\Controller;

use App\Explorer\ChartSeriesResolver;
use App\Explorer\SignalProfileRegistry;
use App\Read\Criteria\TimeWindow;
use App\Repository\TenantRepository;
use App\Security\Voter\TenantVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ExplorerController extends AbstractController
{
    public function __construct(
        private readonly SignalProfileRegistry $profiles,
        private readonly ChartSeriesResolver $chartResolver,
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

        // Validate the signal name against the registry but do NOT resolve
        // the profile here — every profile-dependent piece of the page lives
        // behind a deferred Live Component, so the controller's job is just
        // to pass through the tenant slug, the signal name, and the parsed
        // window bounds. The shell is profile-agnostic.
        if (!$this->profiles->has($signal)) {
            throw new NotFoundHttpException(\sprintf('Unknown signal "%s".', $signal));
        }

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
            'signal' => $signal,
            'window_since_ns' => null === $window ? 0 : $window->sinceUnixNano,
            'window_until_ns' => null === $window ? 0 : $window->untilUnixNano,
            'window_error' => $windowError,
        ]);
    }

    #[Route(
        path: '/tenants/{slug}/explore/{signal}/_chart.json',
        name: 'app_explorer_chart_data',
        requirements: ['signal' => '[a-z]+'],
        methods: ['GET'],
    )]
    public function chartData(
        string $slug,
        string $signal,
        Request $request,
        TenantRepository $tenants,
    ): Response {
        $tenant = $tenants->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(TenantVoter::VIEW, $tenant);

        if (!$this->profiles->has($signal)) {
            throw new NotFoundHttpException(\sprintf('Unknown signal "%s".', $signal));
        }
        $profile = $this->profiles->get($signal);

        try {
            $window = TimeWindow::parse(
                [
                    'since' => self::nullIfBlank($request->query->get('since')),
                    'until' => self::nullIfBlank($request->query->get('until')),
                ],
                $this->clock,
                $this->maxTimeWindowDays,
            );
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // groupBy follows the URL when set, else the profile's default.
        $rawGroupBy = self::nullIfBlank($request->query->get('groupBy'));
        $groupBy = $rawGroupBy ?? $profile->defaultGroupBy();

        $payload = $this->chartResolver->series($tenant->getSlug(), $signal, $window, $groupBy);

        return new JsonResponse($payload);
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        return '' === trim($value) ? null : $value;
    }
}
