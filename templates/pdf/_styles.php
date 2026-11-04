<style>
    @page { margin: 22mm 16mm 20mm 16mm; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2430; line-height: 1.45; }
    h1, h2, h3 { color: #11151c; margin: 0; }
    h2 { font-size: 14pt; margin: 0 0 10px; padding-bottom: 5px; border-bottom: 2px solid #1a7f5a; }
    h3 { font-size: 10.5pt; margin: 14px 0 6px; }
    p { margin: 0 0 8px; }
    a { color: #1a7f5a; text-decoration: none; }
    .section { margin-bottom: 22px; }
    .page-break { page-break-before: always; }
    .muted { color: #6b7280; }
    .small { font-size: 7.5pt; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }

    /* Couverture */
    .cover { padding-top: 70mm; }
    .cover .eyebrow { font-size: 9pt; letter-spacing: 2px; text-transform: uppercase; color: #1a7f5a; margin-bottom: 10px; }
    .cover h1 { font-size: 26pt; line-height: 1.15; margin-bottom: 14px; }
    .cover .project { font-size: 15pt; color: #374151; margin-bottom: 40px; }
    .cover table.meta td { padding: 4px 18px 4px 0; font-size: 10pt; }
    .cover table.meta td.label { color: #6b7280; }

    /* Indicateurs clés */
    table.kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 14px; }
    table.kpis td { width: 25%; padding: 10px 12px; border: 1px solid #e5e7eb; border-top-width: 4px; vertical-align: top; }
    table.kpis .kpi-label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; }
    table.kpis .kpi-value { font-size: 16pt; font-weight: bold; margin-top: 4px; }
    table.kpis .kpi-hint { font-size: 7.5pt; color: #6b7280; }

    /* Tons */
    .tone-ok { border-top-color: #1a7f5a !important; }
    .tone-info { border-top-color: #2563eb !important; }
    .tone-warn { border-top-color: #d97706 !important; }
    .tone-bad { border-top-color: #c62828 !important; }
    .tone-neutral { border-top-color: #9ca3af !important; }
    .text-ok { color: #1a7f5a; }
    .text-warn { color: #b45309; }
    .text-bad { color: #c62828; }

    /* Tableaux de données */
    table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.data th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 5px 6px; }
    table.data td { border-bottom: 1px solid #eef0f3; padding: 5px 6px; vertical-align: top; }
    table.data tr.total td { border-top: 1px solid #9ca3af; border-bottom: none; font-weight: bold; }
    .bar { height: 6px; background: #e5e7eb; }
    .bar span { display: block; height: 6px; background: #1a7f5a; }

    /* Badges */
    .badge { display: inline-block; padding: 1px 6px; font-size: 7pt; font-weight: bold; color: #fff; border-radius: 2px; }
    .badge-breaking { background: #c62828; }
    .badge-warning { background: #d97706; }
    .badge-suggestion { background: #6b7280; }

    /* Fiches détaillées */
    .issue { border-left: 3px solid #c62828; padding: 6px 0 6px 10px; margin-bottom: 10px; page-break-inside: avoid; }
    .issue .title { font-weight: bold; margin-bottom: 3px; }
    .issue .meta { font-size: 7.5pt; color: #6b7280; margin-bottom: 4px; }
    .issue .fix { background: #f3f8f5; padding: 5px 7px; margin-top: 4px; }
    .callout { background: #f6f7f9; border-left: 3px solid #9ca3af; padding: 8px 10px; margin-bottom: 10px; }
    ul.plain { margin: 0; padding-left: 14px; }
    ul.plain li { margin-bottom: 3px; }

    /* Encart d'accompagnement en fin de document */
    .service-offer { page-break-inside: avoid; border-top: 2px solid #1a7f5a; padding-top: 10px; margin-top: 28px; }
    .service-offer h2 { border-bottom: none; padding-bottom: 0; }
</style>
