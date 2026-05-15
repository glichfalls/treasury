<?php

namespace App\Backup;

enum ImportMode: string
{
    case Skip = 'skip';
    case Replace = 'replace';
}
