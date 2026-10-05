<form method="{{ $formMethod === 'get' ? 'get' : 'post' }}" @if ($formAction) action="{{ $formAction }}" @endif toolname="{{ $definition->name }}" tooldescription="{{ $definition->description }}" @if ($autoSubmit) toolautosubmit @endif data-webmcp-endpoint="{{ $endpoint }}" data-webmcp-types="{{ json_encode($types) }}" @if ($definition->confirm) data-webmcp-confirm @endif {{ $attributes->except(['method', 'action', 'toolname', 'tooldescription', 'toolautosubmit', 'data-webmcp-endpoint', 'data-webmcp-types', 'data-webmcp-confirm']) }}>
@if ($formMethod !== 'get')
@csrf
@if ($formMethod !== 'post')
@method(strtoupper($formMethod))
@endif
@endif
@if ($withControls)
@foreach ($fieldList as $field)
<div class="webmcp-field">
@php($id = $field->id($idPrefix))
@if ($field->kind === 'checkbox')
<label for="{{ $id }}"><input type="checkbox" id="{{ $id }}" name="{{ $field->name }}" value="1" @if ($field->description) toolparamdescription="{{ $field->description }}" @endif @checked($field->default === true)> {{ $field->label }}</label>
@else
<label for="{{ $id }}">{{ $field->label }}</label>
@if ($field->kind === 'select')
<select id="{{ $id }}" name="{{ $field->name }}" @if ($field->required) required @endif @if ($field->description) toolparamdescription="{{ $field->description }}" @endif>
@unless ($field->required)
<option value=""></option>
@endunless
@foreach ($field->options as $option)
<option value="{{ $option['value'] }}" @selected($field->default !== null && (string) $field->default === $option['value'])>{{ $option['label'] }}</option>
@endforeach
</select>
@else
<input type="{{ $field->inputType }}" id="{{ $id }}" name="{{ $field->name }}" @if ($field->required) required @endif @if ($field->description) toolparamdescription="{{ $field->description }}" @endif @foreach ($field->attributes as $attribute => $value) {{ $attribute }}="{{ $value }}" @endforeach @if ($field->default !== null) value="{{ $field->default }}" @endif>
@endif
@endif
</div>
@endforeach
@endif
{{ $slot }}
<button type="submit">{{ $submitLabel }}</button>
</form>
