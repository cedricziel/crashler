<?php

declare(strict_types=1);

namespace App\Tests\Component\Explorer;

use App\Twig\Components\Explorer\QueryForm;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Hydrates the QueryForm Live Component. The component is signal-agnostic:
 * filter chips, time inputs, and aggregation controls are all driven by the
 * resolved SignalProfile. Pre-hydration (empty signal) paints a one-line
 * "Loading filters…" placeholder; post-hydration the form renders inline,
 * with input values seeded from the current request's query string.
 */
final class QueryFormComponentTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    public function testEmptySignalRendersLoadingPlaceholder(): void
    {
        $component = $this->createLiveComponent('Explorer:QueryForm', [
            'tenantSlug' => '',
            'signal' => '',
        ]);

        $rendered = (string) $component->render();

        self::assertStringContainsString('aria-busy="true"', $rendered);
        self::assertStringContainsString('Loading filters', $rendered);
        // The form itself MUST NOT be in the DOM yet.
        self::assertStringNotContainsString('<form', $rendered);
    }

    public function testHydratedFormRendersAllFilterChipsForLogsProfile(): void
    {
        $component = $this->createLiveComponent('Explorer:QueryForm', [
            'tenantSlug' => 'acme',
            'signal' => 'logs',
        ]);

        $rendered = (string) $component->render();

        // Form is present.
        self::assertStringContainsString('<form', $rendered);
        // Every LogsProfile filter has its <input name="…"> rendered.
        foreach (['service', 'environment', 'host', 'severity', 'traceId'] as $key) {
            self::assertMatchesRegularExpression(
                '/name="'.preg_quote($key, '/').'"/',
                $rendered,
                \sprintf('expected an input for filter "%s"', $key),
            );
        }
    }

    public function testHydratedFormRendersBothTimeInputs(): void
    {
        $component = $this->createLiveComponent('Explorer:QueryForm', [
            'tenantSlug' => 'acme',
            'signal' => 'logs',
        ]);

        $rendered = (string) $component->render();

        self::assertMatchesRegularExpression('/name="since"/', $rendered);
        self::assertMatchesRegularExpression('/name="until"/', $rendered);
        // since defaults to "1h" when no URL override is present.
        self::assertMatchesRegularExpression('/name="since"[^>]*value="1h"/', $rendered);
    }

    public function testHydratedFormRendersAllAggregationControls(): void
    {
        $component = $this->createLiveComponent('Explorer:QueryForm', [
            'tenantSlug' => 'acme',
            'signal' => 'logs',
        ]);

        $rendered = (string) $component->render();

        // The function <select> is present and lists all five supported fns.
        self::assertMatchesRegularExpression('/<select\s+name="function"/', $rendered);
        foreach (['count', 'sum', 'avg', 'min', 'max'] as $fn) {
            self::assertStringContainsString('value="'.$fn.'"', $rendered);
        }
        // column / groupBy / interval inputs and the Run button.
        self::assertMatchesRegularExpression('/name="column"/', $rendered);
        self::assertMatchesRegularExpression('/name="groupBy"/', $rendered);
        self::assertMatchesRegularExpression('/name="interval"/', $rendered);
        self::assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>\s*Run\s*<\/button>/', $rendered);
    }

    public function testFormDefaultsCameFromProfileWhenUrlIsEmpty(): void
    {
        // No request pushed → queryValue() returns the supplied default for
        // every field. column ← profile.defaultColumn, groupBy ← profile.defaultGroupBy.
        $component = $this->createLiveComponent('Explorer:QueryForm', [
            'tenantSlug' => 'acme',
            'signal' => 'logs',
        ]);

        $rendered = (string) $component->render();

        // LogsProfile defaults.
        self::assertMatchesRegularExpression('/name="column"[^>]*value="severity_number"/', $rendered);
        self::assertMatchesRegularExpression('/name="groupBy"[^>]*value="resource_service_name"/', $rendered);
        // function default — `count` is the option marked selected.
        self::assertMatchesRegularExpression('/<option\s+value="count"\s+selected\b/', $rendered);
    }

    public function testQueryValueReflectsCurrentRequestQueryString(): void
    {
        // The Live Component test harness fires a real HTTP request through
        // KernelBrowser, which builds its own RequestStack — anything pushed
        // here would be invisible at render time. So this test pins the
        // URL→form-value contract at the source: `queryValue()`, the helper
        // every form input invokes. The rendered-output round-trip is
        // proven separately in the functional test
        // ExplorerAccessTest::testFormSubmissionWithEmptyUntilStillRenders,
        // which exercises the full /tenants/{slug}/explore/{signal}?q=…
        // request path.
        $req = Request::create('/tenants/acme/explore/logs', 'GET', [
            'service' => 'checkout',
            'severity' => 'ERROR',
            'since' => '2h',
            'until' => '2026-05-09T15:00:00Z',
            'function' => 'avg',
            'column' => 'duration_ns',
            'groupBy' => 'resource_host_name',
            'interval' => '5m',
        ]);
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($req);

        try {
            $form = self::getContainer()->get(QueryForm::class);
            self::assertInstanceOf(QueryForm::class, $form);

            // Every URL key surfaces back through queryValue() at form render.
            self::assertSame('checkout', $form->queryValue('service'));
            self::assertSame('ERROR', $form->queryValue('severity'));
            self::assertSame('2h', $form->queryValue('since', '1h'));
            self::assertSame('2026-05-09T15:00:00Z', $form->queryValue('until'));
            self::assertSame('avg', $form->queryValue('function', 'count'));
            self::assertSame('duration_ns', $form->queryValue('column', 'severity_number'));
            self::assertSame('resource_host_name', $form->queryValue('groupBy', 'resource_service_name'));
            self::assertSame('5m', $form->queryValue('interval', '1m'));

            // Keys absent from the URL fall through to the supplied default.
            self::assertSame('', $form->queryValue('host'));
            self::assertSame('fallback', $form->queryValue('nonexistent', 'fallback'));
        } finally {
            $stack->pop();
        }
    }
}
