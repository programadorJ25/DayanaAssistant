<?php

return [
    'name' => env('ASSISTANT_NAME', 'Dayana'),

    'version' => env('ASSISTANT_VERSION', '0.1.0'),

    'timezone' => env(
        'ASSISTANT_TIMEZONE',
        'America/Mexico_City'
    ),

    'morning_summary_enabled' => env(
        'ASSISTANT_MORNING_SUMMARY_ENABLED',
        true
    ),

    'evening_summary_enabled' => env(
        'ASSISTANT_EVENING_SUMMARY_ENABLED',
        true
    ),

    'reminders_enabled' => env(
        'ASSISTANT_REMINDERS_ENABLED',
        true
    ),

    'ai' => [
        'enabled' => env('AI_ENABLED', true),

        'monthly_budget_usd' => (float) env(
            'AI_MONTHLY_BUDGET_USD',
            5.00
        ),

        'daily_budget_usd' => (float) env(
            'AI_DAILY_BUDGET_USD',
            0.50
        ),

        'max_output_tokens' => (int) env(
            'AI_MAX_OUTPUT_TOKENS',
            2000
        ),

        'saving_output_tokens' => (int) env(
            'AI_SAVING_OUTPUT_TOKENS',
            800
        ),

        'budget_timezone' => env(
            'AI_BUDGET_TIMEZONE',
            'America/Mexico_City'
        ),

        'alerts_enabled' => env(
            'AI_BUDGET_ALERTS_ENABLED',
            true
        ),

        // USD por cada millón de tokens.
        'pricing' => [
            'gpt-5-mini' => [
                'input' => 0.25,
                'cached_input' => 0.025,
                'output' => 2.00,
            ],

            'gpt-5-mini-2025-08-07' => [
                'input' => 0.25,
                'cached_input' => 0.025,
                'output' => 2.00,
            ],
        ],
    ],

];
