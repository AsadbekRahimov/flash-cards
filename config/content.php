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
];
