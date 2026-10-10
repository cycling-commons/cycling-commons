<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * What a request may carry into an error report (owner 2026-10-10,
 * docs/specs/privacy-notice.md).
 *
 * The body travels, up to the SDK's 10 KB (`max_request_body_size: medium`),
 * so a failure can be replayed locally and an abuse attempt can be read. Two
 * rules hold it in:
 *
 * - every field whose name says it is a secret (a password, a token, a code,
 *   a key) is masked, at any depth, in the body and in the query string;
 * - the forms that carry really personal content send no body and no query
 *   at all: sign-in, registration, password reset, email verification, the
 *   account settings, the contact form, messages, the curator room, curator
 *   applications, the rider's home area and Scout's tags and traffic.
 *
 * A request with no route (one that never reached routing) sends neither.
 * The password reset link also loses the token in its path. No header that
 * can name the visitor's address travels: the SDK strips X-Forwarded-For and
 * X-Real-IP, this drops the other proxy headers, so an error report holds no
 * IP address (`privacy.collect_auto_errors`).
 *
 * @api
 */
final class SentryRequestScrubber
{
    public const string FILTERED = '[Filtered]';

    /** Base route names (no `.en` suffix) whose requests send no body and no query. */
    public const array PERSONAL_ROUTES = [
        'login', '2fa_login', '2fa_login_check', '2fa_setup',
        'register', 'reset_password_request', 'reset_password', 'verify_email', 'verify_resend',
        'settings', 'settings_delete_request', 'settings_delete_confirm',
        'contact_submit', 'messages_reply', 'moderate_message', 'moderate_room_post', 'moderate_room_edit_save',
        'join_country', 'map_my_area_set', 'scout_tag_submit', 'scout_traffic_submit',
    ];

    /** A request header that can carry the visitor's address. */
    private const string ADDRESS_HEADER = '/^(forwarded|x-forwarded-.+|x-real-ip|cf-connecting-ip|true-client-ip|x-client-ip|x-cluster-client-ip|client-ip)$/i';

    /** A field name that says it holds a secret. */
    private const string SECRET = '/password|passwd|^pass$|secret|token|csrf|auth_?code|^code$|totp|(^|_)otp($|_)|backup_?codes?|api_?key|signature/i';

    /**
     * @param array<string, mixed> $request the Sentry event's request part
     *
     * @return array<string, mixed>
     */
    public static function scrub(array $request, ?string $route): array
    {
        if (isset($request['headers']) && \is_array($request['headers'])) {
            foreach (array_keys($request['headers']) as $name) {
                if (\is_string($name) && 1 === preg_match(self::ADDRESS_HEADER, $name)) {
                    unset($request['headers'][$name]);
                }
            }
        }

        $base = null === $route ? null : (string) preg_replace('/\.(en|fr|nl|de|es)$/', '', $route);

        if (null === $base || \in_array($base, self::PERSONAL_ROUTES, true)) {
            unset($request['data'], $request['query_string']);
            if ('reset_password' === $base && isset($request['url']) && \is_string($request['url'])) {
                $request['url'] = (string) preg_replace('#(/reset-password/reset/)[^/?]+#', '$1'.self::FILTERED, $request['url']);
            }

            return $request;
        }

        if (isset($request['data'])) {
            $data = $request['data'];
            if (\is_string($data)) {
                $decoded = json_decode($data, true);
                $data = \is_array($decoded) ? $decoded : $data;
            }
            $request['data'] = \is_array($data) ? self::mask($data) : $data;
        }
        if (isset($request['query_string']) && \is_string($request['query_string']) && '' !== $request['query_string']) {
            parse_str($request['query_string'], $query);
            $request['query_string'] = http_build_query(self::mask($query));
        }

        return $request;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($key) && 1 === preg_match(self::SECRET, $key)) {
                $values[$key] = self::FILTERED;
            } elseif (\is_array($value)) {
                $values[$key] = self::mask($value);
            }
        }

        return $values;
    }
}
