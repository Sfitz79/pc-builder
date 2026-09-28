<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@type": ["Organization", "LocalBusiness"],
    "@id": "{{ url('/') }}#organization",
    "name": "PCTechGuy Online",
    "alternateName": ["PCTechGuyOnline", "PcTechGuy", "PCTG"],
    "url": "{{ url('/') }}",
    "description": "UK custom gaming PC builder. Configure your own PC online with an AI-assisted configurator, built to order in the UK, with warranty and lifetime technical support.",
    "telephone": "+447933101083",
    "email": "info@pctechguyonline.com",
    "address": {
        "@type": "PostalAddress",
        "streetAddress": "Long Cross",
        "addressLocality": "Bristol",
        "addressRegion": "England",
        "postalCode": "BS11 0TT",
        "addressCountry": "GB"
    },
    "contactPoint": [
        {
            "@type": "ContactPoint",
            "telephone": "+447933101083",
            "email": "info@pctechguyonline.com",
            "contactType": "sales",
            "areaServed": "GB",
            "availableLanguage": ["English"]
        },
        {
            "@type": "ContactPoint",
            "telephone": "+447933101083",
            "contactType": "technical support",
            "areaServed": "GB",
            "availableLanguage": ["English"]
        }
    ],
    "areaServed": {
        "@type": "Country",
        "name": "United Kingdom"
    },
    "currenciesAccepted": "GBP",
    "paymentAccepted": ["Credit Card", "Debit Card", "PayPal"],
    "priceRange": "GBP",
    "openingHoursSpecification": [
        {
            "@type": "OpeningHoursSpecification",
            "dayOfWeek": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
            "opens": "09:00",
            "closes": "17:00"
        }
    ],
    "sameAs": [
        "https://www.facebook.com/pctechguyonline",
        "https://www.instagram.com/pctechguyonline",
        "https://www.youtube.com/channel/UCKZVSHOWfJAJKdDr73pmNnA",
        "https://www.tiktok.com/@pctechguyonline",
        "https://www.twitch.tv/pctechguyonline",
        "https://uk.trustpilot.com/review/pctechguyonline.com"
    ],
    "knowsAbout": [
        "Custom gaming PCs",
        "Gaming PC configurator",
        "PC building",
        "PC repair",
        "PC upgrades",
        "Gaming PC performance optimisation",
        "Windows software licensing"
    ],
    "hasOfferCatalog": {
        "@type": "OfferCatalog",
        "name": "UK Custom Gaming PC Builder",
        "itemListElement": [
            {
                "@type": "OfferCatalog",
                "name": "AI PC Builder",
                "url": "{{ url('/builder') }}"
            },
            {
                "@type": "OfferCatalog",
                "name": "Prebuilt Gaming PCs",
                "url": "{{ url('/prebuilts') }}"
            },
            {
                "@type": "OfferCatalog",
                "name": "Gaming PC Buying Guides",
                "url": "{{ url('/best-gaming-pc-under-1000') }}"
            },
            {
                "@type": "OfferCatalog",
                "name": "PC Services",
                "url": "{{ url('/support') }}"
            }
        ]
    }
}
</script>