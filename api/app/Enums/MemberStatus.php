<?php

namespace App\Enums;

enum MemberStatus: string
{
    case Joined = 'joined';
    case Left = 'left';
    case Removed = 'removed';
}
