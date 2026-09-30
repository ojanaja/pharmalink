<?php

namespace Tests;

use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): \Illuminate\Testing\TestResponse
    {
        // Satu proses test menjalankan banyak request; AuthManager tidak mereset
        // guard antar-request, sehingga token yang sudah dicabut bisa tampak valid.
        // Buang guard yang di-cache hanya untuk request bearer agar revoke benar-benar
        // teruji; request tanpa header Authorization (mis. via actingAs) tidak disentuh.
        if (($server['HTTP_AUTHORIZATION'] ?? '') !== '') {
            $this->app->make(AuthManager::class)->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
