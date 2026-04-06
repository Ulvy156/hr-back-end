<?php

namespace App;

enum EmployeeEducationLevel: string
{
    case Certificate = 'certificate';
    case Diploma = 'diploma';
    case HighSchool = 'high_school';
    case Associate = 'associate';
    case Bachelor = 'bachelor';
    case Master = 'master';
    case Doctorate = 'doctorate';
    case Other = 'other';
}
