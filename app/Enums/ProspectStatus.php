<?php

namespace App\Enums;

enum ProspectStatus: string
{
    case New = 'new';
    case Researching = 'researching';
    case Qualified = 'qualified';
    case Contacted = 'contacted';
    case Meeting = 'meeting';
    case Proposal = 'proposal';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::New,
            self::Researching,
            self::Qualified,
            self::Contacted,
            self::Meeting,
            self::Proposal,
            self::Won,
            self::Lost,
        ];
    }
}
