<?php

test('the application entry point redirects guests to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});
