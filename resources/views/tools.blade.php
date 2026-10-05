@foreach ($manifests as $manifest)
<script type="application/json" id="webmcp-manifest-{{ $manifest['slug'] }}" data-webmcp-manifest @if ($nonce) nonce="{{ $nonce }}" @endif>{!! $manifest['json'] !!}</script>
@endforeach
@if ($script && count($manifests) > 0)
@once
<script type="module" src="{{ $src }}" @if ($nonce) nonce="{{ $nonce }}" @endif></script>
@endonce
@endif
