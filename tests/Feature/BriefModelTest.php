<?php

namespace Tests\Feature;

use App\StoryLens\Brief\BriefEvidence;
use App\StoryLens\Brief\BriefFailure;
use App\StoryLens\Brief\BriefModel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class BriefModelTest extends TestCase
{
    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/flash-test:generateContent';

    private const OPENAI_URL = 'https://api.example.test/v1/chat/completions';

    private const KEY = 'fixture-api-key-private';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai.gemini.key' => self::KEY, 'ai.gemini.model' => 'flash-test',
            'ai.openai.key' => self::KEY, 'ai.openai.model' => 'compat-test', 'ai.openai.base_url' => 'https://api.example.test/v1/',
        ]);
    }

    public function test_gemini_uses_json_schema_and_passes_its_key_only_in_the_header(): void
    {
        config(['ai.provider' => 'gemini', 'ai.gemini.thinking_level' => '']);
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
            ['thought' => true, 'text' => 'internal thinking'], ['text' => '{"points":'], ['text' => '[]}'],
        ]]]]])]);
        $result = app(BriefModel::class)->generate('<b>Tiêu đề Story</b>', $this->evidence());
        $this->assertSame('{"points":[]}', $result);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $this->assertSame(self::GEMINI_URL, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('x-goog-api-key', self::KEY));
            $this->assertStringNotContainsString(self::KEY, $request->body());
            $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
            $this->assertSame(['points'], $body['generationConfig']['responseJsonSchema']['required']);
            $this->assertArrayNotHasKey('thinkingConfig', $body['generationConfig']);
            $this->assertSame(16384, $body['generationConfig']['maxOutputTokens']);
            $this->assertStringContainsString('tiếng Việt', $body['systemInstruction']['parts'][0]['text']);
            $this->assertStringContainsString('Mỗi trường text chỉ có một dòng, không xuống dòng.', $body['systemInstruction']['parts'][0]['text']);
            $this->assertStringContainsString('không dùng URL, HTML, Markdown', $body['systemInstruction']['parts'][0]['text']);
            $this->assertPrompt($body['contents'][0]['parts'][0]['text']);

            return true;
        });
    }

    #[DataProvider('thinkingLevels')]
    public function test_gemini_sends_the_configured_rest_thinking_level(string $level): void
    {
        config(['ai.provider' => 'gemini', 'ai.gemini.thinking_level' => $level]);
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{}']]]]],
        ])]);
        $this->assertSame('{}', app(BriefModel::class)->generate('Tiêu đề Story', $this->evidence()));
        Http::assertSent(fn (Request $request) => $request->data()['generationConfig']['thinkingConfig'] === ['thinkingLevel' => strtoupper($level)]);
    }

    public static function thinkingLevels(): iterable
    {
        foreach (['minimal', 'low', 'medium', 'high'] as $level) {
            yield $level => [$level];
        }
    }

    public function test_openai_uses_json_mode_and_bearer_authentication(): void
    {
        config(['ai.provider' => 'openai']);
        Http::fake([self::OPENAI_URL => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{}']]]])]);
        $this->assertSame('{}', app(BriefModel::class)->generate('<b>Tiêu đề Story</b>', $this->evidence()));
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $this->assertSame(self::OPENAI_URL, $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::KEY));
            $this->assertStringNotContainsString(self::KEY, $request->body());
            $this->assertSame('compat-test', $body['model']);
            $this->assertSame(['type' => 'json_object'], $body['response_format']);
            $this->assertSame('system', $body['messages'][0]['role']);
            $this->assertPrompt($body['messages'][1]['content']);

            return true;
        });
    }

    #[DataProvider('failures')]
    public function test_http_failure_is_classified_once_without_exposing_provider_details(string $provider, int $status, string $category): void
    {
        config(['ai.provider' => $provider]);
        $calls = 0;
        Http::fake(function () use (&$calls, $status) {
            $calls++;

            return match ($status) {
                0 => Http::failedConnection('cURL error 28: Operation timed out '.self::KEY),
                -1 => Http::failedConnection('Could not resolve host '.self::KEY),
                -2 => throw new \RuntimeException(self::KEY),
                default => Http::response(['error' => self::KEY], $status),
            };
        });
        try {
            app(BriefModel::class)->generate('Tiêu đề Story', $this->evidence());
            $this->fail('Provider failure did not surface.');
        } catch (BriefFailure $error) {
            $this->assertSame($category, $error->category);
            $this->assertSame($category, $error->getMessage());
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString(self::KEY, (string) $error);
        }
        $this->assertSame(1, $calls);
    }

    public static function failures(): iterable
    {
        foreach (['gemini', 'openai'] as $provider) {
            foreach ([0 => 'timeout', 429 => 'http_429', 402 => 'http_402', 503 => 'http_5xx', 401 => 'http_4xx'] as $status => $category) {
                yield $provider.' '.$category => [$provider, $status, $category];
            }
            yield $provider.' DNS' => [$provider, -1, 'network'];
            yield $provider.' transport' => [$provider, -2, 'network'];
        }
    }

    #[DataProvider('openAiEndpoints')]
    public function test_openai_requires_https_except_for_loopback(string $baseUrl, bool $allowed): void
    {
        config(['ai.provider' => 'openai', 'ai.openai.base_url' => $baseUrl]);
        $url = $baseUrl.'/chat/completions';
        Http::fake([$url => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{}']]]])]);
        if ($allowed) {
            $this->assertSame('{}', app(BriefModel::class)->generate('Tiêu đề Story', $this->evidence()));
            Http::assertSent(fn (Request $request) => $request->url() === $url && $request->hasHeader('Authorization', 'Bearer '.self::KEY));

            return;
        }
        try {
            app(BriefModel::class)->generate('Tiêu đề Story', $this->evidence());
            $this->fail('Cleartext remote endpoint was enabled.');
        } catch (BriefFailure $error) {
            $this->assertSame('disabled', $error->category);
            Http::assertNothingSent();
        }
    }

    public static function openAiEndpoints(): iterable
    {
        yield 'localhost' => ['http://localhost:1234/v1', true];
        yield 'loopback IPv4' => ['http://127.0.0.1:1234/v1', true];
        yield 'loopback IPv6' => ['http://[::1]:1234/v1', true];
        yield 'remote HTTPS' => ['https://api.example.test/v1', true];
        yield 'remote HTTP' => ['http://api.example.test/v1', false];
        yield 'lookalike' => ['http://localhost.example.test/v1', false];
        yield 'userinfo' => ['http://localhost@api.example.test/v1', false];
    }

    public function test_rejects_truncated_provider_output_even_if_its_json_is_complete(): void
    {
        config(['ai.provider' => 'openai']);
        Http::fake([self::OPENAI_URL => Http::response(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"points":[]}']]]])]);
        try {
            app(BriefModel::class)->generate('Tiêu đề Story', $this->evidence());
            $this->fail('Partial generation was returned.');
        } catch (BriefFailure $error) {
            $this->assertSame('provider_incomplete', $error->reason);
        }
    }

    public function test_fake_is_deterministic_and_missing_credentials_disable_generation(): void
    {
        Http::fake();
        config(['ai.provider' => 'fake']);
        $model = app(BriefModel::class);
        $this->assertSame('fixture-v1', $model->model());
        $this->assertSame($model->generate('Tiêu đề Story', $this->evidence()), $model->generate('Tiêu đề Story', $this->evidence()));
        foreach (['none', 'gemini', 'openai'] as $provider) {
            config(['ai.provider' => $provider, 'ai.gemini.key' => '', 'ai.openai.key' => '']);
            try {
                app(BriefModel::class)->generate('Tiêu đề Story', $this->evidence());
                $this->fail('Provider must be disabled.');
            } catch (BriefFailure $error) {
                $this->assertSame('disabled', $error->category);
            }
        }
        Http::assertNothingSent();
    }

    private function evidence(): array
    {
        $articles = [
            ['id' => 'a', 'source' => ['name' => '<b>Nguồn tin</b>', 'feed_url' => 'https://private.example.test'],
                'published_at' => '2026-11-22T10:00:00+07:00', 'title' => '<i>Tiêu đề bài viết</i>',
                'excerpt' => '&lt;p&gt;'.str_repeat('đ', 810).'&lt;/p&gt;', 'original_url' => 'https://example.test/news'],
            ['id' => 'b', 'source' => ['name' => 'Nguồn thứ hai'], 'published_at' => '2026-11-21T10:00:00+07:00'],
        ];

        return BriefEvidence::forPrompt($articles, 1);
    }

    private function assertPrompt(string $input): void
    {
        $data = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Tiêu đề Story', $data['title']);
        $this->assertSame([[
            'id' => 'a', 'source' => 'Nguồn tin', 'published_at' => '2026-11-22T10:00:00+07:00',
            'title' => 'Tiêu đề bài viết', 'excerpt' => str_repeat('đ', 800),
        ]], $data['evidence']);
    }
}
