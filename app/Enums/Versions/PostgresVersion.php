<?php

namespace App\Enums\Versions;

enum PostgresVersion: string
{
	case v12 = '12';
	case v13 = '13';
	case v14 = '14';
	case v15 = '15';
	case v16 = '16';
	case v17 = '17';
	case v18 = '18';
}