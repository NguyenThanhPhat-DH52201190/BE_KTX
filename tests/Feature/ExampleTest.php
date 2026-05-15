<?php

namespace Tests\Feature;

// Dùng Illuminate\Foundation\Testing\RefreshDatabase nếu cần làm mới CSDL giữa các test.
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
