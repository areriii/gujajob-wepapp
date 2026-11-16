<?php

/*
 * '/' sits behind the 'auth' middleware and only redirects (to the MAT-001 page once signed in),
 * so a guest must be sent to the login page rather than served a 200.
 */

test('a guest visiting the root is redirected to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});
