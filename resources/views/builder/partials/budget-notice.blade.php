{{--
     SHARED TAIL OF THE AI WIZARD — generation error, budget floor notice and the
     generated-build confirmation.

     Extracted from ai-wizard.blade.php because that file now branches on
     segment, and the three blocks below are identical for both product lines.
     Duplicating them would have meant fixing a wording bug in one branch only,
     which is how the two halves drift apart.

     Deliberately NOT themed to the segment: red here means the request FAILED
     and amber means the budget was corrected. Those are semantic colours, not
     brand colours, so they stay red and amber in PCTG Business too. Re-painting
     an error red as blue would remove the one signal the customer needs.
--}}

<p
    x-show="generationError"
    x-cloak
    role="alert"
    aria-live="assertive"
    class="mt-3 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-300"
    x-text="generationError"
></p>

{{-- Below the honest minimum: say why, in plain English, and offer the
     part-new/part-used route on WhatsApp rather than leaving the customer
     at a dead end. --}}
<div
    x-show="budgetNotice"
    x-cloak
    x-transition
    class="mt-4 rounded-xl border border-amber-500/50 bg-amber-500/10 p-4"
    role="status"
    aria-live="polite"
>
    <div class="flex items-start gap-3">
        <span class="mt-0.5 text-lg" aria-hidden="true">⚠️</span>

        <div class="flex-1">
            <p
                class="font-semibold text-amber-200"
                x-text="budgetNotice
                    ? 'We have moved your budget to ' + money(budgetNotice.min)
                    : ''"
            ></p>

            <p
                class="mt-1 text-sm text-amber-100/90"
                x-text="budgetNotice ? budgetNotice.message : ''"
            ></p>

            <div
                x-show="budgetNotice && budgetNotice.hybrid"
                class="mt-3 rounded-lg border border-amber-500/30 bg-black/20 p-3"
            >
                <p
                    class="text-sm font-semibold text-white"
                    x-text="budgetNotice && budgetNotice.hybrid
                        ? budgetNotice.hybrid.headline
                        : ''"
                ></p>

                <p
                    class="mt-1 text-sm text-slate-300"
                    x-text="budgetNotice && budgetNotice.hybrid
                        ? budgetNotice.hybrid.message
                        : ''"
                ></p>

                <a
                    class="mt-3 inline-flex items-center gap-2 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-400"
                    :href="budgetNotice && budgetNotice.hybrid
                        ? budgetNotice.hybrid.whatsapp_url
                        : '#'"
                    target="_blank"
                    rel="noopener"
                >
                    <span aria-hidden="true">💬</span>
                    Message us on WhatsApp
                </a>
            </div>

            <button
                type="button"
                class="mt-3 text-sm text-slate-400 underline hover:text-slate-200"
                @click="dismissBudgetNotice()"
            >
                Got it
            </button>
        </div>
    </div>
</div>

<p
    class="mt-4 text-sm font-medium text-emerald-400"
    x-show="aiRecommendation && aiRecommendation.total && !loading"
    x-cloak
>
    ✓ Build generated — £<span x-text="aiRecommendation ? aiRecommendation.total.toLocaleString() : ''"></span> total · review your parts below
</p>
