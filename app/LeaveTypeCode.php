<?php

namespace App;

enum LeaveTypeCode: string
{
    case Annual = 'annual';
    case Sick = 'sick';
    case Maternity = 'maternity';
    case Special = 'special';
    case Unpaid = 'unpaid';
}
