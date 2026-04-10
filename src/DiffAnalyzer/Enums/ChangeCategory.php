<?php

namespace Vistik\LaravelCodeAnalytics\DiffAnalyzer\Enums;

enum ChangeCategory: string
{
    case COSMETIC = 'cosmetic';
    case TYPE_SYSTEM = 'type_system';
    case CONDITIONAL = 'conditional';
    case LOOP = 'loop';
    case TRY_CATCH = 'try_catch';
    case RETURN = 'return';
    case SWITCH_MATCH = 'switch_match';
    case OPERATORS = 'operators';
    case VALUES = 'values';
    case METHOD_SIGNATURE = 'method_signature';
    case METHOD_ADDED = 'method_added';
    case METHOD_CHANGED = 'method_changed';
    case METHOD_REMOVED = 'method_removed';
    case METHOD_RENAMED = 'method_renamed';
    case CLASS_STRUCTURE = 'class_structure';
    case SIDE_EFFECTS = 'side_effects';
    case LARAVEL = 'laravel';
    case RELATIONSHIP_ADDED = 'relationship_added';
    case RELATIONSHIP_REMOVED = 'relationship_removed';
    case RELATIONSHIP_TYPE_CHANGED = 'relationship_type_changed';
    case RELATIONSHIP_CHANGED = 'relationship_changed';
    case RELATIONSHIP_CONSTRAINT_CHANGED = 'relationship_constraint_changed';
    case MIGRATION_MODEL_LINK = 'migration_model_link';
    case IMPORTS = 'imports';
    case ASSIGNMENT = 'assignment';
    case FILE_LEVEL = 'file_level';
    case DATETIME = 'datetime';
    case CACHE_ADDED = 'cache_added';
    case CACHE_MODIFIED = 'cache_modified';
    case CACHE_REMOVED = 'cache_removed';
    case DB_QUERY_ADDED = 'db_query_added';
    case DB_QUERY_MODIFIED = 'db_query_modified';
    case DB_QUERY_REMOVED = 'db_query_removed';

    public function shortDescription(): string
    {
        return match ($this) {
            self::COSMETIC => 'Whitespace, formatting & comments',
            self::TYPE_SYSTEM => 'Type hints & return types',
            self::CONDITIONAL => 'If/elseif/else conditions & branches',
            self::LOOP => 'Loop additions, removals & changes',
            self::TRY_CATCH => 'Try-catch block changes',
            self::RETURN => 'Return statement additions, removals & value changes',
            self::SWITCH_MATCH => 'Switch case & match arm changes',
            self::OPERATORS => 'Operator changes',
            self::VALUES => 'Constants & default values',
            self::METHOD_SIGNATURE => 'Method signatures & visibility',
            self::METHOD_ADDED => 'Method additions',
            self::METHOD_CHANGED => 'Method body changes',
            self::METHOD_REMOVED => 'Method removals',
            self::METHOD_RENAMED => 'Method renames',
            self::CLASS_STRUCTURE => 'Class, interface & enum structure',
            self::SIDE_EFFECTS => 'Side effects & external calls',
            self::LARAVEL => 'Laravel framework changes',
            self::RELATIONSHIP_ADDED => 'Eloquent relationship additions',
            self::RELATIONSHIP_REMOVED => 'Eloquent relationship removals',
            self::RELATIONSHIP_TYPE_CHANGED => 'Eloquent relationship type changes',
            self::RELATIONSHIP_CHANGED => 'Eloquent relationship configuration changes',
            self::RELATIONSHIP_CONSTRAINT_CHANGED => 'Eloquent relationship constraint changes',
            self::MIGRATION_MODEL_LINK => 'Migration-model table connections',
            self::IMPORTS => 'Use/import statement changes',
            self::ASSIGNMENT => 'Variable assignments & compound operators',
            self::FILE_LEVEL => 'File type & status classification',
            self::DATETIME => 'Date & time manipulation',
            self::CACHE_ADDED => 'Cache operation additions',
            self::CACHE_MODIFIED => 'Cache operation modifications',
            self::CACHE_REMOVED => 'Cache operation removals',
            self::DB_QUERY_ADDED => 'DB facade query additions',
            self::DB_QUERY_MODIFIED => 'DB facade query modifications',
            self::DB_QUERY_REMOVED => 'DB facade query removals',
        };
    }
}
