<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Blog;

/**
 * The one image a post opens with, by name: a drawing (an SVG in
 * templates/blog/illustrations/, coloured with the site's own tokens) or an
 * image file in assets/images/blog/ (a screenshot of a real route, say). Both
 * live in the repository: nothing is uploaded, so no image is decoded on a web
 * host and every one is reviewed like code.
 *
 * A drawing is decoration and is hidden from screen readers. An image file
 * carries information, so it has alt text, `blog.image_alt.<name>`, in every
 * language.
 *
 * @see docs/specs/blog.md §2
 *
 * @api
 */
final class BlogIllustrations
{
    /** drawing name => what it shows, for the admin choice */
    public const array NAMES = [
        'online' => 'A road through hills, with pins',
        'vote' => 'A podium with three stars',
        'plan' => 'A route from A to B over its climb',
        'stops' => 'Water, food and a bed along a road',
        'contribute' => 'A map pin with a plus',
        'region' => 'A region outline with its hills',
        'build' => 'A map sheet with a wrench and a pencil',
    ];

    /**
     * image name => [asset path under assets/, width, height, what it shows].
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: string}>
     */
    public const array IMAGES = [];

    /**
     * The image files, typed: the list is empty until a post needs one.
     *
     * @return array<string, array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function images(): array
    {
        return self::IMAGES;
    }

    public static function known(?string $name): bool
    {
        return null !== $name && (\array_key_exists($name, self::NAMES) || \array_key_exists($name, self::images()));
    }

    /** @return array<string, string> label => name, drawings and image files together */
    public static function choices(): array
    {
        $out = [];
        foreach (self::NAMES as $name => $label) {
            $out['Drawing: '.$label] = $name;
        }
        foreach (self::images() as $name => $image) {
            $out['Image: '.$image[3]] = $name;
        }

        return $out;
    }
}
