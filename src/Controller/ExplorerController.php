<?php

declare(strict_types=1);

namespace App\Controller;

use App\Explorer\KpiBundleResolver;
use App\Explorer\KpiValue;
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
        private readonly KpiBundleResolver $kpiResolver,
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

        // Resolve the time window. Same parser the read API uses, so a
        // window > the configured cap surfaces the same 400 error.
        try {
            $window = TimeWindow::parse(
                ['since' => $request->query->get('since'), 'until' => $request->query->get('until')],
                $this->clock,
                $this->maxTimeWindowDays,
            );
            $windowError = null;
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            $window = null;
            $windowError = $e->getMessage();
        }

        $kpiValues = null === $window
            ? array_map(static fn ($spec) => KpiValue::empty($spec), $profile->kpis())
            : $this->kpiResolver->resolve($tenant->getSlug(), $signal, $profile->kpis(), $window);

        $kpiState = null === $window
            ? 'error'
            : (self::anyKpiPopulated($kpiValues) ? 'populated' : 'empty');

        // Chart + table data resolution lands in follow-up; until then we
        // render their empty states. The page already invests in the right
        // shape — a populated chart/table is purely additive.
        return $this->render('explorer/index.html.twig', [
            'tenant' => $tenant,
            'profile' => $profile,
            'signal' => $signal,
            'kpi_values' => $kpiValues,
            'kpi_state' => $kpiState,
            'chart_state' => 'empty',
            'table_state' => 'empty',
            'window_error' => $windowError,
        ]);
    }

    /**
     * @param list<KpiValue> $values
     */
    private static function anyKpiPopulated(array $values): bool
    {
        foreach ($values as $v) {
            if (null !== $v->value) {
                return true;
            }
        }

        return false;
    }
}
