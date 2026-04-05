<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums;

enum FileStatus: string
{
    case ADDED = 'added';
    case DELETED = 'deleted';
    case MODIFIED = 'modified';
    case RENAMED = 'renamed';
}
