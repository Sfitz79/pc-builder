@php
$topic = $topic ?? 'Fortnite';
$resolution = $resolution ?? '1440P';
$entryBudget = $entryBudget ?? 1000;
@endphp

<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
        {
            "@type": "Question",
            "name": "What PC specs do I need for {{ $topic }}?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "For a smooth {{ $topic }} experience you need a capable modern CPU, a dedicated graphics card and at least 16GB of memory. The recommended configurations on this page cover competitive, mid-range and high-end options."
            }
        },
        {
            "@type": "Question",
            "name": "How much FPS can I get in {{ $topic }} at {{ $resolution }}?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "The recommended gaming PCs on this page are selected to deliver strong frame rates in {{ $topic }} at {{ $resolution }}, with higher-tier builds providing significantly more headroom for high refresh rate displays."
            }
        },
        {
            "@type": "Question",
            "name": "What budget do I need for a {{ $topic }} PC?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "A capable {{ $topic }} PC can be built from around £{{ $entryBudget }}, with larger budgets buying higher resolutions, better settings and more future-proof performance."
            }
        },
        {
            "@type": "Question",
            "name": "Can PCTG Builder recommend a {{ $topic }} PC?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "Yes. The PCTG AI Builder recommends compatible components based on your budget, purpose and resolution, so you can generate a {{ $topic }} gaming PC in seconds."
            }
        },
        {
            "@type": "Question",
            "name": "Can I upgrade my {{ $topic }} PC later?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "Most of the recommended builds use upgradeable platforms, allowing future graphics card, memory and storage upgrades where supported."
            }
        }
    ]
}
</script>
