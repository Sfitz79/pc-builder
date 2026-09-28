@php
$crumbs = $crumbs ?? [];
$faqs = $faqs ?? [];
@endphp

<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@type": "Article",
    "headline": {!! json_encode($headline ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!},
    "description": {!! json_encode($description ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!},
    "inLanguage": "en-GB",
    "dateModified": {!! json_encode(now()->toIso8601String(), JSON_UNESCAPED_SLASHES) !!},
    "publisher": {
        "@type": "Organization",
        "@id": "{{ url('/') }}#organization",
        "name": "PCTechGuy Online"
    },
    "mainEntityOfPage": {
        "@type": "WebPage",
        "@id": "{{ url()->current() }}"
    }
}
</script>

@if (! empty($crumbs))
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
        {
            "@type": "ListItem",
            "position": 1,
            "name": "Home",
            "item": "{{ url('/') }}"
        }
        @foreach ($crumbs as $position => $crumb)
        ,{
            "@type": "ListItem",
            "position": {{ $position + 2 }},
            "name": {!! json_encode($crumb['name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!},
            "item": {!! json_encode($crumb['url'], JSON_UNESCAPED_SLASHES) !!}
        }
        @endforeach
    ]
}
</script>
@endif

@if (! empty($faqs))
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
        @foreach ($faqs as $index => $faq)
        {
            "@type": "Question",
            "name": {!! json_encode($faq['q'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!},
            "acceptedAnswer": {
                "@type": "Answer",
                "text": {!! json_encode($faq['a'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
            }
        }{{ ! $loop->last ? ',' : '' }}
        @endforeach
    ]
}
</script>
@endif