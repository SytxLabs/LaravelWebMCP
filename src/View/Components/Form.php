<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use SytxLabs\LaravelWebMcp\Exceptions\InvalidWebMcpConfigurationException;
use SytxLabs\LaravelWebMcp\Forms\DeclarativeForms;
use SytxLabs\LaravelWebMcp\Support\Localizer;

/**
 * <x-webmcp::form :tool="SearchProductsTool::class" action="/search" method="get" submit="Search" />.
 *
 * Renders a declarative WebMCP form for an exposed tool (see DeclarativeForms). Props:
 *   tool        the laravel/mcp tool class (must be exposed and in Session mode)
 *   action      the form action for normal user submits
 *   method      get | post | put | patch | delete (default post; CSRF and method spoofing are added)
 *   server      server slug or class when the tool class is registered on several servers
 *   autosubmit  true/false; default: only read-only tools auto-submit
 *   omit        properties you render yourself in the slot
 *   controls    false = no generated controls, only the annotated <form> around your own markup
 *   submit      button label (text or translation key)
 * Passing `class`, `id`, `data-*` etc. works as on any element.
 *
 * Laravel merges a component's public properties over the view data, so the view uses different
 * variable names than these props (formAction, formMethod, autoSubmit, fieldList, withControls).
 */
class Form extends Component
{
    /**
     * @param class-string $tool
     * @param list<string> $omit
     */
    public function __construct(public string $tool, public ?string $action = null, public string $method = 'post', public ?string $server = null, public ?bool $autosubmit = null, public array $omit = [], public ?string $submit = null, public bool $controls = true)
    {
    }

    public function render(): View
    {
        if ($this->action !== null && preg_match('/^[\s\x00-\x20]*(javascript|data|vbscript):/i', $this->action) === 1) {
            throw new InvalidWebMcpConfigurationException('The form action must not use a javascript:, data: or vbscript: URL.');
        }
        $prepared = app(DeclarativeForms::class)->prepare($this->tool, $this->server, $this->autosubmit, $this->omit);
        $method = strtolower($this->method);

        return view('webmcp::form', [
            'definition' => $prepared['definition'],
            'fieldList' => $prepared['fields'],
            'types' => $prepared['types'],
            'endpoint' => $prepared['endpoint'],
            'autoSubmit' => $prepared['autosubmit'],
            'formAction' => $this->action,
            'formMethod' => in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) ? $method : 'post',
            'withControls' => $this->controls,
            'submitLabel' => $this->submit !== null ? app(Localizer::class)->text($this->submit) : app(Localizer::class)->message('submit'),
            'idPrefix' => 'webmcp-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $prepared['definition']->name),
        ]);
    }
}
