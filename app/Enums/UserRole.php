<?php

namespace App\Enums;

enum UserRole: string
{
    case GlobalBuyer = 'global_buyer';
    case FactoryQa = 'factory_qa';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::GlobalBuyer => 'Global Buyer',
            self::FactoryQa => 'Factory QA',
            self::Admin => 'Administrator',
        };
    }

    public function labelBn(): string
    {
        return match ($this) {
            self::GlobalBuyer => 'গ্লোবাল ক্রেতা',
            self::FactoryQa => 'কারখানা কিউএ',
            self::Admin => 'অ্যাডমিন',
        };
    }
}
