<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Manifest;

enum ExclusionReason: string
{
    case MissingAttribute = 'missing-attribute';
    case ShouldRegister = 'should-register';
    case AppOnly = 'app-only';
    case AppResource = 'app-resource';
    case Prompt = 'prompt';
    case ToolSearchMeta = 'toolsearch-meta';
    case TemplateWithoutAuthorization = 'template-without-authorization';
    case Limit = 'limit';
}
