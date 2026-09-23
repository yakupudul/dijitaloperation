<?php

namespace App\Enums;

enum SeoTaskType: string
{
    case Fix = 'fix';
    case Strengthen = 'strengthen';
    case Create = 'create';
    case AiVisibility = 'ai_visibility';
    case Question = 'question';

    public function label(): string
    {
        return match ($this) {
            self::Fix => 'Düzelt',
            self::Strengthen => 'Güçlendir',
            self::Create => 'Oluştur',
            self::AiVisibility => 'AI Görünürlük',
            self::Question => 'Soru',
        };
    }
}
