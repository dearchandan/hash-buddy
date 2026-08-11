<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';

    /** Written by the app, not a traveller: joins, leaves, call outcomes. */
    case System = 'system';
}
