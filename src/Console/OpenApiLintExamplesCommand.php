<?php

declare(strict_types=1);

namespace App\Console;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Asserts that every read-API operation in the generated OpenAPI document
 * carries an `example` (or non-empty `examples`) on each documented query
 * parameter.
 *
 * Scoped to operations whose path matches `^/v1/(logs|traces|metrics|spans)`.
 * The OTLP write endpoints share the path scheme on POST but are not
 * declared via API Platform attributes; they're excluded by allow-list.
 *
 * Exits non-zero on any violation with a per-violation line so CI catches
 * documentation regressions on every build.
 */
#[AsCommand(name: 'app:openapi:lint-examples', description: 'Assert every read-API parameter has an example in the generated OpenAPI document')]
final class OpenApiLintExamplesCommand extends Command
{
    private const string SCOPE_PATTERN = '#^/v1/(logs|traces|metrics|spans)(/|$)#';

    public function __construct(
        private readonly OpenApiFactoryInterface $openApiFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $openApi = ($this->openApiFactory)();
        $paths = $openApi->getPaths()->getPaths();

        $violations = [];
        foreach ($paths as $path => $pathItem) {
            if (1 !== preg_match(self::SCOPE_PATTERN, (string) $path)) {
                continue;
            }
            foreach (self::operationsOf($pathItem) as $method => $operation) {
                if (null === $operation) {
                    continue;
                }
                $parameters = $operation->getParameters();
                foreach ($parameters as $parameter) {
                    if (self::isFrameworkParameter($parameter->getName())) {
                        continue;
                    }
                    $hasExample = null !== $parameter->getExample()
                        || (null !== $parameter->getExamples() && \count($parameter->getExamples()) > 0);
                    if (!$hasExample) {
                        $violations[] = \sprintf(
                            '%s %s — parameter `%s` lacks both `example` and `examples`',
                            strtoupper($method),
                            $path,
                            $parameter->getName(),
                        );
                    }
                }
            }
        }

        if ([] === $violations) {
            $io->success('OpenAPI examples: every in-scope read-API parameter is documented with at least one example.');

            return Command::SUCCESS;
        }

        $io->error('OpenAPI example coverage incomplete:');
        foreach ($violations as $violation) {
            $io->writeln('  - '.$violation);
        }

        return Command::FAILURE;
    }

    /**
     * AP4 auto-injects framework parameters (`page`, `itemsPerPage`) when
     * pagination is enabled. They're not part of the application's own
     * `#[QueryParameter]` declarations, so we exempt them from the rule.
     */
    private static function isFrameworkParameter(string $name): bool
    {
        return \in_array($name, ['page', 'itemsPerPage', 'pagination'], true);
    }

    /**
     * @return iterable<string, ?\ApiPlatform\OpenApi\Model\Operation>
     */
    private static function operationsOf(\ApiPlatform\OpenApi\Model\PathItem $pathItem): iterable
    {
        yield 'get' => $pathItem->getGet();
        yield 'post' => $pathItem->getPost();
        yield 'put' => $pathItem->getPut();
        yield 'patch' => $pathItem->getPatch();
        yield 'delete' => $pathItem->getDelete();
    }
}
