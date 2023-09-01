<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;

class MySql extends Service
{
	protected static ?string $serviceName = 'mysql-server';

	protected function postInstall(): bool
	{
		// Run the queries that mysql_secure_installation would run as individual queries
		// https://gist.github.com/Mins/4602864
		// mysqladmin -u root password "$DATABASE_PASS"
		// mysql -u root -p"$DATABASE_PASS" -e "UPDATE mysql.user SET Password=PASSWORD('$DATABASE_PASS') WHERE User='root'"
		// mysql -u root -p"$DATABASE_PASS" -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1')"
		// mysql -u root -p"$DATABASE_PASS" -e "DELETE FROM mysql.user WHERE User=''"
		// mysql -u root -p"$DATABASE_PASS" -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\_%'"
		// mysql -u root -p"$DATABASE_PASS" -e "FLUSH PRIVILEGES
		$DATABASE_PASS = config('winux.mysql.root_password', 'root');

		$process = Process::run("mysql -u root -p'$DATABASE_PASS' -e \"UPDATE mysql.user SET Password=PASSWORD('$DATABASE_PASS') WHERE User='root'\"");
		if ($process->failed()) {
			error('Failed to set the root password.');
			return false;
		}
		info('Set the root password.');

		$removeNonLocal = Process::run("mysql -u root -p\"$DATABASE_PASS\" -e \"DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1')\"");
		if ($removeNonLocal->failed()) {
			error('Failed to remove non-local users.');
			return false;
		}
		info('Removed non-local users.');

		$removeEmptyUsers = Process::run("mysql -u root -p\"$DATABASE_PASS\" -e \"DELETE FROM mysql.user WHERE User=''\"");
		if ($removeEmptyUsers->failed()) {
			error('Failed to remove empty users.');
			return false;
		}
		info('Removed empty users.');

		$removeTestDB = Process::run("mysql -u root -p\"$DATABASE_PASS\" -e \"DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%'\"");
		if ($removeTestDB->failed()) {
			error('Failed to remove test databases.');
			return false;
		}
		info('Removed test databases.');

		$flushPrivileges = Process::run("mysql -u root -p\"$DATABASE_PASS\" -e \"FLUSH PRIVILEGES\"");
		if ($flushPrivileges->failed()) {
			error('Failed to flush privileges.');
			return false;
		}
		info('Flushed privileges.');

		return true;


				
	}
}