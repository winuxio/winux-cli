<?php

namespace App\Classes;

use Illuminate\Support\Facades\Process;

class Utility
{
	public static function openBrowser(string $url): true|string
	{
		$process = Process::run(['explorer.exe', $url]);
		// Slight 'bug' in WSL where any call to explorer.exe returns 1 regardless of success
		// So failed() is always true, but we can check if there was an error output
		// Issue on Github: https://github.com/microsoft/WSL/issues/6565
		if ($process->failed() && $process->errorOutput()) {
			return $process->errorOutput();
		}

		return true;
	}
}
