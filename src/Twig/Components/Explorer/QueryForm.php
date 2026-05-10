<?php

declare(strict_types=1);

namespace App\Twig\Components\Explorer;

use App\Explorer\SignalProfileRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Deferred query form. Renders the filter chips, time range inputs,
 * and aggregation controls once the SignalProfile is resolved on the
 * Live render. Initial paint is the form skeleton (single line of
 * "loading…" copy) — the controller handles only the layout shell.
 *
 * Field defaults / current values come from the URL query string,
 * read from the request at render time. The form action target stays
 * the explorer index route so submit triggers a GET with new params.
 */
#[AsLiveComponent('Explorer:QueryForm', template: 'components/explorer/query_form.html.twig')]
final class QueryForm
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $tenantSlug = '';

    #[LiveProp]
    public string $signal = '';

    public function __construct(
        private readonly SignalProfileRegistry $profiles,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function profile(): \App\Explorer\SignalProfile
    {
        return $this->profiles->get($this->signal);
    }

    public function queryValue(string $key, string $default = ''): string
    {
        $req = $this->requestStack->getCurrentRequest();
        if (null === $req) {
            return $default;
        }
        $val = $req->query->get($key);

        return \is_string($val) ? $val : $default;
    }
}
