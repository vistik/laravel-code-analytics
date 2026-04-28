<?php

namespace Vistik\LaravelCodeAnalytics\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Vistik\LaravelCodeAnalytics\Actions\AnalyzeCode;
use Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums\Severity;
use Vistik\LaravelCodeAnalytics\Enums\OutputFormat;

class RunCodeAnalysis implements Tool
{
    /**
     * @param  list<string>|null  $filePatterns
     */
    private ?string $precomputedLlm = null;

    private ?string $precomputedJson = null;

    public function __construct(
        private readonly string $repoPath,
        private readonly ?string $baseBranch = 'main',
        private readonly ?string $prUrl = null,
        private readonly bool $full = false,
        private readonly ?array $filePatterns = null,
        private readonly ?string $fromCommit = null,
        private readonly ?string $toCommit = null,
        private readonly ?Severity $minSeverity = null,
    ) {}

    public function withPrecomputed(string $llm, string $json): static
    {
        $clone = clone $this;
        $clone->precomputedLlm = $llm;
        $clone->precomputedJson = $json;

        return $clone;
    }

    public function description(): Stringable|string
    {
        return 'Runs static code analysis (AST diff, risk scoring, findings) on the repository and returns the results. '
            .'Use format="llm" for a compact text overview of metrics and dependencies, '
            .'or format="json" for full structured data including all individual findings per file.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'format' => $schema->string()
                ->enum(['llm', 'json'])
                ->description('Output format: "llm" for compact metrics+deps overview, "json" for full findings list')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        if ($request['format'] === 'json' && $this->precomputedJson !== null) {
            return $this->precomputedJson;
        }

        if ($request['format'] === 'llm' && $this->precomputedLlm !== null) {
            return $this->precomputedLlm;
        }

        $format = $request['format'] === 'json' ? OutputFormat::JSON : OutputFormat::LLM;

        $result = (new AnalyzeCode)->execute(
            repoPath: $this->repoPath,
            baseBranch: $this->baseBranch,
            prUrl: $this->prUrl,
            full: $this->full,
            format: $format,
            minSeverity: $this->minSeverity,
            filePatterns: $this->filePatterns,
            raw: true,
            fromCommit: $this->fromCommit,
            toCommit: $this->toCommit,
        );

        return $result['content'] ?? 'No analysis output available.';
    }
}
