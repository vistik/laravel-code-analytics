<?php

namespace Vistik\LaravelCodeAnalytics\Support\Detection;

enum ProjectType: string
{
    case LaravelApp = 'LaravelApp';
    case LaravelPackage = 'LaravelPackage';
    case PhpPackage = 'PhpPackage';
    case Unknown = 'Unknown';
}
