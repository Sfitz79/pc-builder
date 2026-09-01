<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gemini (AI build enrichment)
    |--------------------------------------------------------------------------
    |
    | When GEMINI_API_KEY is set, AIRecommendationService asks Gemini to refine
    | its heuristic component scoring with per-category weights and attaches a
    | short build rationale to the result under the `ai` key.
    |
    | Without a key (or on any request failure) the service returns the plain
    | heuristic build unchanged, so this provider is strictly optional.
    |
    | Env: GEMINI_API_KEY, GEMINI_BASE_URL, GEMINI_MODEL, GEMINI_TIMEOUT
    |
    */

    'key' => env('GEMINI_API_KEY'),

    'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),

    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),

    'timeout' => (int) env('GEMINI_TIMEOUT', 15),

];
