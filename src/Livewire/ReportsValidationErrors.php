<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\ComponentHook;

/**
 * Livewire swallows a ValidationException thrown inside an action: the call returns null, and the errors only reach the browser when they belong to a public property of the component
 * (errors of `Validator::make()` are dropped on purpose). An agent would read that as success.
 *
 * For components that have #[WebMcpAction] methods this hook sends the validation messages along as the `webmcpErrors` effect of the response; webmcp-livewire.js turns them into an error result for the agent.
 * Components without WebMCP actions are not touched.
 */
class ReportsValidationErrors extends ComponentHook
{
    /** @var array<string, list<string>>|null */
    private ?array $errors = null;

    /** @param  callable(): void  $stopPropagation */
    public function exception(mixed $e, mixed $stopPropagation): void
    {
        if (!$e instanceof ValidationException || !is_object($this->component) || !ActionManifest::hasActions($this->component)) {
            return;
        }
        $errors = [];
        foreach ($e->errors() as $field => $messages) {
            $errors[(string) $field] = [];
            foreach ((array) $messages as $message) {
                $errors[(string) $field][] = is_scalar($message) ? (string) $message : '';
            }
        }
        $this->errors = $errors;
    }

    public function dehydrate(mixed $context): void
    {
        if ($this->errors !== null && is_object($context) && method_exists($context, 'addEffect')) {
            $context->addEffect('webmcpErrors', $this->errors);
        }
    }
}
