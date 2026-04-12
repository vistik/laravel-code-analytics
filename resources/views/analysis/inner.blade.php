<!DOCTYPE html>
<html lang="en">
@include('laravel-code-analytics::analysis.partials._head')
<body>
@include('laravel-code-analytics::analysis.partials._html')
<script>
@include('laravel-code-analytics::analysis.partials._script-init')
@include('laravel-code-analytics::analysis.partials._script-highlight')
@include('laravel-code-analytics::analysis.partials._script-diff')
@include('laravel-code-analytics::analysis.partials._script-panel')
@include('laravel-code-analytics::analysis.partials._script-pathfind')
@include('laravel-code-analytics::analysis.partials._script-interaction')
@include('laravel-code-analytics::analysis.partials._script-events')
</script>
</body>
</html>
