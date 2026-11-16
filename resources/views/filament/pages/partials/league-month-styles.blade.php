{{--
    Layout of the "Рейтинг месяца" pages. The panel has no theme build of its own (no `->viteTheme()`), so Tailwind
    utility classes written in our own templates produce no CSS: only Filament components and the classes already in its
    stylesheet work. These few rules are prefixed `lm-`, use `currentColor` and translucent greys (they follow the dark
    theme) and need no build; the panel's CSP allows inline styles (`style-src 'self' 'unsafe-inline'`).
--}}
@once
    <style>
        .lm-scroll { overflow-x: auto; }

        /* Header of the month: label above value, as many pairs per row as fit. */
        .lm-head { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 1rem 1.5rem; margin: 0; }
        .lm-head > div { display: flex; flex-direction: column; align-items: flex-start; gap: 0.25rem; min-width: 0; }
        .lm-head dt { font-size: 0.8125rem; opacity: 0.65; }
        .lm-head dd { margin: 0; font-size: 0.9375rem; }

        /* Tables. */
        .lm-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; line-height: 1.25rem; text-align: start; }
        .lm-table th,
        .lm-table td { padding: 0.75rem 0.5rem; text-align: start; vertical-align: top; }
        .lm-table thead th { font-weight: 600; white-space: normal; border-bottom: 1px solid rgba(128, 128, 128, 0.35); }
        .lm-table tbody td { border-top: 1px solid rgba(128, 128, 128, 0.2); }
        .lm-table tbody tr:first-child td { border-top: 0; }
        .lm-table th:first-child,
        .lm-table td:first-child { padding-inline-start: 0.25rem; }
        .lm-table th:last-child,
        .lm-table td:last-child { padding-inline-end: 0.25rem; }
        .lm-table .lm-num { text-align: end; font-variant-numeric: tabular-nums; }
        .lm-table .lm-nowrap { white-space: nowrap; }
        .lm-table .lm-small { font-size: 0.75rem; line-height: 1rem; }
        .lm-muted { opacity: 0.65; font-size: 0.75rem; line-height: 1rem; }
        .lm-table .lm-warn { color: rgb(217, 119, 6); font-weight: 600; }

        /* A badge in a table is never cut ("Канд…"): the label keeps its full width. */
        .lm-table .fi-badge { max-width: none; overflow: visible; text-overflow: clip; white-space: nowrap; flex-shrink: 0; }
        .lm-table .fi-badge .fi-badge-label { overflow: visible; text-overflow: clip; white-space: nowrap; }

        /* Signals: flags as badges in a row, the numbers in two columns under them. */
        .lm-signals { min-width: 11rem; }
        .lm-flags { display: flex; flex-wrap: wrap; gap: 0.25rem; }
        .lm-facts { display: grid; grid-template-columns: repeat(2, minmax(0, auto)); justify-content: start; gap: 0.125rem 0.75rem; margin: 0.5rem 0 0; padding: 0; list-style: none; font-size: 0.75rem; line-height: 1rem; opacity: 0.75; }

        /* Contacts of a winner and the reason of a disqualification. */
        .lm-lines { display: flex; flex-direction: column; gap: 0.125rem; margin-top: 0.25rem; font-size: 0.75rem; line-height: 1rem; }
        .lm-reason { max-width: 18rem; margin-top: 0.25rem; font-size: 0.75rem; line-height: 1rem; }

        /* Row actions: one under another, the same width. */
        .lm-actions { display: flex; flex-direction: column; align-items: stretch; gap: 0.375rem; min-width: 8rem; }
        .lm-actions > * { width: 100%; justify-content: center; }

        /* Prizes of the month and the notes under a section. */
        .lm-list { display: flex; flex-direction: column; gap: 0.375rem; margin: 0; padding: 0; list-style: none; font-size: 0.875rem; }
        .lm-note { margin: 0.75rem 0 0; font-size: 0.75rem; line-height: 1rem; opacity: 0.65; }
        .lm-text { margin: 0; font-size: 0.875rem; }

        /* The modal "Баллы по дням" and the confirmation of the winners. */
        .lm-days { max-width: 100%; }
        .lm-confirm { display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.875rem; }
        .lm-confirm p,
        .lm-confirm ul { margin: 0; }
        .lm-confirm ul { padding-inline-start: 1.25rem; list-style: disc; }
        .lm-confirm li + li { margin-top: 0.25rem; }
        .lm-confirm .lm-alert { padding: 0.5rem 0.75rem; border-radius: 0.5rem; background: rgba(245, 158, 11, 0.15); }
        .lm-confirm .lm-danger { font-weight: 600; color: rgb(220, 38, 38); }
    </style>
@endonce
