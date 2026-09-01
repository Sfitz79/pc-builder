@php
$budget = $budget ?? 1000;

$targetResolution = match (true) {
    $budget >= 2500 => '4K gaming',
    $budget >= 1500 => '1440P gaming',
    default => '1080P gaming',
};

$performanceTier = match (true) {
    $budget >= 3000 => 'enthusiast-level',
    $budget >= 2000 => 'high-end',
    $budget >= 1500 => 'performance-focused',
    default => 'budget-friendly',
};
@endphp

<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
        {
            "@type": "Question",
            "name": "Is a £{{ $budget }} gaming PC worth it?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "A £{{ $budget }} gaming PC offers {{ $performanceTier }} gaming performance and excellent value for gamers looking for a balanced custom gaming system."
            }
        },
        {
            "@type": "Question",
            "name": "What games can a £{{ $budget }} gaming PC run?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "A properly configured £{{ $budget }} gaming PC can run modern games including Fortnite, Call of Duty, Apex Legends, Counter-Strike 2 and many other popular titles."
            }
        },
        {
            "@type": "Question",
            "name": "Can a £{{ $budget }} gaming PC handle {{ $targetResolution }}?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "Yes. A carefully selected £{{ $budget }} gaming PC can provide strong performance for {{ $targetResolution }} depending on game settings and hardware configuration."
            }
        },
        {
            "@type": "Question",
            "name": "Can I upgrade a £{{ $budget }} gaming PC later?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "Most modern gaming PCs can be upgraded with additional storage, memory, processors and graphics cards where supported by the platform."
            }
        },
        {
            "@type": "Question",
            "name": "Can PCTG Builder recommend parts automatically?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "Yes. PCTG Builder includes AI-powered recommendations, compatibility checking and gaming PC configuration tools."
            }
        },
        {
            "@type": "Question",
            "name": "Does PcTechGuyOnline build custom gaming PCs?",
            "acceptedAnswer": {
                "@type": "Answer",
                "text": "PcTechGuyOnline provides custom PC building services, gaming systems and hardware recommendations throughout the UK."
            }
        }
    ]
}
</script>
