<?php

namespace App;

enum EmergencyContactRelationship: string
{
    case Parent = 'parent';
    case Sibling = 'sibling';
    case Spouse = 'spouse';
    case Child = 'child';
    case Relative = 'relative';
    case Friend = 'friend';
    case Guardian = 'guardian';
    case Other = 'other';
}
