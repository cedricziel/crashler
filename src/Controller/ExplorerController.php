<?php

declare(strict_types=1);

namespace App\Controller;

use App\Explorer\SignalProfileRegistry;
use App\Explorer\UnknownSignalException;
use App\Repository\TenantRepository;
use App\Security\Voter\TenantVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ExplorerController extends AbstractController
{
    public function __construct(
        private readonly SignalProfileRegistry $profiles,
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

        // For v1 we render the layout shell with empty-state placeholders for
        // KPI/chart/table. The actual data resolution (KpiBundleResolver +
        // chart aggregate + first-page search) lands in follow-up tasks; the
        // template already speaks the SignalProfile contract so swapping in
        // populated data is purely additive — no template churn.
        return $this->render('explorer/index.html.twig', [
            'tenant' => $tenant,
            'profile' => $profile,
            'signal' => $signal,
            // Per-state placeholders. State enum is duplicated as constants on
            // the KpiTile component; a string here is enough for the v1 page.
            'kpi_state' => 'empty',
            'chart_state' => 'empty',
            'table_state' => 'empty',
        ]);
    }
}
