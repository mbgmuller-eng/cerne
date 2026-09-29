<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Cada categoria já decide sozinha quem vê o documento além do dono/cônjuge
 * — ver DocumentVisibilityScope. `Other` é a única com flag manual
 * (`visible_to_professional`); todo o resto é travado, sem exceção, porque
 * a maioria aqui é dado pessoal/de saúde, nunca visível a consultor/corretor.
 */
enum DocumentCategory: string
{
    use HasOptions;

    case DriverLicense = 'driver_license';
    case Passport = 'passport';
    case BloodDonorCertificate = 'blood_donor_certificate';
    case InsurancePolicy = 'insurance_policy';
    case HealthExam = 'health_exam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DriverLicense => 'Carteira de motorista',
            self::Passport => 'Passaporte',
            self::BloodDonorCertificate => 'Certificado de doador de sangue',
            self::InsurancePolicy => 'Apólice de seguro',
            self::HealthExam => 'Exame de saúde',
            self::Other => 'Outro',
        };
    }
}
