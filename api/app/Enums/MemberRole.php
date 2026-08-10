<?php

namespace App\Enums;

enum MemberRole: string
{
    case Host = 'host';
    case Member = 'member';
}
