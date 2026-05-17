<?php

if (! function_exists('current_user')) {
    function current_user(): ?array
    {
        $session = session();
        $user = $session->get('user');

        return is_array($user) ? $user : null;
    }
}

if (! function_exists('current_user_id')) {
    function current_user_id(): ?int
    {
        $user = current_user();

        return $user['id'] ?? null;
    }
}

if (! function_exists('has_role')) {
    function has_role(array|string $roles): bool
    {
        $user = current_user();
        if ($user === null) {
            return false;
        }

        $roles = is_array($roles) ? $roles : [$roles];

        return in_array($user['role'], $roles, true);
    }
}

if (! function_exists('is_user_type')) {
    function is_user_type(array|string $types): bool
    {
        $user = current_user();
        if ($user === null) {
            return false;
        }

        $types = is_array($types) ? $types : [$types];

        return in_array($user['user_type'], $types, true);
    }
}
