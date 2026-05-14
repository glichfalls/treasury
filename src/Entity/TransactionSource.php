<?php

namespace App\Entity;

enum TransactionSource: string
{
    case Manual = 'manual';
    case Import = 'import';
}
