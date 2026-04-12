<?php

namespace Vistik\LaravelCodeAnalytics\DiffParser;

class DiffParser
{
    /**
     * Parse a unified diff string into structured tokens.
     *
     * Each token is one of:
     *   ['type' => 'hunk',   'raw' => string, 'oldStart' => int, 'newStart' => int]
     *   ['type' => 'change', 'dels' => string[], 'adds' => string[]]
     *   ['type' => 'ctx',    'text' => string]
     *
     * Backslash lines ("\ No newline at end of file") are silently skipped.
     *
     * @param  string  $rawDiff  Raw unified diff string
     * @return list<array<string, mixed>>
     */
    public function parse(string $rawDiff): array
    {
        $lines = explode("\n", $rawDiff);
        $result = [];
        $i = 0;
        $total = count($lines);

        while ($i < $total) {
            $line = $lines[$i];

            if (str_starts_with($line, '@@')) {
                preg_match('/@@ -(\d+)(?:,\d+)? \+(\d+)/', $line, $hm);
                $result[] = [
                    'type'     => 'hunk',
                    'raw'      => $line,
                    'oldStart' => isset($hm[1]) ? (int) $hm[1] : 1,
                    'newStart' => isset($hm[2]) ? (int) $hm[2] : 1,
                ];
                $i++;

            } elseif (str_starts_with($line, '\\')) {
                // "\ No newline at end of file" — skip
                $i++;

            } elseif (str_starts_with($line, '-') || str_starts_with($line, '+')) {
                $dels = [];
                $adds = [];

                while ($i < $total) {
                    if (str_starts_with($lines[$i], '\\')) {
                        $i++;
                        continue;
                    }
                    if (str_starts_with($lines[$i], '-')) {
                        $dels[] = substr($lines[$i], 1);
                        $i++;
                    } elseif (str_starts_with($lines[$i], '+')) {
                        $adds[] = substr($lines[$i], 1);
                        $i++;
                    } else {
                        break;
                    }
                }

                $result[] = ['type' => 'change', 'dels' => $dels, 'adds' => $adds];

            } else {
                $result[] = [
                    'type' => 'ctx',
                    'text' => $line !== '' ? substr($line, 1) : '',
                ];
                $i++;
            }
        }

        return $result;
    }

    /**
     * Parse multiple diffs keyed by file path.
     *
     * @param  array<string, string>  $fileDiffs  Raw unified diffs keyed by path
     * @return array<string, list<array<string, mixed>>>
     */
    public function parseAll(array $fileDiffs): array
    {
        $result = [];
        foreach ($fileDiffs as $path => $rawDiff) {
            $result[$path] = $this->parse($rawDiff);
        }

        return $result;
    }
}
