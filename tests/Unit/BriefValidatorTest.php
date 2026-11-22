<?php

namespace Tests\Unit;

use App\StoryLens\Brief\BriefEvidence;
use App\StoryLens\Brief\BriefFailure;
use App\StoryLens\Brief\BriefValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BriefValidatorTest extends TestCase
{
    public function test_validates_vietnamese_and_normalizes_whitespace_and_citations(): void
    {
        $points = (new BriefValidator)->validate(json_encode(['points' => [
            ['text' => "   Các nguồn ghi nhận sự kiện vào sáng nay. \n", 'evidence_ids' => ['a', 'a', 'b']],
            ['text' => 'Lịch trình tiếp theo được công bố trong ngày.', 'evidence_ids' => ['b']],
        ]]), ['a', 'b']);

        $this->assertSame([
            ['text' => 'Các nguồn ghi nhận sự kiện vào sáng nay.', 'evidence_ids' => ['a', 'b']],
            ['text' => 'Lịch trình tiếp theo được công bố trong ngày.', 'evidence_ids' => ['b']],
        ], $points);
    }

    #[DataProvider('plainTextSamples')]
    public function test_keeps_vietnamese_abbreviations_and_decimals_in_plain_text(string $sample): void
    {
        $text = 'Bản tin địa phương ghi lại '.$sample.' trong nội dung sự kiện.';
        $result = (new BriefValidator)->validate(json_encode(['points' => [
            ['text' => $text, 'evidence_ids' => ['a']],
            ['text' => 'Lịch trình tiếp theo được công bố trong ngày.', 'evidence_ids' => ['a']],
        ]]), ['a']);
        $this->assertSame($text, $result[0]['text']);
    }

    public static function plainTextSamples(): iterable
    {
        foreach (['TP.HCM', 'Tp.HCM', 'P.Bến Nghé', 'Q.1', 'TT.Huế', 'GS.TS', 'PGS.TS', 'ThS.BS', 'v.v.', 'U.23',
            '3.5%', '1.000 tỷ', '2.5 triệu', '3.8', 'TP.HCM/Hà Nội', 'TP.HCM/ngày', 'ACB.HN/HCM', 'Tp.HCM/Q.1',
            '2.150.250.100 đồng', '2.150.250.100 VNĐ'] as $text) {
            yield $text => [$text];
        }
    }

    #[DataProvider('invalidOutput')]
    public function test_rejects_the_entire_output_with_a_safe_reason(string $json, string $reason): void
    {
        try {
            (new BriefValidator)->validate($json, ['a', 'b']);
            $this->fail('Invalid output passed validation.');
        } catch (BriefFailure $e) {
            $this->assertSame('invalid_output', $e->getMessage());
            $this->assertSame($reason, $e->reason);
            $this->assertNull($e->getPrevious());
        }
    }

    public static function invalidOutput(): iterable
    {
        $valid = ['text' => 'Các nguồn ghi nhận sự kiện vào sáng nay.', 'evidence_ids' => ['a']];
        $other = ['text' => 'Lịch trình tiếp theo được công bố trong ngày.', 'evidence_ids' => ['b']];
        $encode = fn (array $points) => json_encode(['points' => $points]);
        yield 'broken JSON' => ['{private response', 'json'];
        yield 'code fence' => ['```json '.$encode([$valid, $other]).' ```', 'json'];
        yield 'oversize payload' => [str_repeat(' ', 32769), 'output_size'];
        yield 'top-level list' => [json_encode([$valid, $other]), 'points_shape'];
        yield 'points object' => ['{"points":{"0":{},"1":{}}}', 'points_shape'];
        yield 'too few' => [$encode([$valid]), 'points_shape'];
        yield 'too many' => [$encode(array_fill(0, 6, $valid)), 'points_shape'];
        yield 'wrong point type' => [$encode([$valid, 'unexpected']), 'point_shape'];
        yield 'additional point property' => [$encode([$valid, $other + ['extra' => true]]), 'point_shape'];
        yield 'absent citation' => [$encode([$valid, ['text' => $other['text'], 'evidence_ids' => []]]), 'evidence_required'];
        yield 'citation object' => [$encode([$valid, array_replace($other, ['evidence_ids' => (object) ['0' => 'b']])]), 'evidence_required'];
        yield 'unknown ID among known ones' => [$encode([$valid, array_replace($other, ['evidence_ids' => ['a', 'unseen']])]), 'unknown_evidence'];
        yield 'nonstring ID' => [$encode([$valid, array_replace($other, ['evidence_ids' => [1]])]), 'unknown_evidence'];
        yield 'short text' => [$encode([$valid, array_replace($other, ['text' => '   quá ngắn    '])]), 'text_length'];
        yield 'long text' => [$encode([$valid, array_replace($other, ['text' => str_repeat('đ', 401)])]), 'text_length'];
        yield 'duplicate' => [$encode([$valid, array_replace($other, ['text' => '  '.mb_strtoupper($valid['text']).'  '])]), 'duplicate_point'];
        foreach (['https://example.invalid', 'HTTP://Example.invalid', 'ftp://example.invalid', 'mailto:news@example.invalid',
            '//example.invalid', 'example.com/news', 'WWW.example.invalid', 'vnexpress.net', 'tin.gov.vn', 'bao.edu.vn',
            'nguon.com.vn', 'site.io', 'site.info', 'site.co', 'site.me', 'site.app', 'site.dev', 'site.news',
            'bit.ly/abc', 'bit.ly/x', 'example.com/x', 'Example.COM/x', 'news.internal/story', 'news.internal:8443/story',
            '192.168.1.1/admin', '10.0.0.1:8080', '192.168.1.1', '10.0.0.1:8080/admin',
            '192.168.1.1/admin ngay trong ngày', '10.0.0.1:8080 đồng thời', 'https://192.168.1.1 đồng thời',
            'VnExpress.net', 'EXAMPLE.COM', 'x.ai', 'example.xyz', 'google.co.uk', 'example.org.uk', 'example.com.au',
            '<b>nguồn</b>', '&lt;b&gt;nguồn&lt;/b&gt;', '[bài báo](#article)', '[bài báo][tham chiếu]'] as $markup) {
            yield $markup => [$encode([$valid, array_replace($other, ['text' => 'Nội dung được đề cập tại '.$markup])]), 'text_markup_or_url'];
        }
        foreach (['**', '__', '# ', '- ', '* '] as $markdown) {
            yield 'markdown '.$markdown => [$encode([$valid, array_replace($other, ['text' => '  '.$markdown.$other['text']])]), 'text_markup_or_url'];
        }
        yield 'inline emphasis' => [$encode([$valid, array_replace($other, ['text' => 'Nội dung **đã được** xác nhận sáng nay.'])]), 'text_markup_or_url'];
        foreach (["\x00", "\x7f", "\n", "\r", "\t", "\u{2028}", "\u{2029}", "\u{200E}", "\u{200F}", "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
            "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}", '&#x202E;'] as $character) {
            yield 'control '.bin2hex($character) => [$encode([$valid, array_replace($other, ['text' => 'Bản tin'.$character.' ghi nhận sự kiện sáng nay.'])]), 'text_control_chars'];
        }
        yield 'total length' => [$encode(array_map(fn ($i) => ['text' => str_repeat('đ', 399).$i, 'evidence_ids' => ['a']], range(1, 5))), 'total_length'];
    }

    public function test_extractive_selection_is_stable_deduplicated_and_handles_one_or_zero_articles(): void
    {
        $base = ['source' => ['name' => 'Nguồn'], 'published_at' => '2026-11-22T10:00:00+07:00'];
        $first = ['id' => 'b', 'title' => 'Tiêu đề', 'excerpt' => '<p>Tình hình đã được cập nhật.</p> Lịch trình sẽ công bố sau.'] + $base;
        $older = ['id' => 'c', 'title' => 'Tin cũ', 'excerpt' => 'Tình hình đã được cập nhật.', 'published_at' => '2026-11-22T02:00:00Z'] + $base;
        $latest = ['id' => 'a', 'title' => 'Tin mới', 'excerpt' => 'Các nguồn đã xác nhận thời điểm.', 'published_at' => '2026-11-22T04:00:00Z'] + $base;
        $result = BriefEvidence::extract([$first, $older, $latest]);
        $this->assertSame($result, BriefEvidence::extract([$latest, $older, $first]));
        $this->assertSame([['a'], ['b']], array_column($result, 'evidence_ids'));
        $this->assertSame(['Các nguồn đã xác nhận thời điểm.', 'Tình hình đã được cập nhật.'], array_column($result, 'text'));
        $this->assertSame([['b'], ['b']], array_column(BriefEvidence::extract([$first]), 'evidence_ids'));
        $this->assertSame(['Tình hình đã được cập nhật.', 'Tiêu đề'],
            array_column(BriefEvidence::extract([array_replace($first, ['excerpt' => 'Tình hình đã được cập nhật.'])]), 'text'));
        $this->assertSame([], BriefEvidence::extract([]));
    }
}
