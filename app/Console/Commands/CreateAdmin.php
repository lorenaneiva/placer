<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'placer:admin';

    protected $description = 'Cria um administrador com senha informada de forma oculta';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Nome'), 'email' => mb_strtolower(trim((string) $this->ask('E-mail'))), 'phone' => $this->ask('Telefone'), 'password' => $this->secret('Senha (mínimo 12 caracteres)')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'phone' => ['required', 'max:30', 'regex:/^\+?[0-9 ()-]{8,30}$/'], 'password' => ['required', Password::min(12)]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User($data);
        $user->is_admin = true;
        $user->save();
        $this->info('Administrador criado.');

        return self::SUCCESS;
    }
}
