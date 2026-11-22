<?php

namespace App\StoryLens\Brief\Models;

use App\StoryLens\Brief\BriefFailure;
use App\StoryLens\Brief\BriefModel;

final class FakeBriefModel implements BriefModel
{
    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fixture-v1';
    }

    public function generate(string $title, array $evidence): string
    {
        if ($evidence === []) {
            throw new BriefFailure('invalid_output', 'no_evidence');
        }
        $first = $evidence[0];
        $second = $evidence[1] ?? $first;

        return json_encode(['points' => [
            ['text' => 'Nguồn tin mới nhất đề cập đến '.$this->headline($first['title']), 'evidence_ids' => [$first['id']]],
            ['text' => 'Bài viết trong danh sách nguồn có tiêu đề '.$this->headline($second['title']), 'evidence_ids' => [$second['id']]],
        ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function headline(string $title): string
    {
        return trim(preg_replace('/[^\pL\pN\s,-]/u', ' ', $title));
    }
}
