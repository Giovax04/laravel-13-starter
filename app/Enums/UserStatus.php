<?php

namespace App\Enums;

enum UserStatus: string
{
    case Activo = 'activo';
    case Baja = 'baja';
    case Bloqueado = 'bloqueado';

    public function label(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Baja => 'Baja',
            self::Bloqueado => 'Bloqueado',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Activo => 'bg-green-100 text-green-800',
            self::Baja => 'bg-red-100 text-red-800',
            self::Bloqueado => 'bg-yellow-100 text-yellow-800',
        };
    }
}
