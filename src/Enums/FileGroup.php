<?php

namespace Vistik\LaravelCodeAnalytics\Enums;

enum FileGroup: string
{
    case TEST = 'test';
    case DB = 'db';
    case NOVA = 'nova';
    case MODEL = 'model';
    case ACTION = 'action';
    case JOB = 'job';
    case CONTROLLER = 'controller';
    case REQUEST = 'request';
    case HTTP = 'http';
    case CONSOLE = 'console';
    case PROVIDER = 'provider';
    case CORE = 'core';
    case EVENT = 'event';
    case SERVICE = 'service';
    case VIEW = 'view';
    case FRONTEND = 'frontend';
    case CONFIG = 'config';
    case ROUTE = 'route';
    case OTHER = 'other';
    // Method-graph visibility groups (used by code:file command)
    case VIS_PUBLIC = 'vis_public';
    case VIS_PROTECTED = 'vis_protected';
    case VIS_PRIVATE = 'vis_private';
    case VIS_EXTERNAL = 'vis_external';

    public function color(): string
    {
        return match ($this) {
            self::TEST => '#58a6ff',
            self::DB => '#8957e5',
            self::NOVA => '#d2a8ff',
            self::MODEL => '#3fb950',
            self::ACTION => '#d29922',
            self::JOB => '#e3b341',
            self::CONTROLLER => '#f0883e',
            self::REQUEST => '#e06c75',
            self::HTTP => '#c8a82e',
            self::CONSOLE => '#b392f0',
            self::PROVIDER => '#56d4dd',
            self::CORE => '#f97583',
            self::EVENT => '#f778ba',
            self::SERVICE => '#79c0ff',
            self::VIEW => '#7ee787',
            self::FRONTEND => '#ff7b72',
            self::CONFIG => '#ffa657',
            self::ROUTE => '#ffab70',
            self::OTHER => '#8b949e',
            self::VIS_PUBLIC => '#79c0ff',
            self::VIS_PROTECTED => '#e3b341',
            self::VIS_PRIVATE => '#8957e5',
            self::VIS_EXTERNAL => '#484f58',
        };
    }

    public function description(string $action, ?string $context = null): string
    {
        return match ($this) {
            self::MODEL => "{$action} model file.",
            self::ACTION => "{$action} action class.",
            self::CONTROLLER => "{$action} controller ({$context}).",
            self::REQUEST => "{$action} form request ({$context}).",
            self::HTTP => "{$action} HTTP layer ({$context}).",
            self::PROVIDER => "{$action} service provider.",
            self::NOVA => "{$action} Nova admin resource.",
            self::DB => "{$action} database file.",
            self::TEST => "{$action} test file.",
            self::CORE => "{$action} core class.",
            self::JOB => "{$action} job class.",
            self::EVENT => "{$action} event/listener.",
            self::SERVICE => "{$action} service class.",
            self::VIEW => "{$action} view template.",
            self::FRONTEND => "{$action} frontend asset.",
            self::CONFIG => "{$action} configuration.",
            self::ROUTE => "{$action} route definition.",
            self::CONSOLE => "{$action} console command.",
            self::OTHER, self::VIS_PUBLIC, self::VIS_PROTECTED, self::VIS_PRIVATE, self::VIS_EXTERNAL => "{$action} file.",
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TEST => 'Test',
            self::DB => 'Database',
            self::NOVA => 'Nova Admin',
            self::MODEL => 'Model',
            self::ACTION => 'Action',
            self::JOB => 'Job',
            self::CONTROLLER => 'Controller',
            self::REQUEST => 'Request',
            self::HTTP => 'HTTP',
            self::CONSOLE => 'Console',
            self::PROVIDER => 'Provider',
            self::CORE => 'Core',
            self::EVENT => 'Event',
            self::SERVICE => 'Service',
            self::VIEW => 'View',
            self::FRONTEND => 'Frontend',
            self::CONFIG => 'Config',
            self::ROUTE => 'Route',
            self::OTHER => 'Other',
            self::VIS_PUBLIC => 'Public',
            self::VIS_PROTECTED => 'Protected',
            self::VIS_PRIVATE => 'Private',
            self::VIS_EXTERNAL => 'External',
        };
    }
}
