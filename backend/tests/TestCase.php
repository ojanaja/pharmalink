<?php

namespace Tests;

use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): \Illuminate\Testing\TestResponse
    {
        // Satu proses test menjalankan banyak request; AuthManager tidak mereset
        // guard antar-request, sehingga (a) token yang sudah dicabut bisa tampak
        // valid, dan (b) user dari request token sebelumnya bisa bocor ke request
        // tamu berikutnya -> 403 alih-alih 401. Buang guard yang di-cache untuk
        // setiap request KECUALI test yang memakai actingAs (user web guard).
        $webUser = $this->app->make(AuthManager::class)->guard('web')->getUser();

        if (($server['HTTP_AUTHORIZATION'] ?? '') !== '' || $webUser === null) {
            $this->app->make(AuthManager::class)->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
