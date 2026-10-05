<?php

declare(strict_types=1);

namespace SytxLabs\LaravelWebMcp\Console\Concerns;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * `--user=ID` / `--guard=name` for commands that evaluate shouldRegister() as a given user.
 */
trait ImpersonatesUser
{
    /**
     * @return bool false when the requested user does not exist (an error was printed)
     */
    protected function actAsRequestedUser(AuthFactory $auth): bool
    {
        $id = $this->option('user');

        if (!is_string($id) || $id === '') {
            return true;
        }

        $guardName = $this->option('guard');
        $guard = $auth->guard(is_string($guardName) && $guardName !== '' ? $guardName : null);
        $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;
        $user = $provider instanceof UserProvider ? $provider->retrieveById($id) : null;

        if ($user === null) {
            $this->components->error("No user [{$id}] found for the guard.");

            return false;
        }

        $guard->setUser($user);

        if ($guard instanceof StatefulGuard) {
            $auth->shouldUse(is_string($guardName) && $guardName !== '' ? $guardName : $auth->getDefaultDriver());
        }

        return true;
    }
}
