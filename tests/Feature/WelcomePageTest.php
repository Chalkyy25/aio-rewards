<?php

namespace Tests\Feature;

use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    public function test_public_landing_page_loads(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSeeText('AIO Rewards');
    }
}
