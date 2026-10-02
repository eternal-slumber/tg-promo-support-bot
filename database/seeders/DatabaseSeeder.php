<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = config('support.operator.email');
        $password = config('support.operator.password');

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! is_string($password) || trim($password) === '') {
            throw new RuntimeException('Operator bootstrap requires a valid OPERATOR_EMAIL and a non-empty OPERATOR_PASSWORD.');
        }

        User::query()->firstOrCreate(['email' => $email], [
            'name' => 'Operator',
            'password' => $password,
        ]);
    }
}
