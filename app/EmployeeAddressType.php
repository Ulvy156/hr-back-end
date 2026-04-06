<?php

namespace App;

enum EmployeeAddressType: string
{
    case Current = 'current';
    case Permanent = 'permanent';
    case Temporary = 'temporary';
    case Other = 'other';
}
