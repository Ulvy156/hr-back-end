<?php

namespace App;

enum EmployeeIdType: string
{
    case NationalId = 'national_id';
    case Passport = 'passport';
    case DriverLicense = 'driver_license';
    case ResidenceCard = 'residence_card';
    case Other = 'other';
}
