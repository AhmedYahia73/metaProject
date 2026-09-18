<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewApiDocs', fn (?User $user = null) => true);
});

test('scramble docs ui pages are accessible', function () {
    $this->get('/docs/api')->assertOk();
    $this->get('/docs/api/admin')->assertOk();
    $this->get('/docs/api/user')->assertOk();
    $this->get('/docs/api/auth')->assertOk();
});

test('scramble docs shortcut redirects work', function () {
    $this->get('/docs/admin')->assertRedirect('/docs/api/admin');
    $this->get('/docs/user')->assertRedirect('/docs/api/user');
    $this->get('/docs/auth')->assertRedirect('/docs/api/auth');
});
