<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content defaults
|--------------------------------------------------------------------------
|
| Reference: docs/12_PHASE1_DESIGN.md §1.7.
*/

return [
    /**
     * Slug of the platform course that receives demo content and imports
     * without an explicit course; also the fallback course of a group
     * whose `course_id` is NULL.
     */
    'default_course_slug' => env('CONTENT_DEFAULT_COURSE_SLUG', 'default'),

    /**
     * Share of a course's active words that must have a translation in EVERY
     * supported locale (config/localization.php) before the course can be
     * published (CoursePublisher, docs/12 §3.2). Applies to new publications
     * only: courses published earlier stay as they are, and the missing
     * locale is shown as the ru fallback.
     */
    'publish_translation_threshold' => (float) env('CONTENT_PUBLISH_TRANSLATION_THRESHOLD', 0.95),
];
