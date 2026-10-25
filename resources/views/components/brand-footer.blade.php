@php($brand = config('screamingrabbit.brand'))
<div style="text-align:center;padding:1rem;font-size:.8rem;opacity:.7">
    {{ $brand['product'] }} &middot; Bir <a href="{{ $brand['url'] }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ $brand['name'] }}</a> ürünü
</div>
