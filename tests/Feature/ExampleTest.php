<?php

use App\Models\User;

test('la raiz redirige al login a los visitantes', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('la raiz redirige al panel a los usuarios autenticados', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});

test('el registro publico esta deshabilitado', function () {
    $this->get('/register')->assertNotFound();
});
