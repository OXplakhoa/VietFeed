<?php

namespace App\StoryLens\Brief;

use JsonException;
use Normalizer;
use stdClass;

final class BriefValidator
{
    private const MAX_OUTPUT_BYTES = 32768;

    private const HOST_LABELS = '(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+';

    private const HOST_START = '(?<![\pL\pN_.-])';

    private const HOST_END = '(?![\pL\pN-]|\.[\pL\pN-])';

    private const DOMAIN_SUFFIX = '(?:(?:ac|co|com|edu|gov|net|org)\.[a-z]{2}'
        .'|aero|app|biz|blog|cloud|com|dev|edu|gov|info|int|live|mil|mobi|museum|name|net|news|online|org|pro|shop|site|store|tech|top|world|xyz'
        .'|ai|ar|at|au|be|br|ca|ch|cn|co|cz|de|dk|es|eu|fi|fr|gr|hk|id|ie|il|in|io|it|jp|kr|ly|me|mx|my|nl|no|nz|ph|pl|pt|ru|se|sg|th|tr|tv|tw|ua|uk|us|vn|za)';

    // An unfamiliar slash host needs a lowercase letter in its final label to distinguish abbreviations such as TP.HCM/Hà Nội.
    private const DOMAIN = '~'.self::HOST_START.self::HOST_LABELS
        .'(?:(?=(?-i:[a-zA-Z]*[a-z]))[a-z]{2,63}(?=(?::[0-9]{1,5})?/\S)|'.self::DOMAIN_SUFFIX.self::HOST_END.')~iu';

    private const IPV4_OCTET = '(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])';

    private const IPV4 = '~'.self::HOST_START.'(?:'.self::IPV4_OCTET.'\.){3}'.self::IPV4_OCTET.self::HOST_END
        .'(?!\h*(?:đồng|đ|vnđ|vnd|usd|tỷ|triệu)(?![\pL\pN_]))~iu';

    /** @throws BriefFailure */
    public function validate(string $json, array $sentIds): array
    {
        if (strlen($json) > self::MAX_OUTPUT_BYTES) {
            throw new BriefFailure('invalid_output', 'output_size');
        }
        try {
            $document = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BriefFailure('invalid_output', 'json');
        }
        if (! $document instanceof stdClass || array_keys(get_object_vars($document)) !== ['points']
            || ! is_array($document->points) || count($document->points) < 2 || count($document->points) > 5) {
            throw new BriefFailure('invalid_output', 'points_shape');
        }

        $points = [];
        $total = 0;
        foreach ($document->points as $point) {
            if (! $point instanceof stdClass || count(get_object_vars($point)) !== 2 || ! is_string($point->text ?? null)) {
                throw new BriefFailure('invalid_output', 'point_shape');
            }
            $text = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $point->text);
            $length = mb_strlen($text);
            if ($length < 20 || $length > 400) {
                throw new BriefFailure('invalid_output', 'text_length');
            }
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $decoded)) {
                throw new BriefFailure('invalid_output', 'text_control_chars');
            }
            if (preg_match('~[<>]|\]\s*[\[(]|[a-z][a-z0-9+.-]*://|(?:https?|ftps?|mailto|tel|javascript|data)\s*:|//\S|www\.|\*\*|__|^(?:#|[-*]\h)~iu', $decoded)
                || preg_match(self::DOMAIN, $decoded) || preg_match(self::IPV4, $decoded)) {
                throw new BriefFailure('invalid_output', 'text_markup_or_url');
            }
            if (! is_array($point->evidence_ids ?? null) || $point->evidence_ids === []) {
                throw new BriefFailure('invalid_output', 'evidence_required');
            }
            foreach ($point->evidence_ids as $id) {
                if (! is_string($id) || ! in_array($id, $sentIds, true)) {
                    throw new BriefFailure('invalid_output', 'unknown_evidence');
                }
            }
            $key = self::pointKey($text);
            if (isset($points[$key])) {
                throw new BriefFailure('invalid_output', 'duplicate_point');
            }
            $points[$key] = ['text' => $text, 'evidence_ids' => array_values(array_unique($point->evidence_ids))];
            $total += $length;
        }
        if ($total > 1600) {
            throw new BriefFailure('invalid_output', 'total_length');
        }

        return array_values($points);
    }

    public static function pointKey(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text) ?: $text;
        }

        return mb_strtolower(trim(preg_replace('/[\p{Z}\s]+/u', ' ', $text) ?? ''));
    }
}
