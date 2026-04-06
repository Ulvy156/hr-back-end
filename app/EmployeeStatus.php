<?php

namespace App;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Terminated = 'terminated';
}
