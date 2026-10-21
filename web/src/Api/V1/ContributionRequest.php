<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1;

use App\Catalog\Entity\Submission;
use App\Catalog\FieldKind;
use App\Catalog\ItemType;
use App\Contribution\PlaceText;

/**
 * A `POST /v1/contributions` body, checked and turned into the payload the
 * site's own form sends (docs/specs/public-api.md §8), so the intake behind
 * it cannot tell the two apart except by who sent it.
 *
 * ```json
 * {"letter": "B", "name": "Fountain on the square",
 *  "geometry": {"type": "Point", "coordinates": [5.86, 50.49]},
 *  "fields": {"potable": "Yes (public supply)"},
 *  "author": {"ref": "u-8f3a", "name": "Sam"},
 *  "licence": "ODbL-1.0"}
 * ```
 *
 * An edit names `item_id` and sends only what changes; `null` clears a
 * field. A new place taken from an OpenStreetMap object names `osm_ref`.
 *
 * @api
 */
final readonly class ContributionRequest
{
    /** The licence the author agrees their contribution is published under. */
    public const string LICENCE = 'ODbL-1.0';

    private const array KEYS = ['letter', 'item_id', 'osm_ref', 'name', 'geometry', 'fields', 'author', 'licence', 'lang'];

    /**
     * @param 'add'|'improve'      $kind
     * @param array<string, mixed> $payload the form payload for CatalogContributionService::submitFromApp()
     */
    private function __construct(
        public string $kind,
        public ?int $itemId,
        public string $letter,
        public string $authorRef,
        public ?string $authorName,
        public array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $body          the decoded JSON body
     * @param string|null          $editingLetter the letter of the item `item_id` names, null when it names no served item
     *
     * @return self|array{error: string, message: string}
     */
    public static function parse(array $body, PublicFields $fields, ?string $editingLetter): self|array
    {
        $unknown = array_diff(array_keys($body), self::KEYS);
        if ([] !== $unknown) {
            return self::bad('invalid_body', \sprintf('unknown member %s; the members are %s', implode(', ', $unknown), implode(', ', self::KEYS)));
        }
        if (self::LICENCE !== ($body['licence'] ?? null)) {
            return self::bad('licence_required', 'licence must be "ODbL-1.0": the author agrees the contribution is published under the Open Database License');
        }

        $author = \is_array($body['author'] ?? null) ? $body['author'] : [];
        $ref = $author['ref'] ?? null;
        if (!\is_string($ref) || 1 !== preg_match('~^[A-Za-z0-9._:-]{1,64}$~', $ref)) {
            return self::bad('invalid_author', 'author.ref must be your stable, opaque reference for the author: 1 to 64 of A-Z a-z 0-9 . _ : -');
        }
        $name = $author['name'] ?? null;
        if (null !== $name) {
            $name = \is_string($name) ? self::clean($name) : '';
            if ('' === $name || mb_strlen($name) > 80) {
                return self::bad('invalid_author', 'author.name is the name the author chose to share: 1 to 80 characters, or absent');
            }
        }

        $lang = $body['lang'] ?? Submission::DEFAULT_LANG;
        if (!\is_string($lang) || !\in_array($lang, PlaceText::LANGS, true)) {
            return self::bad('invalid_lang', 'lang must be the language the contribution is written in, one of '.implode(', ', PlaceText::LANGS).', or absent for en');
        }

        $itemId = $body['item_id'] ?? null;
        if (null !== $itemId && (!\is_int($itemId) || $itemId < 1)) {
            return self::bad('invalid_item', 'item_id must be the id of a served item');
        }
        $editing = null !== $itemId;
        if ($editing && null === $editingLetter) {
            return self::bad('invalid_item', \sprintf('item %d is not a served item', $itemId));
        }

        $letter = $body['letter'] ?? $editingLetter;
        if (!\is_string($letter) || !\in_array($letter, PublicCatalog::CONTRIBUTABLE, true)) {
            return self::bad('invalid_letter', 'letter must be one of '.implode(', ', PublicCatalog::CONTRIBUTABLE).' (see /v1/catalog, `contributions`)');
        }
        if ($editing && $letter !== $editingLetter) {
            return self::bad('invalid_letter', \sprintf('item %d is letter %s, not %s', $itemId, $editingLetter, $letter));
        }
        $type = ItemType::fromLetter($letter);
        if (null === $type) {
            return self::bad('invalid_letter', 'unknown letter');
        }

        $osmRef = $body['osm_ref'] ?? null;
        if (null !== $osmRef && ($editing || !\is_string($osmRef) || 1 !== preg_match('~^(node|way)/\d{1,16}$~', $osmRef))) {
            return self::bad('invalid_osm_ref', $editing ? 'osm_ref names the object a new place is taken from; an edit names item_id' : 'osm_ref must be node/<id> or way/<id>');
        }

        $point = null;
        if (\array_key_exists('geometry', $body)) {
            $point = self::point($body['geometry']);
            if (null === $point) {
                return self::bad('invalid_geometry', 'geometry must be a GeoJSON Point: {"type": "Point", "coordinates": [lon, lat]}');
            }
        } elseif (!$editing) {
            return self::bad('invalid_geometry', 'a new place needs its geometry, a GeoJSON Point');
        }

        $details = self::fields($body['fields'] ?? [], $fields->acceptedFields($type), !$editing);
        if (\is_string($details)) {
            return self::bad('invalid_field', $details);
        }

        $title = $body['name'] ?? null;
        if (null !== $title) {
            $title = \is_string($title) ? self::clean($title) : '';
            if ('' === $title || mb_strlen($title) > 200) {
                return self::bad('invalid_name', 'name must be 1 to 200 characters');
            }
            $details['name'] = $title;
        } elseif (!$editing) {
            return self::bad('invalid_name', 'a new place needs a name');
        }

        $payload = ['details' => $details];
        if (null !== $point) {
            [$payload['lng'], $payload['lat']] = $point;
        }
        if ($editing) {
            $payload['_item_id'] = $itemId;
        } else {
            $payload['type'] = $type->value;
            if (null !== $osmRef) {
                $payload['_osm_ref'] = $osmRef;
            }
        }
        $payload['_consent'] = ['licence' => self::LICENCE];
        // The language the fields are written in; English when the app names none.
        $payload['_lang'] = $lang;

        return new self($editing ? 'improve' : 'add', $editing ? $itemId : null, $letter, $ref, $name, $payload);
    }

    /**
     * The fields, each checked against the letter's form: a pick list takes
     * one of its values, a multi-pick a list of them, a link an http(s)
     * address, text no more than the form allows. `null` clears a field on
     * an edit and means nothing on a new place.
     *
     * @param array<string, \App\Catalog\CatalogField> $accepted
     *
     * @return array<string, mixed>|string the details, or why not
     */
    private static function fields(mixed $raw, array $accepted, bool $new): array|string
    {
        if (!\is_array($raw) || (array_is_list($raw) && [] !== $raw)) {
            return 'fields must be an object of field => value (see /v1/catalog)';
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $field = $accepted[$key] ?? null;
            if (null === $field) {
                return \sprintf('%s is not a field this letter accepts (see /v1/catalog, `accepted`)', $key);
            }
            if (null === $value) {
                if (!$new) {
                    $out[$key] = '';
                }
                continue;
            }
            $ok = match ($field->kind) {
                FieldKind::Select => \is_string($value) && \in_array($value, $field->choices, true),
                FieldKind::MultiSelect => \is_array($value) && array_is_list($value) && \count($value) === \count(array_filter($value, is_string(...))) && [] === array_diff($value, $field->choices),
                FieldKind::Url => \is_string($value) && 1 === preg_match('~^https?://\S+$~i', $value) && \strlen($value) <= 500,
                default => \is_string($value) && mb_strlen(self::clean($value, FieldKind::Textarea === $field->kind)) <= $field->maxLength,
            };
            if (!$ok) {
                return match ($field->kind) {
                    FieldKind::Select => \sprintf('%s must be one of: %s', $key, implode(' | ', $field->choices)),
                    FieldKind::MultiSelect => \sprintf('%s must be a list of: %s', $key, implode(' | ', $field->choices)),
                    FieldKind::Url => \sprintf('%s must be an http(s) address of at most 500 characters', $key),
                    default => \sprintf('%s must be text of at most %d characters', $key, $field->maxLength),
                };
            }
            $out[$key] = \in_array($field->kind, [FieldKind::Text, FieldKind::Textarea], true) ? self::clean((string) $value, FieldKind::Textarea === $field->kind) : $value;
        }

        return $out;
    }

    /** @return array{0: float, 1: float}|null [lon, lat] */
    private static function point(mixed $geometry): ?array
    {
        if (!\is_array($geometry) || 'Point' !== ($geometry['type'] ?? null) || !\is_array($geometry['coordinates'] ?? null)) {
            return null;
        }
        $c = $geometry['coordinates'];
        if (2 !== \count($c) || !array_is_list($c) || !(\is_int($c[0]) || \is_float($c[0])) || !(\is_int($c[1]) || \is_float($c[1]))) {
            return null;
        }
        [$lon, $lat] = [(float) $c[0], (float) $c[1]];

        return abs($lon) <= 180.0 && abs($lat) <= 90.0 ? [$lon, $lat] : null;
    }

    /**
     * Text a stranger wrote: no control or invisible formatting characters,
     * trimmed. A multi-line field keeps its line breaks.
     */
    private static function clean(string $text, bool $multiline = false): string
    {
        $pattern = $multiline ? '/[\x{0}-\x{9}\x{B}-\x{1F}\x{7F}\p{Cf}]+/u' : '/[\p{Cc}\p{Cf}]+/u';

        return trim((string) preg_replace($pattern, ' ', $text));
    }

    /** @return array{error: string, message: string} */
    private static function bad(string $error, string $message): array
    {
        return ['error' => $error, 'message' => $message];
    }
}
