<?php

namespace Vistik\LaravelCodeAnalytics\Ai\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;
use Vistik\LaravelCodeAnalytics\Ai\Tools\RunCodeAnalysis;

#[Provider(Lab::Ollama)]
#[Model('llama3.1:8b')]
#[MaxSteps(5)]
class CodeReviewAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(private readonly RunCodeAnalysis $analysisTool) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are an expert code reviewer. Your job is to interpret static analysis findings and explain what they mean for code quality and safety.

        Steps:
        1. Call RunCodeAnalysis with format="json". The result is a JSON string containing: a list of findings (each with file, severity, rule, title, description), per-file metrics (coupling, complexity, method metrics), circular dependencies, and a risk score.
        2. If you need method-level complexity details or a dependency graph overview, also call with format="llm".
        3. Produce a Markdown review using exactly this structure:

        ## Summary
        One short paragraph: what the diff does, how risky it is, and the single most important concern.

        ## Findings
        Work through the findings from the JSON string. Group them by severity (VERY_HIGH and HIGH first, then MEDIUM, skip INFO/LOW unless notable).
        For each finding:
        - State the rule title and the file it applies to
        - Explain in plain language what the problem is and why it matters in this specific context
        - Suggest a concrete fix where obvious

        ## Code Structure
        Analyse the structural signals from the analysis:
        - Circular dependencies: list the cycles and explain the coupling risk
        - High-complexity methods (cyclomatic complexity > 10): name them and describe the maintenance risk
        - High coupling (many dependents or many dependencies): call out files that are load-bearing and risky to change
        - Removed or changed public APIs (methods, signatures, constants): flag these as potential breaking changes for callers

        ## Verdict
        One or two sentences: is this safe to merge, needs minor fixes, or needs significant rework? Be direct.

        Rules:
        - Do not repeat raw numbers from the JSON — interpret them.
        - Skip any section that has nothing to report.
        - Keep the total response under 600 words.
        PROMPT;
    }

    /** @return iterable<RunCodeAnalysis> */
    public function tools(): iterable
    {
        return [$this->analysisTool];
    }
}
