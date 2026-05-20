<?php

namespace App\Entity;

enum AccountProvider: string
{
    case Manual = 'manual';
    case Ibkr = 'ibkr';
    case Degiro = 'degiro';
    case Zkb = 'zkb';
}
